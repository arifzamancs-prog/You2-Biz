<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/eshop_helper.php';
require_super_admin_user();
ensure_eshop_table($conn);
if(empty($_SESSION['eshop_csrf'])) $_SESSION['eshop_csrf'] = bin2hex(random_bytes(32));
$error = '';
$message = $_SESSION['super_admin_flash_message'] ?? '';
unset($_SESSION['super_admin_flash_message']);
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $company_id = (int)($_POST['company_id'] ?? 0);
    $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
    $enabled = ($_POST['enabled'] ?? '') === '1' ? 1 : 0;
    if(!hash_equals($_SESSION['eshop_csrf'], (string)($_POST['csrf'] ?? ''))){
        $error = 'Your session has changed. Refresh the page and try again.';
    }elseif(!eshop_valid_slug($slug)){
        $error = 'Use 3–64 lowercase letters, numbers or hyphens. Start and end with a letter or number.';
    }else{
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id=? AND role='admin'");
        mysqli_stmt_bind_param($stmt, 'i', $company_id);
        mysqli_stmt_execute($stmt);
        if(!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))){
            $error = 'Company not found.';
        }else{
            try{
                // Update only this company; a slug collision must never update another shop.
                $existing = eshop_company_settings($conn, $company_id);
                $sql = $existing
                    ? 'UPDATE eshop_settings SET slug=?, enabled=? WHERE company_id=?'
                    : 'INSERT INTO eshop_settings (slug, enabled, company_id) VALUES (?, ?, ?)';
                $save = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($save, 'sii', $slug, $enabled, $company_id);
                if(!mysqli_stmt_execute($save)) throw new RuntimeException('Save failed', mysqli_stmt_errno($save));
                $_SESSION['super_admin_flash_message'] = 'E-shop settings saved successfully.';
                $_SESSION['super_admin_flash_type'] = 'success';
                header('Location: index.php');
                exit;
            }catch(Throwable $exception){
                $error = (int)$exception->getCode() === 1062 ? 'This shop URL is already reserved by another company.' : 'Unable to save E-shop settings. Please try again.';
            }
        }
    }
}

if($error !== ''){
    $_SESSION['super_admin_flash_message'] = $error;
    $_SESSION['super_admin_flash_type'] = 'danger';
}
header('Location: index.php');
exit;
