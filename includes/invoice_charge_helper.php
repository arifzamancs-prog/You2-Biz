<?php

require_once __DIR__ . '/schema_cache_helper.php';

function ensure_invoice_charge_columns($conn)
{
    if(!schema_once('ensure_invoice_charge_columns')) return;
    static $checked = false;

    if($checked){
        return;
    }

    $checked = true;

    $show_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM invoice_charge_types LIKE 'show_on_invoice'"
    );

    if(!$show_result || mysqli_num_rows($show_result) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE invoice_charge_types
             ADD show_on_invoice TINYINT(1) NOT NULL DEFAULT 0"
        );
    }

    $value_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM invoice_charge_types LIKE 'charge_value_type'"
    );

    if(!$value_result || mysqli_num_rows($value_result) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE invoice_charge_types
             ADD charge_value_type VARCHAR(20) NOT NULL DEFAULT 'fixed'"
        );
    }

    $default_value_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM invoice_charge_types LIKE 'default_value'"
    );

    if(!$default_value_result || mysqli_num_rows($default_value_result) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE invoice_charge_types
             ADD default_value DECIMAL(15,2) NOT NULL DEFAULT 0.00"
        );
    }

    // 'invoice' = one amount on the invoice total; 'product' = a column on every product line.
    $apply_on_result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM invoice_charge_types LIKE 'apply_on'"
    );

    if(!$apply_on_result || mysqli_num_rows($apply_on_result) === 0){
        mysqli_query(
            $conn,
            "ALTER TABLE invoice_charge_types
             ADD apply_on VARCHAR(20) NOT NULL DEFAULT 'invoice'"
        );
    }

    // Per-line breakdown of product charges. invoice_items.unit_price keeps the
    // final (charged) price, so stock, returns and reports need no change.
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS invoice_item_charges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            invoice_item_id INT NOT NULL,
            charge_type_id INT NOT NULL,
            charge_type VARCHAR(10) NOT NULL DEFAULT 'add',
            charge_value_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
            input_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            base_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            amount_per_unit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            KEY idx_item_charge_item (invoice_item_id),
            KEY idx_item_charge_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function normalize_charge_apply_on($apply_on)
{
    return $apply_on === 'product' ? 'product' : 'invoice';
}

// SQL condition for charges applied to the whole invoice (the original behaviour).
function invoice_level_charge_sql($alias = '')
{
    return " AND COALESCE(" . ($alias === '' ? '' : $alias . '.') . "apply_on,'invoice')='invoice'";
}

// Active, shown product charges, plus charges already saved on the given invoice
// (so editing keeps them even if they were hidden later).
function invoice_product_charge_types($conn, $user_id, $invoice_id = 0)
{
    ensure_invoice_charge_columns($conn);
    $user_id = (int)$user_id;
    $invoice_id = (int)$invoice_id;

    $sql = "SELECT id, charge_name, charge_type, charge_value_type, default_value
            FROM invoice_charge_types
            WHERE user_id={$user_id}
            AND apply_on='product'
            AND (
                (status='active' AND show_on_invoice=1)";
    if($invoice_id > 0){
        $sql .= " OR id IN (
                    SELECT iic.charge_type_id
                    FROM invoice_item_charges iic
                    INNER JOIN invoice_items ii ON ii.id=iic.invoice_item_id
                    WHERE ii.invoice_id={$invoice_id} AND iic.user_id={$user_id}
                )";
    }
    $sql .= ") ORDER BY charge_name";

    $charges = [];
    $result = mysqli_query($conn, $sql);
    while($result && ($row = mysqli_fetch_assoc($result))){
        $row['charge_type'] = normalize_charge_type($row['charge_type']);
        $row['charge_value_type'] = normalize_charge_value_type($row['charge_value_type']);
        $charges[(int)$row['id']] = $row;
    }

    return $charges;
}

// Applies posted product charges (inputs[charge_id][row] = value) to each line's base price.
// $prices is replaced with the final unit prices; returns [row => breakdown lines].
function invoice_apply_product_charges($charges, $product_ids, &$prices, $inputs)
{
    $breakdown = [];
    $inputs = is_array($inputs) ? $inputs : [];

    foreach($product_ids as $key => $product_id){
        if(empty($product_id) || !$charges){
            continue;
        }

        $base_price = round((float)($prices[$key] ?? 0), 2);
        $final_price = $base_price;
        $lines = [];

        foreach($charges as $charge_id => $charge){
            $input = (float)($inputs[$charge_id][$key] ?? 0);
            if(!is_finite($input) || $input < 0){
                throw new RuntimeException($charge['charge_name'] . ' cannot be negative.');
            }
            if($input == 0){
                continue;
            }
            if($charge['charge_value_type'] === 'percent' && $input > 100){
                throw new RuntimeException($charge['charge_name'] . ' percentage cannot be more than 100.');
            }

            $per_unit = $charge['charge_value_type'] === 'percent'
                ? round($base_price * $input / 100, 2)
                : round($input, 2);
            $final_price += $charge['charge_type'] === 'less' ? -$per_unit : $per_unit;

            $lines[] = [
                'charge_type_id' => (int)$charge_id,
                'charge_type' => $charge['charge_type'],
                'charge_value_type' => $charge['charge_value_type'],
                'input_value' => round($input, 2),
                'base_price' => $base_price,
                'amount_per_unit' => $per_unit,
            ];
        }

        $final_price = round($final_price, 2);
        if($final_price < 0){
            throw new RuntimeException('Product charges cannot make a price negative.');
        }

        $prices[$key] = $final_price;
        if($lines){
            $breakdown[$key] = $lines;
        }
    }

    return $breakdown;
}

