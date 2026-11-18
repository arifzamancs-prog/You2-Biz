<?php
// Forms (Travel Agency): the company makes its own forms (Visa Processing is the first
// one). Each form has its own list of fields (Form Settings) and its own entry list.
// An entry stores its values as JSON keyed by field_key, so adding or removing a field
// never touches the entries table.

require_once __DIR__ . '/schema_cache_helper.php';

function visa_process_field_types()
{
    return [
        'text' => 'Text',
        'number' => 'Number',
        'calendar' => 'Calendar (date)',
        'dropdown' => 'Dropdown',
        'textarea' => 'Long Text',
        'phone' => 'Phone',
    ];
}

// The fields the Visa Processing form starts with: [key, label, type, options, required, show in list].
function visa_process_default_fields()
{
    return [
        ['name', 'Name', 'text', '', 1, 1],
        ['passport', 'Passport', 'text', '', 1, 1],
        ['dob', 'DOB', 'calendar', '', 0, 1],
        ['gender', 'Gender', 'dropdown', "Male\nFemale\nOther", 0, 1],
        ['takamul_status', 'Takamul Status', 'dropdown', "Pending\nPassed\nFailed", 0, 1],
        ['present_status', 'Present Status', 'dropdown', "Received\nMedical\nMufa\nVisa Stamping\nTraining\nReady to Fly\nFlown\nCancelled", 0, 1],
        ['mufa_date', 'Mufa Date', 'calendar', '', 0, 1],
        ['agent_name', 'Agent Name', 'text', '', 0, 1],
        ['job_id', 'Job ID', 'text', '', 0, 1],
        ['receive_date', 'Receive Date', 'calendar', '', 0, 1],
        ['training', 'Training', 'dropdown', "Not Started\nRunning\nCompleted", 0, 1],
    ];
}

