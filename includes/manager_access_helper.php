<?php

function ensure_manager_access_columns($conn)
{
    static $checked = false;

    if($checked){
        return;
    }

    $checked = true;

    $columns = [
        'manager_type' => "ALTER TABLE users ADD COLUMN manager_type VARCHAR(20) NOT NULL DEFAULT 'manager'",
        'max_managers' => "ALTER TABLE users ADD COLUMN max_managers INT NOT NULL DEFAULT 2",
        'staff_id' => "ALTER TABLE users ADD COLUMN staff_id BIGINT UNSIGNED NULL",
    ];

    foreach($columns as $column => $sql){
        $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . mysqli_real_escape_string($conn, $column) . "'");

        if($result && mysqli_num_rows($result) === 0){
            mysqli_query($conn, $sql);
        }
    }

    $index = mysqli_query($conn, "SHOW INDEX FROM users WHERE Key_name='idx_users_staff_id'");
    if($index && mysqli_num_rows($index) === 0){
        mysqli_query($conn, "ALTER TABLE users ADD INDEX idx_users_staff_id (staff_id)");
    }
}

function normalize_manager_type($manager_type)
{
    return $manager_type === 'agent' ? 'agent' : 'manager';
}
