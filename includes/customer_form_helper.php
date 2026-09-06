<?php

function customer_form_ensure_schema($conn)
{
    customer_form_ensure_customer_columns($conn);

    $table_result = mysqli_query($conn, "SHOW TABLES LIKE 'customer_form_fields'");
    if($table_result && mysqli_num_rows($table_result) === 0){
        mysqli_query(
            $conn,
            "CREATE TABLE customer_form_fields (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                field_key VARCHAR(80) NOT NULL,
                label VARCHAR(150) NOT NULL,
                field_type ENUM('text','dropdown','photo','calendar','ruler','label') NOT NULL DEFAULT 'text',
                options TEXT NULL,
                is_required TINYINT(1) NOT NULL DEFAULT 0,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_customer_form_field (user_id, field_key),
                KEY idx_customer_form_fields_user_status (user_id, status, sort_order)
            )"
        );
    }

    $is_system_column = mysqli_query($conn, "SHOW COLUMNS FROM customer_form_fields LIKE 'is_system'");
    if($is_system_column && mysqli_num_rows($is_system_column) === 0){
        mysqli_query($conn, "ALTER TABLE customer_form_fields ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER is_required");
    }

    mysqli_query(
        $conn,
        "ALTER TABLE customer_form_fields
         MODIFY field_type ENUM('text','dropdown','photo','calendar','ruler','label') NOT NULL DEFAULT 'text'"
    );

    $extra_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'extra_data'");
    if($extra_column && mysqli_num_rows($extra_column) === 0){
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN extra_data TEXT NULL AFTER address");
    }
}

function customer_form_field_types()
{
    return [
        'text' => 'Text',
        'dropdown' => 'Dropdown',
        'photo' => 'Upload Photo',
        'calendar' => 'Calendar',
        'ruler' => 'Ruler Break',
    ];
}

function customer_form_normalize_type($type)
{
    $type = (string)$type;
    $types = customer_form_field_types();

    return array_key_exists($type, $types) ? $type : 'text';
}

function customer_form_field_has_input($field)
{
    return !in_array((string)($field['field_type'] ?? 'text'), ['ruler', 'label'], true);
}

function customer_form_normalize_calendar_value($value)
{
    $value = trim((string)$value);

    if($value === ''){
        return '';
    }

    if(preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)){
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date ? $date->format('d/m/Y') : $value;
    }

    return $value;
}

function customer_form_is_valid_calendar_value($value)
{
    $value = trim((string)$value);

    if($value === ''){
        return true;
    }

    $date = DateTime::createFromFormat('d/m/Y', $value);
    return $date && $date->format('d/m/Y') === $value;
}

