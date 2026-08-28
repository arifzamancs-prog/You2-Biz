<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/lead_management_helper.php';

require_lead_management_access();
ensure_lead_management_table($conn);

$user_id = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);
$message = '';
$today = date('Y-m-d');
$lead_owner_id = (int)($_SESSION['login_user_id'] ?? 0);
$lead_scope_sql = is_manager_user() ? ' AND created_by_user_id=?' : '';

$lead_stmt = mysqli_prepare($conn, "SELECT * FROM leads WHERE id=? AND user_id=? AND status='lead'{$lead_scope_sql} LIMIT 1");
if(is_manager_user()){
    mysqli_stmt_bind_param($lead_stmt, 'iii', $id, $user_id, $lead_owner_id);
}else{
    mysqli_stmt_bind_param($lead_stmt, 'ii', $id, $user_id);
}
mysqli_stmt_execute($lead_stmt);
$lead = mysqli_fetch_assoc(mysqli_stmt_get_result($lead_stmt));

if(!$lead){
    header('Location: index.php?filter=lead');
    exit;
}

$name = trim($_POST['name'] ?? $lead['name']);
$phone = trim($_POST['phone'] ?? $lead['phone']);
$email = trim($_POST['email'] ?? $lead['email']);
$note = trim($_POST['note'] ?? $lead['note']);
$followup_date = trim($_POST['followup_date'] ?? $lead['followup_date']);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if($name === '' || $phone === '' || $followup_date === ''){
        $message = 'Name, Phone and Followup Date are required.';
    }elseif($followup_date < $today){
        $message = 'Followup Date cannot be earlier than today.';
    }else{
        $note = $note ?: 'General';
        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE leads
             SET name=?, phone=?, email=?, note=?, followup_date=?
             WHERE id=? AND user_id=? AND status='lead'{$lead_scope_sql}"
        );
        if(is_manager_user()){
            mysqli_stmt_bind_param($update_stmt, 'sssssiii', $name, $phone, $email, $note, $followup_date, $id, $user_id, $lead_owner_id);
        }else{
            mysqli_stmt_bind_param($update_stmt, 'sssssii', $name, $phone, $email, $note, $followup_date, $id, $user_id);
        }

        if(mysqli_stmt_execute($update_stmt)){
            header('Location: index.php?filter=lead');
            exit;
        }

        $message = 'Failed to update lead.';
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header"><h3 class="card-title">Edit Lead</h3></div>
    <div class="card-body">
        <?php if($message){ ?><div class="alert alert-danger"><?= htmlspecialchars($message); ?></div><?php } ?>
        <form method="post">
            <div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($name); ?>" required></div>
            <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phone); ?>" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email); ?>"></div>
            <div class="form-group"><label>Note</label><textarea name="note" class="form-control" rows="3"><?= htmlspecialchars($note); ?></textarea></div>
            <div class="form-group"><label>Followup Date</label><input type="date" name="followup_date" class="form-control" value="<?= htmlspecialchars($followup_date); ?>" min="<?= htmlspecialchars($today); ?>" required></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Lead</button>
            <a href="index.php?filter=lead" class="btn btn-secondary">Back</a>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
