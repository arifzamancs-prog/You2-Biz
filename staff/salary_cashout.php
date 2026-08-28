<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/staff_attendance_helper.php';
require_once '../includes/staff_ledger_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/transaction_helper.php';
require_once '../includes/expense_helper.php';

require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_staff_attendance_tables($conn);
ensure_staff_ledger_table($conn);
ensure_default_cash_wallet($conn, $user_id);
ensure_expense_support_tables($conn, $user_id);
$salary_id = (int)($_REQUEST['id'] ?? 0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wallet_id = (int)($_POST['wallet_id'] ?? 0);
    $paid_date = $_POST['paid_date'] ?? date('Y-m-d');
    $note = trim($_POST['note'] ?? '') ?: 'Monthly salary payment';
    if ($salary_id <= 0 || $wallet_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date)) {
        $error = 'Select a payment wallet and valid payment date.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $salary_stmt = mysqli_prepare($conn, "SELECT ms.*, s.name, s.staff_code, s.designation FROM staff_monthly_salaries ms INNER JOIN staff s ON s.id=ms.staff_id AND s.user_id=ms.user_id WHERE ms.id=? AND ms.user_id=? FOR UPDATE");
            mysqli_stmt_bind_param($salary_stmt, 'ii', $salary_id, $user_id);
            mysqli_stmt_execute($salary_stmt);
            $salary = mysqli_fetch_assoc(mysqli_stmt_get_result($salary_stmt));
            if (!$salary || $salary['payment_status'] !== 'pending' || (float)$salary['generated_salary'] <= 0) {
                throw new Exception('This salary is not available for cash out.');
            }
            $wallet_stmt = mysqli_prepare($conn, "SELECT id, wallet_name FROM wallets WHERE id=? AND user_id=? AND status='active' LIMIT 1 FOR UPDATE");
            mysqli_stmt_bind_param($wallet_stmt, 'ii', $wallet_id, $user_id);
            mysqli_stmt_execute($wallet_stmt);
            $wallet = mysqli_fetch_assoc(mysqli_stmt_get_result($wallet_stmt));
            if (!$wallet) {
                throw new Exception('Selected payment wallet is not available.');
            }
            $amount = (float)$salary['generated_salary'];
            $txn_no = generate_short_unique_txn_no($conn, 'SAL', 'staff_ledger_entries');
            $created_by = (int)($_SESSION['login_user_id'] ?? $user_id);
            $insert = mysqli_prepare($conn, "INSERT INTO staff_ledger_entries (txn_no,user_id,staff_id,wallet_id,entry_type,entry_date,amount,note,created_by) VALUES (?,?,?,?, 'salary', ?,?,?,?)");
            mysqli_stmt_bind_param($insert, 'siiisdsi', $txn_no, $user_id, $salary['staff_id'], $wallet_id, $paid_date, $amount, $note, $created_by);
            if (!mysqli_stmt_execute($insert)) { throw new Exception(mysqli_stmt_error($insert)); }
            $ledger_id = (int)mysqli_insert_id($conn);
            debit_wallet($conn, $wallet_id, $user_id, $amount);
            record_wallet_transaction($conn, $txn_no, $user_id, $wallet_id, 'staff_payment', $ledger_id, $amount, 'Staff salary payment: ' . $salary['name'], $paid_date);

            $category_id = reserved_expense_category_id($conn, $user_id, reserved_expense_category_name_from_entry_type('salary'));
            $approved_at = date('Y-m-d H:i:s');
            $expense = mysqli_prepare($conn, "INSERT INTO expenses (txn_no,user_id,wallet_id,category_id,staff_id,txn_date,amount,note,approval_status,created_by,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,?,'approved',?,?,?)");
            mysqli_stmt_bind_param($expense, 'siiiisdsiis', $txn_no, $user_id, $wallet_id, $category_id, $salary['staff_id'], $paid_date, $amount, $note, $created_by, $created_by, $approved_at);
            if (!mysqli_stmt_execute($expense)) { throw new Exception(mysqli_stmt_error($expense)); }

            $paid_at = date('Y-m-d H:i:s');
            $update = mysqli_prepare($conn, "UPDATE staff_monthly_salaries SET payment_status='paid', paid_ledger_id=?, paid_wallet_id=?, paid_at=?, paid_by=? WHERE id=? AND user_id=? AND payment_status='pending'");
            mysqli_stmt_bind_param($update, 'iisiii', $ledger_id, $wallet_id, $paid_at, $created_by, $salary_id, $user_id);
            if (!mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== 1) { throw new Exception('Salary payment could not be finalized.'); }
            mysqli_commit($conn);
            header('Location: salary_voucher.php?id=' . $salary_id . '&paid=1');
            exit;
        } catch (Exception $exception) {
            mysqli_rollback($conn);
            $error = $exception->getMessage();
        }
    }
}

$salary_stmt = mysqli_prepare($conn, "SELECT ms.*, s.name, s.staff_code, s.designation FROM staff_monthly_salaries ms INNER JOIN staff s ON s.id=ms.staff_id AND s.user_id=ms.user_id WHERE ms.id=? AND ms.user_id=? LIMIT 1");
mysqli_stmt_bind_param($salary_stmt, 'ii', $salary_id, $user_id);
mysqli_stmt_execute($salary_stmt);
$salary = mysqli_fetch_assoc(mysqli_stmt_get_result($salary_stmt));
if (!$salary) { http_response_code(404); exit('Salary record not found.'); }
$wallets = active_wallets_result($conn, $user_id);
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-money-bill-wave mr-2"></i>Cash Out Salary</h3></div><div class="card-body">
<?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if($salary['payment_status'] === 'paid'): ?><div class="alert alert-info">This salary has already been paid. <a target="_blank" href="salary_voucher.php?id=<?= (int)$salary_id ?>">Open voucher</a></div><?php else: ?>
<div class="row mb-3"><div class="col-md-6"><strong>Staff:</strong> <?= htmlspecialchars($salary['name']) ?> (<?= htmlspecialchars($salary['staff_code']) ?>)<br><strong>Period:</strong> <?= date('F Y', mktime(0,0,0,(int)$salary['salary_month'],1,(int)$salary['salary_year'])) ?><br><strong>Designation:</strong> <?= htmlspecialchars($salary['designation']) ?></div><div class="col-md-6"><div class="alert alert-success mb-0"><strong>Payable Salary: BDT <?= number_format((float)$salary['generated_salary'],2) ?></strong><br><small>Assigned: BDT <?= number_format((float)$salary['assigned_salary'],2) ?> · Attendance cut: BDT <?= number_format((float)$salary['salary_cut_amount'],2) ?></small></div></div></div>
<form method="post"><div class="row"><div class="col-md-5 form-group"><label>Payment by</label><select name="wallet_id" class="form-control" required><option value="">Select Wallet</option><?php while($wallet=mysqli_fetch_assoc($wallets)): ?><option value="<?= (int)$wallet['id'] ?>"><?= htmlspecialchars($wallet['wallet_name']) ?> — BDT <?= number_format((float)$wallet['balance'],2) ?></option><?php endwhile; ?></select></div><div class="col-md-3 form-group"><label>Payment Date</label><input type="date" name="paid_date" value="<?= date('Y-m-d') ?>" class="form-control" required></div></div><div class="form-group"><label>Note</label><textarea name="note" rows="3" class="form-control" placeholder="Optional note">Monthly salary payment</textarea></div><button class="btn btn-success"><i class="fas fa-cash-register"></i> Cash Out & Print Voucher</button></form><?php endif; ?>
</div></div>
<?php require_once '../includes/footer.php'; ?>
