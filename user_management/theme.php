<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/theme_helper.php';

// Only the company admin picks the theme. Super Admin has no company row to keep one in.
if(!is_admin_user() || is_super_admin_user()){
    header("Location: " . app_path('dashboard.php?error=Permission denied'));
    exit;
}

$company_id = (int)$_SESSION['user_id'];
$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(save_company_theme($conn, $company_id, $_POST['theme_code'] ?? APP_DEFAULT_THEME)){
        $_SESSION['app_theme'] = normalize_theme_code($_POST['theme_code'] ?? APP_DEFAULT_THEME);
        $_SESSION['theme_flash'] = 'Theme updated successfully.';
        // Reload so this page is shown in the new theme.
        header("Location: " . app_path('user_management/theme.php'));
        exit;
    }

    $message = 'Theme could not be updated. Please log out and log in again, then try once more.';
    $message_type = 'danger';
}

if(!empty($_SESSION['theme_flash'])){
    $message = $_SESSION['theme_flash'];
    $message_type = 'success';
    unset($_SESSION['theme_flash']);
}

$current_theme = company_theme($conn, $company_id);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<style>
    .theme-option{
        cursor:pointer;
        display:block;
        margin:0;
    }

    .theme-option input{
        position:absolute;
        opacity:0;
    }

    .theme-card{
        border:2px solid #dee2e6;
        border-radius:10px;
        overflow:hidden;
        transition:border-color .15s, box-shadow .15s;
    }

    .theme-option:hover .theme-card{
        border-color:#adb5bd;
    }

    .theme-option input:checked + .theme-card{
        border-color:var(--theme-accent);
        box-shadow:0 0 0 3px var(--theme-ring);
    }

    .theme-option input:focus-visible + .theme-card{
        outline:2px solid var(--theme-accent);
        outline-offset:2px;
    }

    .theme-preview{
        background:#f4f6f9;
        display:flex;
        height:110px;
    }

    .theme-preview-side{
        background:var(--theme-sidebar);
        border-right:1px solid rgba(0,0,0,.08);
        padding:10px 8px;
        width:34%;
    }

    .theme-preview-side span{
        background:var(--theme-line);
        border-radius:3px;
        display:block;
        height:7px;
        margin-bottom:7px;
    }

    .theme-preview-side span.is-active{
        background:var(--theme-accent);
        height:10px;
    }

    .theme-preview-main{
        flex:1;
        padding:0;
    }

    .theme-preview-bar{
        background:var(--theme-navbar);
        border-bottom:1px solid rgba(0,0,0,.06);
        height:20px;
    }

    .theme-preview-body{
        padding:10px;
    }

    .theme-preview-body span{
        background:#fff;
        border-radius:4px;
        display:block;
        height:30px;
        margin-bottom:8px;
    }

    .theme-preview-btn{
        background:var(--theme-accent);
        border-radius:4px;
        display:inline-block;
        height:14px;
        width:50px;
    }

    .theme-card-footer{
        align-items:center;
        display:flex;
        font-weight:600;
        justify-content:space-between;
        padding:10px 12px;
    }

    .theme-check{
        color:var(--theme-accent);
        visibility:hidden;
    }

    .theme-option input:checked + .theme-card .theme-check{
        visibility:visible;
    }
</style>

<div class="row">
    <div class="col-lg-10">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Theme Settings</h3>
            </div>

            <div class="card-body">
                <?php if($message){ ?>
                    <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                        <?= htmlspecialchars($message); ?>
                    </div>
                <?php } ?>

                <form method="post">
                    <label>Software Theme</label>

                    <div class="row">
                        <?php foreach(app_themes() as $theme_code => $theme){ ?>
                            <?php
                            $theme_style = '--theme-sidebar:' . $theme['sidebar']
                                . ';--theme-accent:' . $theme['accent']
                                . ';--theme-ring:rgba(' . app_theme_hex_rgb($theme['accent']) . ',.25)'
                                . ';--theme-navbar:' . (app_theme_colours_navbar($theme_code) ? $theme['accent'] : '#ffffff')
                                . ';--theme-line:' . (!empty($theme['light']) ? 'rgba(15,23,42,.14)' : 'rgba(255,255,255,.22)');
                            ?>
                            <div class="col-sm-6 col-md-4 mb-3">
                                <label class="theme-option" style="<?= htmlspecialchars($theme_style); ?>">
                                    <input
                                        type="radio"
                                        name="theme_code"
                                        value="<?= htmlspecialchars($theme_code); ?>"
                                        <?= $current_theme === $theme_code ? 'checked' : ''; ?>>
                                    <div class="theme-card">
                                        <div class="theme-preview" aria-hidden="true">
                                            <div class="theme-preview-side">
                                                <span></span>
                                                <span class="is-active"></span>
                                                <span></span>
                                                <span></span>
                                            </div>
                                            <div class="theme-preview-main">
                                                <div class="theme-preview-bar"></div>
                                                <div class="theme-preview-body">
                                                    <span></span>
                                                    <i class="theme-preview-btn"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="theme-card-footer">
                                            <span><?= htmlspecialchars($theme['label']); ?><?php if($theme_code === APP_DEFAULT_THEME){ ?> <small class="text-muted">(Default)</small><?php } ?></span>
                                            <i class="fas fa-check-circle theme-check"></i>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        <?php } ?>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Theme
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
