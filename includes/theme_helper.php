<?php

/*
|----------------------------------
| Theme (colour variant)
|----------------------------------
| Each company admin picks one of the colour themes below. Managers and assistants
| share their company's choice. header.php turns the chosen theme into CSS variables
| that colour the sidebar, buttons, links and other "primary" parts of the pages.
*/

require_once __DIR__ . '/schema_cache_helper.php';

const APP_DEFAULT_THEME = 'blue';

function app_themes()
{
    return [
        'blue' => [
            'label' => 'Standard',
            'sidebar' => '#17202b',
            'accent' => '#2563eb',
            'accent_dark' => '#1d4ed8',
            'light' => false,
            // The default theme keeps the white navbar the software always had.
            'navbar' => false,
        ],
        'green' => [
            'label' => 'Emerald Green',
            'sidebar' => '#0f2a22',
            'accent' => '#059669',
            'accent_dark' => '#047857',
            'light' => false,
        ],
        'purple' => [
            'label' => 'Royal Purple',
            'sidebar' => '#1e1633',
            'accent' => '#7c3aed',
            'accent_dark' => '#6d28d9',
            'light' => false,
        ],
        'orange' => [
            'label' => 'Sunset Orange',
            'sidebar' => '#2a1a12',
            'accent' => '#ea580c',
            'accent_dark' => '#c2410c',
            'light' => false,
        ],
        'rose' => [
            'label' => 'Crimson Rose',
            'sidebar' => '#2b1220',
            'accent' => '#e11d48',
            'accent_dark' => '#be123c',
            'light' => false,
        ],
        'light' => [
            'label' => 'Sky Blue',
            'sidebar' => '#0b2236',
            'accent' => '#0284c7',
            'accent_dark' => '#0369a1',
            'light' => false,
        ],
    ];
}

function ensure_theme_column($conn)
{
    static $checked = false;

    if($checked){
        return;
    }

    $checked = true;

    // Once per login session (see schema_cache_helper.php).
    if(!schema_once('ensure_theme_column')){
        return;
    }

    $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'theme_code'");

    if($result && mysqli_num_rows($result) === 0){
        mysqli_query($conn, "ALTER TABLE users ADD theme_code VARCHAR(20) NOT NULL DEFAULT '" . APP_DEFAULT_THEME . "'");
    }
}

function normalize_theme_code($code)
{
    return array_key_exists((string)$code, app_themes()) ? (string)$code : APP_DEFAULT_THEME;
}

// The company's theme. Managers and assistants share their company's choice.
function company_theme($conn, $company_id)
{
    $company_id = (int)$company_id;

    if($company_id <= 0 || !($conn instanceof mysqli)){
        return APP_DEFAULT_THEME;
    }

    // Reading the theme must never break a page (e.g. right after a database import).
    try{
        ensure_theme_column($conn);
        $stmt = mysqli_prepare($conn, "SELECT theme_code FROM users WHERE id=? LIMIT 1");

        if(!$stmt){
            return APP_DEFAULT_THEME;
        }

        mysqli_stmt_bind_param($stmt, 'i', $company_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    }catch(Throwable $e){
        return APP_DEFAULT_THEME;
    }

    return normalize_theme_code($row['theme_code'] ?? APP_DEFAULT_THEME);
}

function save_company_theme($conn, $company_id, $code)
{
    $code = normalize_theme_code($code);
    $company_id = (int)$company_id;

    try{
        // A save is rare, so the column is checked every time (no session cache).
        $column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'theme_code'");
        if($column && mysqli_num_rows($column) === 0){
            mysqli_query($conn, "ALTER TABLE users ADD theme_code VARCHAR(20) NOT NULL DEFAULT '" . APP_DEFAULT_THEME . "'");
        }

        // Saving must reach a real company row; "success" for a row that is not there hides the problem.
        $check = mysqli_prepare($conn, "SELECT id FROM users WHERE id=? LIMIT 1");
        if(!$check){
            return false;
        }
        mysqli_stmt_bind_param($check, 'i', $company_id);
        mysqli_stmt_execute($check);
        if(!mysqli_fetch_row(mysqli_stmt_get_result($check))){
            return false;
        }

        $stmt = mysqli_prepare($conn, "UPDATE users SET theme_code=? WHERE id=? LIMIT 1");
        if(!$stmt){
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'si', $code, $company_id);

        return mysqli_stmt_execute($stmt);
    }catch(Throwable $e){
        return false;
    }
}

// The theme of the logged-in company; a theme problem must never break a page.
function app_theme()
{
    static $theme = null;

    if($theme !== null){
        return $theme;
    }

    $conn = $GLOBALS['conn'] ?? null;

    if(!($conn instanceof mysqli) || empty($_SESSION['user_id'])){
        return $theme = normalize_theme_code($_SESSION['app_theme'] ?? APP_DEFAULT_THEME);
    }

    try{
        $theme = company_theme($conn, (int)$_SESSION['user_id']);
        $_SESSION['app_theme'] = $theme;
    }catch(Throwable $e){
        $theme = normalize_theme_code($_SESSION['app_theme'] ?? APP_DEFAULT_THEME);
    }

    return $theme;
}

// Whether the theme paints the top navbar in its colour (all but the default).
function app_theme_colours_navbar($code = null)
{
    $themes = app_themes();

    return $themes[normalize_theme_code($code ?? app_theme())]['navbar'] ?? true;
}

function app_theme_hex_rgb($hex)
{
    $hex = ltrim((string)$hex, '#');

    return hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2));
}

// CSS variables (and the light-sidebar text colours) for the chosen theme.
function app_theme_css($code = null)
{
    $themes = app_themes();
    $theme = $themes[normalize_theme_code($code ?? app_theme())];
    $light = !empty($theme['light']);

    $vars = [
        '--app-sidebar-bg' => $theme['sidebar'],
        '--app-accent' => $theme['accent'],
        '--app-accent-dark' => $theme['accent_dark'],
        '--app-accent-rgb' => app_theme_hex_rgb($theme['accent']),
        '--app-sidebar-strong' => $light ? '#1f2937' : '#ffffff',
        '--app-sidebar-text' => $light ? '#374151' : 'rgba(255,255,255,.76)',
        '--app-sidebar-muted' => $light ? '#6b7280' : 'rgba(255,255,255,.46)',
        '--app-sidebar-role' => $light ? '#6b7280' : 'rgba(255,255,255,.58)',
        '--app-sidebar-hover' => $light ? 'rgba(15,23,42,.06)' : 'rgba(255,255,255,.08)',
        '--app-sidebar-line' => $light ? 'rgba(15,23,42,.10)' : 'rgba(255,255,255,.08)',
        '--app-sidebar-danger' => $light ? '#dc2626' : '#ff8f8f',
    ];

    $css = ':root{';
    foreach($vars as $name => $value){
        $css .= $name . ':' . $value . ';';
    }

    return $css . '}';
}
