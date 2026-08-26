<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/input_validation_helper.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/expense_helper.php';

$user_id = $_SESSION['user_id'];

ensure_fifo_inventory_tables($conn);
ensure_product_management_columns($conn);
ensure_product_category_type_column($conn);
ensure_expense_support_tables($conn, $user_id);

if($_SERVER['REQUEST_METHOD'] != 'POST'){
    header("Location:index.php");
    exit;
}

function purchase_find_or_create_supplier($conn, $user_id)
{
    $supplier_choice = trim((string)($_POST['supplier_id'] ?? ''));

    if($supplier_choice === '__new__'){
        $supplier_name = normalize_person_name($_POST['new_supplier_name'] ?? '');
        $phone = normalize_phone_input($_POST['new_supplier_phone'] ?? '');
        $address = trim((string)($_POST['new_supplier_address'] ?? ''));
        $email = '';

        if(($error = validate_person_name($supplier_name, 'Supplier name')) !== ''){
            throw new Exception($error);
        }

        if(($error = validate_phone_input($phone, 'Phone', true)) !== ''){
            throw new Exception($error);
        }

        $duplicate_message = '';
        if(
            contact_has_company_user_conflict($conn, 'phone', $phone, $user_id, $duplicate_message) ||
            contact_has_duplicate_in_table($conn, 'suppliers', 'Supplier', 'phone', $phone, 0, $duplicate_message, $user_id)
        ){
            throw new Exception($duplicate_message);
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO suppliers
             (
                user_id,
                supplier_name,
                phone,
                email,
                address,
                status
             )
             VALUES
             (
                ?,?,?,?,?, 'active'
             )"
        );

        if(!$stmt){
            throw new Exception("Supplier could not be created.");
        }

        mysqli_stmt_bind_param(
            $stmt,
            "issss",
            $user_id,
            $supplier_name,
            $phone,
            $email,
            $address
        );

        if(!mysqli_stmt_execute($stmt)){
            throw new Exception("Supplier could not be created.");
        }

        return (int)mysqli_insert_id($conn);
    }

    $supplier_id = (int)$supplier_choice;

    if($supplier_id <= 0){
        throw new Exception("Please select a supplier.");
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM suppliers
         WHERE id=?
         AND user_id=?
         AND status='active'
         LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, "ii", $supplier_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if(!$result || mysqli_num_rows($result) === 0){
        throw new Exception("Selected supplier not found.");
    }

    return $supplier_id;
}

function purchase_prepare_items($conn, $user_id)
{
    $product_ids = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $prices = $_POST['cost_price'] ?? [];
    $totals = $_POST['line_total'] ?? [];
    $new_product_names = $_POST['new_product_name'] ?? [];

    $items = [];
    $new_product_count = 0;

    $limit_stmt = mysqli_prepare(
        $conn,
        "SELECT u.max_products, COUNT(p.id) AS product_count
         FROM users u
         LEFT JOIN products p ON p.user_id = u.id
         WHERE u.id=?
         GROUP BY u.id, u.max_products"
    );

    mysqli_stmt_bind_param($limit_stmt, "i", $user_id);
    mysqli_stmt_execute($limit_stmt);
    $limit_info = mysqli_fetch_assoc(mysqli_stmt_get_result($limit_stmt));
    $existing_product_count = (int)($limit_info['product_count'] ?? 0);
    $max_products = (int)($limit_info['max_products'] ?? 0);

    // Products added from purchasing always belong to the default stock category.
    ensure_default_product_categories($conn, $user_id);
    $stock_category_stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM product_categories
         WHERE user_id=? AND status='active' AND category_type='stock_product'
         ORDER BY CASE WHEN category_name='Stock Product' THEN 0 ELSE 1 END, id ASC
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stock_category_stmt, 'i', $user_id);
    mysqli_stmt_execute($stock_category_stmt);
    $stock_category = mysqli_fetch_assoc(mysqli_stmt_get_result($stock_category_stmt));
    $stock_category_id = (int)($stock_category['id'] ?? 0);

    if($stock_category_id <= 0){
        throw new Exception('Stock Product category is not available.');
    }

    foreach($product_ids as $key => $product_choice){
        $product_choice = trim((string)$product_choice);
        $qty = (int)($qtys[$key] ?? 0);
        $price = (float)($prices[$key] ?? 0);
        // Sale price is not part of the supplier purchase form. For a newly
        // created product, retain the entered purchase price as its initial value.
        $sale_price = $price;
        $total = (float)($totals[$key] ?? 0);

        if($product_choice === '' && trim((string)($new_product_names[$key] ?? '')) === ''){
            continue;
        }

        if($qty <= 0){
            throw new Exception("Quantity must be at least 1.");
        }

        if($price < 0){
            throw new Exception("Purchase price cannot be negative.");
        }

        if($product_choice === '__new__'){
            $category_id = $stock_category_id;
            $product_name = trim((string)($new_product_names[$key] ?? ''));

            if(($error = validate_person_name($product_name, 'Product name')) !== ''){
                throw new Exception($error);
            }

            if($max_products > 0 && ($existing_product_count + $new_product_count) >= $max_products){
                throw new Exception("Product limit reached for your subscription. " . subscription_support_message());
            }

            $status = 'active';
            $sku = '';
            $expired_on = null;
            $opening_stock = 0;
            $minimum_stock = 5;

            $product_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO products
                 (
                    user_id,
                    category_id,
                    product_name,
                    sku,
                    purchase_price,
                    sale_price,
                    expired_on,
                    current_stock,
                    opening_stock_quantity,
                    opening_stock_unit_cost,
                    minimum_stock,
                    status
                 )
                 VALUES
                 (
                    ?,?,?,?,?,?,?,?,?,?,?,?
                 )"
            );

            if(!$product_stmt){
                throw new Exception("Product could not be created.");
            }

            mysqli_stmt_bind_param(
                $product_stmt,
                "iissddsdddis",
                $user_id,
                $category_id,
                $product_name,
                $sku,
                $price,
                $sale_price,
                $expired_on,
                $opening_stock,
                $opening_stock,
                $price,
                $minimum_stock,
                $status
            );

            if(!mysqli_stmt_execute($product_stmt)){
                throw new Exception("Product could not be created.");
            }

            $product_id = (int)mysqli_insert_id($conn);
            $new_product_count++;
        }else{
            $product_id = (int)$product_choice;

            if($product_id <= 0){
                continue;
            }

            $product_stmt = mysqli_prepare(
                $conn,
                "SELECT p.id
                 FROM products p
                 INNER JOIN product_categories c ON c.id=p.category_id
                 WHERE p.id=?
                 AND p.user_id=?
                 AND p.status='active'
                 AND c.category_type='stock_product'
                 LIMIT 1"
            );

            mysqli_stmt_bind_param($product_stmt, "ii", $product_id, $user_id);
            mysqli_stmt_execute($product_stmt);
            $product_result = mysqli_stmt_get_result($product_stmt);

            if(!$product_result || mysqli_num_rows($product_result) === 0){
                throw new Exception("Selected product not found.");
            }
        }

        $items[] = [
            'product_id' => $product_id,
            'qty' => $qty,
            'price' => $price,
            'sale_price' => $sale_price,
            'total' => $qty * $price,
        ];
    }

    if(empty($items)){
        throw new Exception("Please add at least one product.");
    }

    return $items;
}

