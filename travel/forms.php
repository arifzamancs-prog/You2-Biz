<?php
// Manage Forms: the company makes its own forms. Each form has its own fields
// (Form Settings) and its own entry list; Visa Processing is the first form.
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/visa_processing_helper.php';

if (!visa_process_schema($conn)) {
    exit('Sheet tables could not be prepared.');
}
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$is_admin = is_admin_user();

$flash = $_SESSION['visa_form_flash'] ?? null;
unset($_SESSION['visa_form_flash']);
$back = static function ($type, $text, $to = 'forms.php') {
    $_SESSION['visa_form_flash'] = [$type, $text];
    header('Location: ' . $to);
    exit;
};

$error = '';
$edit = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    if (!$is_admin) {
        http_response_code(403);
        exit('Only the administrator can change sheets.');
    }
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['form_id'] ?? 0);
    $current = $id > 0 ? visa_process_form($conn, $user_id, $id) : null;
    if ($id > 0 && !$current) {
        $back('danger', 'Sheet not found.');
    }

    if ($action === 'delete') {
        if ((int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) n FROM visa_process_entries WHERE user_id={$user_id} AND form_id={$id}"))['n'] ?? 0) > 0) {
            $back('danger', '"' . $current['form_name'] . '" has entries, so it cannot be deleted. Set it Inactive to hide it.');
        }
        mysqli_query($conn, "DELETE FROM visa_process_fields WHERE user_id={$user_id} AND form_id={$id}");
        mysqli_query($conn, "DELETE FROM visa_process_forms WHERE user_id={$user_id} AND id={$id}");
        $back('success', '"' . $current['form_name'] . '" deleted.');
    }

    if ($action === 'up' || $action === 'down') {
        $list = visa_process_forms($conn, $user_id);
        $index = array_search($id, array_map(static fn($f) => (int)$f['id'], $list), true);
        $other = $action === 'up' ? $index - 1 : $index + 1;
        if ($index !== false && isset($list[$other])) {
            [$list[$index], $list[$other]] = [$list[$other], $list[$index]];
            $stmt = mysqli_prepare($conn, "UPDATE visa_process_forms SET sort_order=? WHERE id=? AND user_id=?");
            foreach ($list as $n => $item) {
                $sort = ($n + 1) * 10;
                $item_id = (int)$item['id'];
                mysqli_stmt_bind_param($stmt, 'iii', $sort, $item_id, $user_id);
                mysqli_stmt_execute($stmt);
            }
        }
        header('Location: forms.php');
        exit;
    }

    $edit = [
        'id' => $id,
        'form_name' => mb_substr(trim((string)($_POST['form_name'] ?? '')), 0, 120),
        'status' => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
    ];
    $same = mysqli_prepare($conn, "SELECT id FROM visa_process_forms WHERE user_id=? AND form_name=? AND id<>? LIMIT 1");
    mysqli_stmt_bind_param($same, 'isi', $user_id, $edit['form_name'], $id);
    mysqli_stmt_execute($same);
    if ($edit['form_name'] === '') {
        $error = 'Sheet name is required.';
    } elseif (mysqli_fetch_assoc(mysqli_stmt_get_result($same))) {
        $error = 'A sheet named "' . $edit['form_name'] . '" already exists.';
    } elseif ($current) {
        $stmt = mysqli_prepare($conn, "UPDATE visa_process_forms SET form_name=?, print_title='', status=? WHERE id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'ssii', $edit['form_name'], $edit['status'], $id, $user_id);
        mysqli_stmt_execute($stmt);
        $back('success', '"' . $edit['form_name'] . '" updated.');
    } else {
        $sort = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0)+10 n FROM visa_process_forms WHERE user_id={$user_id}"))['n'] ?? 10);
        $stmt = mysqli_prepare($conn, "INSERT INTO visa_process_forms (user_id, form_name, print_title, status, sort_order) VALUES (?,?,'',?,?)");
        mysqli_stmt_bind_param($stmt, 'issi', $user_id, $edit['form_name'], $edit['status'], $sort);
        mysqli_stmt_execute($stmt);
        $new_id = (int)mysqli_insert_id($conn);
        // A new form has no field yet: go straight to its Form Settings to add them.
        $back('success', '"' . $edit['form_name'] . '" created. Add its fields below.', 'visa_processing_settings.php?form=' . $new_id);
    }
}

