<?php
// Cancels a confirmed E-shop (cash on delivery) invoice when the money never arrived:
// every product goes back to stock, the delivery charge is reversed and the whole due is cleared.
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/sales_return_helper.php';

$cid = (int)$_SESSION['user_id'];

if(is_agent_user() || !manager_can_modify() || $_SERVER['REQUEST_METHOD'] !== 'POST'){
    header('Location: invoice_list.php');
    exit;
}

// Schema work before the transaction: CREATE/ALTER TABLE would commit it.
sales_return_schema($conn);
$invoice_id = (int)($_POST['invoice_id'] ?? 0);
require_branch_record_access($conn, 'invoices', $invoice_id, 'invoice_list.php');
$reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);

$conn->begin_transaction();

try{
    sales_refund_query($conn, 'SELECT id FROM invoices WHERE id=? AND user_id=? FOR UPDATE', 'ii', [$invoice_id, $cid])->get_result();
    $invoice = sales_return_fetch_invoice($conn, $cid, $invoice_id);

    if(!$invoice || ($invoice['created_by_type'] ?? '') !== 'eshop'){
        throw new RuntimeException('Only an E-shop invoice can be cancelled here.');
    }
    if(($invoice['accounting_status'] ?? '') !== 'posted'){
        throw new RuntimeException('Create the invoice first; a pending order is cancelled from E-shop → Orders.');
    }
    if((float)$invoice['paid_amount'] > 0.009){
        throw new RuntimeException('Money was already received for this invoice. Use Sales Return instead.');
    }
    if((int)$invoice['customer_id'] <= 0 || (float)$invoice['due_amount'] <= 0.009){
        throw new RuntimeException('This invoice has no due to cancel.');
    }

    // Every product still on the invoice comes back to stock.
    $lines = sales_return_lines($conn, $invoice);
    $quantities = [];
    foreach($lines as $line_id => $line){
        if((int)$line['returnable_qty'] > 0){
            $quantities[$line_id] = (int)$line['returnable_qty'];
        }
    }
    if(!$quantities){
        throw new RuntimeException('Every product on this invoice was already returned.');
    }
    if(count($quantities) !== count($lines) || array_sum(array_column($lines, 'returned_qty')) > 0){
        throw new RuntimeException('Part of this invoice was already returned. Use Sales Return for the rest.');
    }

    $pick = sales_return_pick($conn, $invoice, $quantities, []);
    // A cancelled order owes nothing: the credit note covers the full invoice, delivery charge included.
    $pick['total'] = round((float)$invoice['due_amount'], 2);

    $branch = (int)$invoice['branch_id'];
    $actor_id = (int)($_SESSION['login_user_id'] ?? $cid);
    $actor_name = trim((string)($_SESSION['login_name'] ?? ''));
    $note = 'E-shop order cancelled (payment not received)' . ($reason !== '' ? ': ' . $reason : '');
    $return = sales_return_create($conn, $cid, $branch, $invoice, $pick, $actor_id, $actor_name, $note);

    // Mirror the invoice's added charges on the credit note so its lines add up to its total.
    $charges = sales_refund_query($conn, 'SELECT charge_type_id, amount FROM invoice_charges WHERE invoice_id=?', 'i', [$invoice_id])->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach($charges as $charge){
        sales_refund_query($conn, 'INSERT INTO invoice_charges(invoice_id,charge_type_id,amount) VALUES(?,?,?)', 'iid', [(int)$return['credit_id'], (int)$charge['charge_type_id'], -(float)$charge['amount']]);
    }

    sales_return_settle($conn, $cid, $branch, $invoice, $return, ['due' => $pick['total']]);

    // The customer's order tracking shows the cancellation.
    $order = sales_refund_query($conn, 'SELECT id FROM eshop_orders WHERE company_id=? AND invoice_id=? LIMIT 1', 'ii', [$cid, $invoice_id])->get_result()->fetch_assoc();
    if($order){
        sales_refund_query($conn, "INSERT INTO eshop_delivery(order_id,status,tracking) VALUES(?,'cancelled','') ON DUPLICATE KEY UPDATE status='cancelled'", 'i', [(int)$order['id']]);
        sales_refund_query($conn, "INSERT INTO eshop_delivery_history(order_id,actor_id,status,tracking) VALUES(?,?,'cancelled',?)", 'iis', [(int)$order['id'], $actor_id, mb_substr($reason, 0, 200)]);
    }

    $conn->commit();
    header('Location: invoice_list.php?success=1&cancelled=' . urlencode((string)$invoice['invoice_no']));
    exit;

}catch(Throwable $e){
    $conn->rollback();
    if(!($e instanceof RuntimeException)){
        error_log($e->getMessage());
    }
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'The invoice could not be cancelled. Nothing was changed. Please try again.';
    header('Location: invoice_list.php?error=' . urlencode($message));
    exit;
}
