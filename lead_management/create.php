<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/lead_management_helper.php';

require_lead_management_access();
ensure_lead_management_table($conn);

$user_id = (int)$_SESSION['user_id'];
$creator_name = '';
$creator_user_id = (int)($_SESSION['login_user_id'] ?? $user_id);
$creator_stmt = mysqli_prepare($conn, "SELECT name FROM users WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($creator_stmt, 'i', $creator_user_id);
mysqli_stmt_execute($creator_stmt);
$creator = mysqli_fetch_assoc(mysqli_stmt_get_result($creator_stmt));
$creator_name = trim((string)($creator['name'] ?? ''));
$message = '';
$today = date('Y-m-d');
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$note = trim($_POST['note'] ?? '');
$followup_date = trim($_POST['followup_date'] ?? '');

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if($name === '' || $phone === '' || $followup_date === ''){
        $message = 'Name, Phone and Followup Date are required.';
    } elseif($followup_date < $today){
        $message = 'Followup Date cannot be earlier than today.';
    } else {
        $note = $note ?: 'General';
        $date_value = $followup_date;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO leads
             (user_id, name, phone, email, note, followup_date, status, created_by_user_id, created_by_name)
             VALUES
             (?, ?, ?, ?, ?, ?, 'lead', ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, 'isssssis', $user_id, $name, $phone, $email, $note, $date_value, $creator_user_id, $creator_name);

        if(mysqli_stmt_execute($stmt)){
            header('Location: index.php?filter=lead');
            exit;
        }

        $message = 'Failed to save lead.';
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Add Lead</h3>
    </div>

    <div class="card-body">
        <?php if($message){ ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php } ?>

        <form method="post">
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($name); ?>" required>
            </div>

            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phone); ?>" required>
            </div>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email); ?>">
            </div>

            <div class="form-group">
                <label>Note</label>
                <textarea name="note" class="form-control" rows="3"><?= htmlspecialchars($note); ?></textarea>
            </div>

            <div class="form-group">
                <label>Followup Date</label>
                <input type="date" name="followup_date" class="form-control" value="<?= htmlspecialchars($followup_date); ?>" min="<?= htmlspecialchars($today); ?>" required>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Save Lead
            </button>
            <a href="index.php?filter=lead" class="btn btn-secondary">Back</a>
        </form>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>
