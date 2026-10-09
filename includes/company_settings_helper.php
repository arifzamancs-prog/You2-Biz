<?php

function ensure_company_setting_columns($conn)
{
    $columns = [
        'company_type' => "ALTER TABLE users ADD COLUMN company_type VARCHAR(30) NOT NULL DEFAULT 'Housing' AFTER name",
        'currency_code' => "ALTER TABLE users ADD COLUMN currency_code VARCHAR(10) NOT NULL DEFAULT 'BDT'",
        'timezone_name' => "ALTER TABLE users ADD COLUMN timezone_name VARCHAR(64) NOT NULL DEFAULT 'Asia/Dhaka'",
        'date_format' => "ALTER TABLE users ADD COLUMN date_format VARCHAR(20) NOT NULL DEFAULT 'd-m-Y'",
    ];

    foreach ($columns as $column => $alter_sql) {
        $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
        if ($result && mysqli_num_rows($result) === 0) {
            mysqli_query($conn, $alter_sql);
        }
    }
}

function company_type_options()
{
    return [
        'Housing' => 'Housing / Real Estate',
        'Service type' => 'Service Business',
        'Stock Product' => 'Inventory & Sales',
        'Fashion house' => 'Fashion Retail',
        'Restaurant & Cafe' => 'Restaurant & Cafe',
    ];
}

function company_type_label($company_type)
{
    return company_type_options()[normalize_company_type($company_type)];
}

function registration_company_type_options()
{
    return company_type_options();
}

function normalize_company_type($company_type)
{
    $company_type = trim((string)$company_type);
    $key = array_search($company_type, company_type_options(), true);
    if ($key !== false) {
        return $key;
    }
    // Keep existing live databases compatible: the former "Others" value
    // now has the clearer, customer-facing name "Service type".
    if ($company_type === 'Others') {
        return 'Service type';
    }

    return in_array($company_type, ['Housing', 'Service type', 'Stock Product', 'Fashion house'], true)
        ? $company_type
        : 'Housing';
}

function valid_company_type($company_type)
{
    // Accept the legacy submitted value during upgrades; normalization stores
    // it as Service type for all new or updated companies.
    $company_type = trim((string)$company_type);
    return $company_type === 'Others'
        || array_key_exists($company_type, company_type_options())
        || in_array($company_type, company_type_options(), true);
}

function company_type_uses_stock_products($company_type)
{
    return in_array(normalize_company_type($company_type), ['Stock Product', 'Fashion house', 'Restaurant & Cafe'], true);
}

function normalize_company_currency($currency)
{
    $currency = strtoupper(trim((string)$currency));
    $currency = preg_replace('/[^A-Z0-9]/', '', $currency);

    if ($currency === '' || strlen($currency) > 10) {
        return 'BDT';
    }

    return $currency;
}

function company_timezone_options()
{
    return [
        'Asia/Dhaka' => 'Bangladesh (Asia/Dhaka)',
        'UTC' => 'UTC',
        'Asia/Dubai' => 'UAE (Asia/Dubai)',
        'Asia/Riyadh' => 'Saudi Arabia (Asia/Riyadh)',
        'Asia/Kolkata' => 'India (Asia/Kolkata)',
        'Europe/London' => 'United Kingdom (Europe/London)',
        'America/New_York' => 'United States Eastern (America/New_York)',
    ];
}

function normalize_company_timezone($timezone)
{
    $timezone = trim((string)$timezone);

    if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
        return 'Asia/Dhaka';
    }

    return $timezone;
}

function company_date_format_options()
{
    return [
        'Y-m-d' => '2026-07-04',
        'd/m/Y' => '04/07/2026',
        'm/d/Y' => '07/04/2026',
        'd-m-Y' => '04-07-2026',
        'M d, Y' => 'Jul 04, 2026',
    ];
}

function normalize_company_date_format($date_format)
{
    $date_format = trim((string)$date_format);
    $allowed = array_keys(company_date_format_options());

    if (!in_array($date_format, $allowed, true)) {
        return 'd-m-Y';
    }

    return $date_format;
}
