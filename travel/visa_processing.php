<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/visa_processing_helper.php';

if (!visa_process_schema($conn)) {
    exit('Visa processing tables could not be prepared.');
}
visa_process_seed_fields($conn, $user_id);
$fields = visa_process_fields($conn, $user_id);
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$flash = $_SESSION['visa_process_flash'] ?? null;
unset($_SESSION['visa_process_flash']);
$error = '';
$entry_param = (string)($_GET['entry'] ?? '');
$posted_sl = '';
$entry = null;
$values = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if (!is_admin_user()) {
            http_response_code(403);
            exit('Only the administrator can delete an entry.');
        }
        $stmt = mysqli_prepare($conn, "DELETE FROM visa_process_entries WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
        mysqli_stmt_execute($stmt);
        $_SESSION['visa_process_flash'] = ['success', 'Entry deleted.'];
        header('Location: visa_processing.php');
        exit;
    }

    $existing = $id > 0 ? visa_process_entry($conn, $user_id, $id) : null;
    if ($id > 0 && !$existing) {
        header('Location: visa_processing.php');
        exit;
    }
    [$data, $error] = visa_process_read_post($fields, $_POST, $existing['values'] ?? []);
    $serial = visa_process_read_serial($_POST['serial_no'] ?? '');
    if ($error === '' && $serial === 0) {
        $error = 'SL must be a whole number from 1.';
    }
    if ($error === '') {
        try {
            if ($existing) {
                // Values of fields that are inactive now are kept, not wiped.
                $data = $data + $existing['values'];
                if (visa_process_serial_taken($conn, $user_id, $serial, $id)) {
                    throw new RuntimeException('SL ' . $serial . ' is already used. Give another SL.');
                }
                $json = json_encode($data, JSON_UNESCAPED_UNICODE);
                $stmt = mysqli_prepare($conn, "UPDATE visa_process_entries SET serial_no=?, data=?, updated_at=NOW() WHERE id=? AND user_id=?");
                mysqli_stmt_bind_param($stmt, 'isii', $serial, $json, $id, $user_id);
                mysqli_stmt_execute($stmt);
            } else {
                // The SL left as suggested is taken fresh at save time, so two people saving
                // together both get a number; a changed SL is used as typed.
                $suggested = (int)($_POST['suggested_sl'] ?? 0);
                [$id, $serial] = visa_process_create($conn, $user_id, $data, (int)($_SESSION['login_user_id'] ?? $user_id), (string)($_SESSION['user_name'] ?? ''), $serial === $suggested ? 0 : $serial);
            }
            $_SESSION['visa_process_flash'] = ['success', 'SL ' . $serial . ' saved.'];
            // Save & Print opens the print page in this tab (a popup after a redirect is blocked by browsers).
            header('Location: ' . ($action === 'save_print' ? 'visa_processing_print.php?auto=1&id=' . $id : 'visa_processing.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    // Show the form again with what was typed.
    $entry_param = $existing ? (string)$id : 'new';
    $entry = $existing;
    $values = array_map('strval', (array)($_POST['f'] ?? []));
    $posted_sl = trim((string)($_POST['serial_no'] ?? ''));
}

if ($entry_param !== '' && $error === '') {
    if ($entry_param !== 'new') {
        $entry = visa_process_entry($conn, $user_id, (int)$entry_param);
        if (!$entry) {
            header('Location: visa_processing.php');
            exit;
        }
        $values = $entry['values'];
    }
}
$show_form = $entry_param !== '';

$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$rows = $show_form ? [] : visa_process_search($conn, $user_id, $search, 1000);
$list_fields = array_values(array_filter($fields, static fn($f) => (int)$f['show_in_list'] === 1));
$next_sl = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(serial_no),0)+1 n FROM visa_process_entries WHERE user_id={$user_id}"))['n'] ?? 1);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>
.vp-table td,.vp-table th{white-space:nowrap;vertical-align:middle}
.vp-table td.vp-wrap{white-space:normal;min-width:160px}
</style>
<section class="content pt-3"><div class="container-fluid">
    <?php if ($flash) { ?><div class="alert alert-<?= $h($flash[0]); ?>"><?= $h($flash[1]); ?></div><?php } ?>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= $h($error); ?></div><?php } ?>

<?php if ($show_form) { ?>
    <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-passport mr-2"></i><?= $entry ? 'Edit Entry' : 'New Visa Processing Entry'; ?></h3>
            <div class="card-tools"><a href="visa_processing.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-list mr-1"></i>Entry List</a></div></div>
        <form method="post" autocomplete="off">
            <div class="card-body">
                <?= travel_csrf_field(); ?>
                <input type="hidden" name="id" value="<?= (int)($entry['id'] ?? 0); ?>">
                <div class="row">
                    <div class="col-4 col-md-2 col-lg-1 form-group">
                        <label for="vf-serial">SL <span class="text-danger">*</span></label>
                        <input type="number" id="vf-serial" name="serial_no" class="form-control" min="1" step="1" required value="<?= $h($posted_sl !== '' ? $posted_sl : ($entry ? (int)$entry['serial_no'] : $next_sl)); ?>">
                        <?php if (!$entry) { ?><input type="hidden" name="suggested_sl" value="<?= $next_sl; ?>"><?php } ?>
                    </div>
                    <?php foreach ($fields as $field) { ?>
                        <div class="<?= $field['field_type'] === 'textarea' ? 'col-12' : 'col-md-4 col-lg-3'; ?> form-group">
                            <label for="vf-<?= $h($field['field_key']); ?>"><?= $h($field['label']); ?><?= (int)$field['is_required'] === 1 ? ' <span class="text-danger">*</span>' : ''; ?></label>
                            <?= visa_process_input($field, (string)($values[$field['field_key']] ?? '')); ?>
                        </div>
                    <?php } ?>
                </div>
                <?php if (!$fields) { ?><p class="text-muted mb-0">No active field. Add fields in Form Settings.</p><?php } ?>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" name="action" value="save"><i class="fas fa-save mr-1"></i>Save</button>
                <button class="btn btn-success" name="action" value="save_print"><i class="fas fa-print mr-1"></i>Save &amp; Print</button>
                <a href="visa_processing.php" class="btn btn-secondary">Cancel</a>
                <?php if (is_admin_user()) { ?><a href="visa_processing_settings.php" class="btn btn-link float-right"><i class="fas fa-cog mr-1"></i>Form Settings</a><?php } ?>
            </div>
        </form>
    </div>
<?php } else { ?>
    <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-passport mr-2"></i>Visa Processing</h3>
            <div class="card-tools">
                <?php if (is_admin_user()) { ?><a href="visa_processing_settings.php" class="btn btn-sm btn-outline-secondary mr-1"><i class="fas fa-cog mr-1"></i>Form Settings</a><?php } ?>
                <a href="?entry=new" class="btn btn-sm btn-info"><i class="fas fa-plus mr-1"></i>New Entry</a>
            </div></div>
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center mb-3" style="gap:.5rem">
                <form method="get" class="form-inline">
                    <input name="q" class="form-control form-control-sm" style="min-width:260px" placeholder="Search SL, name, passport, job ID…" value="<?= $h($search); ?>">
                    <button class="btn btn-sm btn-primary ml-1"><i class="fas fa-search"></i></button>
                    <?php if ($search !== '') { ?><a href="visa_processing.php" class="btn btn-sm btn-link">Clear</a><?php } ?>
                </form>
                <span class="text-muted small"><?= count($rows); ?> entr<?= count($rows) === 1 ? 'y' : 'ies'; ?><?= count($rows) >= 1000 ? ' (latest 1000 — search to narrow)' : ''; ?></span>
                <div class="ml-auto">
                    <button type="button" id="vp-print-selected" class="btn btn-sm btn-outline-primary" disabled><i class="fas fa-print mr-1"></i>Print Selected</button>
                    <a href="visa_processing_print.php?<?= $h(http_build_query(['q' => $search])); ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-print mr-1"></i>Print List</a>
                </div>
            </div>
            <div class="table-responsive"><table class="table table-bordered table-striped table-sm vp-table">
                <thead class="thead-light"><tr>
                    <th style="width:30px"><input type="checkbox" id="vp-check-all" title="Select all"></th>
                    <th>SL</th>
                    <?php foreach ($list_fields as $field) { ?><th><?= $h($field['label']); ?></th><?php } ?>
                    <th class="text-center">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row) { ?>
                    <tr>
                        <td><input type="checkbox" class="vp-check" value="<?= (int)$row['id']; ?>"></td>
                        <td class="font-weight-bold"><?= (int)$row['serial_no']; ?></td>
                        <?php foreach ($list_fields as $field) { ?>
                            <td class="<?= $field['field_type'] === 'textarea' ? 'vp-wrap' : ''; ?>"><?= $h(visa_process_display($field, $row['values'][$field['field_key']] ?? '')); ?></td>
                        <?php } ?>
                        <td class="text-center">
                            <a href="visa_processing_print.php?id=<?= (int)$row['id']; ?>" target="_blank" class="btn btn-xs btn-outline-primary" title="Print"><i class="fas fa-print"></i></a>
                            <a href="?entry=<?= (int)$row['id']; ?>" class="btn btn-xs btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php if (is_admin_user()) { ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete SL <?= (int)$row['serial_no']; ?>? This cannot be undone.');">
                                    <?= travel_csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id']; ?>">
                                    <button class="btn btn-xs btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$rows) { ?><tr><td colspan="<?= count($list_fields) + 3; ?>" class="text-center text-muted py-4"><?= $search !== '' ? 'No entry matches the search.' : 'No entry yet. Click "New Entry" to add one.'; ?></td></tr><?php } ?>
                </tbody>
            </table></div>
        </div>
    </div>
<?php } ?>
</div></section>
<?php
$page_script = '<script>
$(function(){
    function refresh(){
        var ids = $(".vp-check:checked").map(function(){ return this.value; }).get();
        $("#vp-print-selected").prop("disabled", ids.length === 0).data("ids", ids);
        $("#vp-check-all").prop("checked", ids.length > 0 && ids.length === $(".vp-check").length);
    }
    $(document).on("change", ".vp-check", refresh);
    $("#vp-check-all").on("change", function(){ $(".vp-check").prop("checked", this.checked); refresh(); });
    $("#vp-print-selected").on("click", function(){
        var ids = $(this).data("ids") || [];
        if(ids.length){ window.open("visa_processing_print.php?ids=" + ids.join(","), "_blank"); }
    });
});
</script>';
require_once __DIR__ . '/../includes/footer.php';
