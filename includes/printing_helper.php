<?php

require_once __DIR__ . '/app_config.php';

require_once __DIR__ . '/branding_helper.php';

function printing_ensure_column($conn)
{
    static $checked = false;
    static $available = false;

    if($checked){
        return $available;
    }

    $checked = true;

    $result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'printing_option'"
    );

    if(!$result || mysqli_num_rows($result) === 0){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD printing_option VARCHAR(20) NOT NULL DEFAULT 'general'"
        ) ? true : false;
    }else{
        $available = true;
    }

    $width_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'printing_custom_width'"
    );

    if($available && (!$width_result || mysqli_num_rows($width_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD printing_custom_width DECIMAL(8,2) NOT NULL DEFAULT 8.27"
        ) ? true : false;
    }

    $height_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'printing_custom_height'"
    );

    if($available && (!$height_result || mysqli_num_rows($height_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD printing_custom_height DECIMAL(8,2) NOT NULL DEFAULT 11.69"
        ) ? true : false;
    }

    $top_margin_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'printing_custom_top_margin'"
    );

    if($available && (!$top_margin_result || mysqli_num_rows($top_margin_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD printing_custom_top_margin DECIMAL(8,2) NOT NULL DEFAULT 0.50"
        ) ? true : false;
    }

    $notes_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'print_invoice_notes'"
    );

    if($available && (!$notes_result || mysqli_num_rows($notes_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD print_invoice_notes VARCHAR(20) NOT NULL DEFAULT 'active'"
        ) ? true : false;
    }

    $created_by_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'print_invoice_created_by'"
    );

    if($available && (!$created_by_result || mysqli_num_rows($created_by_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD print_invoice_created_by VARCHAR(20) NOT NULL DEFAULT 'active'"
        ) ? true : false;
    }

    $company_seal_file_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'company_seal_file'"
    );

    if($available && (!$company_seal_file_result || mysqli_num_rows($company_seal_file_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD company_seal_file VARCHAR(255) NOT NULL DEFAULT ''"
        ) ? true : false;
    }

    $paid_seal_file_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'paid_seal_file'"
    );

    if($available && (!$paid_seal_file_result || mysqli_num_rows($paid_seal_file_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD paid_seal_file VARCHAR(255) NOT NULL DEFAULT ''"
        ) ? true : false;
    }

    $print_company_seal_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'print_company_seal'"
    );

    if($available && (!$print_company_seal_result || mysqli_num_rows($print_company_seal_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD print_company_seal VARCHAR(20) NOT NULL DEFAULT 'inactive'"
        ) ? true : false;
    }

    $print_paid_seal_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'print_paid_seal'"
    );

    if($available && (!$print_paid_seal_result || mysqli_num_rows($print_paid_seal_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD print_paid_seal VARCHAR(20) NOT NULL DEFAULT 'inactive'"
        ) ? true : false;
    }

    $print_company_logo_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'print_company_logo'"
    );

    if($available && (!$print_company_logo_result || mysqli_num_rows($print_company_logo_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD print_company_logo VARCHAR(20) NOT NULL DEFAULT 'inactive'"
        ) ? true : false;
    }

    $print_company_profile_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'print_company_profile'"
    );

    if($available && (!$print_company_profile_result || mysqli_num_rows($print_company_profile_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD print_company_profile VARCHAR(20) NOT NULL DEFAULT 'active'"
        ) ? true : false;
    }

    $general_top_margin_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'printing_general_top_margin'"
    );

    if($available && (!$general_top_margin_result || mysqli_num_rows($general_top_margin_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD printing_general_top_margin DECIMAL(8,2) NOT NULL DEFAULT 0.50"
        ) ? true : false;
    }

    $print_general_top_margin_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM users LIKE 'print_general_top_margin'"
    );

    if($available && (!$print_general_top_margin_result || mysqli_num_rows($print_general_top_margin_result) === 0)){
        $available = mysqli_query(
            $conn,
            "ALTER TABLE users
             ADD print_general_top_margin VARCHAR(20) NOT NULL DEFAULT 'inactive'"
        ) ? true : false;
    }

    return $available;
}

function printing_upload_dir_path()
{
    return dirname(__DIR__) . '/uploads/printing';
}

function printing_upload_dir_url()
{
    return app_path('uploads/printing');
}

