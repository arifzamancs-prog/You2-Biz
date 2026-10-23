<?php

/**
 * Restrict an operational report to its company owner. Super Admin reports
 * intentionally cover every company account and never include legacy rows
 * that have no company owner (for example, user_id 0).
 */
function report_owner_scope_sql($table_alias, $company_id = null)
{
    $column = $table_alias === '' ? 'user_id' : $table_alias . '.user_id';

    if (is_super_admin_user()) {
        return " AND EXISTS (SELECT 1 FROM users report_company WHERE report_company.id={$column} AND report_company.role='admin')";
    }

    if ($company_id === null) {
        $company_id = (int)($_SESSION['user_id'] ?? 0);
    }

    return ' AND ' . $column . '=' . (int)$company_id;
}

