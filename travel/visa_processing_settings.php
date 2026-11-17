<?php
// Form Settings of Visa Processing: the company adds, edits, orders and turns off the form's fields.
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/visa_processing_helper.php';

if (!is_admin_user()) {
    header('Location: visa_processing.php');
    exit;
}
if (!visa_process_schema($conn)) {
    exit('Visa processing tables could not be prepared.');
}
visa_process_seed_fields($conn, $user_id);
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$types = visa_process_field_types();

$flash = $_SESSION['visa_field_flash'] ?? null;
unset($_SESSION['visa_field_flash']);
$back = static function ($type, $text) {
    $_SESSION['visa_field_flash'] = [$type, $text];
    header('Location: visa_processing_settings.php');
    exit;
};
$field_by_id = static function ($id) use ($conn, $user_id) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM visa_process_fields WHERE id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
};

$error = '';
$form = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['field_id'] ?? 0);
    $field = $id > 0 ? $field_by_id($id) : null;
    if ($id > 0 && !$field) {
        $back('danger', 'Field not found.');
    }

    if ($action === 'delete') {
        if ((int)$field['is_default'] === 1) {
            $back('danger', 'A starting field cannot be deleted; set it Inactive to hide it.');
        }
        $stmt = mysqli_prepare($conn, "DELETE FROM visa_process_fields WHERE id=? AND user_id=? AND is_default=0");
        mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
        mysqli_stmt_execute($stmt);
        $back('success', '"' . $field['label'] . '" deleted. Values already saved in entries are kept.');
    }

    if ($action === 'up' || $action === 'down') {
        // Renumber in steps of 10, then swap with the neighbour.
        $list = visa_process_fields($conn, $user_id, false);
        $index = array_search($id, array_map(static fn($f) => (int)$f['id'], $list), true);
        $other = $action === 'up' ? $index - 1 : $index + 1;
        if ($index !== false && isset($list[$other])) {
            [$list[$index], $list[$other]] = [$list[$other], $list[$index]];
            $stmt = mysqli_prepare($conn, "UPDATE visa_process_fields SET sort_order=? WHERE id=? AND user_id=?");
            foreach ($list as $n => $item) {
                $sort = ($n + 1) * 10;
                $item_id = (int)$item['id'];
                mysqli_stmt_bind_param($stmt, 'iii', $sort, $item_id, $user_id);
                mysqli_stmt_execute($stmt);
            }
        }
        header('Location: visa_processing_settings.php');
        exit;
    }

    $form = [
        'id' => $id,
        'label' => mb_substr(trim((string)($_POST['label'] ?? '')), 0, 120),
        'field_type' => array_key_exists($_POST['field_type'] ?? '', $types) ? $_POST['field_type'] : 'text',
        'options' => visa_process_clean_options($_POST['options'] ?? ''),
        'is_required' => isset($_POST['is_required']) ? 1 : 0,
        'show_in_list' => isset($_POST['show_in_list']) ? 1 : 0,
        'status' => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        'is_default' => (int)($field['is_default'] ?? 0),
    ];
    if ($form['label'] === '') {
        $error = 'Field label is required.';
    } elseif ($form['field_type'] === 'dropdown' && $form['options'] === '') {
        $error = 'Write the dropdown options, one per line.';
    } else {
        if ($form['field_type'] !== 'dropdown') {
            $form['options'] = '';
        }
        if ($field) {
            // The key stays the same so values already saved under it keep showing.
            $stmt = mysqli_prepare($conn, "UPDATE visa_process_fields SET label=?, field_type=?, options=?, is_required=?, show_in_list=?, status=? WHERE id=? AND user_id=?");
            mysqli_stmt_bind_param($stmt, 'sssiisii', $form['label'], $form['field_type'], $form['options'], $form['is_required'], $form['show_in_list'], $form['status'], $id, $user_id);
            mysqli_stmt_execute($stmt);
            $back('success', '"' . $form['label'] . '" updated.');
        }
        $key = visa_process_unique_key($conn, $user_id, $form['label']);
        $sort = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0)+10 n FROM visa_process_fields WHERE user_id={$user_id}"))['n'] ?? 10);
        $stmt = mysqli_prepare($conn, "INSERT INTO visa_process_fields (user_id, field_key, label, field_type, options, is_required, show_in_list, sort_order, status) VALUES (?,?,?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, 'issssiiis', $user_id, $key, $form['label'], $form['field_type'], $form['options'], $form['is_required'], $form['show_in_list'], $sort, $form['status']);
        mysqli_stmt_execute($stmt);
        $back('success', '"' . $form['label'] . '" added to the form.');
    }
}