function customer_form_upload_photo($file, $user_id, $field_key, &$message)
{
    if(!function_exists('imagecreatetruecolor')){
        $message = 'Photo upload needs PHP GD extension enabled.';
        return '';
    }

    if(empty($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE){
        return '';
    }

    if((int)$file['error'] !== UPLOAD_ERR_OK){
        $message = 'Photo upload failed.';
        return '';
    }

    if((int)$file['size'] > 2 * 1024 * 1024){
        $message = 'Photo maximum size is 2MB.';
        return '';
    }

    $image_info = @getimagesize($file['tmp_name']);
    if(!$image_info){
        $message = 'Please upload a valid photo.';
        return '';
    }

    $mime = $image_info['mime'] ?? '';
    if($mime === 'image/jpeg'){
        $source = @imagecreatefromjpeg($file['tmp_name']);
    } elseif($mime === 'image/png'){
        $source = @imagecreatefrompng($file['tmp_name']);
    } elseif($mime === 'image/webp' && function_exists('imagecreatefromwebp')){
        $source = @imagecreatefromwebp($file['tmp_name']);
    } else {
        $message = 'Only JPG, PNG or WEBP photo is allowed.';
        return '';
    }

    if(!$source){
        $message = 'Photo could not be processed.';
        return '';
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $side = min($width, $height);
    $src_x = (int)(($width - $side) / 2);
    $src_y = (int)(($height - $side) / 2);

    $target = imagecreatetruecolor(250, 250);
    imagecopyresampled($target, $source, 0, 0, $src_x, $src_y, 250, 250, $side, $side);

    $upload_dir = dirname(__DIR__) . '/uploads/customer_form';
    if(!is_dir($upload_dir)){
        mkdir($upload_dir, 0775, true);
    }

    $file_name = 'customer_' . (int)$user_id . '_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($field_key)) . '_' . time() . '_' . mt_rand(1000, 9999) . '.jpg';
    $path = $upload_dir . '/' . $file_name;

    if(!imagejpeg($target, $path, 90)){
        $message = 'Photo could not be saved.';
        imagedestroy($source);
        imagedestroy($target);
        return '';
    }

    imagedestroy($source);
    imagedestroy($target);

    return 'uploads/customer_form/' . $file_name;
}

function customer_form_ensure_customer_columns($conn)
{
    $customer_code_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'customer_code'");
    if($customer_code_column && mysqli_num_rows($customer_code_column) === 0){
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN customer_code VARCHAR(60) NULL AFTER user_id");
    }

    $ref_staff_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'ref_staff_id'");
    if($ref_staff_column && mysqli_num_rows($ref_staff_column) === 0){
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN ref_staff_id BIGINT UNSIGNED NULL AFTER customer_name");
        mysqli_query($conn, "ALTER TABLE customers ADD INDEX idx_customers_ref_staff (ref_staff_id)");
    }

    $lead_id_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'lead_id'");
    if($lead_id_column && mysqli_num_rows($lead_id_column) === 0){
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN lead_id BIGINT UNSIGNED NULL AFTER ref_staff_id");
        mysqli_query($conn, "ALTER TABLE customers ADD UNIQUE KEY uniq_customers_lead_id (lead_id)");
    }

    $lead_ref_column = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'lead_ref_name'");
    if($lead_ref_column && mysqli_num_rows($lead_ref_column) === 0){
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN lead_ref_name VARCHAR(150) NULL AFTER lead_id");
    }
}

function customer_form_system_fields()
{
    return [
        ['field_key' => 'photo', 'label' => 'Photo', 'field_type' => 'photo', 'options' => '', 'is_required' => 1, 'sort_order' => 5, 'status' => 'active'],
        ['field_key' => 'customer_code', 'label' => 'Customer ID', 'field_type' => 'text', 'options' => '', 'is_required' => 1, 'sort_order' => 10, 'status' => 'active'],
        ['field_key' => 'customer_name', 'label' => 'Customer Name', 'field_type' => 'text', 'options' => '', 'is_required' => 1, 'sort_order' => 20, 'status' => 'active'],
        ['field_key' => 'ref_staff_id', 'label' => 'Ref. Name', 'field_type' => 'dropdown', 'options' => '', 'is_required' => 1, 'sort_order' => 30, 'status' => 'active'],
        ['field_key' => 'phone', 'label' => 'Phone', 'field_type' => 'text', 'options' => '', 'is_required' => 1, 'sort_order' => 40, 'status' => 'active'],
        ['field_key' => 'email', 'label' => 'Email', 'field_type' => 'text', 'options' => '', 'is_required' => 1, 'sort_order' => 50, 'status' => 'active'],
        ['field_key' => 'address', 'label' => 'Address', 'field_type' => 'text', 'options' => '', 'is_required' => 1, 'sort_order' => 60, 'status' => 'active'],
        ['field_key' => 'status', 'label' => 'Status', 'field_type' => 'dropdown', 'options' => "Active\nInactive", 'is_required' => 1, 'sort_order' => 70, 'status' => 'active'],
    ];
}

function customer_form_seed_system_fields($conn, $user_id)
{
    customer_form_ensure_schema($conn);

    foreach(customer_form_system_fields() as $field){
        $exists_stmt = mysqli_prepare(
            $conn,
            "SELECT id FROM customer_form_fields WHERE user_id=? AND field_key=? LIMIT 1"
        );
        mysqli_stmt_bind_param($exists_stmt, 'is', $user_id, $field['field_key']);
        mysqli_stmt_execute($exists_stmt);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($exists_stmt));

        if($exists){
            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE customer_form_fields
                 SET is_system=1, field_type=?, options=?, is_required=?, status='active'
                 WHERE id=? AND user_id=?"
            );
            $existing_id = (int)$exists['id'];
            mysqli_stmt_bind_param(
                $update_stmt,
                'ssiii',
                $field['field_type'],
                $field['options'],
                $field['is_required'],
                $existing_id,
                $user_id
            );
            mysqli_stmt_execute($update_stmt);
            continue;
        }

        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO customer_form_fields
                (user_id, field_key, label, field_type, options, is_required, is_system, sort_order, status)
             VALUES (?,?,?,?,?,?,1,?,?)"
        );
        mysqli_stmt_bind_param(
            $insert_stmt,
            'issssiis',
            $user_id,
            $field['field_key'],
            $field['label'],
            $field['field_type'],
            $field['options'],
            $field['is_required'],
            $field['sort_order'],
            $field['status']
        );
        mysqli_stmt_execute($insert_stmt);
    }
}

function customer_form_slug($label)
{
    $key = strtolower(trim((string)$label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim((string)$key, '_');

    if($key === ''){
        $key = 'field';
    }

    return substr($key, 0, 60);
}

function customer_form_unique_key($conn, $user_id, $label, $exclude_id = 0)
{
    $base = customer_form_slug($label);
    $key = $base;
    $counter = 2;

    while(true){
        $sql = "SELECT id FROM customer_form_fields WHERE user_id=? AND field_key=?";
        if((int)$exclude_id > 0){
            $sql .= " AND id<>" . (int)$exclude_id;
        }
        $sql .= " LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);
        if(!$stmt){
            return $key;
        }

        mysqli_stmt_bind_param($stmt, 'is', $user_id, $key);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if(!$result || mysqli_num_rows($result) === 0){
            return $key;
        }

        $suffix = '_' . $counter;
        $key = substr($base, 0, 60 - strlen($suffix)) . $suffix;
        $counter++;
    }
}

function customer_form_normalize_options($options)
{
    $rows = preg_split('/\r\n|\r|\n/', (string)$options);
    $clean = [];

    foreach($rows as $row){
        $value = trim($row);
        if($value !== '' && !in_array($value, $clean, true)){
            $clean[] = $value;
        }
    }

    return implode("\n", $clean);
}

function customer_form_get_fields($conn, $user_id, $active_only = true)
{
    customer_form_ensure_schema($conn);
    customer_form_seed_system_fields($conn, $user_id);

    $sql = "SELECT * FROM customer_form_fields WHERE user_id=?";
    if($active_only){
        $sql .= " AND status='active'";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";

    $stmt = mysqli_prepare($conn, $sql);
    if(!$stmt){
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $fields = [];

    while($result && $row = mysqli_fetch_assoc($result)){
        $fields[] = $row;
    }

    return $fields;
}

function customer_form_decode_extra($value)
{
    $data = json_decode((string)$value, true);
    return is_array($data) ? $data : [];
}
