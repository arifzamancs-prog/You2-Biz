<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/invoice_charge_helper.php';
require_once '../includes/invoice_posting_helper.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/customer_due_allocation_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/pending_invoice_stock_helper.php';
require_once '../includes/input_validation_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/restaurant_table_helper.php';
require_once '../includes/invoice_reference_helper.php';

$user_id = $_SESSION['user_id'];
ensure_invoice_charge_columns($conn);
ensure_invoice_posting_columns($conn);
ensure_fifo_inventory_tables($conn);
ensure_staff_table($conn);
ensure_restaurant_tables_table($conn);
ensure_invoice_reference_columns($conn);
$table_system_is_enabled = table_system_enabled($conn, $user_id);

function sales_generate_unique_invoice_no($conn)
{
    for($attempt = 0; $attempt < 10; $attempt++){
        $invoice_no = 'INV-' . date('ymdHis') . random_int(10, 99);

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                (SELECT COUNT(*) FROM invoices WHERE invoice_no=?) AS invoice_count,
                (SELECT COUNT(*) FROM transactions WHERE txn_no=?) AS transaction_count"
        );

        if(!$stmt){
            return $invoice_no;
        }

        mysqli_stmt_bind_param($stmt, "ss", $invoice_no, $invoice_no);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;

        if(
            (int)($row['invoice_count'] ?? 0) === 0 &&
            (int)($row['transaction_count'] ?? 0) === 0
        ){
            return $invoice_no;
        }
    }

    return 'INV-' . date('ymdHis') . random_int(100, 999);
}

if($_SERVER['REQUEST_METHOD']!='POST'){

    header("Location:create_invoice.php");
    exit;

}

mysqli_begin_transaction($conn);

