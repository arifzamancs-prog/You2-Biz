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
$lead_owner_name = trim((string)($_SESSION['login_name'] ?? ''));
$lead_scope_sql = is_manager_user() ? ' AND (created_by_user_id=? OR created_by_name=?)' : '';

$lead_stmt = mysqli_prepare($conn, "SELECT * FROM leads WHERE id=? AND user_id=?{$lead_scope_sql} LIMIT 1");
if(is_manager_user()){
    mysqli_stmt_bind_param($lead_stmt, 'iiis', $id, $user_id, $lead_owner_id, $lead_owner_name);
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
$reference_options = lead_reference_options($conn, $user_id);
$reference_user_id = (int)($_POST['reference_user_id'] ?? $lead['reference_user_id'] ?? 0);
$reference_name = trim((string)($reference_options[$reference_user_id]['name'] ?? ''));

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if($name === '' || $phone === '' || $followup_date === '' || $reference_name === ''){
        $message = 'Name, Ref., Phone and Followup Date are required.';
    }elseif($followup_date < $today){
        $message = 'Followup Date cannot be earlier than today.';
    }else{
        $duplicate_phone_stmt = mysqli_prepare($conn, 'SELECT id FROM leads WHERE user_id=? AND phone=? AND id<>? LIMIT 1');
        mysqli_stmt_bind_param($duplicate_phone_stmt, 'isi', $user_id, $phone, $id);
        mysqli_stmt_execute($duplicate_phone_stmt);
        $duplicate_phone_lead = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate_phone_stmt));
        mysqli_stmt_close($duplicate_phone_stmt);

        if($duplicate_phone_lead){
            $message = 'This phone number is already used by Lead ID ' . lead_code_from_id((int)$duplicate_phone_lead['id']) . '.';
        }else{
            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE leads
                 SET name=?, phone=?, email=?, note=?, followup_date=?, reference_user_id=?, reference_name=?
                 WHERE id=? AND user_id=?{$lead_scope_sql}"
            );
            if(is_manager_user()){
                mysqli_stmt_bind_param($update_stmt, 'sssssisiiis', $name, $phone, $email, $note, $followup_date, $reference_user_id, $reference_name, $id, $user_id, $lead_owner_id, $lead_owner_name);
            }else{
                mysqli_stmt_bind_param($update_stmt, 'sssssisii', $name, $phone, $email, $note, $followup_date, $reference_user_id, $reference_name, $id, $user_id);
            }

            if(mysqli_stmt_execute($update_stmt)){
                header('Location: index.php?filter=' . urlencode($lead['status'] ?? 'lead'));
                exit;
            }

            $message = mysqli_stmt_errno($update_stmt) === 1062
                ? 'This phone number is already used by another lead.'
                : 'Failed to update lead.';
        }
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
            <div class="form-group"><label>Ref.</label><select name="reference_user_id" class="form-control" required><option value="">Select Ref.</option><?php foreach($reference_options as $option){ ?><option value="<?= (int)$option['login_user_id']; ?>" <?= $reference_user_id === (int)$option['login_user_id'] ? 'selected' : ''; ?>><?= htmlspecialchars($option['name'] . (!empty($option['designation']) ? ' (' . $option['designation'] . ')' : '')); ?></option><?php } ?></select></div>
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
