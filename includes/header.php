<!DOCTYPE html>
<html lang="en">

<head>

<?php
require_once __DIR__ . '/branding_helper.php';

$app_favicon_url = branding_favicon_url(isset($conn) ? $conn : null);
$app_root_path = app_root_path();
$app_favicon_path = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . (string)parse_url($app_favicon_url, PHP_URL_PATH);
$app_favicon_version = is_file($app_favicon_path) ? (string)filemtime($app_favicon_path) : '1';
?>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>You2 Biz</title>

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
        background:#17202b;
    }

    .app-brand{
        border-bottom:1px solid rgba(255,255,255,.08);
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
        color:#fff;
        display:block;
        font-size:15px;
        font-weight:600;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }

    .app-brand-role{
        color:rgba(255,255,255,.58);
        display:block;
        font-size:11px;
        font-weight:500;
        letter-spacing:.04em;
        margin-top:3px;
        text-transform:uppercase;
    }

    .app-sidebar .sidebar{
        padding-left:10px;
        padding-right:10px;
    }

    .app-sidebar .nav-sidebar > .nav-header{
        color:rgba(255,255,255,.46);
        font-size:11px;
        font-weight:700;
        letter-spacing:.08em;
        padding:14px 10px 6px;
    }

    .app-sidebar .nav-sidebar .nav-link{
        border-radius:6px;
        color:rgba(255,255,255,.76);
        margin-bottom:2px;
        min-height:40px;
    }

    .app-sidebar .nav-sidebar .nav-link:hover{
        background:rgba(255,255,255,.08);
        color:#fff;
    }

    .app-sidebar .nav-sidebar .nav-link.active{
        background:#2563eb;
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
        border-left:1px solid rgba(255,255,255,.08);
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
        color:#ff8f8f !important;
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

<body class="hold-transition sidebar-mini layout-fixed">

<div class="wrapper">
