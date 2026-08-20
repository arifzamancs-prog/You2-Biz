<?php
if(session_status() === PHP_SESSION_NONE){
    session_start();
}

require_once __DIR__ . '/includes/app_config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/branding_helper.php';

$site_logo = branding_logo_url($conn);
$site_favicon = branding_favicon_url($conn);
$trial_error = isset($_GET['trial']) && $_GET['trial'] === 'error'
    ? (string)($_SESSION['trial_lead_error'] ?? 'Please try again.')
    : '';

unset($_SESSION['trial_lead_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<title>Start Free Trial - You2 Biz</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($site_favicon); ?>">
    <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars($site_favicon); ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_path('adminlte/plugins/fontawesome-free/css/all.min.css')); ?>">
    <style>
        *{box-sizing:border-box}
        body{
            margin:0;
            font-family:Arial,Helvetica,sans-serif;
            background:#edf5ff;
            color:#13243d;
        }
        .page{
            min-height:100vh;
            padding:28px 16px 40px;
        }
        .shell{
            max-width:520px;
            margin:0 auto;
        }
        .topbar{
            display:flex;
            align-items:center;
            justify-content:center;
            gap:16px;
            margin-bottom:24px;
        }
        .brand{
            display:flex;
            align-items:center;
            gap:12px;
            text-decoration:none;
            color:#13243d;
            font-size:24px;
            font-weight:700;
        }
        .brand img{
            width:48px;
            height:48px;
            object-fit:contain;
            border-radius:12px;
            background:#fff;
            padding:4px;
            box-shadow:0 10px 24px rgba(37,99,235,.12);
        }
        .panel{
            background:#fff;
            border:1px solid rgba(37,99,235,.08);
            border-radius:24px;
            padding:28px 24px;
            box-shadow:0 24px 60px rgba(37,99,235,.12);
            position:relative;
            overflow:hidden;
        }
        .panel::before{
            content:"";
            position:absolute;
            inset:0 0 auto 0;
            height:6px;
            background:linear-gradient(90deg,#2563eb,#38bdf8);
        }
        .tag{
            display:inline-flex;
            align-items:center;
            gap:8px;
            background:#dbeafe;
            color:#1d4ed8;
            border-radius:999px;
            padding:8px 14px;
            font-size:13px;
            font-weight:700;
            text-transform:uppercase;
        }
        h1{
            margin:18px 0 12px;
            font-size:31px;
            line-height:1.15;
            text-align:center;
        }
        .lead{
            margin:0 auto 24px;
            color:#51627a;
            font-size:16px;
            line-height:1.65;
            text-align:center;
            max-width:380px;
        }
        .alert{
            border-radius:14px;
            padding:14px 16px;
            margin-bottom:18px;
            font-size:14px;
            line-height:1.5;
        }
        .alert-danger{
            background:#fee2e2;
            color:#b91c1c;
            border:1px solid #fecaca;
        }
        .field{
            margin-bottom:16px;
        }
        .field label{
            display:block;
            margin-bottom:8px;
            font-size:14px;
            font-weight:700;
            color:#29415f;
        }
        .field input{
            width:100%;
            border:1px solid #cfe0fb;
            border-radius:14px;
            padding:13px 15px;
            font-size:15px;
            color:#13243d;
            background:#fff;
        }
        .field input:focus{
            outline:none;
            border-color:#2563eb;
            box-shadow:0 0 0 3px rgba(37,99,235,.12);
        }
        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            width:100%;
            border:none;
            border-radius:14px;
            padding:13px 18px;
            background:#2563eb;
            color:#fff;
            font-size:15px;
            font-weight:700;
            cursor:pointer;
            box-shadow:0 14px 30px rgba(37,99,235,.18);
        }
        .note{
            margin-top:16px;
            color:#697990;
            font-size:13px;
            line-height:1.6;
            text-align:center;
        }
        .hotline{
            margin-top:18px;
            color:#4f627d;
            font-size:14px;
            text-align:center;
        }
        @media (max-width: 991px){
            h1{
                font-size:29px;
            }
        }
        @media (max-width: 575px){
            .page{
                padding:16px 10px 28px;
            }
            .topbar{
                margin-bottom:14px;
            }
            .panel{
                padding:22px 16px;
                border-radius:18px;
            }
            .brand{
                font-size:20px;
            }
            h1{
                font-size:25px;
            }
            .lead{
                font-size:15px;
                margin-bottom:20px;
                max-width:100%;
            }
            .field{
                margin-bottom:14px;
            }
            .field input{
                padding:12px 14px;
                font-size:14px;
            }
            .btn{
                padding:12px 16px;
                font-size:14px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="shell">
            <div class="topbar">
                <a class="brand" href="<?= htmlspecialchars(app_path('trial-offer/')); ?>">
<img src="<?= htmlspecialchars($site_logo); ?>" alt="You2 Biz">
<span>You2 Biz</span>
                </a>
            </div>

            <div class="panel">
                <div style="text-align:center;">
                    <span class="tag">
                        <i class="fas fa-gift"></i>
                        Free Trial Offer
                    </span>
                </div>

                <h1>Start Free Trial</h1>

                <p class="lead">
                    For free trial, please fill up the form and enjoy!
                </p>

                <?php if($trial_error !== ''){ ?>
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($trial_error); ?>
                    </div>
                <?php } ?>

                <form method="post" action="<?= htmlspecialchars(app_path('trial_lead_submit.php')); ?>">
                    <input type="hidden" name="landing_page" value="<?= htmlspecialchars(app_base_url() . '/trial-offer'); ?>">
                    <input type="hidden" name="utm_source" value="<?= htmlspecialchars($_GET['utm_source'] ?? ''); ?>">
                    <input type="hidden" name="utm_medium" value="<?= htmlspecialchars($_GET['utm_medium'] ?? ''); ?>">
                    <input type="hidden" name="utm_campaign" value="<?= htmlspecialchars($_GET['utm_campaign'] ?? ''); ?>">
                    <input type="hidden" name="utm_content" value="<?= htmlspecialchars($_GET['utm_content'] ?? ''); ?>">
                    <input type="hidden" name="utm_term" value="<?= htmlspecialchars($_GET['utm_term'] ?? ''); ?>">
                    <input type="hidden" name="fbclid" value="<?= htmlspecialchars($_GET['fbclid'] ?? ''); ?>">

                    <div class="field">
                        <label for="full_name">Name</label>
                        <input id="full_name" type="text" name="full_name" placeholder="Enter your name" required>
                    </div>

                    <div class="field">
                        <label for="phone">Phone</label>
                        <input id="phone" type="text" name="phone" placeholder="Enter your phone number" required>
                    </div>

                    <div class="field">
                        <label for="business_name">Business Name</label>
                        <input id="business_name" type="text" name="business_name" placeholder="Enter your business name" required>
                    </div>

                    <button type="submit" class="btn">
                        <i class="fas fa-paper-plane"></i>
                        Submit For Free Trial
                    </button>
                </form>

                <div class="note">
After submit, you will be redirected to the main You2 Biz page.
                </div>

                <div class="hotline">
                    Hotline: +8801977592783
                </div>
            </div>
        </div>
    </div>
</body>
</html>
