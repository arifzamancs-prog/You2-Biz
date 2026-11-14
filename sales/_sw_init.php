<?php
// Shared start of the Service Center and Warranty pages (Inventory & Sales companies).
// These pages live under sales/, so the stock bootstrap has already checked the
// company type, the stock_sales permission and the CSRF token of every POST.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/transaction_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/fifo_inventory_helper.php';
require_once __DIR__ . '/../includes/product_category_helper.php';
require_once __DIR__ . '/../includes/pending_invoice_stock_helper.php';
require_once __DIR__ . '/../includes/branch_context_helper.php';
require_once __DIR__ . '/../includes/date_helper.php';
require_once __DIR__ . '/../includes/service_warranty_helper.php';

$user_id = (int)$_SESSION['user_id'];
ensure_branch_accounting_columns($conn, $user_id);
sw_require_module($conn);
$branch_id = (int)selected_branch_id($conn, true);

function sw_csrf_field()
{
    return '<input type="hidden" name="stock_csrf" value="' . htmlspecialchars(stock_csrf_token()) . '">';
}

function sw_flash($type, $message)
{
    $_SESSION['sw_flash'] = ['type' => $type, 'message' => $message];
}

function sw_flash_html()
{
    $flash = $_SESSION['sw_flash'] ?? null;
    unset($_SESSION['sw_flash']);
    if (!$flash) {
        return '';
    }
    return '<div class="alert alert-' . htmlspecialchars($flash['type']) . ' alert-dismissible fade show">'
        . htmlspecialchars($flash['message']) . '<button type="button" class="close" data-dismiss="alert">&times;</button></div>';
}

function sw_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sw_amount($value)
{
    return number_format((float)$value, 2);
}

function sw_date($value)
{
    return $value ? date('d M Y', strtotime($value)) : '—';
}

function sw_datetime($value)
{
    return $value ? date('d M Y, h:i A', strtotime($value)) : '—';
}

function sw_wallet_options($conn, $user_id, $selected = 0)
{
    $html = '';
    $wallets = active_wallets_result($conn, $user_id);
    while ($wallets && ($wallet = mysqli_fetch_assoc($wallets))) {
        $html .= '<option value="' . (int)$wallet['id'] . '"' . ((int)$wallet['id'] === (int)$selected ? ' selected' : '') . '>'
            . sw_h($wallet['wallet_name']) . ' (BDT ' . sw_amount($wallet['balance']) . ')</option>';
    }
    return $html;
}

function sw_request_key()
{
    return bin2hex(random_bytes(16));
}

// Company and branch heading for the printed receipts.
function sw_company_info($conn, $user_id, $branch_id)
{
    $company = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT name, phone, email, address FROM users WHERE id=' . (int)$user_id)) ?: [];
    $branch = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT branch_name, is_head_office FROM branches WHERE id=' . (int)$branch_id . ' AND user_id=' . (int)$user_id));
    $company['branch'] = $branch && empty($branch['is_head_office']) ? (string)$branch['branch_name'] : '';
    return $company;
}