function visa_process_schema($conn)
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = mysqli_query($conn, "CREATE TABLE IF NOT EXISTS visa_process_forms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            form_name VARCHAR(120) NOT NULL,
            print_title VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_visa_form_user (user_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
        && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS visa_process_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            form_id INT NOT NULL DEFAULT 0,
            field_key VARCHAR(60) NOT NULL,
            label VARCHAR(120) NOT NULL,
            field_type VARCHAR(20) NOT NULL DEFAULT 'text',
            options TEXT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            show_in_list TINYINT(1) NOT NULL DEFAULT 1,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_visa_form_field (user_id, form_id, field_key),
            KEY idx_visa_field_sort (user_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
        && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS visa_process_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            form_id INT NOT NULL DEFAULT 0,
            serial_no INT NOT NULL,
            data LONGTEXT NOT NULL,
            created_by INT NOT NULL DEFAULT 0,
            created_by_name VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uniq_visa_form_serial (user_id, form_id, serial_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($ready && schema_once('visa_process_forms_v1')) {
        // Tables made before forms existed: add form_id and make keys unique per form.
        foreach (['visa_process_fields' => ['uniq_visa_field', 'uniq_visa_form_field', 'field_key'], 'visa_process_entries' => ['uniq_visa_serial', 'uniq_visa_form_serial', 'serial_no']] as $table => [$old_key, $new_key, $column]) {
            $col = mysqli_query($conn, "SHOW COLUMNS FROM {$table} LIKE 'form_id'");
            if ($col && mysqli_num_rows($col) === 0) {
                mysqli_query($conn, "ALTER TABLE {$table} ADD COLUMN form_id INT NOT NULL DEFAULT 0 AFTER user_id");
            }
            $old = mysqli_query($conn, "SHOW INDEX FROM {$table} WHERE Key_name='{$old_key}'");
            if ($old && mysqli_num_rows($old) > 0) {
                mysqli_query($conn, "ALTER TABLE {$table} DROP INDEX {$old_key}");
            }
            $new = mysqli_query($conn, "SHOW INDEX FROM {$table} WHERE Key_name='{$new_key}'");
            if ($new && mysqli_num_rows($new) === 0) {
                mysqli_query($conn, "ALTER TABLE {$table} ADD UNIQUE KEY {$new_key} (user_id, form_id, {$column})");
            }
        }
    }
    return $ready;
}

// Every company starts with the Visa Processing form; fields and entries saved before
// forms existed belong to it.
function visa_process_ensure_forms($conn, $user_id)
{
    $user_id = (int)$user_id;
    if (!visa_process_schema($conn) || $user_id <= 0) {
        return;
    }
    $has = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM visa_process_forms WHERE user_id={$user_id} LIMIT 1"));
    $orphans = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM visa_process_entries WHERE user_id={$user_id} AND form_id=0 LIMIT 1"))
        ?: mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM visa_process_fields WHERE user_id={$user_id} AND form_id=0 LIMIT 1"));
    if ($has && !$orphans) {
        return;
    }
    // The first sheet is "Data Sheet-1" (it can be renamed); rows saved before sheets existed
    // belong to it. Rows restored later from an older backup get the next free "Data Sheet-N".
    $title = '';
    $number = 1;
    $taken = mysqli_prepare($conn, "SELECT id FROM visa_process_forms WHERE user_id=? AND form_name=? LIMIT 1");
    do {
        $name = 'Data Sheet-' . $number++;
        mysqli_stmt_bind_param($taken, 'is', $user_id, $name);
        mysqli_stmt_execute($taken);
    } while (mysqli_fetch_assoc(mysqli_stmt_get_result($taken)));
    $sort = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0)+10 n FROM visa_process_forms WHERE user_id={$user_id}"))['n'] ?? 10);
    mysqli_query($conn, "INSERT INTO visa_process_forms (user_id, form_name, print_title, sort_order) VALUES ({$user_id}, '{$name}', '{$title}', {$sort})");
    $form_id = (int)mysqli_insert_id($conn);
    if ($form_id <= 0) {
        return;
    }
    mysqli_query($conn, "UPDATE visa_process_fields SET form_id={$form_id} WHERE user_id={$user_id} AND form_id=0");
    mysqli_query($conn, "UPDATE visa_process_entries SET form_id={$form_id} WHERE user_id={$user_id} AND form_id=0");
    visa_process_seed_fields($conn, $user_id, $form_id);
}

function visa_process_forms($conn, $user_id, $active_only = false)
{
    visa_process_ensure_forms($conn, $user_id);
    $forms = [];
    $result = mysqli_query($conn, "SELECT f.*,
            (SELECT COUNT(*) FROM visa_process_fields x WHERE x.form_id=f.id AND x.user_id=f.user_id) AS field_count,
            (SELECT COUNT(*) FROM visa_process_entries e WHERE e.form_id=f.id AND e.user_id=f.user_id) AS entry_count
        FROM visa_process_forms f WHERE f.user_id=" . (int)$user_id
        . ($active_only ? " AND f.status='active'" : '') . " ORDER BY f.sort_order, f.id");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $forms[] = $row;
    }
    return $forms;
}

function visa_process_form($conn, $user_id, $form_id)
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM visa_process_forms WHERE id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $form_id, $user_id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

// The form a page works on: ?form=ID, else the first active form.
function visa_process_current_form($conn, $user_id)
{
    $forms = visa_process_forms($conn, $user_id);
    $wanted = (int)($_GET['form'] ?? $_POST['form'] ?? 0);
    foreach ($forms as $form) {
        if ((int)$form['id'] === $wanted) {
            return $form;
        }
    }
    if ($wanted > 0) {
        return null;
    }
    foreach ($forms as $form) {
        if ($form['status'] === 'active') {
            return $form;
        }
    }
    return $forms[0] ?? null;
}

// Heading on the printed form / list: the form's name.
function visa_process_print_title($form)
{
    return mb_strtoupper(trim((string)($form['form_name'] ?? '')) ?: 'Sheet');
}

// Adds the starting Visa Processing fields to a form that has no field yet.
function visa_process_seed_fields($conn, $user_id, $form_id)
{
    $user_id = (int)$user_id;
    $form_id = (int)$form_id;
    $has = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM visa_process_fields WHERE user_id={$user_id} AND form_id={$form_id} LIMIT 1"));
    if ($has) {
        return;
    }
    $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO visa_process_fields (user_id, form_id, field_key, label, field_type, options, is_required, show_in_list, is_default, sort_order) VALUES (?,?,?,?,?,?,?,?,1,?)");
    $sort = 10;
    foreach (visa_process_default_fields() as [$key, $label, $type, $options, $required, $list]) {
        mysqli_stmt_bind_param($stmt, 'iissssiii', $user_id, $form_id, $key, $label, $type, $options, $required, $list, $sort);
        mysqli_stmt_execute($stmt);
        $sort += 10;
    }
}

function visa_process_fields($conn, $user_id, $form_id, $active_only = true)
{
    $fields = [];
    $result = mysqli_query($conn, "SELECT * FROM visa_process_fields WHERE user_id=" . (int)$user_id . " AND form_id=" . (int)$form_id
        . ($active_only ? " AND status='active'" : '') . " ORDER BY sort_order, id");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $fields[] = $row;
    }
    return $fields;
}

