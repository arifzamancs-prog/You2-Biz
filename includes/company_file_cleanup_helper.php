<?php
// Uploaded files that belong to one company: product photos, staff/customer photos,
// shop logo and banners, brand logos, print seals, avatars, leave and invoice-request photos.
// Super Admin company delete uses this to remove them from disk and free the space.
//
// Flow: company_files_collect() BEFORE the database delete, then
// company_files_remove() AFTER the delete has been committed. A file that is still
// referenced anywhere else (for example a company restored from this company's
// backup) is kept.

function company_files_root()
{
    return str_replace('\\', '/', dirname(__DIR__)) . '/uploads';
}

// Folders a company can own files in. uploads/branding is system branding and never touched.
function company_files_owned_folders()
{
    return ['products', 'customer_form', 'customers', 'avatars', 'eshop_logos', 'eshop_banners', 'invoice_requests', 'leave_applications', 'printing', 'brands'];
}

function company_files_extension_regex()
{
    return 'jpe?g|png|gif|webp|bmp|ico|svg|pdf';
}

// Shared images that must survive any company delete.
function company_files_is_protected($name)
{
    $name = strtolower(basename((string)$name));
    if($name === '' || $name === 'you2biz.png' || strpos($name, 'default-') === 0){
        return true;
    }
    if(defined('SUPER_ADMIN_PROFILE_AVATAR') && $name === strtolower(basename((string)SUPER_ADMIN_PROFILE_AVATAR))){
        return true;
    }
    return false;
}

// Columns that can hold a file name: photo_path, logo_path, image, avatar, seal files,
// customers.extra_data (custom photo fields), branch print settings JSON, system settings.
function company_files_schema($conn)
{
    $schema = [];
    $result = mysqli_query(
        $conn,
        "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()"
    );
    while($result && ($row = mysqli_fetch_row($result))){
        [$table, $column, $type] = $row;
        if(!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)){
            continue;
        }
        $schema[$table]['columns'][$column] = true;
        $is_text = in_array(strtolower($type), ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true);
        if($is_text && preg_match('/photo|image|logo|file|avatar|path|seal|picture|banner|attach|document|extra|setting|json|icon/i', $column)){
            $schema[$table]['file_columns'][] = $column;
        }
    }
    return $schema;
}

// Pulls every file-looking token out of a stored value (plain name, path or JSON).
function company_files_tokens($value, array &$tokens)
{
    $value = str_replace('\\/', '/', (string)$value);
    $pattern = '~[A-Za-z0-9_\-./%]+\.(?:' . company_files_extension_regex() . ')(?![A-Za-z0-9])~i';
    if(preg_match_all($pattern, $value, $matches)){
        foreach($matches[0] as $token){
            $tokens[rawurldecode($token)] = true;
        }
    }
}

// Absolute path of an existing file inside an owned upload folder, or '' if unsafe/missing.
function company_files_safe_path($relative)
{
    $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
    if($relative === '' || strpos($relative, '..') !== false || strpos($relative, "\0") !== false){
        return '';
    }
    $parts = explode('/', $relative);
    if(count($parts) < 2 || !in_array($parts[0], company_files_owned_folders(), true)){
        return '';
    }
    $full = company_files_root() . '/' . $relative;
    if(!is_file($full)){
        return '';
    }
    $real = realpath($full);
    $root = realpath(company_files_root());
    if($real === false || $root === false){
        return '';
    }
    $real = str_replace('\\', '/', $real);
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    return stripos($real, $root) === 0 ? $real : '';
}

// A stored token -> the matching files on disk (keyed by path relative to uploads/).
function company_files_resolve($token)
{
    $token = str_replace('\\', '/', (string)$token);
    $candidates = [];
    $position = strpos($token, 'uploads/');
    if($position !== false){
        $candidates[] = substr($token, $position + 8);
    }else{
        // Bare file names are stored for products, logos, banners, avatars, seals, request photos.
        $name = basename($token);
        foreach(company_files_owned_folders() as $folder){
            if($folder !== 'brands'){
                $candidates[] = $folder . '/' . $name;
            }
        }
    }
    $files = [];
    foreach($candidates as $relative){
        $full = company_files_safe_path($relative);
        if($full !== '' && !company_files_is_protected($relative)){
            $files[$relative] = $full;
        }
    }
    return $files;
}

