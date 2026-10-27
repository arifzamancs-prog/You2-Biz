<?php

require_once __DIR__ . '/branding_helper.php';
require_once __DIR__ . '/company_settings_helper.php';

// Product photos are optional for every company type, Inventory & Sales included.
function product_photos_enabled($conn, $user_id)
{
    return true;
}

function ensure_product_image_column($conn)
{
    $column = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'photo_path'");
    if($column && mysqli_num_rows($column) === 0){
        mysqli_query($conn, "ALTER TABLE products ADD COLUMN photo_path VARCHAR(255) NULL AFTER sku");
    }
}

function product_image_upload_dir()
{
    return dirname(__DIR__) . '/uploads/products';
}

function product_image_url($conn, $photo_path = '')
{
    $photo_path = trim((string)$photo_path);
    $path = product_image_upload_dir() . '/' . basename($photo_path);
    if($photo_path !== '' && is_file($path)){
        return app_path('uploads/products/' . rawurlencode(basename($photo_path)));
    }
    return branding_logo_url($conn);
}

function product_save_compressed_photo($upload, &$error = '')
{
    if(empty($upload['tmp_name']) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK){ $error = 'Product photo upload failed.'; return ''; }
    $restaurant_photo_rules = (($_SESSION['company_type'] ?? '') === 'Restaurant & Cafe');
    $image = @getimagesize($upload['tmp_name']);
    if(!$image || !in_array($image[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)){ $error = 'Use a JPG, PNG, or WEBP product photo.'; return ''; }
    $directory = product_image_upload_dir();
    if(!is_dir($directory) && !@mkdir($directory, 0775, true)){ $error = 'Product photo upload folder could not be created.'; return ''; }
    $source_extension = $image[2] === IMAGETYPE_PNG ? 'png' : ($image[2] === IMAGETYPE_WEBP ? 'webp' : 'jpg');
    // Restaurant uploads retain their valid original format if GD is unavailable.
    // All existing non-restaurant upload behavior remains JPEG-only.
    $extension = function_exists('imagecreatetruecolor') || !$restaurant_photo_rules ? 'jpg' : $source_extension;
    $filename_extension = (int)($upload['size'] ?? 0) <= 40 * 1024
        ? $source_extension
        : $extension;
    $filename = 'product-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $filename_extension;
    $target = $directory . '/' . $filename;

    // Product photos at or below 40 KB retain their exact uploaded file.
    // Larger valid images are optimized below.
    if((int)($upload['size'] ?? 0) <= 40 * 1024){
        if(!move_uploaded_file($upload['tmp_name'], $target)){ $error = 'Product photo could not be saved.'; return ''; }
        return $filename;
    }

    if(!function_exists('imagecreatetruecolor')){
        if(!move_uploaded_file($upload['tmp_name'], $target)){ $error = 'Product photo could not be saved.'; return ''; }
        return $filename;
    }
    $source = $image[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($upload['tmp_name']) : ($image[2] === IMAGETYPE_PNG ? @imagecreatefrompng($upload['tmp_name']) : @imagecreatefromwebp($upload['tmp_name']));
    if(!$source){ $error = 'Product photo could not be processed.'; return ''; }
    $source_width = imagesx($source); $source_height = imagesy($source); $saved = false;
    // First aim for 50 KB, but never shrink a product photo below 350 px.
    // Highly detailed food photos cannot remain clear at 40–50 KB otherwise.
    $max_file_size = 50 * 1024;
    $quality_steps = [100, 95, 92, 90, 88, 85, 82, 78, 74, 70];
    $dimension_steps = [1600, 1200, 900, 700, 500];
    foreach($dimension_steps as $max_dimension){
        $scale = min(1, $max_dimension / max($source_width, $source_height));
        $width = max(1, (int)round($source_width * $scale)); $height = max(1, (int)round($source_height * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $source_width, $source_height);
        foreach($quality_steps as $quality){
            imagejpeg($canvas, $target, $quality);
            if(filesize($target) <= $max_file_size){ $saved = true; break 2; }
        }
        imagedestroy($canvas);
    }
    if(!$saved){
        // Protect visual quality if 50 KB is not realistic. Keep at least
        // 350 px and use the best JPEG quality that stays within 120 KB.
        $scale = min(1, 350 / max($source_width, $source_height));
        $width = max(1, (int)round($source_width * $scale));
        $height = max(1, (int)round($source_height * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $source_width, $source_height);
        foreach([95, 92, 90, 88, 85, 82, 80, 78, 75, 72, 70] as $quality){
            imagejpeg($canvas, $target, $quality);
            if(filesize($target) <= 120 * 1024){ $saved = true; break; }
        }
        imagedestroy($canvas);
        if(!$saved && is_file($target) && (int)filesize($target) > 0){
            // Keep the final 350 px result instead of creating a blurry thumbnail.
            $saved = true;
        }
        if(!$saved){
            imagedestroy($source);
            $error = 'Product photo could not be saved.';
            return '';
        }
    }
    imagedestroy($source);
    return $filename;
}

function product_delete_photo_file($filename)
{
    $filename = basename(trim((string)$filename));
    if($filename !== '') @unlink(product_image_upload_dir() . '/' . $filename);
}
