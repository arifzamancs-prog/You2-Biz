<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/app_config.php';
require_once '../includes/system_notice_helper.php';

require_super_admin_user();
ensure_system_notice_table($conn);

$audiences = system_notice_audiences();
if (empty($_SESSION['system_notice_token'])) $_SESSION['system_notice_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['system_notice_token'];
$message = $_SESSION['system_notice_flash'] ?? '';
unset($_SESSION['system_notice_flash']);
$error = '';
$edit = null;

$id = (int)($_POST['notice_id'] ?? $_GET['edit'] ?? 0);
if ($id > 0) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM system_notices WHERE id=?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

$audience = $edit['audience'] ?? ($_GET['audience'] ?? 'login');
if (!isset($audiences[$audience])) $audience = 'login';
$title = $edit['title'] ?? '';
$body = $edit['message'] ?? '';
$show_from = $edit['show_from'] ?? '';
$show_to = $edit['show_to'] ?? '';

// '' for an empty field, the date for a valid one, false for anything else.
function system_notice_date_input($value)
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $title = trim((string)($_POST['title'] ?? $title));
    $body = trim((string)($_POST['message'] ?? $body));
    if (isset($_POST['audience'])) $audience = (string)$_POST['audience'];
    if (in_array($action, ['publish', 'update'], true)) {
        $show_from = system_notice_date_input($_POST['show_from'] ?? '');
        $show_to = system_notice_date_input($_POST['show_to'] ?? '');
    }

    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $error = 'Please reload the page and try again.';
    } elseif (!in_array($action, ['publish', 'update', 'show', 'hide', 'delete'], true)) {
        $error = 'Invalid action.';
    } elseif (!isset($audiences[$audience])) {
        $error = 'Select where the notice is shown.';
    } elseif ($action !== 'publish' && !$edit) {
        $error = 'Notice not found.';
    } elseif (in_array($action, ['publish', 'update'], true) && ($title === '' || $body === '')) {
        $error = 'Title and notice are required.';
    } elseif ($show_from === false || $show_to === false) {
        $error = 'Enter valid From and To dates.';
    } elseif ($show_from !== '' && $show_to !== '' && $show_from > $show_to) {
        $error = 'From date must be on or before To date.';
    } else {
        if ($edit && $action !== 'update') {
            $audience = $edit['audience'];
            $show_from = (string)($edit['show_from'] ?? '');
            $show_to = (string)($edit['show_to'] ?? '');
        }
        $from_value = $show_from === '' ? null : $show_from;
        $to_value = $show_to === '' ? null : $show_to;
        mysqli_begin_transaction($conn);
        try {
            // One visible notice per place and day: showing one hides the others of that
            // place whose dates overlap it. Notices on other dates stay as they are.
            if ($action === 'publish' || $action === 'show' || ($action === 'update' && $edit['status'] === 'active')) {
                $overlap_from = $from_value ?? '1000-01-01';
                $overlap_to = $to_value ?? '9999-12-31';
                $stmt = mysqli_prepare($conn, "UPDATE system_notices SET status='inactive'
                    WHERE audience=? AND status='active' AND id<>?
                    AND COALESCE(show_from, '1000-01-01') <= ? AND COALESCE(show_to, '9999-12-31') >= ?");
                mysqli_stmt_bind_param($stmt, 'siss', $audience, $id, $overlap_to, $overlap_from);
                if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Cannot hide previous notice.');
            }
            if ($action === 'publish') {
                $stmt = mysqli_prepare($conn, 'INSERT INTO system_notices (audience,title,message,show_from,show_to) VALUES (?,?,?,?,?)');
                mysqli_stmt_bind_param($stmt, 'sssss', $audience, $title, $body, $from_value, $to_value);
                $flash = 'Notice published successfully.';
            } elseif ($action === 'update') {
                $stmt = mysqli_prepare($conn, 'UPDATE system_notices SET audience=?, title=?, message=?, show_from=?, show_to=? WHERE id=?');
                mysqli_stmt_bind_param($stmt, 'sssssi', $audience, $title, $body, $from_value, $to_value, $id);
                $flash = 'Notice updated successfully.';
            } elseif ($action === 'delete') {
                $stmt = mysqli_prepare($conn, 'DELETE FROM system_notices WHERE id=?');
                mysqli_stmt_bind_param($stmt, 'i', $id);
                $flash = 'Notice deleted.';
            } else {
                $status = $action === 'show' ? 'active' : 'inactive';
                $stmt = mysqli_prepare($conn, 'UPDATE system_notices SET status=? WHERE id=?');
                mysqli_stmt_bind_param($stmt, 'si', $status, $id);
                $flash = $action === 'show' ? 'Notice is now shown.' : 'Notice hidden.';
            }
            if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Cannot save notice.');
            mysqli_commit($conn);
            $_SESSION['system_notice_flash'] = $flash;
            header('Location: notice_publish.php');
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error = 'Unable to save the notice. Please try again.';
        }
    }
}

