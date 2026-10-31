<?php

// Promotional / special message printed at the bottom of the sales invoice, just above
// "Thank you". A company setting (Printing Option), Inactive by default.

const PRINT_PROMO_MAX_LENGTH = 300;

function print_promo_ensure_columns($conn)
{
    static $done = false;

    if($done){
        return;
    }

    $done = true;

    if(function_exists('schema_once') && !schema_once('print_promo_columns')){
        return;
    }

    $columns = [
        'print_promo_status' => "ALTER TABLE users ADD COLUMN print_promo_status VARCHAR(20) NOT NULL DEFAULT 'inactive'",
        'print_promo_message' => "ALTER TABLE users ADD COLUMN print_promo_message TEXT NULL",
    ];

    foreach($columns as $column => $alter_sql){
        $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '{$column}'");

        if($result && mysqli_num_rows($result) === 0){
            mysqli_query($conn, $alter_sql);
        }
    }
}

// ['status' => 'active'|'inactive', 'message' => text] for the logged-in company.
function print_promo_settings($conn)
{
    $settings = ['status' => 'inactive', 'message' => ''];
    $company_id = (int)($_SESSION['user_id'] ?? 0);

    if($company_id <= 0){
        return $settings;
    }

    print_promo_ensure_columns($conn);
    $result = mysqli_query($conn, "SELECT print_promo_status, print_promo_message FROM users WHERE id={$company_id} LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;

    if($row){
        $settings['status'] = ($row['print_promo_status'] ?? '') === 'active' ? 'active' : 'inactive';
        $settings['message'] = (string)($row['print_promo_message'] ?? '');
    }

    return $settings;
}

function save_print_promo_settings($conn, $status, $message)
{
    $company_id = (int)($_SESSION['user_id'] ?? 0);

    if($company_id <= 0){
        return false;
    }

    print_promo_ensure_columns($conn);
    $status = $status === 'active' ? 'active' : 'inactive';
    $message = mb_substr(trim(str_replace("\r\n", "\n", (string)$message)), 0, PRINT_PROMO_MAX_LENGTH);
    $stmt = mysqli_prepare($conn, 'UPDATE users SET print_promo_status=?, print_promo_message=? WHERE id=?');

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ssi', $status, $message, $company_id);

    return mysqli_stmt_execute($stmt);
}

// The bordered, centered message box for a print, or '' when it is off or empty.
// $compact: smaller type for thermal (POS) receipts.
function print_promo_html($conn, $compact = false)
{
    $settings = print_promo_settings($conn);

    if($settings['status'] !== 'active' || trim($settings['message']) === ''){
        return '';
    }

    $style = $compact
        ? 'border:1px solid #000;border-radius:3px;padding:4px 6px;margin:6px 0;text-align:center;font-size:11px;line-height:1.3;color:#000;word-wrap:break-word;overflow-wrap:anywhere'
        : 'border:1px solid #000;border-radius:4px;padding:8px 12px;margin:10px 0;text-align:center;font-size:13px;line-height:1.4;color:#000;word-wrap:break-word;overflow-wrap:anywhere;page-break-inside:avoid';

    return '<div class="print-promo-message" style="' . $style . '">' . nl2br(htmlspecialchars($settings['message'])) . '</div>';
}
