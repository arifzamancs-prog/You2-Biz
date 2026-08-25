<?php

function ensure_staff_table($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        staff_code VARCHAR(30) NULL,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NULL,
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

    $column = mysqli_query($conn, "SHOW COLUMNS FROM staff LIKE 'email'");
    if($column && mysqli_num_rows($column) === 0){
        mysqli_query($conn, "ALTER TABLE staff ADD COLUMN email VARCHAR(150) NULL AFTER name");
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

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS staff_designations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        designation_name VARCHAR(100) NOT NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_staff_designation_per_user (user_id, designation_name),
        INDEX idx_staff_designation_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function staff_code_from_id($staff_id)
{
    return 'STF-' . str_pad((string)(int)$staff_id, 3, '0', STR_PAD_LEFT);
}

function staff_designations($conn, $user_id)
{
    ensure_default_staff_designations($conn, $user_id);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT designation_name
         FROM staff_designations
         WHERE user_id=?
         ORDER BY is_default DESC, designation_name ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $designations = [];
    while($row = mysqli_fetch_assoc($result)){
        $designations[] = $row['designation_name'];
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

function default_staff_designation_names()
{
    return [
        'Accountant',
        'Director',
        'General Manager',
    ];
}

function ensure_default_staff_designations($conn, $user_id)
{
    $defaults = default_staff_designation_names();
    foreach($defaults as $designation){
        $stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO staff_designations (user_id, designation_name, is_default)
             VALUES (?, ?, 1)"
        );
        mysqli_stmt_bind_param($stmt, 'is', $user_id, $designation);
        mysqli_stmt_execute($stmt);
    }

    $reset_stmt = mysqli_prepare(
        $conn,
        "UPDATE staff_designations
         SET is_default=0
         WHERE user_id=?
         AND designation_name NOT IN ('Accountant', 'Director', 'General Manager')"
    );
    mysqli_stmt_bind_param($reset_stmt, 'i', $user_id);
    mysqli_stmt_execute($reset_stmt);

    $legacy_stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT TRIM(designation) AS designation
         FROM staff
         WHERE user_id=?
         AND designation IS NOT NULL
         AND TRIM(designation)<>''
         ORDER BY designation ASC"
    );
    mysqli_stmt_bind_param($legacy_stmt, 'i', $user_id);
    mysqli_stmt_execute($legacy_stmt);
    $legacy_result = mysqli_stmt_get_result($legacy_stmt);

    while($legacy_result && $row = mysqli_fetch_assoc($legacy_result)){
        $designation = trim((string)$row['designation']);
        if($designation === ''){
            continue;
        }

        $is_default = in_array($designation, $defaults, true) ? 1 : 0;
        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO staff_designations (user_id, designation_name, is_default)
             VALUES (?, ?, ?)"
        );
        mysqli_stmt_bind_param($insert_stmt, 'isi', $user_id, $designation, $is_default);
        mysqli_stmt_execute($insert_stmt);
    }
}

function create_staff_designation($conn, $user_id, $designation)
{
    $designation = mb_substr(trim((string)$designation), 0, 100);
    if($designation === ''){
        return '';
    }

    $is_default = in_array($designation, default_staff_designation_names(), true) ? 1 : 0;
    $stmt = mysqli_prepare(
        $conn,
        "INSERT IGNORE INTO staff_designations (user_id, designation_name, is_default)
         VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'isi', $user_id, $designation, $is_default);
    mysqli_stmt_execute($stmt);

    return $designation;
}

function staff_designation_rows($conn, $user_id)
{
    ensure_default_staff_designations($conn, $user_id);

    $rows = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, designation_name, is_default
         FROM staff_designations
         WHERE user_id=?
         ORDER BY is_default DESC, designation_name ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while($result && $row = mysqli_fetch_assoc($result)){
        $row['can_delete'] = !((int)$row['is_default'] === 1) &&
            !staff_designation_in_use($conn, $user_id, $row['designation_name']);
        $rows[] = $row;
    }

    return $rows;
}

function staff_designation_in_use($conn, $user_id, $designation)
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM staff
         WHERE user_id=?
         AND designation=?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $designation);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    return $result && mysqli_num_rows($result) > 0;
}

function delete_staff_designation($conn, $user_id, $designation_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT designation_name, is_default
         FROM staff_designations
         WHERE id=?
         AND user_id=?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $designation_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    if(!$row || (int)$row['is_default'] === 1){
        return false;
    }

    if(staff_designation_in_use($conn, $user_id, $row['designation_name'])){
        return false;
    }

    $delete_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM staff_designations
         WHERE id=?
         AND user_id=?"
    );
    mysqli_stmt_bind_param($delete_stmt, 'ii', $designation_id, $user_id);
    return mysqli_stmt_execute($delete_stmt);
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
