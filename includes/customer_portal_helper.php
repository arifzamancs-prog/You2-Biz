<?php

function ensure_customer_portal_columns($conn)
{
    $columns = [
        'portal_password' => "ALTER TABLE customers ADD COLUMN portal_password VARCHAR(255) NULL AFTER email",
        'portal_password_changed' => "ALTER TABLE customers ADD COLUMN portal_password_changed TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_password",
        'photo' => "ALTER TABLE customers ADD COLUMN photo VARCHAR(255) NULL AFTER portal_password_changed",
    ];

    foreach($columns as $column => $sql){
        $result = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
        if($result && mysqli_num_rows($result) === 0){
            mysqli_query($conn, $sql);
        }
    }
}

function customer_portal_default_password_hash()
{
    static $hash = null;

    if($hash === null){
        $hash = password_hash('123456', PASSWORD_DEFAULT);
    }

    return $hash;
}

function customer_portal_password_hash($customer)
{
    $hash = trim((string)($customer['portal_password'] ?? ''));

    return $hash !== '' ? $hash : customer_portal_default_password_hash();
}

function customer_portal_logged_in()
{
    return isset($_SESSION['customer_portal_id'], $_SESSION['customer_portal_user_id']);
}

function require_customer_portal_login()
{
    if(!customer_portal_logged_in()){
        header('Location: ../login.php');
        exit;
    }
}

function customer_portal_current_customer($conn)
{
    ensure_customer_portal_columns($conn);

    $customer_id = (int)($_SESSION['customer_portal_id'] ?? 0);
    $user_id = (int)($_SESSION['customer_portal_user_id'] ?? 0);

    if($customer_id <= 0 || $user_id <= 0){
        return null;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM customers
         WHERE id=?
         AND user_id=?
         AND status='active'
         LIMIT 1"
    );

    if(!$stmt){
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $customer_id, $user_id);
    mysqli_stmt_execute($stmt);
    $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return $customer ?: null;
}

function customer_photo_url($photo)
{
    $photo = trim((string)$photo);

    if($photo === ''){
        return '';
    }

    $photo = str_replace('\\', '/', $photo);

    if(str_starts_with($photo, 'uploads/')){
        return '../' . implode('/', array_map('rawurlencode', explode('/', $photo)));
    }

    $filename = basename($photo);
    $root = dirname(__DIR__);

    if(is_file($root . '/uploads/customers/' . $filename)){
        return '../uploads/customers/' . rawurlencode($filename);
    }

    if(is_file($root . '/uploads/avatars/' . $filename)){
        return '../uploads/avatars/' . rawurlencode($filename);
    }

    return '';
}