function visa_process_options($field)
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($field['options'] ?? ''))), 'strlen'));
}

function visa_process_clean_options($options)
{
    return implode("\n", array_values(array_unique(visa_process_options(['options' => $options]))));
}

// A key made from the label, unique inside the form.
function visa_process_unique_key($conn, $user_id, $form_id, $label, $ignore_id = 0)
{
    $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
    if ($base === '' || $base === 'sl') {
        $base = 'field';
    }
    $base = substr($base, 0, 50);
    $key = $base;
    $n = 2;
    $stmt = mysqli_prepare($conn, "SELECT id FROM visa_process_fields WHERE user_id=? AND form_id=? AND field_key=? AND id<>? LIMIT 1");
    while (true) {
        mysqli_stmt_bind_param($stmt, 'iisi', $user_id, $form_id, $key, $ignore_id);
        mysqli_stmt_execute($stmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
            return $key;
        }
        $key = $base . '_' . $n++;
    }
}

// Reads the posted values for the given fields. Returns [data, error].
// $old: the entry's saved values, so a dropdown option removed later can still be kept.
function visa_process_read_post($fields, $post, $old = [])
{
    $data = [];
    foreach ($fields as $field) {
        $key = $field['field_key'];
        $value = trim((string)($post['f'][$key] ?? ''));
        $value = mb_substr($value, 0, $field['field_type'] === 'textarea' ? 2000 : 255);
        if ($value !== '') {
            if ($field['field_type'] === 'calendar') {
                $date = DateTime::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    return [null, $field['label'] . ': enter a valid date.'];
                }
            } elseif ($field['field_type'] === 'number' && !is_numeric($value)) {
                return [null, $field['label'] . ': enter a number.'];
            } elseif ($field['field_type'] === 'dropdown' && !in_array($value, visa_process_options($field), true) && $value !== (string)($old[$key] ?? '')) {
                return [null, $field['label'] . ': choose an option from the list.'];
            }
        } elseif ((int)$field['is_required'] === 1) {
            return [null, $field['label'] . ' is required.'];
        }
        $data[$key] = $value;
    }
    return [$data, ''];
}

