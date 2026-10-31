<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/branch_context_helper.php';

$message = '';
$user_id = (int)$_SESSION['user_id'];
$multi_branch = company_multi_branch_enabled($conn, $user_id);

// Multi Branch: only the admin adds wallets, for one branch or for every branch.
if($multi_branch && !is_admin_user()){
    $_SESSION['wallet_message'] = ['type' => 'danger', 'text' => 'Only the admin can add wallets.'];
    header('Location: index.php');
    exit;
}

$wallet_branches = [];
if($multi_branch){
    $branch_stmt = mysqli_prepare($conn, "SELECT id, branch_name, branch_code FROM branches WHERE user_id=? AND status='active'" . warehouse_branch_filter_sql() . " ORDER BY CASE WHEN is_head_office=1 THEN 0 WHEN LOWER(TRIM(branch_name))='main warehouse' THEN 1 ELSE 2 END, branch_name ASC");
    mysqli_stmt_bind_param($branch_stmt, 'i', $user_id);
    mysqli_stmt_execute($branch_stmt);
    $wallet_branches = mysqli_fetch_all(mysqli_stmt_get_result($branch_stmt), MYSQLI_ASSOC);
}
$wallet_branch_names = array_column($wallet_branches, 'branch_name', 'id');
$wallet_branch_choice = (string)($_POST['wallet_branch'] ?? selected_branch_id($conn, false));

if($multi_branch && $_SERVER['REQUEST_METHOD'] == 'POST'){

    ensure_branch_accounting_columns($conn, $user_id);
    $wallet_name = trim((string)($_POST['wallet_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $target_ids = $wallet_branch_choice === 'all'
        ? array_keys($wallet_branch_names)
        : (isset($wallet_branch_names[(int)$wallet_branch_choice]) ? [(int)$wallet_branch_choice] : []);

    if($wallet_name == ''){
        $message = "Wallet Name Required";
    }elseif(!$target_ids){
        $message = "Select a branch for the wallet.";
    }else{
        // Each branch gets its own copy (own balance); a branch that already has this name is skipped.
        $exists = mysqli_prepare($conn, 'SELECT id FROM wallets WHERE user_id=? AND branch_id=? AND wallet_name=?');
        $insert = mysqli_prepare($conn, "INSERT INTO wallets (user_id, branch_id, wallet_name, description, balance, status) VALUES (?,?,?,?,0,'active')");
        $created = $skipped = [];

        foreach($target_ids as $target_id){
            mysqli_stmt_bind_param($exists, 'iis', $user_id, $target_id, $wallet_name);
            mysqli_stmt_execute($exists);
            if(mysqli_num_rows(mysqli_stmt_get_result($exists)) > 0){
                $skipped[] = $wallet_branch_names[$target_id];
                continue;
            }
            mysqli_stmt_bind_param($insert, 'iiss', $user_id, $target_id, $wallet_name, $description);
            mysqli_stmt_execute($insert);
            $created[] = $wallet_branch_names[$target_id];
        }

        if(!$created){
            $message = "Wallet already exists" . (count($target_ids) > 1 ? ' in every branch.' : '');
        }else{
            $_SESSION['wallet_message'] = [
                'type' => 'success',
                'text' => 'Wallet "' . $wallet_name . '" added to ' . implode(', ', $created) . '.'
                    . ($skipped ? ' Already there: ' . implode(', ', $skipped) . '.' : ''),
            ];
            header("Location:index.php");
            exit;
        }
    }

}elseif($_SERVER['REQUEST_METHOD'] == 'POST'){

    ensure_branch_accounting_columns($conn, (int)$user_id);
    $branch_id = selected_branch_id($conn, true);

    $wallet_name = trim($_POST['wallet_name']);
    $description = trim($_POST['description']);

    if($wallet_name == ''){

        $message = "Wallet Name Required";

    }else{

        $sql = "SELECT id
                FROM wallets
                WHERE user_id=?
                AND branch_id=?
                AND wallet_name=?";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(
            $stmt,
            "iis",
            $user_id,
            $branch_id,
            $wallet_name
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if(mysqli_num_rows($result) > 0){

            $message = "Wallet already exists";

        }else{

            $sql = "INSERT INTO wallets
            (
                user_id,
                branch_id,
                wallet_name,
                description,
                balance,
                status
            )
            VALUES
            (
                ?,?,?,?,0,'active'
            )";

            $stmt = mysqli_prepare($conn,$sql);

            mysqli_stmt_bind_param(
                $stmt,
                "iiss",
                $user_id,
                $branch_id,
                $wallet_name,
                $description
            );

            mysqli_stmt_execute($stmt);

            header("Location:index.php");
            exit;
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            Add Wallet
        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-danger">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <form method="post">

            <?php if($multi_branch){ ?>
            <div class="form-group">
                <label for="wallet_branch">Branch</label>
                <select name="wallet_branch" id="wallet_branch" class="form-control" required>
                    <option value="all" <?= $wallet_branch_choice === 'all' ? 'selected' : ''; ?>>All Branches</option>
                    <?php foreach($wallet_branches as $wallet_branch){ ?>
                        <option value="<?= (int)$wallet_branch['id']; ?>" <?= $wallet_branch_choice === (string)$wallet_branch['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($wallet_branch['branch_name'] . (!empty($wallet_branch['branch_code']) ? ' [' . $wallet_branch['branch_code'] . ']' : '')); ?>
                        </option>
                    <?php } ?>
                </select>
                <small class="text-muted">All Branches adds a separate wallet with this name to every branch, each with its own balance.</small>
            </div>
            <?php } ?>

            <div class="form-group">

                <label>
                    Wallet Name
                </label>

                <input
                    type="text"
                    name="wallet_name"
                    class="form-control"
                    maxlength="100"
                    required>

            </div>

            <div class="form-group">

                <label>
                    Description
                </label>

                <textarea
                    name="description"
                    class="form-control"
                    rows="4"
                    maxlength="255"></textarea>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-save"></i>
                Save Wallet

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