function customer_portal_upload_photo($file, &$message = '')
{
    if(!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE){
        return '';
    }

    if(($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK){
        $message = 'Photo upload failed.';
        return false;
    }

    if((int)($file['size'] ?? 0) > 2 * 1024 * 1024){
        $message = 'Photo size must be 2MB or less.';
        return false;
    }

    $info = getimagesize($file['tmp_name']);
    if(!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)){
        $message = 'Please upload JPG, PNG or WEBP photo.';
        return false;
    }

    if(!extension_loaded('gd')){
        $message = 'Photo resize needs PHP GD extension.';
        return false;
    }

    $source = null;
    if($info[2] === IMAGETYPE_JPEG){
        $source = imagecreatefromjpeg($file['tmp_name']);
    }elseif($info[2] === IMAGETYPE_PNG){
        $source = imagecreatefrompng($file['tmp_name']);
    }elseif($info[2] === IMAGETYPE_WEBP){
        $source = imagecreatefromwebp($file['tmp_name']);
    }

    if(!$source){
        $message = 'Photo could not be processed.';
        return false;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $side = min($width, $height);
    $src_x = (int)(($width - $side) / 2);
    $src_y = (int)(($height - $side) / 2);

    $target = imagecreatetruecolor(250, 250);
    imagealphablending($target, true);
    imagesavealpha($target, true);
    imagecopyresampled($target, $source, 0, 0, $src_x, $src_y, 250, 250, $side, $side);

    $dir = dirname(__DIR__) . '/uploads/customers';
    if(!is_dir($dir)){
        mkdir($dir, 0775, true);
    }

    $filename = 'customer_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $path = $dir . '/' . $filename;

    $saved = imagejpeg($target, $path, 88);
    imagedestroy($source);
    imagedestroy($target);

    if(!$saved){
        $message = 'Photo could not be saved.';
        return false;
    }

    return $filename;
}

function customer_portal_ledger_rows($conn, $user_id, $customer_id)
{
    $ledger = [];

    $stmt = mysqli_prepare($conn, "SELECT invoice_date, invoice_no, total_amount FROM invoices WHERE customer_id=? AND user_id=? AND accounting_status='posted'");
    mysqli_stmt_bind_param($stmt, 'ii', $customer_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while($result && $row = mysqli_fetch_assoc($result)){
        $ledger[] = [
            'date' => $row['invoice_date'],
            'type' => 'Invoice',
            'reference' => $row['invoice_no'],
            'debit' => (float)$row['total_amount'],
            'credit' => 0,
        ];
    }

    $stmt = mysqli_prepare($conn, "SELECT payment_date, amount, note FROM customer_payments WHERE customer_id=? AND user_id=?");
    mysqli_stmt_bind_param($stmt, 'ii', $customer_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while($result && $row = mysqli_fetch_assoc($result)){
        $ledger[] = [
            'date' => $row['payment_date'],
            'type' => 'Payment',
            'reference' => $row['note'] ?: 'Payment',
            'debit' => 0,
            'credit' => (float)$row['amount'],
        ];
    }

    $stmt = mysqli_prepare($conn, "SELECT entry_date, due_no, amount FROM customer_opening_dues WHERE customer_id=? AND user_id=?");
    if($stmt){
        mysqli_stmt_bind_param($stmt, 'ii', $customer_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while($result && $row = mysqli_fetch_assoc($result)){
            $ledger[] = [
                'date' => $row['entry_date'],
                'type' => 'Previous Due',
                'reference' => $row['due_no'],
                'debit' => (float)$row['amount'],
                'credit' => 0,
            ];
        }
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            bi.invoice_date,
            bi.invoice_no,
            COALESCE(NULLIF(bi.total_price, 0), pk.price, bi.amount) AS package_price,
            bi.invoice_type
         FROM booking_invoices bi
         LEFT JOIN packages pk
            ON pk.id=bi.package_id
            AND pk.user_id=bi.user_id
         WHERE bi.customer_id=?
         AND bi.user_id=?
         AND bi.status='confirmed'"
    );
    if($stmt){
        mysqli_stmt_bind_param($stmt, 'ii', $customer_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while($result && $row = mysqli_fetch_assoc($result)){
            $ledger[] = [
                'date' => $row['invoice_date'],
                'type' => $row['invoice_type'] ?: 'Booking Invoice',
                'reference' => $row['invoice_no'],
                'debit' => (float)$row['package_price'],
                'credit' => 0,
            ];
        }
    }

    usort($ledger, function($a, $b){
        return strcmp((string)$a['date'], (string)$b['date']);
    });

    return $ledger;
}

function customer_portal_admin_style_ledger_rows($conn, $user_id, $customer_id, $invoice_types = [])
{
    $ledger = [];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            t.id,
            t.txn_date,
            t.transaction_type,
            t.amount,
            bi.invoice_no,
            bi.invoice_type,
            bi.notes AS invoice_note,
            bi.invoice_date,
            COALESCE(NULLIF(bi.total_price, 0), pk.price, bi.amount) AS package_price,
            p.project_name,
            pk.package_name,
            w.wallet_name
         FROM transactions t
         INNER JOIN booking_invoices bi
            ON bi.id=t.reference_id
            AND bi.user_id=t.user_id
         LEFT JOIN projects p
            ON p.id=bi.project_id
            AND p.user_id=bi.user_id
         LEFT JOIN packages pk
            ON pk.id=bi.package_id
            AND pk.user_id=bi.user_id
         LEFT JOIN wallets w
            ON w.id=t.wallet_id
            AND w.user_id=t.user_id
         WHERE bi.customer_id=?
         AND bi.user_id=?
         AND bi.status='confirmed'
         AND t.transaction_type IN ('invoice_income', 'invoice_expense')
         ORDER BY t.txn_date, t.id"
    );

    if($stmt){
        mysqli_stmt_bind_param($stmt, 'ii', $customer_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while($result && $row = mysqli_fetch_assoc($result)){
            $is_refund = $row['transaction_type'] === 'invoice_expense';
            $project_details = trim(
                (string)($row['project_name'] ?? '') .
                (($row['project_name'] ?? '') !== '' && ($row['package_name'] ?? '') !== '' ? ' - ' : '') .
                (string)($row['package_name'] ?? '')
            );

            $ledger[] = [
                'trx_date' => $row['txn_date'],
                'invoice_no' => trim((string)$row['invoice_no']),
                'wallet_name' => (string)($row['wallet_name'] ?? ''),
                'payment_type' => function_exists('booking_invoice_type_label')
                    ? booking_invoice_type_label($row['invoice_type'] ?? '', $invoice_types)
                    : (string)($row['invoice_type'] ?? ''),
                'note' => trim((string)($row['invoice_note'] ?? '')) !== '' ? (string)$row['invoice_note'] : 'confirmed',
                'project_details' => $project_details,
                'booking_date' => $row['invoice_date'],
                'total_amount' => $is_refund ? 0 : (float)$row['package_price'],
                'sort_order' => $is_refund ? 3 : 2,
                'reference_id' => (int)$row['id'],
                'debit' => $is_refund ? (float)$row['amount'] : 0,
                'credit' => $is_refund ? 0 : (float)$row['amount'],
            ];
        }
    }

    usort($ledger, function($a, $b){
        $date_compare = strtotime($a['trx_date']) - strtotime($b['trx_date']);
        if($date_compare !== 0){
            return $date_compare;
        }
        if($a['sort_order'] !== $b['sort_order']){
            return $a['sort_order'] - $b['sort_order'];
        }
        return $a['reference_id'] - $b['reference_id'];
    });

    return $ledger;
}

function customer_portal_group_ledger_rows($ledger)
{
    $groups = [];

    foreach($ledger as $row){
        $invoice_no = trim((string)($row['invoice_no'] ?? ''));
        $project_details = trim((string)($row['project_details'] ?? ''));
        $booking_date = trim((string)($row['booking_date'] ?? ''));
        $group_key = $invoice_no !== ''
            ? 'invoice-' . md5($invoice_no)
            : 'entry-' . ($row['reference_id'] ?? count($groups));

        if(!isset($groups[$group_key])){
            $groups[$group_key] = [
                'project_details' => $project_details !== '' ? $project_details : '-',
                'booking_date' => $booking_date !== '' && function_exists('app_date') ? app_date($booking_date) : ($booking_date ?: '-'),
                'booking_dates' => [],
                'total_amount' => 0,
                'total_paid' => 0,
                'total_due' => 0,
                'latest_time' => strtotime($row['trx_date']) ?: 0,
                'rows' => [],
            ];
        }

        $row_total_amount = (float)($row['total_amount'] ?? 0);
        if($row_total_amount > 0){
            $groups[$group_key]['total_amount'] += $row_total_amount;
        }

        if($booking_date !== ''){
            $groups[$group_key]['booking_dates'][$booking_date] = function_exists('app_date') ? app_date($booking_date) : $booking_date;
        }

        $groups[$group_key]['total_paid'] += ((float)$row['credit'] - (float)$row['debit']);
        $groups[$group_key]['latest_time'] = max((int)$groups[$group_key]['latest_time'], strtotime($row['trx_date']) ?: 0);
        $groups[$group_key]['rows'][] = $row;
    }

    foreach($groups as &$group){
        $running_paid = 0;
        $rows = [];

        if(!empty($group['booking_dates'])){
            $group['booking_date'] = implode(', ', array_values($group['booking_dates']));
        }

        foreach($group['rows'] as $row){
            $running_paid += ((float)$row['credit'] - (float)$row['debit']);
            $row['due'] = max(0, (float)$group['total_amount'] - $running_paid);
            $rows[] = $row;
        }

        $group['rows'] = array_reverse($rows);
        $group['total_due'] = max(0, (float)$group['total_amount'] - (float)$group['total_paid']);
    }
    unset($group);

    foreach($groups as $key => $group){
        if(trim((string)$group['project_details']) !== '-' && (float)$group['total_amount'] <= 0){
            unset($groups[$key]);
        }
    }

    uasort($groups, function($a, $b){
        return ((int)$b['latest_time']) <=> ((int)$a['latest_time']);
    });

    return $groups;
}
