<?php

function ensure_company_setting_columns($conn)
{
    $columns = [
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