if (!$form && (int)($_GET['edit'] ?? 0) > 0) {
    $form = $field_by_id((int)$_GET['edit']);
}
$form = $form ?: ['id' => 0, 'label' => '', 'field_type' => 'text', 'options' => '', 'is_required' => 0, 'show_in_list' => 1, 'status' => 'active', 'is_default' => 0];
$fields = visa_process_fields($conn, $user_id, false);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid">
    <?php if ($flash) { ?><div class="alert alert-<?= $h($flash[0]); ?>"><?= $h($flash[1]); ?></div><?php } ?>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= $h($error); ?></div><?php } ?>
    <div class="row">
        <div class="col-lg-4">
            <div class="card card-outline card-info">
                <div class="card-header"><h3 class="card-title"><?= (int)$form['id'] > 0 ? 'Edit Field' : 'Add Field'; ?></h3></div>
                <form method="post">
                    <div class="card-body">
                        <?= travel_csrf_field(); ?>
                        <input type="hidden" name="field_id" value="<?= (int)$form['id']; ?>">
                        <div class="form-group"><label for="vfs-label">Field Label</label><input id="vfs-label" name="label" class="form-control" maxlength="120" value="<?= $h($form['label']); ?>" required></div>
                        <div class="form-group"><label for="vfs-type">Input Type</label>
                            <select id="vfs-type" name="field_type" class="form-control">
                                <?php foreach ($types as $key => $label) { ?><option value="<?= $h($key); ?>"<?= $form['field_type'] === $key ? ' selected' : ''; ?>><?= $h($label); ?></option><?php } ?>
                            </select></div>
                        <div class="form-group" id="vfs-options-group"><label for="vfs-options">Dropdown Options</label>
                            <textarea id="vfs-options" name="options" class="form-control" rows="5" placeholder="One option per line"><?= $h($form['options']); ?></textarea>
                            <small class="text-muted">Write each option on a new line.</small></div>
                        <div class="form-group"><label for="vfs-status">Status</label>
                            <select id="vfs-status" name="status" class="form-control">
                                <option value="active"<?= $form['status'] === 'active' ? ' selected' : ''; ?>>Active (shown on the form)</option>
                                <option value="inactive"<?= $form['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive (hidden)</option>
                            </select></div>
                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="vfs-required" name="is_required" value="1"<?= (int)$form['is_required'] === 1 ? ' checked' : ''; ?>><label class="custom-control-label" for="vfs-required">Required</label></div>
                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="vfs-list" name="show_in_list" value="1"<?= (int)$form['show_in_list'] === 1 ? ' checked' : ''; ?>><label class="custom-control-label" for="vfs-list">Show as a column in the list and list print</label></div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary"><i class="fas fa-save mr-1"></i><?= (int)$form['id'] > 0 ? 'Update Field' : 'Add Field'; ?></button>
                        <?php if ((int)$form['id'] > 0) { ?><a href="visa_processing_settings.php" class="btn btn-secondary">Cancel</a><?php } ?>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-cog mr-2"></i>Visa Processing Form Settings</h3>
                    <div class="card-tools"><a href="visa_processing.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-list mr-1"></i>Entry List</a></div></div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-bordered table-striped table-sm mb-0">
                        <thead><tr><th style="width:70px">Order</th><th>Label</th><th>Type</th><th>Required</th><th>In List</th><th>Status</th><th style="width:100px">Action</th></tr></thead>
                        <tbody>
                            <tr class="text-muted"><td></td><td>SL <span class="badge badge-secondary">Auto</span></td><td>Auto number</td><td>—</td><td>Yes</td><td>Always</td><td>Locked</td></tr>
                            <?php foreach ($fields as $n => $field) { ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <form method="post" class="d-inline"><?= travel_csrf_field(); ?><input type="hidden" name="field_id" value="<?= (int)$field['id']; ?>"><button class="btn btn-xs btn-outline-secondary" name="action" value="up" title="Move up"<?= $n === 0 ? ' disabled' : ''; ?>><i class="fas fa-arrow-up"></i></button><button class="btn btn-xs btn-outline-secondary ml-1" name="action" value="down" title="Move down"<?= $n === count($fields) - 1 ? ' disabled' : ''; ?>><i class="fas fa-arrow-down"></i></button></form>
                                    </td>
                                    <td><?= $h($field['label']); ?><?= (int)$field['is_default'] === 1 ? ' <span class="badge badge-info">Starting</span>' : ''; ?>
                                        <?php if ($field['field_type'] === 'dropdown') { ?><div class="small text-muted"><?= $h(implode(', ', visa_process_options($field))); ?></div><?php } ?></td>
                                    <td><?= $h($types[$field['field_type']] ?? $field['field_type']); ?></td>
                                    <td><?= (int)$field['is_required'] === 1 ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>'; ?></td>
                                    <td><?= (int)$field['show_in_list'] === 1 ? 'Yes' : 'No'; ?></td>
                                    <td><span class="badge badge-<?= $field['status'] === 'active' ? 'success' : 'secondary'; ?>"><?= $h(ucfirst($field['status'])); ?></span></td>
                                    <td class="text-nowrap">
                                        <a href="?edit=<?= (int)$field['id']; ?>" class="btn btn-xs btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                        <?php if ((int)$field['is_default'] !== 1) { ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this field? Values already saved in entries stay in the database.');"><?= travel_csrf_field(); ?><input type="hidden" name="field_id" value="<?= (int)$field['id']; ?>"><button class="btn btn-xs btn-danger" name="action" value="delete" title="Delete"><i class="fas fa-trash"></i></button></form>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">Starting fields can be renamed, retyped or set Inactive, but not deleted. Fields you add can be deleted.</div>
            </div>
        </div>
    </div>
</div></section>
<?php
$page_script = '<script>
$(function(){
    function toggleOptions(){ $("#vfs-options-group").toggle($("#vfs-type").val() === "dropdown"); }
    $("#vfs-type").on("change", toggleOptions);
    toggleOptions();
});
</script>';
require_once __DIR__ . '/../includes/footer.php';