mysqli_begin_transaction($conn);

try{
    $supplier_id = purchase_find_or_create_supplier($conn, $user_id);
    $items = purchase_prepare_items($conn, $user_id);

    $purchase_date = date('Y-m-d');
    $grand_total = 0;
    foreach($items as $item){
        $grand_total += (float)$item['total'];
    }

    $paid_amount = (float)($_POST['paid_amount'] ?? 0);
    $payment_wallet_id = (int)($_POST['payment_wallet_id'] ?? 0);
    $payment_status = trim((string)($_POST['payment_status'] ?? 'due'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $due_amount = $grand_total - $paid_amount;

    if($paid_amount < 0){
        throw new Exception("Paid Amount cannot be negative.");
    }

    if($paid_amount > 0){
        $wallet_balance_stmt = mysqli_prepare(
            $conn,
            "SELECT balance
             FROM wallets
             WHERE id=?
             AND user_id=?
             LIMIT 1"
        );

        mysqli_stmt_bind_param($wallet_balance_stmt, "ii", $payment_wallet_id, $user_id);
        mysqli_stmt_execute($wallet_balance_stmt);
        $wallet_balance_row = mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_balance_stmt));

        if(!$wallet_balance_row){
            throw new Exception("Selected payment wallet not found.");
        }

        if($paid_amount > (float)$wallet_balance_row['balance']){
            throw new Exception("Paid amount exceeds wallet balance.");
        }
    }

    $purchase_no = generate_short_unique_txn_no($conn, 'PUR', 'purchases', 'purchase_no');

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO purchases
         (
            user_id,
            purchase_no,
            supplier_id,
            payment_wallet_id,
            purchase_date,
            total_amount,
            paid_amount,
            due_amount,
            payment_status,
            notes
         )
         VALUES
         (
            ?,?,?,?,?,?,?,?,?,?
         )"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "isiisdddss",
        $user_id,
        $purchase_no,
        $supplier_id,
        $payment_wallet_id,
        $purchase_date,
        $grand_total,
        $paid_amount,
        $due_amount,
        $payment_status,
        $notes
    );

    mysqli_stmt_execute($stmt);
    $purchase_id = mysqli_insert_id($conn);

    foreach($items as $item){
        $product_id = (int)$item['product_id'];
        $qty = (int)$item['qty'];
        $price = (float)$item['price'];
        $total = (float)$item['total'];

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO purchase_items
             (
                purchase_id,
                product_id,
                quantity,
                unit_cost,
                total_cost
             )
             VALUES
             (
                ?,?,?,?,?
             )"
        );

        mysqli_stmt_bind_param($stmt, "iiddd", $purchase_id, $product_id, $qty, $price, $total);
        mysqli_stmt_execute($stmt);

        if(!fifo_inventory_create_batch(
            $conn,
            $user_id,
            $product_id,
            $qty,
            $price,
            'purchase',
            $purchase_id,
            $purchase_no,
            $purchase_date
        )){
            throw new Exception("FIFO batch could not be created.");
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE products
             SET current_stock = current_stock + ?,
                 purchase_price = ?
             WHERE id = ?
             AND user_id = ?"
        );

        mysqli_stmt_bind_param($stmt, "ddii", $qty, $price, $product_id, $user_id);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO stock_transactions
             (
                user_id,
                product_id,
                transaction_type,
                quantity,
                note,
                txn_date,
                reference_no
             )
             VALUES
             (
                ?,?,
                'stock_in',
                ?,
                ?,
                ?,
                ?
             )"
        );

        $note = "Purchase";
        mysqli_stmt_bind_param($stmt, "iidsss", $user_id, $product_id, $qty, $note, $purchase_date, $purchase_no);
        mysqli_stmt_execute($stmt);
    }

    if($paid_amount > 0){
        debit_wallet($conn, $payment_wallet_id, $user_id, $paid_amount);

        record_wallet_transaction(
            $conn,
            $purchase_no,
            $user_id,
            $payment_wallet_id,
            'purchase',
            $purchase_id,
            $paid_amount,
            'Purchase - ' . $purchase_no,
            $purchase_date
        );

        record_supplier_payment_expense(
            $conn,
            $user_id,
            $payment_wallet_id,
            $paid_amount,
            $purchase_date,
            'Supplier Payment - ' . $purchase_no,
            'purchase_payment',
            $purchase_id
        );
    }

    mysqli_commit($conn);

    $_SESSION['success'] = "Purchase Saved Successfully.";
    header("Location:index.php?success=1");
    exit;
}catch(Exception $e){
    mysqli_rollback($conn);
    $_SESSION['error'] = $e->getMessage();
    header("Location:create.php");
    exit;
}

?>
