<?php

function product_sku_exists($conn, $user_id, $sku, $exclude_id = 0)
{
    $sku = trim((string)$sku);
    if ($sku === '') return false;
    $stmt = mysqli_prepare($conn, 'SELECT id FROM products WHERE user_id=? AND UPPER(TRIM(sku))=UPPER(?) AND id<>? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'isi', $user_id, $sku, $exclude_id);
    mysqli_stmt_execute($stmt);
    $exists = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $exists;
}

/**
 * Product inventory is FIFO-only.  The legacy category_type column remains
 * for backwards-compatible databases, but every category is now stock based.
 */
function ensure_default_product_categories($conn, $user_id)
{
    return ensure_fifo_only_product_categories($conn, $user_id);
}

function ensure_fifo_only_product_categories($conn, $user_id)
{
    ensure_product_category_type_column($conn);
    $user_id = (int)$user_id;

    // Super Admin uses user ID 0, which is also allowed to have the
    // standard categories in its own management view.
    if($user_id < 0){
        return false;
    }

    // Preserve historic categories and products, but make each one eligible
    // for FIFO inventory. No records are deleted during this migration.
    $update = mysqli_prepare(
        $conn,
        "UPDATE product_categories SET category_type='stock_product' WHERE user_id=?"
    );
    if(!$update){
        return false;
    }
    mysqli_stmt_bind_param($update, 'i', $user_id);
    $ok = mysqli_stmt_execute($update);
    mysqli_stmt_close($update);

    // Remove the legacy non-stock wording from records shown in the FIFO UI.
    // Duplicate category names are harmless and preserve every historic link.
    $rename = mysqli_prepare(
        $conn,
        "UPDATE product_categories
         SET category_name='General'
         WHERE user_id=?
           AND category_name IN ('General (Non Stock/Service)', 'General (Non Stock)')"
    );
    if($rename){
        mysqli_stmt_bind_param($rename, 'i', $user_id);
        mysqli_stmt_execute($rename);
        mysqli_stmt_close($rename);
    }

    return $ok;
}

function ensure_product_category_type_column($conn)
{
    $column_check = mysqli_query($conn, "SHOW COLUMNS FROM product_categories LIKE 'category_type'");

    if($column_check && mysqli_num_rows($column_check) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE product_categories
             ADD COLUMN category_type ENUM('non_stock', 'stock_product')
             NOT NULL DEFAULT 'non_stock' AFTER category_name"
        );
    }

}

