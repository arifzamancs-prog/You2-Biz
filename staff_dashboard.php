<?php
require_once 'includes/notice_board_helper.php';

ensure_notice_board_table($conn);
$company_id = (int)$_SESSION['user_id'];
$login_user_id = (int)($_SESSION['login_user_id'] ?? 0);
$login_stmt = mysqli_prepare($conn, 'SELECT last_login FROM users WHERE id=? LIMIT 1');
mysqli_stmt_bind_param($login_stmt, 'i', $login_user_id);
mysqli_stmt_execute($login_stmt);
$login_row = mysqli_fetch_assoc(mysqli_stmt_get_result($login_stmt)) ?: [];
$notice_stmt = mysqli_prepare($conn, "SELECT title, message, published_at FROM notice_board WHERE user_id=? AND status='active' ORDER BY published_at DESC LIMIT 1");
mysqli_stmt_bind_param($notice_stmt, 'i', $company_id);
mysqli_stmt_execute($notice_stmt);
$notices = mysqli_stmt_get_result($notice_stmt);
$quick_links = [
    'staff' => ['Staff Manage', 'staff/index.php', 'fas fa-user-tie'],
    'sales' => ['Sales', 'create_invoice/index.php', 'fas fa-file-invoice'],
    'wallets' => ['Wallets', 'wallets/index.php', 'fas fa-wallet'],
    'projects' => ['Project & Package', 'project_package/projects.php', 'fas fa-project-diagram'],
    'customers' => ['Customer Manage', 'customers/index.php', 'fas fa-users'],
    'leads' => ['Lead Management', 'lead_management/index.php', 'fas fa-filter'],
    'suppliers' => ['Supplier', 'suppliers/index.php', 'fas fa-truck'],
];
require_once 'includes/header.php'; require_once 'includes/navbar.php'; require_once 'includes/sidebar.php';
?>
<div class="content-header"><div class="container-fluid"><h1 class="m-0">Welcome, <?= htmlspecialchars($_SESSION['login_name'] ?? 'Staff') ?></h1><p class="text-muted mb-0">Your work dashboard</p></div></div>
<section class="content"><div class="container-fluid">
 <div class="row"><div class="col-lg-4"><div class="small-box bg-info"><div class="inner"><h4><?= htmlspecialchars(date('d M Y')) ?></h4><p>Today</p></div><div class="icon"><i class="fas fa-calendar-day"></i></div></div></div><div class="col-lg-4"><div class="small-box bg-success"><div class="inner"><h4><?= htmlspecialchars($login_row['last_login'] ? date('h:i A', strtotime($login_row['last_login'])) : '—') ?></h4><p>Last Login</p></div><div class="icon"><i class="fas fa-sign-in-alt"></i></div></div></div><div class="col-lg-4"><div class="small-box bg-secondary"><div class="inner"><h4><?= count($_SESSION['access_permissions'] ?? []) ?></h4><p>Assigned Permissions</p></div><div class="icon"><i class="fas fa-key"></i></div></div></div></div>
 <div class="row"><div class="col-lg-7"><div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-bullhorn mr-2"></i>Notice Board</h3></div><div class="card-body"><?php if(mysqli_num_rows($notices) === 0){ ?><p class="text-muted mb-0">No notice has been published yet.</p><?php }else{ while($notice=mysqli_fetch_assoc($notices)){ ?><div class="border-bottom pb-2 mb-3"><strong><?= htmlspecialchars($notice['title']) ?></strong><small class="text-muted float-right"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($notice['published_at']))) ?></small><div class="mt-1"><?= nl2br(htmlspecialchars($notice['message'])) ?></div></div><?php }} ?></div></div></div><div class="col-lg-5"><div class="card"><div class="card-header"><h3 class="card-title">Your Assigned Options</h3></div><div class="card-body"><div class="row"><?php foreach($quick_links as $permission => $link){ if(manager_has_permission($permission)){ ?><div class="col-6 mb-2"><a class="btn btn-outline-primary btn-block text-left" href="<?= htmlspecialchars(app_path($link[1])) ?>"><i class="<?= htmlspecialchars($link[2]) ?> mr-1"></i><?= htmlspecialchars($link[0]) ?></a></div><?php }} ?></div></div></div></div></div>
</div></section>
<?php require_once 'includes/footer.php'; ?>