function invoice_save_item_charges($conn, $user_id, $invoice_item_id, $lines)
{
    foreach($lines as $line){
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO invoice_item_charges
             (user_id, invoice_item_id, charge_type_id, charge_type, charge_value_type, input_value, base_price, amount_per_unit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'iiissddd',
            $user_id,
            $invoice_item_id,
            $line['charge_type_id'],
            $line['charge_type'],
            $line['charge_value_type'],
            $line['input_value'],
            $line['base_price'],
            $line['amount_per_unit']
        );
        if(!mysqli_stmt_execute($stmt)){
            throw new Exception(mysqli_stmt_error($stmt));
        }
    }
}

function invoice_delete_item_charges($conn, $user_id, $invoice_id)
{
    ensure_invoice_charge_columns($conn);
    $stmt = mysqli_prepare(
        $conn,
        "DELETE iic FROM invoice_item_charges iic
         INNER JOIN invoice_items ii ON ii.id=iic.invoice_item_id
         WHERE ii.invoice_id=? AND iic.user_id=?"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $invoice_id, $user_id);
    mysqli_stmt_execute($stmt);
}

// For the edit form: [invoice_item_id => ['base_price' => x, 'inputs' => [charge_id => value]]].
function invoice_item_charge_inputs($conn, $user_id, $invoice_id)
{
    ensure_invoice_charge_columns($conn);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT iic.invoice_item_id, iic.charge_type_id, iic.input_value, iic.base_price
         FROM invoice_item_charges iic
         INNER JOIN invoice_items ii ON ii.id=iic.invoice_item_id
         WHERE ii.invoice_id=? AND iic.user_id=?"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $invoice_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $map = [];
    while($row = mysqli_fetch_assoc($result)){
        $item_id = (int)$row['invoice_item_id'];
        $map[$item_id]['base_price'] = (float)$row['base_price'];
        $map[$item_id]['inputs'][(int)$row['charge_type_id']] = (float)$row['input_value'];
    }

    return $map;
}

function default_invoice_charges()
{
    return [
        ['Discount', 'less'],
        ['Labour', 'add'],
        ['Transport', 'add'],
        ['VAT', 'add'],
    ];
}

function ensure_default_invoice_charges($conn, $user_id)
{
    ensure_invoice_charge_columns($conn);

    $user_id = (int)$user_id;

    foreach(default_invoice_charges() as $charge){
        $charge_name = $charge[0];
        $charge_type = $charge[1];

        $check_sql = "SELECT id
                      FROM invoice_charge_types
                      WHERE user_id=?
                      AND charge_name=?
                      LIMIT 1";

        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "is", $user_id, $charge_name);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if(mysqli_num_rows($check_result) > 0){
            continue;
        }

        $insert_sql = "INSERT INTO invoice_charge_types
                       (
                           user_id,
                           charge_name,
                           charge_type,
                           charge_value_type,
                           show_on_invoice,
                           status
                       )
                       VALUES
                       (
                           ?,
                           ?,
                           ?,
                           'fixed',
                           0,
                           'active'
                       )";

        $insert_stmt = mysqli_prepare($conn, $insert_sql);
        mysqli_stmt_bind_param(
            $insert_stmt,
            "iss",
            $user_id,
            $charge_name,
            $charge_type
        );
        mysqli_stmt_execute($insert_stmt);
    }
}

function ensure_all_admin_invoice_charges($conn)
{
    ensure_invoice_charge_columns($conn);

    $result = mysqli_query(
        $conn,
        "SELECT id
         FROM users
         WHERE role='admin'"
    );

    while($row = mysqli_fetch_assoc($result)){
        ensure_default_invoice_charges($conn, (int)$row['id']);
    }
}

function normalize_charge_type($charge_type)
{
    return $charge_type === 'less' ? 'less' : 'add';
}

function normalize_charge_value_type($value_type)
{
    return $value_type === 'percent' ? 'percent' : 'fixed';
}

function calculate_invoice_charge_amount($input_amount, $value_type, $subtotal)
{
    $input_amount = (float)$input_amount;

    if(normalize_charge_value_type($value_type) === 'percent'){
        return ($subtotal * $input_amount) / 100;
    }

    return $input_amount;
}