try{
    $action = $_POST['action'] ?? 'save';

    if(is_agent_user() && $action === 'print'){
        throw new Exception("Agent can only save pending sales voucher.");
    }

    $should_post = $action === 'print';
    $accounting_status = $should_post ? 'posted' : 'pending';


    $limit_sql = "SELECT
                    max_invoices_monthly,
                    (
                        SELECT COUNT(*)
                        FROM invoices
                        WHERE user_id=users.id
                        AND MONTH(invoice_date)=MONTH(CURDATE())
                        AND YEAR(invoice_date)=YEAR(CURDATE())
                    ) AS invoice_count
                  FROM users
                  WHERE id=?";

    $limit_stmt = mysqli_prepare($conn, $limit_sql);
    mysqli_stmt_bind_param($limit_stmt, "i", $user_id);
    mysqli_stmt_execute($limit_stmt);
    $limit = mysqli_fetch_assoc(mysqli_stmt_get_result($limit_stmt));

    if($limit && (int)$limit['invoice_count'] >= (int)$limit['max_invoices_monthly']){
        throw new Exception("Monthly invoice limit reached for your subscription. " . subscription_support_message());
    }

    /*
    ==========================================
    CUSTOMER
    ==========================================
    */

    $customer_id = !empty($_POST['customer_id'])
        ? (int)$_POST['customer_id']
        : 0;

    $customer_name = trim(
        $_POST['customer_name'] ?? ''
    );

    $is_instant_customer_without_profile = false;

    if($customer_id==0){

        $phone = trim(
            $_POST['customer_phone'] ?? ''
        );

        $address = trim(
            $_POST['customer_address'] ?? ''
        );

        if(
            $customer_name === '' &&
            $phone === '' &&
            $address === ''
        ){

            $customer_name =
                "Instant Customer";

            $is_instant_customer_without_profile =
                true;

        }else{

        $customer_name = normalize_person_name($customer_name);
        $phone = normalize_phone_input($phone);

        if(
            empty($customer_name) ||
            empty($phone)
        ){

            throw new Exception(
                "Customer Name & Phone Required."
            );

        }

        $name_error = validate_person_name($customer_name, 'Customer name');
        if($name_error !== ''){
            throw new Exception($name_error);
        }

        $phone_error = validate_phone_input($phone, 'Phone', true);
        if($phone_error !== ''){
            throw new Exception($phone_error);
        }

        $duplicate_message = '';

        if(contact_has_duplicate_in_table($conn, 'customers', 'Customer', 'phone', $phone, 0, $duplicate_message, $user_id)){
            throw new Exception($duplicate_message);
        }

        /*
        Auto Customer Create
        */

        $sql="INSERT INTO customers(

                user_id,
                customer_name,
                phone,
                address,
                status

              )

              VALUES(

                ?,
                ?,
                ?,
                ?,
                'active'

              )";

        $stmt=mysqli_prepare(
            $conn,
            $sql
        );

        mysqli_stmt_bind_param(

            $stmt,

            "isss",

            $user_id,
            $customer_name,
            $phone,
            $address

        );

        mysqli_stmt_execute($stmt);

        $customer_id=
            mysqli_insert_id($conn);

        }

    }

    /*
    Existing Customer Name
    */

    if(!$is_instant_customer_without_profile){

    $sql="SELECT customer_name
          FROM customers
          WHERE id=?
          AND user_id=?";

    $stmt=mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(
        $stmt,
        "ii",
        $customer_id,
        $user_id
    );

    mysqli_stmt_execute($stmt);

    $result=
        mysqli_stmt_get_result($stmt);

    $customer=
        mysqli_fetch_assoc($result);

    if(!$customer){

        throw new Exception(
            "Customer Not Found."
        );

    }

    $customer_name =
        $customer['customer_name'];

    }

    /*
    ==========================================
    SUMMARY
    ==========================================
    */

    $grand_total =
        (float)$_POST['grand_total'];

    $paid_amount =
        (float)$_POST['paid_amount'];

    if($paid_amount < 0){
        throw new Exception("Paid Amount cannot be negative.");
    }
    
    $receive_wallet_id =
    (int)($_POST['receive_wallet_id'] ?? 0);

    $due_amount =
        (float)$_POST['due_amount'];

    $payment_status =
        $_POST['payment_status'];

    $notes =
        trim($_POST['notes']);

    $staff_id = (int)($_POST['staff_id'] ?? 0);
    $restaurant_table_id = (int)($_POST['restaurant_table_id'] ?? 0);
    if(!$table_system_is_enabled){ $staff_id = 0; $restaurant_table_id = 0; }
    if($restaurant_table_id > 0 && $staff_id === 0){
        throw new Exception('Select the staff before selecting a table.');
    }
    if($staff_id > 0){
        $stmt = mysqli_prepare($conn, "SELECT id FROM staff WHERE id=? AND user_id=? AND status='active'");
        mysqli_stmt_bind_param($stmt, 'ii', $staff_id, $user_id); mysqli_stmt_execute($stmt);
        if(mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0){ throw new Exception('Selected staff is not valid.'); }
    }
    if($restaurant_table_id > 0){
        $stmt = mysqli_prepare($conn, "SELECT id FROM restaurant_tables WHERE id=? AND user_id=? AND staff_id=? AND status='active'");
        mysqli_stmt_bind_param($stmt, 'iii', $restaurant_table_id, $user_id, $staff_id); mysqli_stmt_execute($stmt);
        if(mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0){ throw new Exception('Selected table is not assigned to the selected staff.'); }
    }

    $product_ids = $_POST['product_id'] ?? [];
    $qtys        = $_POST['qty'] ?? [];
    $prices      = $_POST['price'] ?? [];
    $totals      = $_POST['line_total'] ?? [];
    $positive_qty_totals = [];

    $subtotal = 0;

    foreach($product_ids as $key => $product_id){
        if(empty($product_id)){
            continue;
        }

        $subtotal += (float)($totals[$key] ?? 0);

        $product_id = (int)$product_id;
        $line_qty = (float)($qtys[$key] ?? 0);

        if($line_qty > 0){
            if(!isset($positive_qty_totals[$product_id])){
                $positive_qty_totals[$product_id] = 0.0;
            }

            $positive_qty_totals[$product_id] += $line_qty;
        }
    }

    $calculated_charges = [];

    if(isset($_POST['charge_id'])){
        foreach($_POST['charge_id'] as $key => $charge_id){
            $charge_id = (int)$charge_id;
            $input_amount = (float)($_POST['charge_amount'][$key] ?? 0);

            if($input_amount <= 0){
                continue;
            }

            $charge_sql = "SELECT id, charge_type, charge_value_type
                           FROM invoice_charge_types
                           WHERE id=?
                           AND user_id=?
                           AND status='active'
                           AND show_on_invoice=1";

            $charge_stmt = mysqli_prepare($conn, $charge_sql);
            mysqli_stmt_bind_param($charge_stmt, "ii", $charge_id, $user_id);
            mysqli_stmt_execute($charge_stmt);
            $charge = mysqli_fetch_assoc(mysqli_stmt_get_result($charge_stmt));

            if(!$charge){
                continue;
            }

            $charge_amount = calculate_invoice_charge_amount(
                $input_amount,
                $charge['charge_value_type'] ?? 'fixed',
                $subtotal
            );

            if($charge_amount <= 0){
                continue;
            }

            $calculated_charges[] = [
                'charge_id' => $charge_id,
                'amount' => $charge_amount,
                'charge_type' => normalize_charge_type($charge['charge_type'] ?? 'add'),
            ];
        }
    }

    $grand_total = $subtotal;

    foreach($calculated_charges as $charge){
        if($charge['charge_type'] === 'less'){
            $grand_total -= $charge['amount'];
        }else{
            $grand_total += $charge['amount'];
        }
    }

    $customer_balance_before_invoice = $customer_id > 0
        ? customer_signed_balance_total($conn, $user_id, $customer_id)
        : 0;
    $previous_due_total = $customer_id > 0
        ? customer_previous_due_total($conn, $user_id, $customer_id)
        : 0;
    $outstanding_amount_total = abs(min($customer_balance_before_invoice, 0));

    if($customer_id === 0 && abs($paid_amount - $grand_total) > 0.01){
        throw new Exception("For instant customers, the Paid Amount must be equal to the Grand Total.");
    }
    // Existing customers may overpay; any extra amount becomes outstanding payable credit.

    $current_invoice_cash_payment = min($paid_amount, $grand_total);
    $applied_outstanding_amount = min(
        $outstanding_amount_total,
        max($grand_total - $current_invoice_cash_payment, 0)
    );
    $current_invoice_payment = min(
        $current_invoice_cash_payment + $applied_outstanding_amount,
        $grand_total
    );
    $remaining_after_current = max(
        ($paid_amount + $applied_outstanding_amount) - $current_invoice_payment,
        0
    );
    $previous_due_payment = min($remaining_after_current, $previous_due_total);
    $outstanding_payable = max($remaining_after_current - $previous_due_payment, 0);
    $due_amount = $grand_total - $current_invoice_payment;

    if($outstanding_payable > 0.01){
        $due_amount = -$outstanding_payable;
    }

    if($due_amount <= 0){
        $payment_status = 'paid';
        if(abs($due_amount) <= 0.01){
            $due_amount = 0;
        }
    }elseif($paid_amount > 0 || $applied_outstanding_amount > 0){
        $payment_status = 'partial';
    }else{
        $payment_status = 'due';
    }

    /*
    Invoice Number
    */

    $invoice_no = sales_generate_unique_invoice_no($conn);

    foreach($positive_qty_totals as $product_id => $requested_qty){
        $product_snapshot = product_stock_snapshot_for_invoice(
            $conn,
            $user_id,
            (int)$product_id
        );

        if(!$product_snapshot){
            throw new Exception("Product Not Found.");
        }

        if(!$product_snapshot['is_stock_product']){
            continue;
        }

        if($requested_qty > ((float)$product_snapshot['available_stock'] + 0.0001)){
            throw new Exception("Not enough available stock for selected product. Pending voucher reserved this product.");
        }
    }

    $created_by_user_id = null;
    $created_by_name = null;
    $created_by_type = null;

    if(($_SESSION['user_role'] ?? '') === 'manager'){
        $created_by_user_id = (int)($_SESSION['login_user_id'] ?? 0);
        $created_by_name = trim((string)($_SESSION['login_name'] ?? ''));
        $created_by_type = (($_SESSION['manager_type'] ?? '') === 'agent')
            ? 'Agent'
            : 'Manager';
    }

    /*
    ==========================================
    SAVE INVOICE
    ==========================================
    */

    $sql="INSERT INTO invoices(

            user_id,
            invoice_no,
            customer_id,
            receive_wallet_id,
            customer_name,
            invoice_date,
            total_amount,
            notes,
            staff_id,
            restaurant_table_id,
            paid_amount,
            due_amount,
            payment_status,
            created_by_user_id,
            created_by_name,
            created_by_type,
            accounting_status

        )

        VALUES(

            ?,
            ?,
            ?,
            ?,
            ?,
            CURDATE(),
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?

        )";

    $stmt=mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(

        $stmt,

        "isiisdsiiddsisss",

        $user_id,
        $invoice_no,
        $customer_id,
        $receive_wallet_id,
        $customer_name,
        $grand_total,
        $notes,
        $staff_id,
        $restaurant_table_id,
        $paid_amount,
        $due_amount,
        $payment_status,
        $created_by_user_id,
        $created_by_name,
        $created_by_type,
        $accounting_status

    );

    if(!mysqli_stmt_execute($stmt)){

        throw new Exception(
            mysqli_stmt_error($stmt)
        );

    }

    $invoice_id =
        mysqli_insert_id($conn);

