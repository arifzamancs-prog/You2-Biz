<?php

require_once __DIR__ . '/system_settings_helper.php';
require_once __DIR__ . '/app_config.php';

function branding_upload_dir_path()
{
    return dirname(__DIR__) . '/uploads/branding';
}

function branding_upload_dir_url()
{
    return app_path('uploads/branding');
}

function branding_default_logo_url()
{
    return app_path('assets/you2biz-logo.png');
}

function branding_default_favicon_url()
{
    return app_path('assets/you2biz-favicon.png');
}

function ensure_branding_upload_dir()
{
    $dir = branding_upload_dir_path();

    if(!is_dir($dir)){
        @mkdir($dir, 0777, true);
    }

    return is_dir($dir);
}

function branding_file_url($filename, $default_url)
{
    $filename = trim((string)$filename);

    if($filename === ''){
        return $default_url;
    }

    $path = branding_upload_dir_path() . '/' . basename($filename);

    if(!file_exists($path)){
        return $default_url;
    }

    return branding_upload_dir_url() . '/' . rawurlencode(basename($filename));
}

function branding_logo_url($conn = null)
{
    $filename = '';

    if($conn instanceof mysqli){
        $filename = system_setting($conn, 'site_logo_file', '');
    }

    return branding_file_url($filename, branding_default_logo_url());
}

function branding_favicon_url($conn = null)
{
    $filename = '';

    if($conn instanceof mysqli){
        $filename = system_setting($conn, 'site_favicon_file', '');
    }

    return branding_file_url($filename, branding_default_favicon_url());
}

function branding_has_custom_logo($conn = null)
{
    // The standard You2 Biz logo should be shown before a replacement is uploaded.
    return branding_logo_url($conn) !== '';
}

function branding_has_custom_favicon($conn = null)
{
    return branding_favicon_url($conn) !== branding_default_favicon_url();
}

function branding_avatar_upload_dir_path()
{
    return dirname(__DIR__) . '/uploads/avatars';
}

function branding_company_default_avatar_filename($conn = null)
{
    // New companies use the brand logo until the profile image is changed.
    $default = 'you2biz.png';

    if(!($conn instanceof mysqli)){
        return $default;
    }

    $filename = trim((string)system_setting($conn, 'company_default_avatar_file', $default));

    // Migrate the previous generic avatar setting so existing installations
    // also give newly created companies the You2 Biz logo.
    if($filename === '' || $filename === 'default-avatar.png'){
        $filename = $default;
        system_setting_save($conn, 'company_default_avatar_file', $filename);
    }

    $path = branding_avatar_upload_dir_path() . '/' . basename($filename);

    if(
        ($filename === '' || !file_exists($path)) &&
        trim((string)system_setting($conn, 'site_logo_file', '')) !== ''
    ){
        [$synced, $synced_filename] = branding_sync_company_default_avatar($conn);

        if($synced){
            $filename = $synced_filename;
            $path = branding_avatar_upload_dir_path() . '/' . basename($filename);
        }
    }

    if($filename === '' || !file_exists($path)){
        return $default;
    }

    return basename($filename);
}

function branding_sync_company_default_avatar($conn)
{
    $source_file = trim((string)system_setting($conn, 'site_logo_file', ''));

    if($source_file === ''){
        system_setting_save($conn, 'company_default_avatar_file', 'you2biz.png');
        return [true, 'you2biz.png'];
    }

    $source_path = branding_upload_dir_path() . '/' . basename($source_file);

    if(!file_exists($source_path)){
        system_setting_save($conn, 'company_default_avatar_file', 'you2biz.png');
        return [true, 'you2biz.png'];
    }

    $extension = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'webp'];

    if(!in_array($extension, $allowed, true)){
        return [false, 'you2biz.png'];
    }

    $avatar_dir = branding_avatar_upload_dir_path();

    if(!is_dir($avatar_dir)){
        @mkdir($avatar_dir, 0777, true);
    }

    if(!is_dir($avatar_dir)){
        return [false, 'you2biz.png'];
    }

    $new_filename = 'company-default-avatar.' . $extension;
    $destination = $avatar_dir . '/' . $new_filename;

    foreach($allowed as $old_extension){
        $old_file = $avatar_dir . '/company-default-avatar.' . $old_extension;

        if($old_extension !== $extension && file_exists($old_file)){
            @unlink($old_file);
        }
    }

    if(!@copy($source_path, $destination)){
        return [false, 'you2biz.png'];
    }

    system_setting_save($conn, 'company_default_avatar_file', $new_filename);

    return [true, $new_filename];
}

