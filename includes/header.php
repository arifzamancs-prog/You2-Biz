<?php
require_once __DIR__ . '/bill_reminder_helper.php';
// A company with an older unpaid service charge bill sees only the payment page.
if (isset($conn)) bill_reminder_enforce_lock($conn);
// Travel agencies see "Client" wherever the app says "Customer".
require_once __DIR__ . '/travel_agency_helper.php';
if (travel_agency_active() && !is_super_admin_user()) ob_start('travel_client_terms_html');
?>
<!DOCTYPE html>
<html lang="en">

<head>

<?php
require_once __DIR__ . '/branding_helper.php';
require_once __DIR__ . '/installation_reporting_helper.php';
require_once __DIR__ . '/theme_helper.php';
if (isset($conn) && $conn instanceof mysqli) installation_reporting_schedule($conn);

$app_favicon_url = branding_favicon_url(isset($conn) ? $conn : null);
$app_site_title = branding_site_title(isset($conn) ? $conn : null);
$app_root_path = app_root_path();
$app_favicon_path = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . (string)parse_url($app_favicon_url, PHP_URL_PATH);
$app_favicon_version = is_file($app_favicon_path) ? (string)filemtime($app_favicon_path) : '1';
?>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= htmlspecialchars($app_site_title); ?></title>

<link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($app_favicon_url); ?>?v=<?= htmlspecialchars($app_favicon_version); ?>">
<link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars($app_favicon_url); ?>?v=<?= htmlspecialchars($app_favicon_version); ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($app_favicon_url); ?>?v=<?= htmlspecialchars($app_favicon_version); ?>">

<!-- Font Awesome -->
<link rel="stylesheet"
      href="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/fontawesome-free/css/all.min.css'); ?>">

<!-- DataTables -->
<link rel="stylesheet"
      href="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css'); ?>">

<link rel="stylesheet"
      href="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables-responsive/css/responsive.bootstrap4.min.css'); ?>">

<link rel="stylesheet"
      href="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/datatables-buttons/css/buttons.bootstrap4.min.css'); ?>">

<!-- Select2 -->
<link rel="stylesheet"
      href="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/select2/css/select2.min.css'); ?>">

<link rel="stylesheet"
      href="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css'); ?>">

<!-- AdminLTE -->
<link rel="stylesheet"
      href="<?= htmlspecialchars(($app_root_path === '' ? '' : $app_root_path) . '/adminlte/dist/css/adminlte.min.css'); ?>">

