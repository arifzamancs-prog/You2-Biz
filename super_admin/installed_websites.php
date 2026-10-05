<?php
require_once '../includes/auth.php';
require_super_admin_user();
require_once '../includes/db.php';
require_once '../includes/installation_reporting_helper.php';
installation_reporting_tables($conn);
$config = installation_reporting_config();
if (empty($_SESSION['installation_csrf'])) $_SESSION['installation_csrf'] = bin2hex(random_bytes(32));
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['installation_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid request.'); }
    if (($_POST['action'] ?? '') === 'test') {
        try { $message = installation_reporting_send($conn, true); }
        catch (Throwable $e) { $message = 'Connection test failed. Check hosting configuration.'; }
    } elseif ($config['receiver_enabled'] && ($_POST['action'] ?? '') === 'review') {
        $id = (string)($_POST['id'] ?? '');
        $stmt = mysqli_prepare($conn, 'UPDATE installation_checkins SET reviewed=1 WHERE installation_id=?');
        mysqli_stmt_bind_param($stmt, 's', $id);
        mysqli_stmt_execute($stmt);
        $message = 'Marked as seen.';
    }
}
$newCount = $config['receiver_enabled'] ? (int)mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS n FROM installation_checkins WHERE reviewed=0'))['n'] : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * 50;
$rows = $config['receiver_enabled'] ? mysqli_query($conn, "SELECT * FROM installation_checkins ORDER BY reviewed,last_seen DESC LIMIT 51 OFFSET {$offset}") : null;
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<section class="content"><div class="container-fluid"><div class="card">
<div class="card-header"><h3 class="card-title">Installed Websites <span class="badge badge-danger"><?= $newCount ?> New</span></h3></div>
<div class="card-body">
<?php if ($message !== '') { ?><div class="alert alert-info"><?= htmlspecialchars($message) ?></div><?php } ?>
<p>Installation reporting sends only the site URL and a random installation ID to you2biz.com. The server records first/last check-in times. No customer, invoice, password or sales data is sent.</p>
<p class="text-muted">For information only. Reporting does not block or restrict the software. Automatic check-in is attempted once per 24 hours while the app is in use. Times below are UTC.</p>
<?php if (!$config['receiver_enabled']) { ?><div class="alert alert-warning">Receiver is disabled here. The central installation list is available on the original you2biz.com hosting after setup. See docs/installation-reporting.md.</div><?php } ?>
<form method="post" class="mb-3"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['installation_csrf']) ?>"><input type="hidden" name="action" value="test"><button class="btn btn-primary">Test Connection</button></form>
<?php if ($rows) { ?>
<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Website</th><th>Installation ID</th><th>First Seen (UTC)</th><th>Last Active (UTC)</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php $count=0; while ($row=mysqli_fetch_assoc($rows)) { if (++$count > 50) break; ?>
<tr><td><a href="<?= htmlspecialchars($row['site_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($row['site_url']) ?></a></td><td class="text-break"><?= htmlspecialchars($row['installation_id']) ?></td><td><?= htmlspecialchars($row['first_seen']) ?></td><td><?= htmlspecialchars($row['last_seen']) ?></td><td><?= $row['reviewed'] ? 'Seen' : 'New' ?></td><td><?php if (!$row['reviewed']) { ?><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['installation_csrf']) ?>"><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= htmlspecialchars($row['installation_id']) ?>"><button class="btn btn-sm btn-secondary">Mark Seen</button></form><?php } ?></td></tr>
<?php } if ($count===0) { ?><tr><td colspan="6">No installation reports received yet.</td></tr><?php } ?>
</tbody></table></div>
<?php if ($page>1) { ?><a class="btn btn-light" href="?page=<?= $page-1 ?>">Previous</a><?php } if ($count>50) { ?><a class="btn btn-light" href="?page=<?= $page+1 ?>">Next</a><?php } ?>
<?php } ?>
</div></div></div></section>
<?php require_once '../includes/footer.php'; ?>