/*
==========================================
INVOICE ITEMS
==========================================
*/

foreach($product_ids as $key => $product_id){

    if(empty($product_id)){
        continue;
    }

    $product_id = (int)$product_id;
    $qty        = (int)$qtys[$key];
    $price      = (float)$prices[$key];
    $total      = (float)$totals[$key];

    /*
    STOCK CHECK
    */

    $sql = "SELECT current_stock
            FROM products
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "ii",
        $product_id,
        $user_id
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $product = mysqli_fetch_assoc($result);

    if(!$product){

        throw new Exception(
            "Product Not Found."
        );

    }

    if($qty === 0){

        throw new Exception(
            "Quantity cannot be zero."
        );

    }

    $is_stock_product = product_uses_stock($conn, $product_id, $user_id);

    /*
    SAVE ITEM
    */

    $sql = "INSERT INTO invoice_items(

                invoice_id,
                product_id,
                quantity,
                unit_price,
                total_price

            )

            VALUES(

                ?,
                ?,
                ?,
                ?,
                ?

            )";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(

        $stmt,

        "iiddd",

        $invoice_id,
        $product_id,
        $qty,
        $price,
        $total

    );

    if(!mysqli_stmt_execute($stmt)){

        throw new Exception(
            mysqli_stmt_error($stmt)
        );

    }

    $invoice_item_id = mysqli_insert_id($conn);

    if($should_post && $is_stock_product){

    /*
    STOCK DEDUCT
    */

    $sql = "UPDATE products

            SET current_stock =
                current_stock - ?

            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(

        $stmt,

        "dii",

        $qty,
        $product_id,
        $user_id

    );

    if(!mysqli_stmt_execute($stmt)){

        throw new Exception(
            mysqli_stmt_error($stmt)
        );

    }

    /*
    STOCK TRANSACTION
    */

    $stock_transaction_type = $qty < 0 ? 'stock_in' : 'stock_out';
    $stock_quantity = abs($qty);

    $sql = "INSERT INTO stock_transactions(

                user_id,
                product_id,
                transaction_type,
                quantity,
                note,
                txn_date,
                reference_no

            )

            VALUES(

                ?,
                ?,
                ?,
                ?,
                ?,
                CURDATE(),
                ?

            )";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    $note = $qty < 0 ? "Sales Invoice Return" : "Sales Invoice";

    mysqli_stmt_bind_param(

        $stmt,

        "iisdss",

        $user_id,
        $product_id,
        $stock_transaction_type,
        $stock_quantity,
        $note,
        $invoice_no

    );

    if(!mysqli_stmt_execute($stmt)){

        throw new Exception(
            mysqli_stmt_error($stmt)
        );

    }

    if($qty > 0){
        $allocation = fifo_inventory_allocate_sale(
            $conn,
            $user_id,
            $invoice_item_id,
            $product_id,
            $qty
        );

        if(!$allocation['success']){
            throw new Exception($allocation['error'] ?? 'FIFO allocation failed.');
        }
    }elseif($qty < 0){
        $product_cost_stmt = mysqli_prepare(
            $conn,
            "SELECT purchase_price
             FROM products
             WHERE id=?
             AND user_id=?"
        );

        mysqli_stmt_bind_param($product_cost_stmt, "ii", $product_id, $user_id);
        mysqli_stmt_execute($product_cost_stmt);
        $product_cost_row = mysqli_fetch_assoc(mysqli_stmt_get_result($product_cost_stmt));
        $return_unit_cost = (float)($product_cost_row['purchase_price'] ?? 0);

        if(!fifo_inventory_add_return_batch(
            $conn,
            $user_id,
            $invoice_item_id,
            $product_id,
            $qty,
            $return_unit_cost,
            $invoice_no,
            date('Y-m-d')
        )){
            throw new Exception("FIFO return batch failed.");
        }
    }

}

}

