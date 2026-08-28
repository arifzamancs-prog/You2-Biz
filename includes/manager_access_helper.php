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
        'access_permissions' => "ALTER TABLE users ADD COLUMN access_permissions TEXT NULL",
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

function available_manager_permissions()
{
    return [
        'dashboard' => 'Company Dashboard',
        'staff' => 'Staff Manage',
        'sales' => 'Sales',
        'wallets' => 'Wallets',
        'projects' => 'Project & Package',
        'customers' => 'Customer Manage',
        'leads' => 'Lead Management',
        'suppliers' => 'Supplier',
        'admin' => 'Admin',
        'notice_publish' => 'Notice Publish',
    ];
}

function normalize_manager_permissions($permissions)
{
    $permissions = is_array($permissions) ? $permissions : [];
    return array_values(array_intersect(array_keys(available_manager_permissions()), $permissions));
}

function normalize_manager_type($manager_type)
{
    return $manager_type === 'agent' ? 'agent' : 'manager';
}
