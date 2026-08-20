<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$message = '';

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $user_id = $_SESSION['user_id'];

    $wallet_name = trim($_POST['wallet_name']);
    $description = trim($_POST['description']);

    if($wallet_name == ''){

        $message = "Wallet Name Required";

    }else{

        $sql = "SELECT id
                FROM wallets
                WHERE user_id=?
                AND wallet_name=?";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(
            $stmt,
            "is",
            $user_id,
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
                wallet_name,
                description,
                balance,
                status
            )
            VALUES
            (
                ?,?,?,0,'active'
            )";

            $stmt = mysqli_prepare($conn,$sql);

            mysqli_stmt_bind_param(
                $stmt,
                "iss",
                $user_id,
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