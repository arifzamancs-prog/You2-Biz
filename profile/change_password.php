<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

$user_id = $_SESSION['login_user_id'] ?? $_SESSION['user_id'];

$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $current_password = $_POST['current_password'];
    $new_password     = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if(empty($current_password) ||
       empty($new_password) ||
       empty($confirm_password)){

        $message = "All fields are required";
        $message_type = "danger";
    }

    elseif($new_password != $confirm_password){

        $message = "New password and confirm password do not match";
        $message_type = "danger";
    }

    elseif(strlen($new_password) < 6){

        $message = "Password must be at least 6 characters";
        $message_type = "danger";
    }

    elseif(is_super_admin_user()){

        if(!password_verify($current_password, SUPER_ADMIN_PASSWORD_HASH)){

            $message = "Current password is incorrect";
            $message_type = "danger";

        }else{

            $new_hash = password_hash(
                $new_password,
                PASSWORD_DEFAULT
            );

            $config_path = __DIR__ . '/../includes/super_admin_config.php';
            $config = file_get_contents($config_path);
            $new_password_line = "const SUPER_ADMIN_PASSWORD_HASH = " . var_export($new_hash, true) . ";";
            $updated_config = preg_replace_callback(
                "/const SUPER_ADMIN_PASSWORD_HASH = '.*?';/",
                function() use ($new_password_line){
                    return $new_password_line;
                },
                $config,
                1
            );

            if($updated_config === null || $updated_config === $config){

                $message = "Super admin password could not be updated";
                $message_type = "danger";

            }elseif(file_put_contents($config_path, $updated_config, LOCK_EX) === false){

                $message = "Super admin password file is not writable";
                $message_type = "danger";

            }else{

                $message = "Password changed successfully";
                $message_type = "success";
            }
        }
    }

    else{

        $sql = "SELECT password
                FROM users
                WHERE id=?";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(
            $stmt,
            "i",
            $user_id
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        $user = mysqli_fetch_assoc($result);

        if(!$user){

            $message = "User not found";
            $message_type = "danger";

        }elseif(!password_verify($current_password,$user['password'])){

            $message = "Current password is incorrect";
            $message_type = "danger";

        }else{

            $new_hash = password_hash(
                $new_password,
                PASSWORD_DEFAULT
            );

            $sql = "UPDATE users
                    SET password=?
                    WHERE id=?";

            $stmt = mysqli_prepare($conn,$sql);

            mysqli_stmt_bind_param(
                $stmt,
                "si",
                $new_hash,
                $user_id
            );

            mysqli_stmt_execute($stmt);

            $message = "Password changed successfully";
            $message_type = "success";
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
            Change Password
        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-<?= $message_type; ?>">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <form method="post">

            <div class="form-group">

                <label>
                    Current Password
                </label>

                <input
                    type="password"
                    name="current_password"
                    class="form-control"
                    required>

            </div>

            <div class="form-group">

                <label>
                    New Password
                </label>

                <input
                    type="password"
                    name="new_password"
                    class="form-control"
                    required>

            </div>

            <div class="form-group">

                <label>
                    Confirm New Password
                </label>

                <input
                    type="password"
                    name="confirm_password"
                    class="form-control"
                    required>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-key"></i>
                Change Password

            </button>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
