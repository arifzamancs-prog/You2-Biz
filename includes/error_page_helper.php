<?php

require_once __DIR__ . '/app_config.php';

function render_app_error_page($title, $message, $back_url = '')
{
    http_response_code(400);

    $safe_title = htmlspecialchars($title ?: 'Could Not Complete Request');
    $safe_message = htmlspecialchars($message ?: 'Something went wrong. Please try again.');
    $safe_back_url = htmlspecialchars($back_url ?: 'javascript:history.back()');

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $safe_title . '</title>
    <style>
        :root{
            --bg:#eef5ff;
            --card:#ffffff;
            --text:#0f172a;
            --muted:#64748b;
            --border:#dbe7ff;
            --danger:#dc3545;
            --primary:#2563eb;
            --primary-dark:#1d4ed8;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
            font-family:Arial,Helvetica,sans-serif;
            background:linear-gradient(180deg,#eaf3ff 0%,#f8fbff 100%);
            color:var(--text);
        }
        .error-card{
            width:min(100%,540px);
            background:var(--card);
            border:1px solid var(--border);
            border-radius:18px;
            box-shadow:0 18px 45px rgba(37,99,235,.12);
            overflow:hidden;
        }
        .eyebrow{
            display:inline-flex;
            align-items:center;
            gap:8px;
            font-size:12px;
            font-weight:700;
            letter-spacing:.04em;
            text-transform:uppercase;
            color:var(--danger);
        }
        .eyebrow::before{
            content:"";
            width:10px;
            height:10px;
            border-radius:999px;
            background:var(--danger);
            display:inline-block;
        }
        h1{
            margin:14px 0 0;
            font-size:18px;
            line-height:1.15;
        }
        .error-body{
            padding:24px;
        }
        .message-box{
            background:#fff;
            border:1px solid #ffe1e6;
            color:#b42318;
            border-radius:14px;
            padding:14px 16px;
            font-size:15px;
            font-weight:700;
            line-height:1.45;
        }
        .actions{
            display:flex;
            gap:12px;
            margin-top:18px;
            flex-wrap:wrap;
        }
        .btn{
            appearance:none;
            border:0;
            border-radius:12px;
            padding:12px 18px;
            font-size:15px;
            font-weight:700;
            text-decoration:none;
            cursor:pointer;
        }
        .btn-primary{
            background:var(--primary);
            color:#fff;
        }
        .btn-primary:hover{
            background:var(--primary-dark);
        }
        .btn-secondary{
            background:#e8f0ff;
            color:var(--primary-dark);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-head">
            <div class="eyebrow">Please Check</div>
            <h1>' . $safe_title . '</h1>
        </div>
        <div class="error-body">
            <div class="message-box">' . $safe_message . '</div>
            <div class="actions">
                <a class="btn btn-primary" href="' . $safe_back_url . '">Go Back</a>
                <a class="btn btn-secondary" href="' . htmlspecialchars(app_path('dashboard.php')) . '">Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>';
    exit;
}
