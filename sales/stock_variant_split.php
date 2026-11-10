<?php
// Saves the one-time split of a product's stock across its variants (My Stock or the warehouse).
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/variant_split_helper.php';

header('Content-Type: application/json; charset=utf-8');
$respond = function($ok, $message){ echo json_encode(['ok' => $ok, 'message' => $message]); exit; };

if($_SERVER['REQUEST_METHOD'] !== 'POST' || is_agent_user()){
    http_response_code(403);
    $respond(false, 'You cannot split stock.');
}

$company_id = (int)$_SESSION['user_id'];
variant_split_schema($conn);
$product_id = (int)($_POST['product_id'] ?? 0);
$quantities = is_array($_POST['quantities'] ?? null) ? $_POST['quantities'] : [];

if(($_POST['scope'] ?? '') === 'warehouse'){
    if(!stock_can_manage_warehouse($conn)){
        http_response_code(403);
        $respond(false, 'Only warehouse managers can split warehouse stock.');
    }
    $branch_id = company_multi_branch_enabled($conn, $company_id) ? (int)stock_warehouse_id($conn, $company_id) : (int)stock_head_office_id($conn, $company_id);
}else{
    $branch_id = (int)selected_branch_id($conn, true);
}

mysqli_begin_transaction($conn);
try{
    variant_split_query($conn, 'SELECT id FROM products WHERE id=? AND user_id=? FOR UPDATE', 'ii', [$product_id, $company_id]);
    variant_split_apply($conn, $company_id, $product_id, $branch_id, $quantities, (int)($_SESSION['login_user_id'] ?? $company_id));
    mysqli_commit($conn);
    $respond(true, 'Stock split by variant.');
}catch(Throwable $e){
    mysqli_rollback($conn);
    if(!($e instanceof RuntimeException)){
        error_log($e->getMessage());
    }
    $respond(false, $e instanceof RuntimeException ? $e->getMessage() : 'The split could not be saved. Nothing was changed.');
}
