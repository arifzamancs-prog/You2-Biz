<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/restaurant_table_helper.php';

require_admin_user();
ensure_restaurant_tables_table($conn);
$user_id = (int)$_SESSION['user_id'];
if (!table_system_enabled($conn, $user_id)) { header('Location: ../dashboard.php'); exit; }
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $table_name = trim($_POST['table_name'] ?? '');
    $capacity = trim($_POST['capacity'] ?? '');
    $capacity = $capacity === '' ? null : max(1, (int)$capacity);

    if ($table_name === '') {
        $error = 'Table name or number is required.';
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_tables (user_id, table_name, capacity) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isi', $user_id, $table_name, $capacity);
        if (mysqli_stmt_execute($stmt)) {
            header('Location: index.php');
            exit;
        }
        $error = 'This table name or number already exists.';
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Create Restaurant Table</h3></div><div class="card-body">
<?php if($error){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post"><div class="form-group"><label>Table Name / No.</label><input class="form-control" name="table_name" value="<?=htmlspecialchars($_POST['table_name'] ?? '')?>" placeholder="e.g. Table 1" required></div><div class="form-group"><label>Capacity <small class="text-muted">(optional)</small></label><input class="form-control" type="number" min="1" name="capacity" value="<?=htmlspecialchars($_POST['capacity'] ?? '')?>" placeholder="e.g. 4"></div><button class="btn btn-primary"><i class="fas fa-save"></i> Save Table</button> <a href="index.php" class="btn btn-secondary">Back</a></form>
</div></div>
<?php require_once '../includes/footer.php'; ?>
