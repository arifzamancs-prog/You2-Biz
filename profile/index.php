<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/contact_unique_helper.php';
require_once '../includes/login_email_otp_helper.php';

ensure_login_email_otp_columns($conn);

$is_super_admin = is_super_admin_user();
$is_manager = is_manager_user();
$is_company_admin = !$is_super_admin && !$is_manager;
$user_id = $is_super_admin
    ? 0
    : ($is_manager
    ? ($_SESSION['login_user_id'] ?? $_SESSION['user_id'])
    : $_SESSION['user_id']);

$message = '';
$message_type = 'success';

function profile_redirect_with_flash($type, $message)
{
    $_SESSION['profile_flash_message'] = $message;
    $_SESSION['profile_flash_type'] = $type;
    header("Location: index.php");
    exit;
}

function super_admin_config_value($constant_name, $default)
{
    return defined($constant_name) ? constant($constant_name) : $default;
}

function update_super_admin_config_constant($config, $constant_name, $value)
{
    $line = "const " . $constant_name . " = " . var_export($value, true) . ";";
    $pattern = "/const " . preg_quote($constant_name, '/') . " = .*?;/";

    if(preg_match($pattern, $config)){
        return preg_replace_callback(
            $pattern,
            function() use ($line){
                return $line;
            },
            $config,
            1
        );
    }

    return preg_replace(
        "/(<\?php\s*)/",
        "$1\r\n" . $line . "\r\n",
        $config,
        1
    );
}

function profile_user_contact_duplicate_message($conn, $field, $value, $exclude_user_id)
{
    $field = $field === 'phone' ? 'phone' : 'email';
    $value = trim((string)$value);

    if(contact_unique_value_is_blank($value)){
        return '';
    }

    $reserved_message = contact_reserved_super_admin_message($field, $value);

    if($reserved_message !== ''){
        return $reserved_message;
    }

    $sql = "SELECT id
            FROM users
            WHERE LOWER(`$field`)=LOWER(?)
            AND id<>?
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return '';
    }

    $exclude_user_id = (int)$exclude_user_id;
    mysqli_stmt_bind_param($stmt, "si", $value, $exclude_user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if($result && mysqli_num_rows($result) > 0){
        return ucfirst($field) . " already exists.";
    }

    return '';
}

if($is_super_admin){
    $user = [
        'name' => super_admin_config_value('SUPER_ADMIN_NAME', $_SESSION['user_name'] ?? 'Super Admin'),
        'address' => super_admin_config_value('SUPER_ADMIN_PROFILE_ADDRESS', 'None'),
        'email' => function_exists('super_admin_notify_email') ? super_admin_notify_email() : '',
        'phone' => super_admin_config_value('SUPER_ADMIN_PROFILE_PHONE', 'None'),
            'avatar' => super_admin_config_value('SUPER_ADMIN_PROFILE_AVATAR', $_SESSION['avatar'] ?? 'you2biz.png'),
        'login_email_otp_status' => function_exists('super_admin_login_email_otp_status')
            ? super_admin_login_email_otp_status()
            : 'inactive',
    ];
}else{
    $sql = "SELECT * FROM users WHERE id=?";

    $stmt = mysqli_prepare($conn,$sql);
    mysqli_stmt_bind_param($stmt,"i",$user_id);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    if($is_manager && $user){
        if(
            preg_match('/\.manager\.\d+@you2-wallet\.local$/', (string)($user['email'] ?? '')) ||
            trim((string)($user['email'] ?? '')) === ''
        ){
            $user['email'] = 'None';
        }

        if(
            str_starts_with((string)($user['phone'] ?? ''), 'MGR') ||
            trim((string)($user['phone'] ?? '')) === ''
        ){
            $user['phone'] = 'None';
        }

        if(trim((string)($user['address'] ?? '')) === ''){
            $user['address'] = 'None';
        }
    }
}

if(isset($_SESSION['profile_flash_message'])){
    $message = $_SESSION['profile_flash_message'];
    $message_type = $_SESSION['profile_flash_type'] ?? 'success';
    unset($_SESSION['profile_flash_message'], $_SESSION['profile_flash_type']);
}