/*
==========================================
SAVE CHARGES
==========================================
*/

foreach($calculated_charges as $charge){

        $charge_id = (int)$charge['charge_id'];
        $amount = (float)$charge['amount'];

        $sql = "INSERT INTO invoice_charges(

                    invoice_id,
                    charge_type_id,
                    amount

                )

                VALUES(

                    ?,
                    ?,
                    ?

                )";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(

            $stmt,

            "iid",

            $invoice_id,
            $charge_id,
            $amount

        );

        if(!mysqli_stmt_execute($stmt)){

            throw new Exception(
                mysqli_stmt_error($stmt)
            );

        }

}

/*
==========================================
CUSTOMER PAYMENT
==========================================
*/

if($should_post && $paid_amount > 0){

    if($receive_wallet_id <= 0){
        throw new Exception("Receive wallet is required.");
    }

    $payment_note = "Invoice Payment - ".$invoice_no;

    if($customer_id > 0){
        if($current_invoice_cash_payment > 0.01){
            $sql = "INSERT INTO customer_payments(
                        user_id,
                        customer_id,
                        invoice_id,
                        amount,
                        payment_date,
                        note
                    )
                    VALUES(
                        ?,
                        ?,
                        ?,
                        ?,
                        CURDATE(),
                        ?
                    )";

            $stmt = mysqli_prepare($conn, $sql);

            mysqli_stmt_bind_param(
                $stmt,
                "iiids",
                $user_id,
                $customer_id,
                $invoice_id,
                $current_invoice_cash_payment,
                $payment_note
            );

            if(!mysqli_stmt_execute($stmt)){
                throw new Exception(mysqli_stmt_error($stmt));
            }
        }

        if($previous_due_payment > 0.01){
            allocate_customer_previous_due_payment(
                $conn,
                $user_id,
                $customer_id,
                $invoice_id,
                $invoice_no,
                $previous_due_payment
            );
        }

        if($outstanding_payable > 0.01){
            $advance_note = "Outstanding Amount - " . $invoice_no;
            $sql = "INSERT INTO customer_payments(
                        user_id,
                        customer_id,
                        invoice_id,
                        amount,
                        payment_date,
                        note
                    )
                    VALUES(
                        ?,
                        ?,
                        ?,
                        ?,
                        CURDATE(),
                        ?
                    )";

            $stmt = mysqli_prepare($conn, $sql);

            mysqli_stmt_bind_param(
                $stmt,
                "iiids",
                $user_id,
                $customer_id,
                $invoice_id,
                $outstanding_payable,
                $advance_note
            );

            if(!mysqli_stmt_execute($stmt)){
                throw new Exception(mysqli_stmt_error($stmt));
            }
        }
    }

    record_wallet_transaction(
        $conn,
        $invoice_no,
        $user_id,
        $receive_wallet_id,
        'sales_invoice',
        $invoice_id,
        $paid_amount,
        'Sales Invoice - '.$invoice_no,
        date('Y-m-d')
    );

    $sql = "UPDATE wallets
            SET balance = balance + ?
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        "dii",
        $paid_amount,
        $receive_wallet_id,
        $user_id
    );

    if(!mysqli_stmt_execute($stmt)){
        throw new Exception(mysqli_stmt_error($stmt));
    }
}

/*
==========================================
COMMIT
==========================================
*/

mysqli_commit($conn);

$_SESSION['success'] =
    "Invoice Created Successfully.";

if(
    isset($_POST['action']) &&
    $_POST['action'] == 'print'
){

    header(
        "Location:print_invoice.php?id=".$invoice_id."&reload_parent=create"
    );

}else{

    if(is_agent_user()){
        header(
            "Location:create_invoice.php?success=1"
        );
        exit;
    }

    header(
        "Location:invoice_list.php?success=1"
    );

}

exit;


}catch(Exception $e){


mysqli_rollback($conn);

$_SESSION['error'] = $e->getMessage();

header("Location:create_invoice.php");
exit;


}
?>