function printing_default_company_seal_filename()
{
    return 'default-company-seal.png';
}

function printing_default_paid_seal_filename()
{
    return 'default-paid-seal.png';
}

function printing_is_default_seal_filename($filename)
{
    return in_array(
        basename((string)$filename),
        [printing_default_company_seal_filename(), printing_default_paid_seal_filename()],
        true
    );
}

function ensure_printing_upload_dir()
{
    $dir = printing_upload_dir_path();

    if(!is_dir($dir)){
        return @mkdir($dir, 0777, true);
    }

    return is_writable($dir);
}

function printing_file_url($filename)
{
    $filename = basename((string)$filename);

    if($filename === ''){
        return '';
    }

    $path = printing_upload_dir_path() . '/' . $filename;

    if(!is_file($path)){
        return '';
    }

    return printing_upload_dir_url() . '/' . rawurlencode($filename);
}

function printing_upload_file($field_name, $current_filename, $prefix)
{
    if(
        !isset($_FILES[$field_name]) ||
        !is_array($_FILES[$field_name]) ||
        (int)($_FILES[$field_name]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ){
        return [true, basename((string)$current_filename), false, ''];
    }

    $upload = $_FILES[$field_name];
    $error_code = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

    if($error_code !== UPLOAD_ERR_OK){
        return [false, basename((string)$current_filename), false, 'Upload failed. Please try again.'];
    }

    $tmp_name = (string)($upload['tmp_name'] ?? '');

    if($tmp_name === '' || !is_uploaded_file($tmp_name)){
        return [false, basename((string)$current_filename), false, 'Uploaded file could not be verified.'];
    }

    $extension = strtolower((string)pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed_extensions = ['png', 'jpg', 'jpeg', 'webp'];

    if(!in_array($extension, $allowed_extensions, true)){
        return [false, basename((string)$current_filename), false, 'Please upload a PNG, JPG, JPEG, or WEBP image.'];
    }

    if(!ensure_printing_upload_dir()){
        return [false, basename((string)$current_filename), false, 'Printing upload directory is not writable.'];
    }

    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $filename = $prefix . '-' . $user_id . '-' . time() . '-' . mt_rand(1000, 9999) . '.' . $extension;
    $destination = printing_upload_dir_path() . '/' . $filename;

    if(!move_uploaded_file($tmp_name, $destination)){
        return [false, basename((string)$current_filename), false, 'Uploaded file could not be saved.'];
    }

    $old_filename = basename((string)$current_filename);
    $old_path = printing_upload_dir_path() . '/' . $old_filename;

    if($old_filename !== '' && !printing_is_default_seal_filename($old_filename) && $old_filename !== $filename && is_file($old_path)){
        @unlink($old_path);
    }

    return [true, $filename, true, ''];
}

function normalize_printing_option($option)
{
    if($option === 'pos'){
        return 'pos';
    }

    if($option === 'custom'){
        return 'custom';
    }

    return 'general';
}

function normalize_printing_custom_size($width, $height)
{
    $width = (float)$width;
    $height = (float)$height;

    if($width <= 0){
        $width = 8.27;
    }

    if($height <= 0){
        $height = 11.69;
    }

    return [
        'width' => round($width, 2),
        'height' => round($height, 2),
    ];
}

function normalize_printing_custom_top_margin($top_margin)
{
    $top_margin = (float)$top_margin;

    if($top_margin < 0){
        $top_margin = 0;
    }

    return round($top_margin, 2);
}

function normalize_printing_yes_no_option($option)
{
    return $option === 'inactive' ? 'inactive' : 'active';
}

function printing_default_system_key($column)
{
    $map = [
        'printing_option' => 'default_printing_option',
        'printing_custom_width' => 'default_printing_custom_width',
        'printing_custom_height' => 'default_printing_custom_height',
        'printing_custom_top_margin' => 'default_printing_custom_top_margin',
        'print_invoice_notes' => 'default_print_invoice_notes',
        'print_invoice_created_by' => 'default_print_invoice_created_by',
        'company_seal_file' => 'default_company_seal_file',
        'paid_seal_file' => 'default_paid_seal_file',
        'print_company_seal' => 'default_print_company_seal',
        'print_paid_seal' => 'default_print_paid_seal',
        'print_company_logo' => 'default_print_company_logo',
        'print_company_profile' => 'default_print_company_profile',
        'printing_general_top_margin' => 'default_printing_general_top_margin',
        'print_general_top_margin' => 'default_print_general_top_margin',
    ];

    return $map[$column] ?? '';
}

function printing_default_user_value($conn, $column, $default = '')
{
    $system_key = printing_default_system_key($column);

    if($system_key === ''){
        return $default;
    }

    return system_setting($conn, $system_key, $default);
}

function printing_default_settings_payload(
    $option,
    $custom_width,
    $custom_height,
    $custom_top_margin,
    $print_invoice_notes,
    $print_invoice_created_by,
    $company_seal_file,
    $paid_seal_file,
    $print_company_seal,
    $print_paid_seal,
    $print_company_logo,
    $print_company_profile,
    $general_top_margin,
    $print_general_top_margin
) {
    return [
        'default_printing_option' => $option,
        'default_printing_custom_width' => (string)$custom_width,
        'default_printing_custom_height' => (string)$custom_height,
        'default_printing_custom_top_margin' => (string)$custom_top_margin,
        'default_print_invoice_notes' => $print_invoice_notes,
        'default_print_invoice_created_by' => $print_invoice_created_by,
        'default_company_seal_file' => $company_seal_file,
        'default_paid_seal_file' => $paid_seal_file,
        'default_print_company_seal' => $print_company_seal,
        'default_print_paid_seal' => $print_paid_seal,
        'default_print_company_logo' => $print_company_logo,
        'default_print_company_profile' => $print_company_profile,
        'default_printing_general_top_margin' => (string)$general_top_margin,
        'default_print_general_top_margin' => $print_general_top_margin,
    ];
}

function current_printing_user_value($conn, $column, $default = '')
{
    if(is_super_admin_user()){
        return $_SESSION[$column] ?? printing_default_user_value($conn, $column, $default);
    }

    if(!printing_ensure_column($conn)){
        return $default;
    }

    $user_id = (int)($_SESSION['user_id'] ?? 0);

    if($user_id <= 0){
        return $default;
    }

    $allowed_columns = [
        'printing_option',
        'printing_custom_width',
        'printing_custom_height',
        'printing_custom_top_margin',
        'print_invoice_notes',
        'print_invoice_created_by',
        'company_seal_file',
        'paid_seal_file',
        'print_company_seal',
        'print_paid_seal',
        'print_company_logo',
        'print_company_profile',
        'printing_general_top_margin',
        'print_general_top_margin',
    ];

    if(!in_array($column, $allowed_columns, true)){
        return $default;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT {$column}
         FROM users
         WHERE id=?
         LIMIT 1"
    );

    if(!$stmt){
        return $default;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return $row[$column] ?? $default;
}

function current_printing_option($conn)
{
    return normalize_printing_option(
        current_printing_user_value($conn, 'printing_option', 'general')
    );
}

function is_pos_printing($conn)
{
    return current_printing_option($conn) === 'pos';
}

function is_custom_printing($conn)
{
    return current_printing_option($conn) === 'custom';
}

function current_printing_custom_size($conn)
{
    return normalize_printing_custom_size(
        current_printing_user_value($conn, 'printing_custom_width', 8.27),
        current_printing_user_value($conn, 'printing_custom_height', 11.69)
    );
}

function current_printing_custom_top_margin($conn)
{
    return normalize_printing_custom_top_margin(
        current_printing_user_value($conn, 'printing_custom_top_margin', 0.50)
    );
}

function current_printing_general_top_margin($conn)
{
    return normalize_printing_custom_top_margin(
        current_printing_user_value($conn, 'printing_general_top_margin', 0.50)
    );
}

function current_print_invoice_notes_option($conn)
{
    return normalize_printing_yes_no_option(
        current_printing_user_value($conn, 'print_invoice_notes', 'active')
    );
}

function current_print_invoice_created_by_option($conn)
{
    return normalize_printing_yes_no_option(
        current_printing_user_value($conn, 'print_invoice_created_by', 'active')
    );
}

function should_print_invoice_notes($conn)
{
    return current_print_invoice_notes_option($conn) === 'active';
}

function should_print_invoice_created_by($conn)
{
    return current_print_invoice_created_by_option($conn) === 'active';
}

function current_company_seal_file($conn)
{
    $filename = basename((string)current_printing_user_value(
        $conn,
        'company_seal_file',
        printing_default_company_seal_filename()
    ));

    if($filename === '' || !is_file(printing_upload_dir_path() . '/' . $filename)){
        return printing_default_company_seal_filename();
    }

    return $filename;
}

function current_paid_seal_file($conn)
{
    $filename = basename((string)current_printing_user_value(
        $conn,
        'paid_seal_file',
        printing_default_paid_seal_filename()
    ));

    if($filename === '' || !is_file(printing_upload_dir_path() . '/' . $filename)){
        return printing_default_paid_seal_filename();
    }

    return $filename;
}

function current_company_seal_url($conn)
{
    return printing_file_url(current_company_seal_file($conn));
}

function current_paid_seal_url($conn)
{
    return printing_file_url(current_paid_seal_file($conn));
}

function current_print_company_seal_option($conn)
{
    return normalize_printing_yes_no_option(
        current_printing_user_value($conn, 'print_company_seal', 'inactive')
    );
}

function current_print_paid_seal_option($conn)
{
    return normalize_printing_yes_no_option(
        current_printing_user_value($conn, 'print_paid_seal', 'inactive')
    );
}

function should_print_company_seal($conn)
{
    return current_print_company_seal_option($conn) === 'active';
}

function should_print_paid_seal($conn)
{
    return current_print_paid_seal_option($conn) === 'active';
}

function current_print_company_logo_option($conn)
{
    return normalize_printing_yes_no_option(
        current_printing_user_value($conn, 'print_company_logo', 'inactive')
    );
}

function should_print_company_logo($conn)
{
    return current_print_company_logo_option($conn) === 'active';
}

function printing_default_company_logo_url()
{
    return printing_file_url('default-company-logo.png');
}

function current_print_general_top_margin_option($conn)
{
    return normalize_printing_yes_no_option(
        current_printing_user_value($conn, 'print_general_top_margin', 'inactive')
    );
}

function should_use_general_top_margin($conn)
{
    return current_print_general_top_margin_option($conn) === 'active';
}

function current_print_company_profile_option($conn)
{
    return normalize_printing_yes_no_option(
        current_printing_user_value($conn, 'print_company_profile', 'active')
    );
}

function should_print_company_profile($conn)
{
    return current_print_company_profile_option($conn) === 'active';
}

function current_print_company_logo_url($conn)
{
    $logo_url = branding_logo_url($conn);
    $default_logo_url = branding_default_logo_url();
    $company_default_avatar = basename((string)branding_company_default_avatar_filename($conn));
    $login_user_id = (int)($_SESSION['login_user_id'] ?? $_SESSION['user_id'] ?? 0);
    $login_avatar = '';

    if($login_user_id > 0 && !is_super_admin_user()){
        $avatar_stmt = mysqli_prepare(
            $conn,
            "SELECT avatar
             FROM users
             WHERE id=?
             LIMIT 1"
        );

        if($avatar_stmt){
            mysqli_stmt_bind_param($avatar_stmt, "i", $login_user_id);
            mysqli_stmt_execute($avatar_stmt);
            $avatar_row = mysqli_fetch_assoc(mysqli_stmt_get_result($avatar_stmt));
            $login_avatar = basename(trim((string)($avatar_row['avatar'] ?? '')));
        }
    }elseif(is_super_admin_user()){
        $login_avatar = basename(trim((string)(defined('SUPER_ADMIN_PROFILE_AVATAR') ? SUPER_ADMIN_PROFILE_AVATAR : '')));
    }

    $use_custom_avatar_logo = $login_avatar !== '' &&
        $login_avatar !== 'default-avatar.png' &&
        $login_avatar !== $company_default_avatar;

    if($use_custom_avatar_logo){
        $logo_path = dirname(__DIR__) . '/uploads/avatars/' . $login_avatar;
        $logo_url = app_path('uploads/avatars/' . rawurlencode($login_avatar));
    }else{
        // Every company starts with the shared You2 Biz print logo. Once the
        // company changes its profile photo, the uploaded profile image is used.
        $default_company_logo_url = printing_default_company_logo_url();
        if($default_company_logo_url !== ''){
            return $default_company_logo_url;
        }

        if(trim((string)$logo_url) === ''){
            return '';
        }

        if($logo_url === $default_logo_url){
        $logo_path = dirname(__DIR__) . '/assets/you2biz-logo.png';
        }else{
            $site_logo_file = trim((string)system_setting($conn, 'site_logo_file', ''));

            if($site_logo_file === ''){
                return $logo_url;
            }

            $logo_path = branding_upload_dir_path() . '/' . basename($site_logo_file);
        }
    }

    if(!is_file($logo_path)){
        return $logo_url;
    }

    $extension = strtolower((string)pathinfo($logo_path, PATHINFO_EXTENSION));

    if(!in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)){
        return $logo_url;
    }

    if(!ensure_printing_upload_dir()){
        return $logo_url;
    }

    $transparent_filename = 'print-logo-' . md5($logo_path . '|' . (string)@filemtime($logo_path)) . '.png';
    $transparent_path = printing_upload_dir_path() . '/' . $transparent_filename;
    $source_mtime = (int)@filemtime($logo_path);
    $target_mtime = is_file($transparent_path) ? (int)@filemtime($transparent_path) : 0;

    if(!is_file($transparent_path) || $source_mtime > $target_mtime){
        if(!branding_remove_background_from_favicon($logo_path, $transparent_path, $extension)){
            return $logo_url;
        }
    }

    if(!is_file($transparent_path)){
        return $logo_url;
    }

    return printing_upload_dir_url() . '/' . rawurlencode($transparent_filename);
}

function printing_company_profile_data($conn)
{
    $default_profile = [
        'name' => trim((string)($_SESSION['user_name'] ?? 'Company')),
        'address' => 'None',
        'email' => 'None',
        'phone' => 'None',
    ];

    if(is_super_admin_user()){
        return [
            'name' => trim((string)(defined('SUPER_ADMIN_NAME') ? SUPER_ADMIN_NAME : ($default_profile['name'] ?: 'Super Admin'))),
            'address' => trim((string)(defined('SUPER_ADMIN_PROFILE_ADDRESS') ? SUPER_ADMIN_PROFILE_ADDRESS : 'None')),
            'email' => trim((string)(function_exists('super_admin_notify_email') ? super_admin_notify_email() : 'None')),
            'phone' => trim((string)(defined('SUPER_ADMIN_PROFILE_PHONE') ? SUPER_ADMIN_PROFILE_PHONE : 'None')),
        ];
    }

    $login_user_id = (int)($_SESSION['login_user_id'] ?? $_SESSION['user_id'] ?? 0);
    $target_user_id = $login_user_id;

    if($login_user_id > 0){
        $owner_stmt = mysqli_prepare(
            $conn,
            "SELECT
                CASE
                    WHEN owner_id IS NULL OR owner_id = 0 THEN id
                    ELSE owner_id
                END AS company_id
             FROM users
             WHERE id=?
             LIMIT 1"
        );

        if($owner_stmt){
            mysqli_stmt_bind_param($owner_stmt, "i", $login_user_id);
            mysqli_stmt_execute($owner_stmt);
            $owner_row = mysqli_fetch_assoc(mysqli_stmt_get_result($owner_stmt));

            if((int)($owner_row['company_id'] ?? 0) > 0){
                $target_user_id = (int)$owner_row['company_id'];
            }
        }
    }

    if($target_user_id <= 0){
        $target_user_id = (int)($_SESSION['user_id'] ?? 0);
    }

    if($target_user_id <= 0){
        return $default_profile;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT name, address, email, phone
         FROM users
         WHERE id=?
         LIMIT 1"
    );

    if(!$stmt){
        return $default_profile;
    }

    mysqli_stmt_bind_param($stmt, "i", $target_user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if(!$row){
        return $default_profile;
    }

    $profile = [
        'name' => trim((string)($row['name'] ?? '')),
        'address' => trim((string)($row['address'] ?? '')),
        'email' => trim((string)($row['email'] ?? '')),
        'phone' => trim((string)($row['phone'] ?? '')),
    ];

    foreach($profile as $key => $value){
        if($value === ''){
            $profile[$key] = 'None';
        }
    }

    if(
        preg_match('/\.manager\.\d+@you2-wallet\.local$/', (string)$profile['email']) ||
        strcasecmp($profile['email'], 'none') === 0
    ){
        $profile['email'] = 'None';
    }

    if(
        str_starts_with((string)$profile['phone'], 'MGR') ||
        strcasecmp($profile['phone'], 'none') === 0
    ){
        $profile['phone'] = 'None';
    }

    if($profile['name'] === ''){
        $profile['name'] = $default_profile['name'];
    }

    return $profile;
}

function save_printing_option(
    $conn,
    $option,
    $custom_width = 8.27,
    $custom_height = 11.69,
    $custom_top_margin = 0.50,
    $print_invoice_notes = 'active',
    $print_invoice_created_by = 'active',
    $company_seal_file = '',
    $paid_seal_file = '',
    $print_company_seal = 'inactive',
    $print_paid_seal = 'inactive',
    $print_company_logo = 'inactive',
    $print_company_profile = 'active',
    $general_top_margin = 0.50,
    $print_general_top_margin = 'inactive'
) 
{
    $option = normalize_printing_option($option);
    $custom_size = normalize_printing_custom_size($custom_width, $custom_height);
    $custom_top_margin = normalize_printing_custom_top_margin($custom_top_margin);
    $print_invoice_notes = normalize_printing_yes_no_option($print_invoice_notes);
    $print_invoice_created_by = normalize_printing_yes_no_option($print_invoice_created_by);
    $company_seal_file = basename((string)$company_seal_file);
    $paid_seal_file = basename((string)$paid_seal_file);
    $print_company_seal = normalize_printing_yes_no_option($print_company_seal);
    $print_paid_seal = normalize_printing_yes_no_option($print_paid_seal);
    $print_company_logo = normalize_printing_yes_no_option($print_company_logo);
    $print_company_profile = normalize_printing_yes_no_option($print_company_profile);
    $general_top_margin = normalize_printing_custom_top_margin($general_top_margin);
    $print_general_top_margin = normalize_printing_yes_no_option($print_general_top_margin);

    if(is_super_admin_user()){
        $_SESSION['printing_option'] = $option;
        $_SESSION['printing_custom_width'] = $custom_size['width'];
        $_SESSION['printing_custom_height'] = $custom_size['height'];
        $_SESSION['printing_custom_top_margin'] = $custom_top_margin;
        $_SESSION['print_invoice_notes'] = $print_invoice_notes;
        $_SESSION['print_invoice_created_by'] = $print_invoice_created_by;
        $_SESSION['company_seal_file'] = $company_seal_file;
        $_SESSION['paid_seal_file'] = $paid_seal_file;
        $_SESSION['print_company_seal'] = $print_company_seal;
        $_SESSION['print_paid_seal'] = $print_paid_seal;
        $_SESSION['print_company_logo'] = $print_company_logo;
        $_SESSION['print_company_profile'] = $print_company_profile;
        $_SESSION['printing_general_top_margin'] = $general_top_margin;
        $_SESSION['print_general_top_margin'] = $print_general_top_margin;

        return system_settings_save_many(
            $conn,
            printing_default_settings_payload(
                $option,
                $custom_size['width'],
                $custom_size['height'],
                $custom_top_margin,
                $print_invoice_notes,
                $print_invoice_created_by,
                $company_seal_file,
                $paid_seal_file,
                $print_company_seal,
                $print_paid_seal,
                $print_company_logo,
                $print_company_profile,
                $general_top_margin,
                $print_general_top_margin
            )
        );
    }

    if(!printing_ensure_column($conn)){
        return false;
    }

    $user_id = (int)($_SESSION['user_id'] ?? 0);

    if($user_id <= 0){
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET printing_option=?,
             printing_custom_width=?,
             printing_custom_height=?,
             printing_custom_top_margin=?,
             print_invoice_notes=?,
             print_invoice_created_by=?,
             company_seal_file=?,
             paid_seal_file=?,
             print_company_seal=?,
             print_paid_seal=?,
             print_company_logo=?,
             print_company_profile=?,
             printing_general_top_margin=?,
             print_general_top_margin=?
         WHERE id=?"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sdddssssssssdsi",
        $option,
        $custom_size['width'],
        $custom_size['height'],
        $custom_top_margin,
        $print_invoice_notes,
        $print_invoice_created_by,
        $company_seal_file,
        $paid_seal_file,
        $print_company_seal,
        $print_paid_seal,
        $print_company_logo,
        $print_company_profile,
        $general_top_margin,
        $print_general_top_margin,
        $user_id
    );

    return mysqli_stmt_execute($stmt);
}