<style>
    <?= app_theme_css(); ?>

    .table-responsive{
        margin-bottom:0;
    }

    .table th,
    .table td{
        vertical-align:middle;
    }

    .content-wrapper{
        overflow-x:hidden;
    }

    .app-sidebar{
        background:var(--app-sidebar-bg);
    }

    .app-brand{
        border-bottom:1px solid var(--app-sidebar-line);
        min-height:72px;
        padding:12px 14px;
        white-space:normal;
    }

    .app-brand-image{
        height:42px !important;
        margin-left:0 !important;
        margin-right:12px !important;
        object-fit:cover;
        width:42px !important;
    }

    .app-brand-text{
        display:flex;
        flex-direction:column;
        line-height:1.2;
        min-width:0;
    }

    .app-brand-name{
        color:var(--app-sidebar-strong);
        display:block;
        font-size:15px;
        font-weight:600;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }

    .app-brand-role{
        color:var(--app-sidebar-role);
        display:block;
        font-size:11px;
        font-weight:500;
        letter-spacing:.04em;
        margin-top:3px;
        text-transform:uppercase;
    }

    .app-brand-company-context{
        color:rgba(255,255,255,.74);
        display:flex;
        flex-direction:column;
        font-size:10px;
        font-weight:600;
        line-height:1.25;
        margin-top:3px;
        text-transform:uppercase;
    }

    .app-brand-company-context--inline{
        align-items:center;
        flex-direction:row;
        white-space:nowrap;
    }

    .app-brand-context-divider{
        display:none;
    }

    .app-brand-company-context--inline .app-brand-context-divider{
        color:rgba(255,255,255,.42);
        display:inline;
        margin:0 5px;
    }

    .app-brand-company-type,
    .app-brand-multi-branch{
        flex-shrink:0;
        white-space:nowrap;
    }

    .app-brand-context-divider{
        flex-shrink:0;
    }

    .app-brand-multi-branch{
        color:#7ee2a8;
        margin-top:2px;
    }

    .app-brand-company-context--inline .app-brand-multi-branch{
        margin-top:0;
    }

    .app-sidebar .sidebar{
        padding-left:10px;
        padding-right:10px;
    }

    .app-sidebar .nav-sidebar > .nav-header{
        color:var(--app-sidebar-muted);
        font-size:11px;
        font-weight:700;
        letter-spacing:.08em;
        padding:14px 10px 6px;
    }

    .app-sidebar .nav-sidebar .nav-link{
        border-radius:6px;
        color:var(--app-sidebar-text);
        margin-bottom:2px;
        min-height:40px;
    }

    .app-sidebar .nav-sidebar .nav-link:hover{
        background:var(--app-sidebar-hover);
        color:var(--app-sidebar-strong);
    }

    .app-sidebar .nav-sidebar .nav-link.active{
        background:var(--app-accent);
        color:#fff;
    }

    .app-sidebar .nav-sidebar .nav-icon{
        color:inherit;
        font-size:15px;
        margin-left:2px;
        margin-right:8px;
        text-align:center;
        width:18px;
    }

    .app-sidebar .nav-treeview{
        border-left:1px solid var(--app-sidebar-line);
        margin-left:19px;
        padding-left:5px;
    }

    .app-sidebar .nav-treeview .nav-link{
        font-size:14px;
        margin-left:0;
        min-height:34px;
        padding-bottom:6px;
        padding-top:6px;
    }

    .app-sidebar .nav-treeview .nav-icon{
        font-size:8px;
        margin-right:9px;
        width:12px;
    }

    .app-sidebar .nav-link.text-danger{
        color:var(--app-sidebar-danger) !important;
    }

    /*
    | Colour themes. The Standard (default) theme keeps the look the software always had,
    | so everything below applies only when another theme is chosen (body.app-navbar-colored).
    */
    .app-navbar-colored .app-sidebar.main-sidebar .nav-sidebar .nav-treeview > .nav-item > .nav-link.active,
    .app-navbar-colored .app-sidebar .nav-sidebar .nav-link.active:hover{
        background:var(--app-accent);
        color:#fff;
    }

    .app-navbar-colored .main-header.navbar{
        background:linear-gradient(90deg, var(--app-accent-dark), var(--app-accent));
        border-bottom:0;
    }

    .app-navbar-colored .main-header.navbar .navbar-nav > .nav-item > .nav-link,
    .app-navbar-colored .main-header.navbar .navbar-nav > .nav-item > .nav-link:hover,
    .app-navbar-colored .main-header.navbar .navbar-nav > .nav-item > .nav-link:focus,
    .app-navbar-colored .main-header.navbar .navbar-nav > .show > .nav-link,
    .app-navbar-colored .main-header.navbar .navbar-nav > .nav-item > .nav-link .text-primary{
        color:#fff !important;
    }

    .app-navbar-colored .main-header.navbar .navbar-nav > .nav-item > .nav-link:hover{
        opacity:.85;
    }

    .app-navbar-colored .main-header.navbar .navbar-clock{
        color:rgba(255,255,255,.92);
    }

    .app-navbar-colored .main-header.navbar .navbar-clock-sep{
        color:rgba(255,255,255,.5);
    }

    .app-navbar-colored .main-header.navbar .img-circle{
        border:2px solid rgba(255,255,255,.7);
    }

    /* The branch label in the navbar would vanish on a navbar of its own colour. */
    .app-navbar-colored .main-header.navbar .navbar-nav > .nav-item > .badge-primary{
        background-color:rgba(255,255,255,.18) !important;
        border:1px solid rgba(255,255,255,.55) !important;
        color:#fff !important;
    }

    /* :where() keeps these as weak as Bootstrap's own link colour, so buttons and menus made
       from links keep their colours. */
    :where(.app-navbar-colored) a,
    :where(.app-navbar-colored) .btn-link{
        color:var(--app-accent);
    }

    :where(.app-navbar-colored) a:hover,
    :where(.app-navbar-colored) .btn-link:hover{
        color:var(--app-accent-dark);
    }

    .app-navbar-colored .text-primary{
        color:var(--app-accent) !important;
    }

    .app-navbar-colored .btn-primary,
    .app-navbar-colored .bg-primary,
    .app-navbar-colored .badge-primary,
    .app-navbar-colored .page-item.active .page-link,
    .app-navbar-colored .nav-pills .nav-link.active,
    .app-navbar-colored .custom-control-input:checked ~ .custom-control-label::before,
    .app-navbar-colored .card-primary:not(.card-outline) > .card-header{
        background-color:var(--app-accent) !important;
        border-color:var(--app-accent) !important;
        color:#fff !important;
    }

    .app-navbar-colored .btn-primary:hover,
    .app-navbar-colored .btn-primary:focus,
    .app-navbar-colored .btn-primary:not(:disabled):not(.disabled):active{
        background-color:var(--app-accent-dark) !important;
        border-color:var(--app-accent-dark) !important;
    }

    .app-navbar-colored .btn-primary:focus,
    .app-navbar-colored .form-control:focus,
    .app-navbar-colored .custom-select:focus,
    .app-navbar-colored .custom-control-input:focus ~ .custom-control-label::before{
        box-shadow:0 0 0 .2rem rgba(var(--app-accent-rgb),.25);
    }

    .app-navbar-colored .form-control:focus,
    .app-navbar-colored .custom-select:focus,
    .app-navbar-colored .select2-container--bootstrap4.select2-container--focus .select2-selection{
        border-color:var(--app-accent);
    }

    .app-navbar-colored .btn-outline-primary{
        border-color:var(--app-accent);
        color:var(--app-accent);
    }

    .app-navbar-colored .btn-outline-primary:hover,
    .app-navbar-colored .btn-outline-primary.active{
        background-color:var(--app-accent);
        border-color:var(--app-accent);
        color:#fff;
    }

    .app-navbar-colored .page-link{
        color:var(--app-accent);
    }

    .app-navbar-colored .card-primary.card-outline{
        border-top-color:var(--app-accent);
    }

    .app-navbar-colored .select2-container--bootstrap4 .select2-results__option--highlighted,
    .app-navbar-colored .select2-container--bootstrap4 .select2-results__option--highlighted.select2-results__option[aria-selected=true]{
        background-color:var(--app-accent);
    }

    .sidebar-bottom-break{
        height:36px;
    }

    @media (max-width: 767.98px){
        .content-wrapper > .content,
        .content-wrapper .content{
            padding-left:8px;
            padding-right:8px;
        }

        .card{
            margin-bottom:12px;
        }

        .card-header{
            align-items:flex-start;
            display:flex;
            flex-direction:column;
            gap:8px;
        }

        .card-header::after{
            display:none;
        }

        .card-title{
            float:none;
            line-height:1.3;
            margin:0;
        }

        .card-tools{
            float:none;
            margin-left:0;
            text-align:left;
            width:100%;
        }

        .card-tools .btn{
            margin-bottom:4px;
        }

        .row > [class*="col-"]{
            margin-bottom:12px;
        }

        .form-group{
            margin-bottom:12px;
        }

        .table{
            min-width:680px;
            white-space:nowrap;
        }

        .table td,
        .table th{
            padding:7px;
        }

        .table td .btn{
            margin-bottom:4px;
        }

        .dataTables_wrapper .row{
            margin-left:0;
            margin-right:0;
        }

        .dataTables_length,
        .dataTables_filter,
        .dataTables_info,
        .dataTables_paginate{
            text-align:left !important;
            width:100%;
        }

        .dataTables_filter input{
            display:block;
            margin-left:0 !important;
            margin-top:6px;
            width:100% !important;
        }

        .dataTables_length select{
            width:auto;
        }

        .pagination{
            flex-wrap:wrap;
            justify-content:flex-start;
        }

        .btn-lg{
            font-size:1rem;
            padding:.5rem .75rem;
        }

        .main-footer{
            font-size:12px;
            text-align:center;
        }
    }

    @media (max-width: 575.98px){
        .small-box h3{
            font-size:1.35rem;
        }

        .info-box{
            min-height:74px;
        }

        .info-box-icon{
            width:58px;
        }

        .btn-mobile-block,
        form .btn-lg{
            display:block;
            margin-left:0 !important;
            width:100%;
        }
    }
</style>

</head>

<body class="hold-transition sidebar-mini layout-fixed<?= app_theme_colours_navbar() ? ' app-navbar-colored' : ''; ?>">

<div class="wrapper">
