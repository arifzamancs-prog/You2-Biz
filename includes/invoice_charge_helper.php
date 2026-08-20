<?php

function ensure_invoice_charge_columns($conn)
{
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
