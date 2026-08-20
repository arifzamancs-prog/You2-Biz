<?php

session_start();

require_once 'includes/db.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$token_hash = $token ? hash('sha256', $token) : '';
$message = '';
$message_type = '';
$valid_reset = false;
$reset_user = null;

if($token_hash){

    $sql = "SELECT
                pr.id AS reset_id,
                pr.user_id,
                u.name,
                u.status
            FROM password_resets pr
            INNER JOIN users u
                ON u.id = pr.user_id
            WHERE pr.token=?
            AND pr.used_at IS NULL
            AND pr.expires_at > NOW()
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        "s",
        $token_hash
    );

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $reset_user = mysqli_fetch_assoc($result);

    if($reset_user && $reset_user['status'] === 'active'){
        $valid_reset = true;
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_reset){

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if(strlen($password) < 6){

        $message = "Password must be at least 6 characters.";
        $message_type = "danger";

    }elseif($password !== $confirm_password){

        $message = "Password and confirm password do not match.";
        $message_type = "danger";

    }else{

        $hash = password_hash($password, PASSWORD_DEFAULT);

        mysqli_begin_transaction($conn);

        try{

            $update_sql = "UPDATE users
                           SET password=?
                           WHERE id=?";

            $update_stmt = mysqli_prepare($conn, $update_sql);

            mysqli_stmt_bind_param(
                $update_stmt,
                "si",
                $hash,
                $reset_user['user_id']
            );

            mysqli_stmt_execute($update_stmt);

            $used_sql = "UPDATE password_resets
                         SET used_at = NOW()
                         WHERE id=?";

            $used_stmt = mysqli_prepare($conn, $used_sql);

            mysqli_stmt_bind_param(
                $used_stmt,
                "i",
                $reset_user['reset_id']
            );

            mysqli_stmt_execute($used_stmt);

            mysqli_commit($conn);

            $valid_reset = false;
            $message = "Password updated successfully. You can sign in now.";
            $message_type = "success";

        }catch(Exception $e){

            mysqli_rollback($conn);
            $message = "Password could not be updated.";
            $message_type = "danger";
        }
    }
}

if(!$valid_reset && !$message){
    $message = "This reset link is invalid or expired.";
    $message_type = "danger";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Password - You2 Biz</title>
<link rel="stylesheet" href="adminlte/plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="adminlte/dist/css/adminlte.min.css">
<style>
body.auth-page{background:#eef2f7;min-height:100vh;}
.auth-shell{align-items:center;display:flex;justify-content:center;min-height:100vh;padding:24px;}
.auth-box{background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(15,23,42,.16);max-width:460px;padding:38px;width:100%;}
.auth-logo{align-items:center;display:flex;gap:12px;font-size:22px;font-weight:700;margin-bottom:30px;}
.auth-logo-mark{align-items:center;background:#2563eb;border-radius:10px;color:#fff;display:flex;height:42px;justify-content:center;width:42px;}
.auth-box h1{color:#111827;font-size:28px;font-weight:700;margin:0 0 8px;}
.auth-subtitle{color:#6b7280;margin-bottom:24px;}
.auth-input{border:1px solid #d8dee8;border-radius:9px;height:46px;}
.auth-submit{border-radius:9px;font-weight:600;height:46px;}
.auth-link{color:#2563eb;font-weight:600;}
</style>
</head>
<body class="auth-page">
<div class="auth-shell">
    <div class="auth-box">
        <div class="auth-logo">
            <span class="auth-logo-mark"><i class="fas fa-wallet"></i></span>
<span>You2 Biz</span>
        </div>

        <h1>Reset password</h1>
        <div class="auth-subtitle">Set a new password for your account.</div>

        <?php if($message){ ?>
            <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <?php if($valid_reset){ ?>
            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">

                <div class="form-group">
                    <label>New Password</label>
                    <input
                        type="password"
                        name="password"
                        class="form-control auth-input"
                        required>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <input
                        type="password"
                        name="confirm_password"
                        class="form-control auth-input"
                        required>
                </div>

                <button type="submit" class="btn btn-primary btn-block auth-submit">
                    Update Password
                </button>
            </form>
        <?php } ?>

        <div class="text-center mt-4">
            <a href="login.php" class="auth-link">Back to login</a>
        </div>
    </div>
</div>
</body>
</html>