// Everything on disk that belongs to the company. Call before deleting its rows.
function company_files_collect($conn, $company_id, array $user_ids)
{
    $company_id = (int)$company_id;
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    if($company_id <= 0 || empty($user_ids)){
        return [];
    }
    $id_list = implode(',', $user_ids);
    $extension_sql = "'[.](" . company_files_extension_regex() . ")'";
    $tokens = [];

    // 1. Files the company's own rows point to.
    foreach(company_files_schema($conn) as $table => $info){
        if(empty($info['file_columns']) || in_array($table, ['company_delete_backups', 'super_admin_access_log', 'system_settings'], true)){
            continue;
        }
        $owner = [];
        if($table === 'users'){
            $owner[] = "`id` IN ({$id_list})";
        }else{
            if(isset($info['columns']['user_id'])){
                $owner[] = "`user_id` IN ({$id_list})";
            }
            if(isset($info['columns']['company_id'])){
                $owner[] = "`company_id`={$company_id}";
            }
        }
        if(empty($owner)){
            continue;
        }
        $select = [];
        $match = [];
        foreach($info['file_columns'] as $column){
            $select[] = "`{$column}`";
            $match[] = "`{$column}` REGEXP {$extension_sql}";
        }
        $result = mysqli_query(
            $conn,
            "SELECT " . implode(',', $select) . " FROM `{$table}`
             WHERE (" . implode(' OR ', $owner) . ") AND (" . implode(' OR ', $match) . ")"
        );
        while($result && ($row = mysqli_fetch_row($result))){
            foreach($row as $value){
                if($value !== null && $value !== ''){
                    company_files_tokens($value, $tokens);
                }
            }
        }
    }

    $files = [];
    foreach(array_keys($tokens) as $token){
        $files += company_files_resolve($token);
    }

    // 2. Files whose names carry the company id: replaced logos, banners and seals,
    //    old avatars and customer/staff photos that are no longer linked to a row.
    $root = company_files_root();
    $patterns = [
        'eshop_logos' => ['/^shop-' . $company_id . '-\d{14}-[a-f0-9]+\.[a-z0-9]+$/i'],
        'eshop_banners' => ['/^banner-' . $company_id . '-\d{14}-[a-f0-9]+\.[a-z0-9]+$/i'],
        'printing' => ['/^(company|paid)-seal-' . $company_id . '-\d+-\d+\.[a-z0-9]+$/i'],
        'customer_form' => ['/^customer_' . $company_id . '_[a-z0-9_]+_\d+_\d+\.[a-z0-9]+$/i'],
        'leave_applications' => ['/^leave_' . $company_id . '_\d+_\d{14}_[a-f0-9]+\.[a-z0-9]+$/i'],
        'avatars' => [],
    ];
    foreach($user_ids as $user_id){
        $patterns['avatars'][] = '/^\d+_' . $user_id . '\.[a-z0-9]+$/i';
    }
    foreach($patterns as $folder => $folder_patterns){
        $names = is_dir($root . '/' . $folder) ? (scandir($root . '/' . $folder) ?: []) : [];
        foreach($names as $name){
            foreach($folder_patterns as $pattern){
                if(preg_match($pattern, $name) && !company_files_is_protected($name)){
                    $full = company_files_safe_path($folder . '/' . $name);
                    if($full !== ''){
                        $files[$folder . '/' . $name] = $full;
                    }
                    break;
                }
            }
        }
    }

    // 3. Brand logos live in a folder named after the company.
    $brand_dir = $root . '/brands/' . $company_id;
    if(is_dir($brand_dir)){
        foreach(scandir($brand_dir) ?: [] as $name){
            $full = company_files_safe_path('brands/' . $company_id . '/' . $name);
            if($name !== '.' && $name !== '..' && $full !== ''){
                $files['brands/' . $company_id . '/' . $name] = $full;
            }
        }
    }

    ksort($files);
    return $files;
}

// Lower-case file names still referenced anywhere in the database (other companies, system settings).
function company_files_referenced_names($conn)
{
    $extension_sql = "'[.](" . company_files_extension_regex() . ")'";
    $tokens = [];
    foreach(company_files_schema($conn) as $table => $info){
        if(empty($info['file_columns']) || $table === 'company_delete_backups'){
            continue;
        }
        foreach($info['file_columns'] as $column){
            $result = mysqli_query($conn, "SELECT DISTINCT `{$column}` FROM `{$table}` WHERE `{$column}` REGEXP {$extension_sql}");
            while($result && ($row = mysqli_fetch_row($result))){
                company_files_tokens($row[0], $tokens);
            }
        }
    }
    $names = [];
    foreach(array_keys($tokens) as $token){
        $names[strtolower(basename(str_replace('\\', '/', $token)))] = true;
    }
    return $names;
}

// Deletes the collected files. Call only after the company's rows are committed as deleted.
// Returns [removed_count, freed_bytes, kept_count, failed_count].
function company_files_remove($conn, array $files, $company_id = 0)
{
    $still_used = empty($files) ? [] : company_files_referenced_names($conn);
    $removed = 0;
    $bytes = 0;
    $kept = 0;
    $failed = 0;

    foreach($files as $relative => $full){
        if(isset($still_used[strtolower(basename($relative))]) || company_files_is_protected($relative)){
            $kept++;
            continue;
        }
        if(!is_file($full)){
            continue;
        }
        $size = (int)@filesize($full);
        if(@unlink($full)){
            $removed++;
            $bytes += $size;
        }else{
            $failed++;
        }
    }

    $company_id = (int)$company_id;
    $brand_dir = company_files_root() . '/brands/' . $company_id;
    if($company_id > 0 && is_dir($brand_dir) && count(scandir($brand_dir) ?: []) <= 2){
        @rmdir($brand_dir);
    }

    return [$removed, $bytes, $kept, $failed];
}

function company_files_format_bytes($bytes)
{
    $bytes = (float)$bytes;
    $units = ['B', 'KB', 'MB', 'GB'];
    $unit = 0;
    while($bytes >= 1024 && $unit < count($units) - 1){
        $bytes /= 1024;
        $unit++;
    }
    return ($unit === 0 ? number_format($bytes) : number_format($bytes, 2)) . ' ' . $units[$unit];
}
