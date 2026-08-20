<?php

function ensure_staff_table($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        staff_code VARCHAR(30) NULL,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(30) NULL,
        address VARCHAR(255) NULL,
        designation VARCHAR(100) NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_staff_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $column = mysqli_query($conn, "SHOW COLUMNS FROM staff LIKE 'address'");
    if($column && mysqli_num_rows($column) === 0){
        mysqli_query($conn, "ALTER TABLE staff ADD COLUMN address VARCHAR(255) NULL AFTER phone");
    }

    $column = mysqli_query($conn, "SHOW COLUMNS FROM staff LIKE 'staff_code'");
    if($column && mysqli_num_rows($column) === 0){
        mysqli_query($conn, "ALTER TABLE staff ADD COLUMN staff_code VARCHAR(30) NULL AFTER user_id");
    }

    mysqli_query($conn, "UPDATE staff SET staff_code=CONCAT('STF-', LPAD(id, 3, '0'))");
    $index = mysqli_query($conn, "SHOW INDEX FROM staff WHERE Key_name='uniq_staff_code'");
    if($index && mysqli_num_rows($index) === 0){
        mysqli_query($conn, "ALTER TABLE staff ADD UNIQUE INDEX uniq_staff_code (staff_code)");
    }
}

function staff_code_from_id($staff_id)
{
    return 'STF-' . str_pad((string)(int)$staff_id, 3, '0', STR_PAD_LEFT);
}

function staff_designations($conn, $user_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT TRIM(designation) AS designation
         FROM staff
         WHERE user_id=?
         AND designation IS NOT NULL
         AND TRIM(designation)<>''
         ORDER BY designation ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $designations = [];
    while($row = mysqli_fetch_assoc($result)){
        $designations[] = $row['designation'];
    }
    return $designations;
}

function staff_submitted_designation()
{
    $designation = trim($_POST['designation'] ?? '');
    if($designation === '__new__'){
        $designation = trim($_POST['new_designation'] ?? '');
    }
    return mb_substr($designation, 0, 100);
}

function staff_has_transactions($conn, $staff_id)
{
    foreach(['invoices','money_ins','expenses','transfers'] as $table){
        $column = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE 'staff_id'");
        if($column && mysqli_num_rows($column) > 0){
            $stmt = mysqli_prepare($conn, "SELECT id FROM `{$table}` WHERE staff_id=? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'i', $staff_id);
            mysqli_stmt_execute($stmt);
            if(mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0){ return true; }
        }
    }
    return false;
}
