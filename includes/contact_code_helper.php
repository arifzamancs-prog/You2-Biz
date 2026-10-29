<?php

/*
|----------------------------------
| Customer ID / Supplier ID
|----------------------------------
| Each company numbers its own customers (CUS-0001, ...) and suppliers
| (SUP-0001, ...). Forms suggest the next ID and let the user change it; the
| ID must be unique within the company. Customers and suppliers created from
| an invoice or purchase get the next ID automatically, and existing records
| are numbered in the order they were created.
*/

function contact_code_config($type)
{
    return $type === 'supplier'
        ? ['table' => 'suppliers', 'column' => 'supplier_code', 'prefix' => 'SUP-', 'label' => 'Supplier ID']
        : ['table' => 'customers', 'column' => 'customer_code', 'prefix' => 'CUS-', 'label' => 'Customer ID'];
}

// Pages call this before any transaction: ALTER TABLE would commit it, so it only runs when the column is missing.
function ensure_contact_code_columns($conn)
{
    static $checked = false;

    if($checked){
        return;
    }

    $checked = true;

    // Biz supplier IDs are prepared by supplier_helper.php (ensure_supplier_code_column).
    foreach(['customer'] as $type){
        $config = contact_code_config($type);
        $column = mysqli_query($conn, "SHOW COLUMNS FROM `{$config['table']}` LIKE '{$config['column']}'");

        if($column && mysqli_num_rows($column) === 0){
            mysqli_query($conn, "ALTER TABLE `{$config['table']}` ADD COLUMN `{$config['column']}` VARCHAR(60) NULL AFTER user_id");
            mysqli_query($conn, "ALTER TABLE `{$config['table']}` ADD INDEX idx_{$config['column']} (user_id, `{$config['column']}`)");
        }
    }
}

function contact_code_exists($conn, $type, $user_id, $code, $exclude_id = 0)
{
    $config = contact_code_config($type);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM `{$config['table']}` WHERE user_id=? AND LOWER(`{$config['column']}`)=LOWER(?) AND id<>? LIMIT 1"
    );

    if(!$stmt){
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'isi', $user_id, $code, $exclude_id);
    mysqli_stmt_execute($stmt);

    return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
}

// The next free ID in the standard series (CUS-0001, SUP-0001, ...). IDs typed by hand in another
// style (e.g. VIP-7) are kept as they are and do not change the series.
function contact_code_next($conn, $type, $user_id)
{
    $config = contact_code_config($type);
    $prefix = $config['prefix'];
    $number = 0;
    $digits = 4;
    $like = $prefix . '%';
    $stmt = mysqli_prepare(
        $conn,
        "SELECT `{$config['column']}` AS code FROM `{$config['table']}`
         WHERE user_id=? AND `{$config['column']}` LIKE ?"
    );

    if($stmt){
        mysqli_stmt_bind_param($stmt, 'is', $user_id, $like);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while($row = mysqli_fetch_assoc($result)){
            if(preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', (string)$row['code'], $m) && (int)$m[1] > $number){
                $number = (int)$m[1];
                $digits = max(4, strlen($m[1]));
            }
        }
    }

    do{
        $number++;
        $code = $prefix . str_pad((string)$number, $digits, '0', STR_PAD_LEFT);
    }while(contact_code_exists($conn, $type, $user_id, $code));

    return $code;
}

// '' when the ID can be used, otherwise the reason.
function contact_code_error($conn, $type, $user_id, $code, $exclude_id = 0)
{
    $config = contact_code_config($type);
    $code = trim((string)$code);

    if($code === ''){
        return $config['label'] . ' is required.';
    }

    if(mb_strlen($code) > 60){
        return $config['label'] . ' must be 60 characters or fewer.';
    }

    if(contact_code_exists($conn, $type, $user_id, $code, $exclude_id)){
        return $config['label'] . ' already exists.';
    }

    return '';
}

// Gives every customer/supplier of the company without an ID the next ID, oldest first.
function contact_code_fill_missing($conn, $type, $user_id)
{
    $config = contact_code_config($type);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM `{$config['table']}`
         WHERE user_id=? AND (`{$config['column']}` IS NULL OR `{$config['column']}`='')
         ORDER BY id ASC"
    );

    if(!$stmt){
        return;
    }

    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $ids = array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), 'id');

    if(!$ids){
        return;
    }

    $update = mysqli_prepare($conn, "UPDATE `{$config['table']}` SET `{$config['column']}`=? WHERE id=? AND user_id=?");

    foreach($ids as $id){
        $code = contact_code_next($conn, $type, $user_id);
        $id = (int)$id;
        mysqli_stmt_bind_param($update, 'sii', $code, $id, $user_id);
        mysqli_stmt_execute($update);
    }
}

// The company's IDs (lower case) for the live "already exists" check on the forms.
function contact_code_list($conn, $type, $user_id, $exclude_id = 0)
{
    $config = contact_code_config($type);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT LOWER(`{$config['column']}`) AS code FROM `{$config['table']}`
         WHERE user_id=? AND id<>? AND `{$config['column']}` IS NOT NULL AND `{$config['column']}`<>''"
    );

    if(!$stmt){
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'ii', $user_id, $exclude_id);
    mysqli_stmt_execute($stmt);

    return array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), 'code');
}

// The ID input for create/edit forms, with the live availability check.
function contact_code_field_html($type, $value, $existing_codes)
{
    $config = contact_code_config($type);
    $id = $config['column'];

    return '<div class="form-group">'
        . '<label>' . htmlspecialchars($config['label']) . ' <span class="text-danger">*</span></label>'
        . '<input type="text" name="' . $id . '" id="' . $id . '" class="form-control" maxlength="60" required value="' . htmlspecialchars((string)$value) . '">'
        . '<small id="' . $id . '_status" class="form-text text-muted">The next free ID is filled in; you can change it.</small>'
        . '</div>'
        . '<script>(function(){'
        . 'var input = document.getElementById(' . json_encode($id) . '), status = document.getElementById(' . json_encode($id . '_status') . ');'
        . 'var existing = ' . json_encode(array_values($existing_codes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';'
        . 'function check(){'
        . 'var value = input.value.trim().toLowerCase();'
        . 'if(!value){ status.className = "form-text text-danger"; status.textContent = ' . json_encode($config['label'] . ' is required.') . '; input.setCustomValidity(status.textContent); return; }'
        . 'if(existing.indexOf(value) !== -1){ status.className = "form-text text-danger"; status.textContent = ' . json_encode($config['label'] . ' already exists.') . '; input.setCustomValidity(status.textContent); return; }'
        . 'status.className = "form-text text-success"; status.textContent = "Available"; input.setCustomValidity("");'
        . '}'
        . 'input.addEventListener("input", check);'
        . '})();</script>';
}

// "Rahim (ID: CUS-0001)" for prints and ledgers; just the name when there is no ID (e.g. walk-in).
function contact_name_with_code($name, $code)
{
    $code = trim((string)$code);

    return $code === '' ? (string)$name : $name . ' (ID: ' . $code . ')';
}

// Makes sure the column exists and every record of the company has an ID before a page shows one.
function contact_code_prepare($conn, $type, $user_id)
{
    ensure_contact_code_columns($conn);
    contact_code_fill_missing($conn, $type, (int)$user_id);
}

// Ledger running balance without a minus sign: a negative balance means they have paid in advance.
if(!function_exists('ledger_balance_display')){
    function ledger_balance_display($balance)
    {
        $balance = round((float)$balance, 2);

        return $balance < 0
            ? number_format(abs($balance), 2) . ' (Advance)'
            : number_format($balance, 2);
    }
}
