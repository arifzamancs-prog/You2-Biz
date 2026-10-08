<?php

function ensure_eshop_table($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS eshop_settings (
        company_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        slug VARCHAR(64) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_eshop_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function eshop_valid_slug($slug)
{
    return is_string($slug) && preg_match('/\A[a-z0-9][a-z0-9-]{1,62}[a-z0-9]\z/D', $slug) === 1;
}

function eshop_company_settings($conn, $company_id)
{
    // Existing pages remain usable before the optional module is provisioned.
    static $available = null;
    if($available === null){
        $result = mysqli_query($conn, "SHOW TABLES LIKE 'eshop_settings'");
        $available = $result && mysqli_num_rows($result) > 0;
    }
    if(!$available) return null;
    $stmt = mysqli_prepare($conn, 'SELECT company_id, slug, enabled FROM eshop_settings WHERE company_id=?');
    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

function eshop_reserved_url($slug)
{
    return app_url('shop/' . rawurlencode($slug));
}
