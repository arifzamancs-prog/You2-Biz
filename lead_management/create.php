<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/lead_management_helper.php';

require_admin_user();
ensure_lead_management_table($conn);

$user_id = (int)$_SESSION['user_id'];
$message = '';
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$note = trim($_POST['note'] ?? '');
$followup_date = trim($_POST['followup_date'] ?? '');

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if($name === '' || $phone === '' || $followup_date === ''){
        $message = 'Name, Phone and Followup Date are required.';
    } else {
        $note = $note ?: 'General';
        $date_value = $followup_date;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO leads
             (user_id, name, phone, email, note, followup_date, status)
             VALUES
             (?, ?, ?, ?, ?, ?, 'lead')"
        );
        mysqli_stmt_bind_param($stmt, 'isssss', $user_id, $name, $phone, $email, $note, $date_value);

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
                <input type="date" name="followup_date" class="form-control" value="<?= htmlspecialchars($followup_date); ?>" required>
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