$forms = visa_process_forms($conn, $user_id);
if (!$edit && $is_admin && (int)($_GET['edit'] ?? 0) > 0) {
    $edit = visa_process_form($conn, $user_id, (int)$_GET['edit']);
}
$edit = $edit ?: ['id' => 0, 'form_name' => '', 'status' => 'active'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid">
    <?php if ($flash) { ?><div class="alert alert-<?= $h($flash[0]); ?>"><?= $h($flash[1]); ?></div><?php } ?>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= $h($error); ?></div><?php } ?>
    <div class="row">
        <?php if ($is_admin) { ?>
        <div class="col-lg-4">
            <div class="card card-outline card-info">
                <div class="card-header"><h3 class="card-title"><?= (int)$edit['id'] > 0 ? 'Edit Sheet' : 'Create New Sheet'; ?></h3></div>
                <form method="post" autocomplete="off">
                    <div class="card-body">
                        <?= travel_csrf_field(); ?>
                        <input type="hidden" name="form_id" value="<?= (int)$edit['id']; ?>">
                        <div class="form-group"><label for="vff-name">Sheet Name <span class="text-danger">*</span></label>
                            <input id="vff-name" name="form_name" class="form-control" maxlength="120" required placeholder="e.g. Hajj Registration" value="<?= $h($edit['form_name']); ?>"></div>
                        <div class="form-group mb-0"><label for="vff-status">Status</label>
                            <select id="vff-status" name="status" class="form-control">
                                <option value="active"<?= $edit['status'] === 'active' ? ' selected' : ''; ?>>Active (shown in the menu)</option>
                                <option value="inactive"<?= $edit['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive (hidden from the menu)</option>
                            </select></div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary"><i class="fas fa-save mr-1"></i><?= (int)$edit['id'] > 0 ? 'Update Sheet' : 'Create Sheet'; ?></button>
                        <?php if ((int)$edit['id'] > 0) { ?><a href="forms.php" class="btn btn-secondary">Cancel</a><?php } ?>
                    </div>
                </form>
            </div>
        </div>
        <?php } ?>
        <div class="<?= $is_admin ? 'col-lg-8' : 'col-12'; ?>">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-clipboard-list mr-2"></i>Sheets</h3></div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-bordered table-striped table-sm mb-0">
                        <thead><tr><?php if ($is_admin) { ?><th style="width:70px">Order</th><?php } ?><th>Sheet</th><th class="text-center">Fields</th><th class="text-center">Entries</th><th>Status</th><th style="width:1%">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($forms as $n => $form) { $fid = (int)$form['id']; ?>
                            <tr>
                                <?php if ($is_admin) { ?><td class="text-nowrap">
                                    <form method="post" class="d-inline"><?= travel_csrf_field(); ?><input type="hidden" name="form_id" value="<?= $fid; ?>"><button class="btn btn-xs btn-outline-secondary" name="action" value="up" title="Move up"<?= $n === 0 ? ' disabled' : ''; ?>><i class="fas fa-arrow-up"></i></button><button class="btn btn-xs btn-outline-secondary ml-1" name="action" value="down" title="Move down"<?= $n === count($forms) - 1 ? ' disabled' : ''; ?>><i class="fas fa-arrow-down"></i></button></form>
                                </td><?php } ?>
                                <td><a href="visa_processing.php?form=<?= $fid; ?>" class="font-weight-bold"><?= $h($form['form_name']); ?></a></td>
                                <td class="text-center"><?= (int)$form['field_count']; ?></td>
                                <td class="text-center"><?= (int)$form['entry_count']; ?></td>
                                <td><span class="badge badge-<?= $form['status'] === 'active' ? 'success' : 'secondary'; ?>"><?= $h(ucfirst($form['status'])); ?></span></td>
                                <td class="text-nowrap">
                                    <a href="visa_processing.php?form=<?= $fid; ?>&amp;entry=new" class="btn btn-xs btn-info" title="New Entry"><i class="fas fa-plus"></i></a>
                                    <a href="visa_processing.php?form=<?= $fid; ?>" class="btn btn-xs btn-outline-primary" title="Entry List"><i class="fas fa-list"></i></a>
                                    <?php if ($is_admin) { ?>
                                        <a href="visa_processing_settings.php?form=<?= $fid; ?>" class="btn btn-xs btn-outline-secondary" title="Sheet Settings (fields)"><i class="fas fa-cog"></i></a>
                                        <a href="?edit=<?= $fid; ?>" class="btn btn-xs btn-warning" title="Rename / status"><i class="fas fa-edit"></i></a>
                                        <?php if ((int)$form['entry_count'] === 0) { ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete the sheet &quot;<?= $h($form['form_name']); ?>&quot; and its fields?');"><?= travel_csrf_field(); ?><input type="hidden" name="form_id" value="<?= $fid; ?>"><button class="btn btn-xs btn-danger" name="action" value="delete" title="Delete"><i class="fas fa-trash"></i></button></form>
                                        <?php } ?>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                        <?php if (!$forms) { ?><tr><td colspan="6" class="text-center text-muted py-4">No sheet yet.</td></tr><?php } ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">Each sheet keeps its own fields and its own entry list with its own SL numbers. A sheet with entries cannot be deleted; set it Inactive to hide it from the menu.</div>
            </div>
        </div>
    </div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