function ensure_product_variant_schema($conn)
{
    $column = mysqli_query($conn, "SHOW COLUMNS FROM product_categories LIKE 'variant_options'");
    if($column && mysqli_num_rows($column) === 0){
        mysqli_query($conn, "ALTER TABLE product_categories ADD COLUMN variant_options TEXT NULL AFTER category_name");
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_variants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        variant_name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_product_variant (product_id, variant_name),
        KEY idx_variant_owner (user_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_product_subcategory_schema($conn)
{
    $category_column = mysqli_query($conn, "SHOW COLUMNS FROM product_categories LIKE 'sub_category'");
    if($category_column && mysqli_num_rows($category_column) === 0){
        mysqli_query($conn, "ALTER TABLE product_categories ADD COLUMN sub_category VARCHAR(100) NULL AFTER category_name");
    }
    $product_column = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'sub_category'");
    if($product_column && mysqli_num_rows($product_column) === 0){
        mysqli_query($conn, "ALTER TABLE products ADD COLUMN sub_category VARCHAR(100) NULL AFTER category_id");
    }
}

function product_category_subcategory($conn, $category_id, $user_id)
{
    ensure_product_subcategory_schema($conn);
    $stmt = mysqli_prepare($conn, 'SELECT sub_category FROM product_categories WHERE id=? AND user_id=? LIMIT 1');
    if(!$stmt){ return ''; }
    mysqli_stmt_bind_param($stmt, 'ii', $category_id, $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return trim((string)($row['sub_category'] ?? ''));
}

function product_variant_options_from_text($value)
{
    $parts = preg_split('/[\r\n,]+/', (string)$value);
    $options = [];
    foreach($parts as $part){
        $part = trim($part);
        if($part !== '' && mb_strlen($part) <= 100){
            $options[mb_strtolower($part)] = $part;
        }
    }
    return array_values($options);
}

function product_category_variant_options($conn, $category_id, $user_id)
{
    ensure_product_variant_schema($conn);
    $stmt = mysqli_prepare($conn, 'SELECT variant_options FROM product_categories WHERE id=? AND user_id=? LIMIT 1');
    if(!$stmt){ return []; }
    mysqli_stmt_bind_param($stmt, 'ii', $category_id, $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return product_variant_options_from_text($row['variant_options'] ?? '');
}

function product_category_name_exists($conn, $user_id, $category_name, $exclude_id = 0)
{
    $category_name = trim((string)$category_name);
    $exclude_id = (int)$exclude_id;
    if($category_name === ''){ return false; }
    $sql = 'SELECT id FROM product_categories WHERE user_id=? AND LOWER(TRIM(category_name))=LOWER(TRIM(?))';
    if($exclude_id > 0){ $sql .= ' AND id<>?'; }
    $sql .= ' LIMIT 1';
    $stmt = mysqli_prepare($conn, $sql);
    if(!$stmt){ return false; }
    if($exclude_id > 0){
        mysqli_stmt_bind_param($stmt, 'isi', $user_id, $category_name, $exclude_id);
    }else{
        mysqli_stmt_bind_param($stmt, 'is', $user_id, $category_name);
    }
    mysqli_stmt_execute($stmt);
    $exists = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

function product_variant_names($conn, $product_id, $user_id)
{
    ensure_product_variant_schema($conn);
    $stmt = mysqli_prepare($conn, 'SELECT variant_name FROM product_variants WHERE product_id=? AND user_id=? ORDER BY id');
    if(!$stmt){ return []; }
    mysqli_stmt_bind_param($stmt, 'ii', $product_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $names = [];
    while($row = mysqli_fetch_assoc($result)){ $names[] = $row['variant_name']; }
    mysqli_stmt_close($stmt);
    if(empty($names)){ return []; }

    $category_stmt = mysqli_prepare($conn, 'SELECT c.variant_options FROM products p INNER JOIN product_categories c ON c.id=p.category_id WHERE p.id=? AND p.user_id=? LIMIT 1');
    if(!$category_stmt){ return $names; }
    mysqli_stmt_bind_param($category_stmt, 'ii', $product_id, $user_id);
    mysqli_stmt_execute($category_stmt);
    $category = mysqli_fetch_assoc(mysqli_stmt_get_result($category_stmt));
    mysqli_stmt_close($category_stmt);
    $category_variants = product_variant_options_from_text($category['variant_options'] ?? '');
    if(empty($category_variants)){ return $names; }

    $available = [];
    foreach($names as $name){ $available[mb_strtolower($name)] = $name; }
    $ordered = [];
    foreach($category_variants as $name){
        $key = mb_strtolower($name);
        if(isset($available[$key])){ $ordered[] = $available[$key]; unset($available[$key]); }
    }
    foreach($names as $name){
        if(isset($available[mb_strtolower($name)])){ $ordered[] = $name; }
    }
    return $ordered;
}

function product_variant_is_valid($conn, $product_id, $user_id, $variant_name)
{
    $variant_name = trim((string)$variant_name);
    $variants = product_variant_names($conn, $product_id, $user_id);
    return empty($variants) ? $variant_name === '' : in_array($variant_name, $variants, true);
}

function product_category_type_label($category_type)
{
    return 'FIFO Stock';
}

function product_category_is_stock($conn, $category_id, $user_id)
{
    $category_id = (int)$category_id;
    $user_id = (int)$user_id;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT category_type FROM product_categories WHERE id=? AND user_id=? LIMIT 1"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $category_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $category = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return ($category['category_type'] ?? 'non_stock') === 'stock_product';
}

function product_has_transactions($conn, $product_id, $user_id)
{
    $product_id = (int)$product_id;
    $user_id = (int)$user_id;

    $sql = "SELECT
                (SELECT COUNT(*) FROM invoice_items ii
                 INNER JOIN invoices i ON i.id=ii.invoice_id
                 WHERE ii.product_id=? AND i.user_id=?)
                +
                (SELECT COUNT(*) FROM purchase_items pi
                 INNER JOIN purchases p ON p.id=pi.purchase_id
                 WHERE pi.product_id=? AND p.user_id=?)
                +
                (SELECT COUNT(*) FROM stock_transactions
                 WHERE product_id=? AND user_id=?) AS total";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return true;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'iiiiii',
        $product_id,
        $user_id,
        $product_id,
        $user_id,
        $product_id,
        $user_id
    );
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return (int)($row['total'] ?? 0) > 0;
}

function product_uses_stock($conn, $product_id, $user_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT c.category_type
         FROM products p
         LEFT JOIN product_categories c ON c.id=p.category_id
         WHERE p.id=? AND p.user_id=? LIMIT 1"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $product_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $product = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return ($product['category_type'] ?? 'non_stock') === 'stock_product';
}

function product_category_is_default($category_name)
{
    // Kept as a compatibility helper for older callers. FIFO categories are
    // user-managed, so none of them are locked as a system default.
    return false;
}

function product_category_has_usage($conn, $category_id, $user_id)
{
    $category_id = (int)$category_id;
    $user_id = (int)$user_id;

    if($category_id <= 0 || $user_id < 0){
        return true;
    }

    // A category is in use as soon as it has a product. The additional checks
    // protect categories whose products have sales, purchase, or stock history.
    $sql = "SELECT
                (SELECT COUNT(*) FROM products
                 WHERE category_id=? AND user_id=?)
                +
                (SELECT COUNT(*) FROM invoice_items ii
                 INNER JOIN products p ON p.id=ii.product_id
                 WHERE p.category_id=? AND p.user_id=?)
                +
                (SELECT COUNT(*) FROM purchase_items pi
                 INNER JOIN products p ON p.id=pi.product_id
                 WHERE p.category_id=? AND p.user_id=?)
                +
                (SELECT COUNT(*) FROM stock_transactions st
                 INNER JOIN products p ON p.id=st.product_id
                 WHERE p.category_id=? AND p.user_id=?)
                AS total";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return true;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'iiiiiiii',
        $category_id,
        $user_id,
        $category_id,
        $user_id,
        $category_id,
        $user_id,
        $category_id,
        $user_id
    );
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return (int)($row['total'] ?? 0) > 0;
}