function branding_apply_company_default_avatar_to_admins($conn, $avatar_filename, $previous_default_avatar = 'you2biz.png')
{
    $avatar_filename = trim((string)$avatar_filename);
    $previous_default_avatar = basename(trim((string)$previous_default_avatar));

    if($avatar_filename === ''){
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET avatar=?
         WHERE role='admin'
         AND (
             avatar IS NULL
             OR avatar=''
             OR avatar='default-avatar.png'
             OR avatar=?
         )"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $avatar_filename, $previous_default_avatar);

    return mysqli_stmt_execute($stmt);
}

function branding_apply_company_default_avatar_to_super_admin($avatar_filename, $previous_default_avatar = 'you2biz.png')
{
    $avatar_filename = basename(trim((string)$avatar_filename));

    if($avatar_filename === ''){
        return false;
    }

    $config_path = __DIR__ . '/super_admin_config.php';

    if(!file_exists($config_path)){
        return false;
    }

    $config = file_get_contents($config_path);

    if($config === false){
        return false;
    }

    $line = "const SUPER_ADMIN_PROFILE_AVATAR = " . var_export($avatar_filename, true) . ";";
    $pattern = "/const SUPER_ADMIN_PROFILE_AVATAR = .*?;/";

    if(preg_match($pattern, $config)){
        $updated = preg_replace($pattern, $line, $config, 1);
    }else{
        $updated = preg_replace(
            "/(<\?php\s*)/",
            "$1\r\n" . $line . "\r\n",
            $config,
            1
        );
    }

    if(!is_string($updated)){
        return false;
    }

    return file_put_contents($config_path, $updated, LOCK_EX) !== false;
}

function branding_create_image_resource($path, $extension)
{
    $extension = strtolower((string)$extension);

    if($extension === 'png' && function_exists('imagecreatefrompng')){
        return @imagecreatefrompng($path);
    }

    if(($extension === 'jpg' || $extension === 'jpeg') && function_exists('imagecreatefromjpeg')){
        return @imagecreatefromjpeg($path);
    }

    if($extension === 'webp' && function_exists('imagecreatefromwebp')){
        return @imagecreatefromwebp($path);
    }

    return false;
}

function branding_remove_background_from_favicon($source_path, $destination_path, $extension)
{
    if(!function_exists('imagecreatetruecolor') || !function_exists('imagecolorallocatealpha')){
        return false;
    }

    $source = branding_create_image_resource($source_path, $extension);

    if(!$source){
        return false;
    }

    $width = imagesx($source);
    $height = imagesy($source);

    if($width <= 0 || $height <= 0){
        imagedestroy($source);
        return false;
    }

    $canvas = imagecreatetruecolor($width, $height);

    if(!$canvas){
        imagedestroy($source);
        return false;
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

    $sample_points = [
        [0, 0],
        [$width - 1, 0],
        [0, $height - 1],
        [$width - 1, $height - 1],
    ];

    $red_total = 0;
    $green_total = 0;
    $blue_total = 0;

    foreach($sample_points as $point){
        $rgb = imagecolorat($canvas, $point[0], $point[1]);
        $red_total += ($rgb >> 16) & 0xFF;
        $green_total += ($rgb >> 8) & 0xFF;
        $blue_total += $rgb & 0xFF;
    }

    $sample_count = count($sample_points);
    $target_red = (int)round($red_total / $sample_count);
    $target_green = (int)round($green_total / $sample_count);
    $target_blue = (int)round($blue_total / $sample_count);
    $threshold = 55;

    for($x = 0; $x < $width; $x++){
        for($y = 0; $y < $height; $y++){
            $rgb = imagecolorat($canvas, $x, $y);
            $red = ($rgb >> 16) & 0xFF;
            $green = ($rgb >> 8) & 0xFF;
            $blue = $rgb & 0xFF;
            $diff = abs($red - $target_red) + abs($green - $target_green) + abs($blue - $target_blue);

            if($diff <= $threshold){
                imagesetpixel($canvas, $x, $y, $transparent);
            }
        }
    }

    $saved = function_exists('imagepng') ? @imagepng($canvas, $destination_path) : false;

    imagedestroy($canvas);
    imagedestroy($source);

    return $saved;
}

function branding_upload_file($conn, $field_name, $setting_key, $prefix, $allow_ico = false)
{
    if(
        !isset($_FILES[$field_name]) ||
        !is_array($_FILES[$field_name]) ||
        (int)($_FILES[$field_name]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ){
        return [true, system_setting($conn, $setting_key, ''), false, ''];
    }

    $file = $_FILES[$field_name];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if($error !== UPLOAD_ERR_OK){
        return [false, '', false, 'Upload failed.'];
    }

    $tmp_name = (string)($file['tmp_name'] ?? '');
    $original_name = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $allowed = $allow_ico
        ? ['png', 'jpg', 'jpeg', 'webp', 'ico']
        : ['png', 'jpg', 'jpeg', 'webp'];

    if(!in_array($extension, $allowed, true)){
        return [false, '', false, 'Invalid file type. Allowed: ' . strtoupper(implode(', ', $allowed)) . '.'];
    }

    if(($file['size'] ?? 0) > (2 * 1024 * 1024)){
        return [false, '', false, 'File size must be 2MB or smaller.'];
    }

    if($extension !== 'ico'){
        $image_info = @getimagesize($tmp_name);

        if($image_info === false){
            return [false, '', false, 'Uploaded file is not a valid image.'];
        }
    }

    if(!ensure_branding_upload_dir()){
        return [false, '', false, 'Branding upload directory is not writable.'];
    }

    try {
        $unique = bin2hex(random_bytes(6));
    } catch (Exception $exception) {
        $unique = (string)mt_rand(100000, 999999);
    }

    $save_as_png = $allow_ico && $extension !== 'ico';
    $final_extension = $save_as_png ? 'png' : $extension;
    $filename = $prefix . '-' . date('YmdHis') . '-' . $unique . '.' . $final_extension;
    $destination = branding_upload_dir_path() . '/' . $filename;

    if($save_as_png){
        if(!branding_remove_background_from_favicon($tmp_name, $destination, $extension)){
            return [false, '', false, 'Favicon background could not be processed. Please upload a PNG, JPG, or WEBP image.'];
        }
    }elseif(!move_uploaded_file($tmp_name, $destination)){
        return [false, '', false, 'Uploaded file could not be saved.'];
    }

    $old_file = trim((string)system_setting($conn, $setting_key, ''));
    $old_path = branding_upload_dir_path() . '/' . basename($old_file);

    if($old_file !== '' && $old_file !== $filename && file_exists($old_path)){
        @unlink($old_path);
    }

    return [true, $filename, true, ''];
}
