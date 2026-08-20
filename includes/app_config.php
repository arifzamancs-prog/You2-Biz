<?php

const APP_BASE_URL_OVERRIDE = '';

function app_root_path()
{
    if(APP_BASE_URL_OVERRIDE !== ''){
        $override_path = parse_url(APP_BASE_URL_OVERRIDE, PHP_URL_PATH);
        $override_path = is_string($override_path) ? trim($override_path) : '';

        if($override_path === '' || $override_path === '/'){
            return '';
        }

        return '/' . trim($override_path, '/');
    }

    $document_root = isset($_SERVER['DOCUMENT_ROOT'])
        ? realpath($_SERVER['DOCUMENT_ROOT'])
        : false;
    $project_root = realpath(dirname(__DIR__));

    if($document_root && $project_root){
        $document_root = str_replace('\\', '/', $document_root);
        $project_root = str_replace('\\', '/', $project_root);

        if(str_starts_with($project_root, $document_root)){
            $relative = trim(substr($project_root, strlen($document_root)), '/');
            return $relative === '' ? '' : '/' . $relative;
        }
    }

    $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $script_dir = dirname($script_name);
    $script_dir = $script_dir === '.' || $script_dir === '/' ? '' : rtrim($script_dir, '/');

    return $script_dir;
}

function app_base_url()
{
    if(APP_BASE_URL_OVERRIDE !== ''){
        return rtrim(APP_BASE_URL_OVERRIDE, '/');
    }

    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $script_dir = app_root_path();

    return $scheme . '://' . $host . rtrim($script_dir, '/');
}

function app_path($path = '')
{
    $root = app_root_path();
    $path = ltrim($path, '/');

    if($path === ''){
        return $root === '' ? '/' : $root . '/';
    }

    return ($root === '' ? '' : $root) . '/' . $path;
}

function app_url($path = '')
{
    return app_base_url() . '/' . ltrim($path, '/');
}
