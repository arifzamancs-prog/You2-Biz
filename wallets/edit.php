<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_context_helper.php';

$user_id = $_SESSION['user_id'];
$branch_scope = branch_scope_sql($conn, 'wallets');

$id = (int)$_GET['id'];

$message = '';

/* Load Wallet */

$sql = "SELECT *
        FROM wallets
        WHERE id=?
        AND user_id=? {$branch_scope}";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$wallet = mysqli_fetch_assoc($result);
if(!$wallet){
    die("Wallet Not Found");
}
if ($wallet['is_system'] == 1) {

    die("System Wallet Cannot Be Edited");

}

/* Update */

if($_SERVER['REQUEST_METHOD']=='POST'){

    $wallet_name = trim($_POST['wallet_name']);
    $description = trim($_POST['description'] ?? '');

    $sql = "UPDATE wallets
            SET wallet_name=?, description=?
            WHERE id=?
            AND user_id=? {$branch_scope}";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "ssii",
        $wallet_name,
        $description,
        $id,
        $user_id
    );

    mysqli_stmt_execute($stmt);

    header("Location:index.php");
    exit;
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Edit Wallet
        </h3>

    </div>

    <div class="card-body">

        <form method="post">

            <div class="form-group">

                <label>
                    Wallet Name
                </label>

                <input
                    type="text"
                    name="wallet_name"
                    class="form-control"
                    required
                    value="<?= htmlspecialchars($wallet['wallet_name']); ?>">

            </div>

            <div class="form-group">

                <label>
                    Description
                </label>

                <textarea
                    name="description"
                    class="form-control"
                    rows="4"
                    maxlength="255"><?= htmlspecialchars($wallet['description'] ?? ''); ?></textarea>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                Update Wallet

            </button>

            <a href="index.php"
               class="btn btn-secondary">

                Back

            </a>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
