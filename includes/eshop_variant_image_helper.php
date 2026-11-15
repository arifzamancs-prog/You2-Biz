<?php
// E-shop variant photos: a company with an active e-shop can give each variant of a
// product its own photo (Products create/edit and Purchase receive). The photos are
// shown only on the e-shop product page, when the customer picks that variant.

require_once __DIR__ . '/eshop_helper.php';
require_once __DIR__ . '/product_image_helper.php';
require_once __DIR__ . '/product_category_helper.php';

function eshop_variant_images_enabled($conn, $company_id)
{
    static $cache = [];
    $company_id = (int)$company_id;
    if (!isset($cache[$company_id])) {
        $settings = $company_id > 0 ? eshop_company_settings($conn, $company_id) : null;
        $cache[$company_id] = $settings && (int)$settings['enabled'] === 1;
    }
    return $cache[$company_id];
}

function eshop_variant_image_schema($conn)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS eshop_variant_images (
        company_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        variant_name VARCHAR(100) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (company_id, product_id, variant_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// [variant_name => file_name] of one product.
function eshop_variant_images_map($conn, $company_id, $product_id)
{
    eshop_variant_image_schema($conn);
    $map = [];
    $stmt = mysqli_prepare($conn, 'SELECT variant_name, file_name FROM eshop_variant_images WHERE company_id=? AND product_id=?');
    mysqli_stmt_bind_param($stmt, 'ii', $company_id, $product_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $map[$row['variant_name']] = $row['file_name'];
    }
    return $map;
}

// [variant_name => public URL] for the storefront and the forms.
function eshop_variant_image_urls($conn, $company_id, $product_id)
{
    $urls = [];
    foreach (eshop_variant_images_map($conn, $company_id, $product_id) as $variant => $file) {
        $file = basename((string)$file);
        if ($file !== '' && is_file(product_image_upload_dir() . '/' . $file)) {
            $urls[$variant] = app_path('uploads/products/' . rawurlencode($file));
        }
    }
    return $urls;
}

/**
 * Saves the photos posted for one product: $files is the per-variant slice of $_FILES
 * (['name' => [variant => …], 'tmp_name' => …, …]); $remove lists variants whose photo
 * is removed. Only the product's own variants are accepted. Returns an error text or ''.
 * Call after the product transaction has been committed.
 */
function eshop_variant_images_save($conn, $company_id, $product_id, array $files, array $remove = [])
{
    if (!eshop_variant_images_enabled($conn, $company_id) || $product_id <= 0) {
        return '';
    }
    eshop_variant_image_schema($conn);
    $variants = function_exists('product_variant_names') ? product_variant_names($conn, (int)$product_id, (int)$company_id) : [];
    if (!$variants) {
        return '';
    }
    $current = eshop_variant_images_map($conn, $company_id, $product_id);
    $error = '';
    foreach ($variants as $variant) {
        $has_upload = isset($files['name'][$variant]) && (int)($files['error'][$variant] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($has_upload) {
            $upload = [
                'name' => $files['name'][$variant],
                'type' => $files['type'][$variant] ?? '',
                'tmp_name' => $files['tmp_name'][$variant] ?? '',
                'error' => $files['error'][$variant] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$variant] ?? 0,
            ];
            $upload_error = '';
            $file = product_save_compressed_photo($upload, $upload_error);
            if ($file === '') {
                $error = $error ?: ($variant . ': ' . ($upload_error ?: 'photo could not be saved.'));
                continue;
            }
            $stmt = mysqli_prepare($conn, 'INSERT INTO eshop_variant_images (company_id, product_id, variant_name, file_name) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE file_name=VALUES(file_name)');
            mysqli_stmt_bind_param($stmt, 'iiss', $company_id, $product_id, $variant, $file);
            mysqli_stmt_execute($stmt);
            if (!empty($current[$variant]) && $current[$variant] !== $file) {
                product_delete_photo_file($current[$variant]);
            }
        } elseif (in_array($variant, $remove, true) && !empty($current[$variant])) {
            $stmt = mysqli_prepare($conn, 'DELETE FROM eshop_variant_images WHERE company_id=? AND product_id=? AND variant_name=?');
            mysqli_stmt_bind_param($stmt, 'iis', $company_id, $product_id, $variant);
            mysqli_stmt_execute($stmt);
            product_delete_photo_file($current[$variant]);
        }
    }
    return $error;
}

// The per-variant slice of a nested $_FILES field: $_FILES['variant_photo'] for products,
// $_FILES['variant_photo'][<line>] for purchases.
function eshop_variant_files_slice($field, $line = null)
{
    $files = $_FILES[$field] ?? null;
    if (!$files || !is_array($files['name'] ?? null)) {
        return [];
    }
    if ($line === null) {
        return $files;
    }
    $slice = [];
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
        $slice[$key] = $files[$key][$line] ?? [];
    }
    return is_array($slice['name']) ? $slice : [];
}

/**
 * Script for a form whose variant inputs are built by JavaScript: adds a small photo
 * field under each variant input. $selector finds the variant inputs; $name_from turns
 * the input's name into the photo field name (regex [pattern, replacement]).
 * $current: [variant => url] for the edit page.
 */
function eshop_variant_photo_script($selector, array $name_from, array $current = [])
{
    return '<script>
document.addEventListener("DOMContentLoaded", function () {
    var current = ' . json_encode((object)$current, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
    var pattern = new RegExp(' . json_encode($name_from[0]) . ');
    var replacement = ' . json_encode($name_from[1]) . ';
    var variantOf = function (input) { var m = /\[([^\]]*)\]$/.exec(input.name || ""); return m ? m[1] : ""; };
    var add = function (input) {
        if (input.dataset.variantPhoto) return;
        input.dataset.variantPhoto = "1";
        var variant = variantOf(input);
        var box = document.createElement("div");
        box.className = "variant-photo-box mt-1";
        box.innerHTML = (current[variant] ? "<img src=\"" + current[variant] + "\" alt=\"\" style=\"width:38px;height:38px;object-fit:cover;border-radius:4px;border:1px solid #d1d5db;margin-right:6px;vertical-align:middle\">" : "")
            + "<label class=\"small text-muted mb-0\" style=\"cursor:pointer\"><i class=\"fas fa-image mr-1\"></i>" + (current[variant] ? "Change e-shop photo" : "E-shop photo") + "</label>"
            + (current[variant] ? " <label class=\"small text-danger mb-0 ml-2\"><input type=\"checkbox\" class=\"variant-photo-remove\" value=\"\"> Remove</label>" : "");
        var file = document.createElement("input");
        file.type = "file"; file.accept = "image/jpeg,image/png,image/webp"; file.className = "variant-photo-input d-block small mt-1";
        file.style.maxWidth = "100%";
        box.appendChild(file);
        var remove = box.querySelector(".variant-photo-remove");
        if (remove) remove.dataset.variant = variant; // value is set only when the form is sent (a numeric value would be counted in stock totals)
        (input.closest(".input-group") || input).insertAdjacentElement("afterend", box);
    };
    var scan = function () { document.querySelectorAll(' . json_encode($selector) . ').forEach(add); };
    scan();
    new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
    // Field names follow the (possibly re-numbered) variant input when the form is sent.
    document.addEventListener("submit", function (e) {
        e.target.querySelectorAll(".variant-photo-box").forEach(function (box) {
            var input = box.previousElementSibling && box.previousElementSibling.matches("input") ? box.previousElementSibling : (box.previousElementSibling ? box.previousElementSibling.querySelector("input") : null);
            if (!input || !input.name) return;
            var name = input.name.replace(pattern, replacement);
            var file = box.querySelector(".variant-photo-input"); if (file) file.name = name;
            var remove = box.querySelector(".variant-photo-remove"); if (remove) { remove.name = "variant_photo_remove[]"; remove.value = remove.dataset.variant || ""; }
        });
    }, true);
});
</script>';
}