if($_SERVER['REQUEST_METHOD']=='POST'){

    $name = $is_manager ? $user['name'] : trim($_POST['name']);
    $address = trim($_POST['address'] ?? '');
    $email = ($is_super_admin || $is_company_admin) ? $user['email'] : trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $login_email_otp_status = (
        !$is_manager &&
        (($_POST['login_email_otp_status'] ?? 'inactive') === 'active')
    ) ? 'active' : 'inactive';

    if($address === ''){
        $address = 'None';
    }

    if($is_manager){
        if($email === ''){
            $email = 'None';
        }

        if($phone === ''){
            $phone = 'None';
        }
    }

    $can_update_profile = true;
    $duplicate_message = '';
    $should_check_contact_uniqueness = !$is_super_admin;

    if(
        $should_check_contact_uniqueness &&
        (
            ($duplicate_message = profile_user_contact_duplicate_message($conn, 'email', $email, $user_id)) !== '' ||
            ($duplicate_message = profile_user_contact_duplicate_message($conn, 'phone', $phone, $user_id)) !== ''
        )
    ){
        $message = $duplicate_message;
        $message_type = 'danger';
        $can_update_profile = false;
    }

    $avatar = $user['avatar'] ?? 'you2biz.png';

    if(
        $can_update_profile &&
        isset($_FILES['avatar']) &&
        $_FILES['avatar']['error']==0
    ){

        $ext = strtolower(
            pathinfo(
                $_FILES['avatar']['name'],
                PATHINFO_EXTENSION
            )
        );

        if(
            in_array(
                $ext,
                ['jpg','jpeg','png']
            )
        ){

            $avatar =
                time().'_'.$user_id.'.'.$ext;

            move_uploaded_file(
                $_FILES['avatar']['tmp_name'],
                '../uploads/avatars/'.$avatar
            );
        }
    }

    if($can_update_profile && $is_super_admin){

        $config_path = __DIR__ . '/../includes/super_admin_config.php';
        $config = file_get_contents($config_path);
        $config = update_super_admin_config_constant($config, 'SUPER_ADMIN_NAME', $name);
        $config = update_super_admin_config_constant($config, 'SUPER_ADMIN_PROFILE_ADDRESS', $address);
        $config = update_super_admin_config_constant($config, 'SUPER_ADMIN_PROFILE_PHONE', $phone);
        $config = update_super_admin_config_constant($config, 'SUPER_ADMIN_PROFILE_AVATAR', $avatar);
        $config = update_super_admin_config_constant($config, 'SUPER_ADMIN_LOGIN_EMAIL_OTP_STATUS', $login_email_otp_status);

        if(file_put_contents($config_path, $config, LOCK_EX) !== false){
            $_SESSION['login_name'] = $name;
            $_SESSION['user_name'] = $name;
            $_SESSION['avatar'] = $avatar;
            $_SESSION['login_avatar'] = $avatar;

            $message =
            "Profile updated successfully";
            $message_type = 'success';

            $user['name'] = $name;
            $user['address'] = $address;
            $user['email'] = $email;
            $user['phone'] = $phone;
            $user['avatar'] = $avatar;
            $user['login_email_otp_status'] = $login_email_otp_status;

            profile_redirect_with_flash('success', 'Profile updated successfully');
        }

    }elseif($can_update_profile && $is_manager){

        $sql = "UPDATE users
                SET
                    address=?,
                    email=?,
                    phone=?,
                    avatar=?
                WHERE id=?";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(
            $stmt,
            "ssssi",
            $address,
            $email,
            $phone,
            $avatar,
            $user_id
        );

    }elseif($can_update_profile){

        $sql = "UPDATE users
            SET
                name=?,
                address=?,
                email=?,
                phone=?,
                avatar=?,
                login_email_otp_status=?
            WHERE id=?";

        $stmt = mysqli_prepare($conn,$sql);

        mysqli_stmt_bind_param(
            $stmt,
            "ssssssi",
            $name,
            $address,
            $email,
            $phone,
            $avatar,
            $login_email_otp_status,
            $user_id
        );

    }

    if($can_update_profile && !$is_super_admin && mysqli_stmt_execute($stmt)){

        if($is_manager){
            $_SESSION['login_avatar'] = $avatar;
        }else{
            $_SESSION['login_name'] = $name;
            $_SESSION['user_name'] = $name;
            $_SESSION['avatar'] = $avatar;
        }

        $user['name'] = $name;
        $user['address'] = $address;
        $user['email'] = $email;
        $user['phone'] = $phone;
        $user['avatar'] = $avatar;
        $user['login_email_otp_status'] = $login_email_otp_status;

        profile_redirect_with_flash('success', 'Profile updated successfully');
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

$avatar_image =
                app_path('uploads/avatars/you2biz.png');

if(!empty($user['avatar'])){

    $avatar_image =
        app_path('uploads/avatars/') .
    $user['avatar'];
}

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">
            My Profile
        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-<?= htmlspecialchars($message_type); ?>">

                <?= $message; ?>

            </div>

        <?php } ?>

        <form
            method="post"
            enctype="multipart/form-data">

            <div class="row">

                <div class="col-md-3 text-center">

                    <img
                        src="<?= $avatar_image; ?>"
                        id="avatar_preview"
                        class="img-fluid img-thumbnail mb-3"
                        style="
                            width:200px;
                            height:200px;
                            object-fit:cover;
                        ">

                        <input
                            type="file"
                            name="avatar"
                            id="avatar_input"
                            accept="image/*"
                            class="form-control">

                </div>

                <div class="col-md-9">

                    <div class="form-group">

                        <label>Name</label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            value="<?= htmlspecialchars($user['name']); ?>"
                            <?= $is_manager ? 'readonly' : ''; ?>
                            required>

                    </div>

                    <div class="form-group">

                        <label>Address</label>

                        <input
                            type="text"
                            name="address"
                            class="form-control"
                            value="<?= htmlspecialchars($user['address'] ?? 'None'); ?>"
                            placeholder="None">

                    </div>

                    <div class="form-group">

                        <label>Email</label>

                        <input
                            type="<?= $is_manager ? 'text' : 'email'; ?>"
                            name="email"
                            class="form-control"
                            value="<?= htmlspecialchars($user['email']); ?>"
                            <?= ($is_super_admin || $is_company_admin) ? 'readonly' : ''; ?>
                            required>

                    </div>

                    <div class="form-group">

                        <label>Phone</label>

                        <input
                            type="text"
                            name="phone"
                            class="form-control"
                            value="<?= htmlspecialchars($user['phone']); ?>"
                            placeholder="None">

                    </div>

                    <?php if(!$is_manager){ ?>
                        <div class="form-group">
                            <label>Login Email OTP Verification</label>
                            <select
                                name="login_email_otp_status"
                                class="form-control">
                                <option value="inactive" <?= login_email_otp_status($user) === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="active" <?= login_email_otp_status($user) === 'active' ? 'selected' : ''; ?>>Active</option>
                            </select>
                            <small class="text-muted">By default this stays inactive. When active, admin login requires email OTP after password.</small>
                        </div>
                    <?php } ?>

                    <button
                        type="submit"
                        class="btn btn-primary">

                        <i class="fas fa-save"></i>

                        <?= $is_manager ? 'Update' : 'Update Profile'; ?>

                    </button>

                    <a
                        href="index.php"
                        class="btn btn-secondary ml-2">

                        Cancel

                    </a>

                </div>

            </div>

        </form>

    </div>

</div>

<?php
$page_script = '
<script>
$(function(){
    $("#avatar_input").on("change", function(){
        const file = this.files && this.files[0] ? this.files[0] : null;

        if(!file){
            return;
        }

        if(!file.type || !file.type.startsWith("image/")){
            return;
        }

        const reader = new FileReader();

        reader.onload = function(e){
            $("#avatar_preview").attr("src", e.target.result);
        };

        reader.readAsDataURL(file);
    });
});
</script>
';

require_once '../includes/footer.php';
?>