$notices = mysqli_query($conn, 'SELECT * FROM system_notices ORDER BY published_at DESC, id DESC');

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="row">
 <div class="col-lg-5"><div class="card">
  <div class="card-header"><h3 class="card-title"><?= $edit ? 'Edit Notice' : 'Publish Notice' ?></h3></div>
  <form method="post">
   <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
   <input type="hidden" name="action" value="<?= $edit ? 'update' : 'publish' ?>">
   <?php if ($edit): ?><input type="hidden" name="notice_id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
   <div class="card-body">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="form-group">
     <label>Show On</label>
     <div>
      <?php foreach ($audiences as $key => $label): ?>
       <div class="custom-control custom-radio custom-control-inline">
        <input type="radio" class="custom-control-input" id="audience_<?= $key ?>" name="audience" value="<?= $key ?>" <?= $audience === $key ? 'checked' : '' ?>>
        <label class="custom-control-label" for="audience_<?= $key ?>"><?= htmlspecialchars($label) ?></label>
       </div>
      <?php endforeach; ?>
     </div>
     <small class="text-muted d-block mt-1">Login Page: everyone who opens the login page sees it. Admin Login: every company admin sees it once after logging in.</small>
    </div>
    <div class="form-group"><label for="noticeTitle">Title</label><input id="noticeTitle" name="title" required maxlength="180" class="form-control" value="<?= htmlspecialchars($title) ?>"></div>
    <div class="form-group"><label for="noticeMessage">Notice</label><textarea id="noticeMessage" name="message" required class="form-control" rows="6"><?= htmlspecialchars($body) ?></textarea></div>
    <div class="form-row">
     <div class="form-group col-sm-6 mb-1"><label for="noticeFrom">Show From <small class="text-muted">(Optional)</small></label><input type="date" id="noticeFrom" name="show_from" class="form-control" value="<?= htmlspecialchars((string)$show_from) ?>"></div>
     <div class="form-group col-sm-6 mb-1"><label for="noticeTo">Show To <small class="text-muted">(Optional)</small></label><input type="date" id="noticeTo" name="show_to" class="form-control" value="<?= htmlspecialchars((string)$show_to) ?>"></div>
    </div>
    <small class="text-muted d-block">The notice shows from the start of the From day to the end of the To day. Leave both empty to show it until you hide it.</small>
   </div>
   <div class="card-footer">
    <button type="submit" class="btn btn-primary"><i class="fas <?= $edit ? 'fa-save' : 'fa-bullhorn' ?>"></i> <?= $edit ? 'Update Notice' : 'Publish Notice' ?></button>
    <?php if ($edit): ?><a href="notice_publish.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
   </div>
  </form>
 </div></div>
 <div class="col-lg-7"><div class="card">
  <div class="card-header"><h3 class="card-title">Published Notices</h3></div>
  <div class="card-body table-responsive"><table class="table table-bordered notice-table">
   <thead><tr><th class="notice-sl">SL</th><th class="notice-where">Show On</th><th class="notice-title">Title</th><th class="notice-message">Notice</th><th class="notice-published">Show Dates</th><th class="notice-action">Action</th></tr></thead>
   <tbody>
   <?php $sl = 1; while ($row = mysqli_fetch_assoc($notices)): $active = $row['status'] === 'active'; ?>
    <tr>
     <td><?= $sl++ ?></td>
     <?php
     $state_badges = ['visible' => ['badge-success', 'Visible'], 'scheduled' => ['badge-warning', 'Scheduled'], 'expired' => ['badge-secondary', 'Expired'], 'hidden' => ['badge-light border', 'Hidden']];
     $state_badge = $state_badges[system_notice_state($row)];
     ?>
     <td><span class="badge <?= $row['audience'] === 'login' ? 'badge-info' : 'badge-primary' ?>"><?= htmlspecialchars($audiences[$row['audience']] ?? $row['audience']) ?></span><br><span class="badge <?= $state_badge[0] ?> mt-1"><?= $state_badge[1] ?></span></td>
     <td><?= htmlspecialchars($row['title']) ?></td>
     <td><?= nl2br(htmlspecialchars($row['message'])) ?></td>
     <td class="small">
      <?php if (empty($row['show_from']) && empty($row['show_to'])): ?>Always<?php else: ?>
       <?= !empty($row['show_from']) ? htmlspecialchars(date('d-m-Y', strtotime($row['show_from']))) : 'Any day' ?><br>to <?= !empty($row['show_to']) ? htmlspecialchars(date('d-m-Y', strtotime($row['show_to']))) : 'Any day' ?>
      <?php endif; ?>
      <div class="text-muted">Published <?= htmlspecialchars(date('d-m-Y', strtotime($row['published_at']))) ?></div>
     </td>
     <td>
      <a href="?edit=<?= (int)$row['id'] ?>" class="btn btn-info btn-sm mb-1" title="Edit" aria-label="Edit notice"><i class="fas fa-edit"></i></a>
      <form method="post" class="d-inline">
       <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
       <input type="hidden" name="action" value="<?= $active ? 'hide' : 'show' ?>">
       <input type="hidden" name="notice_id" value="<?= (int)$row['id'] ?>">
       <button type="submit" class="btn <?= $active ? 'btn-warning' : 'btn-success' ?> btn-sm mb-1" title="<?= $active ? 'Hide' : 'Show' ?>"><i class="fas <?= $active ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
      </form>
      <form method="post" class="d-inline" onsubmit="return confirm('Delete this notice?');">
       <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
       <input type="hidden" name="action" value="delete">
       <input type="hidden" name="notice_id" value="<?= (int)$row['id'] ?>">
       <button type="submit" class="btn btn-danger btn-sm mb-1" title="Delete" aria-label="Delete notice"><i class="fas fa-trash"></i></button>
      </form>
     </td>
    </tr>
   <?php endwhile; ?>
   </tbody>
  </table></div>
 </div></div>
</div>
<style>
.notice-table { table-layout: fixed; }
.notice-table .notice-sl { width: 6%; }
.notice-table .notice-where { width: 14%; }
.notice-table .notice-title { width: 18%; }
.notice-table .notice-message { width: 34%; }
.notice-table .notice-published { width: 13%; }
.notice-table .notice-action { width: 15%; }
.notice-table td:nth-child(3), .notice-table td:nth-child(4) { overflow-wrap: anywhere; }
</style>
<?php require_once '../includes/footer.php'; ?>
