<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];

$id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

$category_stmt = mysqli_prepare(
    $conn,
    "SELECT category_name FROM product_categories WHERE id=? AND user_id=?"
);
mysqli_stmt_bind_param($category_stmt, 'ii', $id, $user_id);
mysqli_stmt_execute($category_stmt);
$category_result = mysqli_stmt_get_result($category_stmt);
$category = $category_result ? mysqli_fetch_assoc($category_result) : null;

if(!$category){
    die('Category Not Found');
}

if(product_category_is_default($category['category_name'])){
    die('Default categories cannot be deleted.');
}

if(product_category_has_usage($conn, $id, $user_id)){
    die('Cannot delete category. Products or transactions exist in this category.');
}

$sql = "SELECT COUNT(*) total
        FROM products
        WHERE category_id=?
        AND user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result =
mysqli_stmt_get_result($stmt);

$row =
mysqli_fetch_assoc($result);

if($row['total'] > 0){

    die(
        'Cannot delete category. Products exist in this category.'
    );

}

$sql = "DELETE
        FROM product_categories
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute(
    $stmt
);

header(
    "Location: index.php"
);

exit;
