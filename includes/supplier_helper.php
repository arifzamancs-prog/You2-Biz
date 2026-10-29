<?php

function ensure_supplier_code_column($conn)
{
    $column = mysqli_query($conn, "SHOW COLUMNS FROM suppliers LIKE 'supplier_code'");
    if($column && mysqli_num_rows($column) === 0){
        mysqli_query($conn, "ALTER TABLE suppliers ADD COLUMN supplier_code VARCHAR(100) NULL AFTER user_id");
        mysqli_query($conn, "ALTER TABLE suppliers ADD UNIQUE KEY uniq_supplier_code_per_user (user_id, supplier_code)");
    }
}

/*
| Every company numbers its suppliers. Companies that call them vendors
| (Inventory & Sales, Fashion retail) use VEN-0001, VEN-0002, ...; the others
| use SUP-0001, ... The form suggests the next ID, the user may change it, and
| it must be unique within the company. Suppliers created elsewhere (for
| example from a purchase) and older suppliers without an ID get the next ID.
*/
function supplier_uses_auto_code($conn, $user_id)
{
    return (int)$user_id > 0;
}

// "Vendor ID" or "Supplier ID", following the company's wording.
function supplier_code_label()
{
    return supplier_display_text('Supplier') . ' ID';
}

function supplier_code_prefix()
{
    return supplier_display_text('Supplier') === 'Vendor' ? 'VEN-' : 'SUP-';
}

function supplier_code_exists($conn, $user_id, $code, $exclude_id = 0)
{
    $stmt = mysqli_prepare($conn, 'SELECT id FROM suppliers WHERE user_id=? AND LOWER(supplier_code)=LOWER(?) AND id<>? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'isi', $user_id, $code, $exclude_id);
    mysqli_stmt_execute($stmt);
    $exists = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);

    return $exists;
}

// The next free ID in the VEN-0001 / SUP-0001 series. IDs typed in another style are kept and do not change the series.
function supplier_code_next($conn, $user_id)
{
    $prefix = supplier_code_prefix();
    $like = $prefix . '%';
    $number = 0;
    $digits = 4;
    $stmt = mysqli_prepare($conn, 'SELECT supplier_code FROM suppliers WHERE user_id=? AND supplier_code LIKE ?');
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $like);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while($row = mysqli_fetch_assoc($result)){
        if(preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', (string)$row['supplier_code'], $match) && (int)$match[1] > $number){
            $number = (int)$match[1];
            $digits = max(4, strlen($match[1]));
        }
    }

    mysqli_stmt_close($stmt);

    do{
        $number++;
        $code = $prefix . str_pad((string)$number, $digits, '0', STR_PAD_LEFT);
    }while(supplier_code_exists($conn, $user_id, $code));

    return $code;
}

// '' when the ID can be used, otherwise the reason.
function supplier_code_error($conn, $user_id, $code, $exclude_id = 0)
{
    $code = trim((string)$code);

    $label = supplier_code_label();

    if($code === ''){
        return $label . ' is required.';
    }

    if(mb_strlen($code) > 60){
        return $label . ' must be 60 characters or fewer.';
    }

    if(supplier_code_exists($conn, $user_id, $code, $exclude_id)){
        return $label . ' already exists.';
    }

    return '';
}

// Gives the next ID to one vendor, e.g. a vendor created from a purchase.
function supplier_assign_auto_code($conn, $supplier_id, $user_id)
{
    $code = supplier_code_next($conn, $user_id);
    $stmt = mysqli_prepare($conn, "UPDATE suppliers SET supplier_code=? WHERE id=? AND user_id=? AND (supplier_code IS NULL OR supplier_code='')");
    mysqli_stmt_bind_param($stmt, 'sii', $code, $supplier_id, $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $code;
}

// Every vendor of the company without an ID gets the next ID, oldest first.
function supplier_code_fill_missing($conn, $user_id)
{
    $stmt = mysqli_prepare($conn, "SELECT id FROM suppliers WHERE user_id=? AND (supplier_code IS NULL OR supplier_code='') ORDER BY id ASC");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $ids = array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), 'id');
    mysqli_stmt_close($stmt);

    foreach($ids as $id){
        supplier_assign_auto_code($conn, (int)$id, $user_id);
    }
}

// The company's vendor IDs (lower case) for the live "already exists" check.
function supplier_code_list($conn, $user_id, $exclude_id = 0)
{
    $stmt = mysqli_prepare($conn, "SELECT LOWER(supplier_code) AS code FROM suppliers WHERE user_id=? AND id<>? AND supplier_code IS NOT NULL AND supplier_code<>''");
    mysqli_stmt_bind_param($stmt, 'ii', $user_id, $exclude_id);
    mysqli_stmt_execute($stmt);
    $codes = array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), 'code');
    mysqli_stmt_close($stmt);

    return $codes;
}

// The required Vendor/Supplier ID input with the live availability check.
function supplier_code_field_html($value, $existing_codes)
{
    $label = supplier_code_label();

    return '<div class="form-group">'
        . '<label for="supplier_code">' . htmlspecialchars($label) . ' <span class="text-danger">*</span></label>'
        . '<input type="text" name="supplier_code" id="supplier_code" class="form-control" maxlength="60" required value="' . htmlspecialchars((string)$value) . '">'
        . '<small id="supplier_code_status" class="form-text text-muted">The next free ID is filled in; you can change it.</small>'
        . '</div>'
        . '<script>(function(){'
        . 'var input = document.getElementById("supplier_code"), status = document.getElementById("supplier_code_status");'
        . 'window.supplierCodeExisting = ' . json_encode(array_values($existing_codes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';'
        . 'function check(){'
        . 'var value = input.value.trim().toLowerCase();'
        . 'if(!value){ status.className = "form-text text-danger"; status.textContent = ' . json_encode($label . ' is required.') . '; input.setCustomValidity(status.textContent); return; }'
        . 'if(window.supplierCodeExisting.indexOf(value) !== -1){ status.className = "form-text text-danger"; status.textContent = ' . json_encode($label . ' already exists.') . '; input.setCustomValidity(status.textContent); return; }'
        . 'status.className = "form-text text-success"; status.textContent = "Available"; input.setCustomValidity("");'
        . '}'
        . 'input.addEventListener("input", check);'
        . '})();</script>';
}

// "Rahim (ID: VEN-0001)" for ledgers and prints; just the name when there is no ID.
function supplier_name_with_code($name, $code)
{
    $code = trim((string)$code);

    return $code === '' ? (string)$name : $name . ' (ID: ' . $code . ')';
}

// Backwards-compatible name for existing Restaurant & Cafe callers.
function ensure_restaurant_supplier_code_column($conn)
{
    ensure_supplier_code_column($conn);
}

function supplier_has_transactions($conn, $supplier_id, $user_id)
{
    $supplier_id = (int)$supplier_id;
    $user_id = (int)$user_id;

    if($supplier_id <= 0 || $user_id <= 0){
        return false;
    }

    foreach(['purchases', 'supplier_payments'] as $table){
        $table_result = mysqli_query($conn, "SHOW TABLES LIKE '" . $table . "'");
        if(!$table_result || mysqli_num_rows($table_result) === 0){
            continue;
        }

        $stmt = mysqli_prepare($conn, "SELECT id FROM " . $table . " WHERE supplier_id=? AND user_id=? LIMIT 1");
        if(!$stmt){
            continue;
        }

        mysqli_stmt_bind_param($stmt, 'ii', $supplier_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if($result && mysqli_num_rows($result) > 0){
            return true;
        }
    }

    return false;
}
