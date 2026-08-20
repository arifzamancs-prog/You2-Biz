<?php

/**
 * Add the standard product categories for a newly created company.
 * The existence check makes this safe if registration is retried.
 */
function ensure_default_product_categories($conn, $user_id)
{
    ensure_product_category_type_column($conn);
    $user_id = (int)$user_id;

    // Super Admin uses user ID 0, which is also allowed to have the
    // standard categories in its own management view.
    if($user_id < 0){
        return false;
    }

    $categories = [
        ['General (Non Stock)', 'non_stock'],
        ['Stock Product', 'stock_product'],
    ];

    $sql = "INSERT INTO product_categories (user_id, category_name, category_type, status)
            SELECT ?, ?, ?, 'active'
            WHERE NOT EXISTS (
                SELECT 1
                FROM product_categories
                WHERE user_id=? AND category_name=?
            )";

    $stmt = mysqli_prepare($conn, $sql);

    if(!$stmt){
        return false;
    }

    foreach($categories as $category){
        [$category_name, $category_type] = $category;

        mysqli_stmt_bind_param(
            $stmt,
            'issis',
            $user_id,
            $category_name,
            $category_type,
            $user_id,
            $category_name
        );

        if(!mysqli_stmt_execute($stmt)){
            mysqli_stmt_close($stmt);
            return false;
        }
    }

    mysqli_stmt_close($stmt);

    return true;
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

    mysqli_query(
        $conn,
        "UPDATE product_categories
         SET category_name='General (Non Stock)'
         WHERE category_name='General'"
    );

    mysqli_query(
        $conn,
        "UPDATE product_categories
         SET category_type='stock_product'
         WHERE category_name='Stock Product'"
    );
}

function product_category_type_label($category_type)
{
    return $category_type === 'stock_product' ? 'Stock' : 'Non Stock';
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
    return in_array(
        trim((string)$category_name),
        ['General (Non Stock)', 'General', 'Stock Product'],
        true
    );
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