// Value as shown on screen and in print.
function visa_process_display($field, $value)
{
    $value = (string)$value;
    if ($field['field_type'] === 'calendar' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return $value;
}

// One form control for a field; $value is the saved value.
function visa_process_input($field, $value)
{
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $name = 'f[' . $h($field['field_key']) . ']';
    $id = 'vf-' . $h($field['field_key']);
    $required = (int)$field['is_required'] === 1 ? ' required' : '';
    switch ($field['field_type']) {
        case 'dropdown':
            $html = '<select name="' . $name . '" id="' . $id . '" class="form-control"' . $required . '><option value="">Select</option>';
            $options = visa_process_options($field);
            if ($value !== '' && !in_array($value, $options, true)) {
                $options[] = $value; // an option removed later stays selectable on old entries
            }
            foreach ($options as $option) {
                $html .= '<option value="' . $h($option) . '"' . ($option === $value ? ' selected' : '') . '>' . $h($option) . '</option>';
            }
            return $html . '</select>';
        case 'textarea':
            return '<textarea name="' . $name . '" id="' . $id . '" class="form-control" rows="2"' . $required . '>' . $h($value) . '</textarea>';
        case 'calendar':
            return '<input type="date" name="' . $name . '" id="' . $id . '" class="form-control" value="' . $h($value) . '"' . $required . '>';
        case 'number':
            return '<input type="number" step="any" name="' . $name . '" id="' . $id . '" class="form-control" value="' . $h($value) . '"' . $required . '>';
        case 'phone':
            return '<input type="tel" name="' . $name . '" id="' . $id . '" class="form-control" value="' . $h($value) . '"' . $required . '>';
        default:
            return '<input type="text" name="' . $name . '" id="' . $id . '" class="form-control" maxlength="255" value="' . $h($value) . '"' . $required . '>';
    }
}

// Reads the typed SL: a whole number from 1. Returns 0 when it is not one.
function visa_process_read_serial($value)
{
    $value = trim((string)$value);
    return preg_match('/^[0-9]{1,9}$/', $value) && (int)$value > 0 ? (int)$value : 0;
}

function visa_process_serial_taken($conn, $user_id, $form_id, $serial, $ignore_id = 0)
{
    $stmt = mysqli_prepare($conn, "SELECT id FROM visa_process_entries WHERE user_id=? AND form_id=? AND serial_no=? AND id<>? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'iiii', $user_id, $form_id, $serial, $ignore_id);
    mysqli_stmt_execute($stmt);
    return (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

function visa_process_next_serial($conn, $user_id, $form_id)
{
    return (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(serial_no),0)+1 n FROM visa_process_entries WHERE user_id=" . (int)$user_id . " AND form_id=" . (int)$form_id))['n'] ?? 1);
}

// Saves a new entry. $serial 0 = the next SL of the form (last SL + 1); else that SL,
// which must be free. Returns [id, SL].
function visa_process_create($conn, $user_id, $form_id, $data, $by_id, $by_name, $serial = 0)
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $user_id = (int)$user_id;
    $form_id = (int)$form_id;
    mysqli_begin_transaction($conn);
    try {
        // Locking the form's rows makes two saves at once take different SLs.
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(serial_no),0) AS last_sl FROM visa_process_entries WHERE user_id={$user_id} AND form_id={$form_id} FOR UPDATE"));
        if ($serial <= 0) {
            $serial = (int)($row['last_sl'] ?? 0) + 1;
        } elseif (visa_process_serial_taken($conn, $user_id, $form_id, $serial)) {
            throw new RuntimeException('SL ' . $serial . ' is already used. Give another SL.');
        }
        $stmt = mysqli_prepare($conn, "INSERT INTO visa_process_entries (user_id, form_id, serial_no, data, created_by, created_by_name) VALUES (?,?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, 'iiisis', $user_id, $form_id, $serial, $json, $by_id, $by_name);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('The entry could not be saved.');
        }
        $id = (int)mysqli_insert_id($conn);
        mysqli_commit($conn);
        return [$id, $serial];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

function visa_process_entry($conn, $user_id, $form_id, $id)
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM visa_process_entries WHERE id=? AND user_id=? AND form_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'iii', $id, $user_id, $form_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($row) {
        $row['values'] = json_decode((string)$row['data'], true) ?: [];
    }
    return $row ?: null;
}

// Entries of a form matching a search text (any field), newest SL first.
function visa_process_search($conn, $user_id, $form_id, $search = '', $limit = 1000, $ids = [])
{
    $sql = "SELECT * FROM visa_process_entries WHERE user_id=? AND form_id=?";
    $types = 'ii';
    $params = [(int)$user_id, (int)$form_id];
    if ($search !== '') {
        // Values only ('$.*'), so a search for "name" does not match every entry by its key.
        // The JSON keeps non-ASCII as is (JSON_UNESCAPED_UNICODE), so Bangla text is found too.
        $sql .= " AND (JSON_EXTRACT(data, '$.*') LIKE ? OR serial_no=?)";
        $types .= 'si';
        $params[] = '%' . addcslashes($search, '%_\\') . '%';
        $params[] = ctype_digit($search) ? (int)$search : -1;
    }
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids) {
        $sql .= " AND id IN (" . implode(',', $ids) . ")";
    }
    $sql .= " ORDER BY serial_no DESC LIMIT " . max(1, (int)$limit);
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['values'] = json_decode((string)$row['data'], true) ?: [];
        $rows[] = $row;
    }
    return $rows;
}

// Sidebar: one link per active form (its entry list), then Manage Forms for the administrator.
function visa_process_sidebar_items($conn, $user_id, $with_manage)
{
    $items = [];
    if (!visa_process_schema($conn)) {
        return $items;
    }
    foreach (visa_process_forms($conn, $user_id, true) as $form) {
        $items[] = ['href' => app_path('travel/visa_processing.php?form=' . (int)$form['id']), 'label' => (string)$form['form_name']];
    }
    if ($with_manage) {
        $items[] = ['href' => app_path('travel/forms.php'), 'label' => 'Manage Sheets'];
    }
    return $items;
}
