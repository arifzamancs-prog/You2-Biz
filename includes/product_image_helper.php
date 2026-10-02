<?php

require_once __DIR__ . '/branding_helper.php';

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
    $image = @getimagesize($upload['tmp_name']);
    if(!$image || !in_array($image[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)){ $error = 'Use a JPG, PNG, or WEBP product photo.'; return ''; }
    $directory = product_image_upload_dir();
    if(!is_dir($directory) && !@mkdir($directory, 0775, true)){ $error = 'Product photo upload folder could not be created.'; return ''; }
    $filename = 'product-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.jpg';
    $target = $directory . '/' . $filename;
    if(!function_exists('imagecreatetruecolor')){
        if((int)filesize($upload['tmp_name']) > 50 * 1024){ $error = 'Photo must be 50 KB or smaller.'; return ''; }
        if(!move_uploaded_file($upload['tmp_name'], $target)){ $error = 'Product photo could not be saved.'; return ''; }
        return $filename;
    }
    $source = $image[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($upload['tmp_name']) : ($image[2] === IMAGETYPE_PNG ? @imagecreatefrompng($upload['tmp_name']) : @imagecreatefromwebp($upload['tmp_name']));
    if(!$source){ $error = 'Product photo could not be processed.'; return ''; }
    $source_width = imagesx($source); $source_height = imagesy($source); $saved = false;
    // Keep trying smaller dimensions/quality for detailed photos. A product
    // image is only a thumbnail in the application, so preserving the 50 KB
    // limit is preferable to rejecting an otherwise valid upload.
    foreach([1200, 900, 700, 500, 350, 250, 180, 128, 96] as $max_dimension){
        $scale = min(1, $max_dimension / max($source_width, $source_height));
        $width = max(1, (int)round($source_width * $scale)); $height = max(1, (int)round($source_height * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $source_width, $source_height);
        foreach([82, 68, 54, 40, 28, 18, 10] as $quality){
            imagejpeg($canvas, $target, $quality);
            if(filesize($target) <= 50 * 1024){ $saved = true; break 2; }
        }
        imagedestroy($canvas);
    }
    imagedestroy($source);
    if(!$saved){
        // A photo is optional and must never prevent product creation. The
        // final pass is already a very small, low-quality thumbnail; retain
        // it when an unusually detailed image cannot reach the 50 KB target.
        if(is_file($target) && (int)filesize($target) > 0){
            return $filename;
        }
        $error = 'Product photo could not be saved.';
        return '';
    }
    return $filename;
}

function product_delete_photo_file($filename)
{
    $filename = basename(trim((string)$filename));
    if($filename !== '') @unlink(product_image_upload_dir() . '/' . $filename);
}
