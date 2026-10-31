<?php

// Table/column checks (SHOW COLUMNS, CREATE TABLE IF NOT EXISTS, ...) only need to run once:
// after that the schema is in place. schema_once('name') returns true the first time a check
// runs in this login session and false afterwards, so the check can be skipped.
// Changing any file in includes/ (a deploy) starts the checks again.
function schema_once($name)
{
    static $ran = [];
    static $version = null;

    if(isset($ran[$name])){
        return false;
    }

    $ran[$name] = true;

    if(session_status() !== PHP_SESSION_ACTIVE){
        return true;
    }

    if($version === null){
        $stamp = '';
        foreach(glob(__DIR__ . '/*.php') ?: [] as $file){
            $stamp .= basename($file) . filemtime($file) . ';';
        }
        $version = md5($stamp . ($GLOBALS['db'] ?? ''));
    }

    if(($_SESSION['__schema_version'] ?? '') !== $version){
        $_SESSION['__schema_version'] = $version;
        $_SESSION['__schema_done'] = [];
    }

    if(!empty($_SESSION['__schema_done'][$name])){
        return false;
    }

    $_SESSION['__schema_done'][$name] = 1;

    return true;
}

// Keep a value found by a check (e.g. which tables exist) for as long as the check is skipped.
function schema_remember($name, $value)
{
    if(session_status() === PHP_SESSION_ACTIVE){
        $_SESSION['__schema_done']['data:' . $name] = $value;
    }
}

// The value kept by schema_remember(), or null when the check has to run again.
function schema_recall($name)
{
    return session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['__schema_done']['data:' . $name] ?? null) : null;
}

// After an import or data reset the tables may be rebuilt, so every check runs again.
function schema_cache_reset()
{
    if(session_status() === PHP_SESSION_ACTIVE){
        unset($_SESSION['__schema_done'], $_SESSION['__schema_version']);
    }
}
