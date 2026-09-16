<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

$uid = (int)$_SESSION['user_id'];
if (project_package_company_type($conn, $uid) !== 'Housing' || (is_manager_user() && !manager_has_permission('land_ledger'))) die('Access denied');

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS land_plots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    block_name VARCHAR(10) NOT NULL,
    plot_size DECIMAL(10,2) NOT NULL,
    total_plots INT UNSIGNED NOT NULL DEFAULT 0,
    sold_plots INT UNSIGNED NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(user_id), INDEX(project_id)
)");

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_plot'])) {
        $deleteId = (int)($_POST['plot_id'] ?? 0);
        $delete = mysqli_prepare($conn, 'DELETE FROM land_plots WHERE id=? AND user_id=?');
        mysqli_stmt_bind_param($delete, 'ii', $deleteId, $uid);
        mysqli_stmt_execute($delete);
        header('Location: plot_manage.php?deleted=1'); exit;
    }
    $projectId = (int)($_POST['project_id'] ?? 0);
    $block = strtoupper(trim($_POST['block_name'] ?? ''));
    $size = (float)($_POST['plot_size'] ?? 0);
    $total = (int)($_POST['total_plots'] ?? 0);
    $sold = (int)($_POST['sold_plots'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    if (!$projectId || !preg_match('/^[A-Z]$/', $block) || $size <= 0 || $total < 0 || $sold < 0 || $sold > $total) {
        $error = 'Project, Block (A-Z), plot size, total plot এবং সঠিক sold plot দিন।';
    } else {
        $check = mysqli_prepare($conn, 'SELECT id FROM projects WHERE id=? AND user_id=?');
        mysqli_stmt_bind_param($check, 'ii', $projectId, $uid); mysqli_stmt_execute($check);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($check))) $error = 'Selected project পাওয়া যায়নি।';
        else {
            $stmt = mysqli_prepare($conn, 'INSERT INTO land_plots (user_id, project_id, block_name, plot_size, total_plots, sold_plots, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'iisdiis', $uid, $projectId, $block, $size, $total, $sold, $note);
            if (mysqli_stmt_execute($stmt)) { header('Location: plot_manage.php?saved=1'); exit; }
            $error = 'Plot entry save করা যায়নি।';
        }
    }
}
$projects = mysqli_query($conn, "SELECT id, project_name FROM projects WHERE user_id={$uid} ORDER BY project_name");
$rows = mysqli_query($conn, "SELECT lp.*, p.project_name FROM land_plots lp JOIN projects p ON p.id=lp.project_id WHERE lp.user_id={$uid} ORDER BY p.project_name, lp.block_name, lp.plot_size");
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3>Plot Manage</h3></div><div class="card-body">
<?php if(isset($_GET['saved'])) { ?><div class="alert alert-success">Plot entry saved successfully.</div><script>if(window.history.replaceState){window.history.replaceState({},document.title,'plot_manage.php');}</script><?php } ?>
<?php if(isset($_GET['deleted'])) { ?><div class="alert alert-success">Plot entry deleted successfully.</div><script>if(window.history.replaceState){window.history.replaceState({},document.title,'plot_manage.php');}</script><?php } ?>
<?php if($error) { ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post"><div class="form-row"><div class="form-group col-md-4"><label>Select Project</label><select name="project_id" class="form-control" required><option value="">Select Project</option><?php while($p=mysqli_fetch_assoc($projects)) { ?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['project_name'])?></option><?php } ?></select></div><div class="form-group col-md-2"><label>Block</label><select name="block_name" class="form-control" required><option value="">Block</option><?php foreach(range('A','Z') as $block) { ?><option value="<?=$block?>"><?=$block?></option><?php } ?></select></div><div class="form-group col-md-2"><label>Plot Size (Katha)</label><select name="plot_size" class="form-control" required><option value="">Select size</option><?php foreach([3,5,10,12,20] as $size) { ?><option value="<?=$size?>"><?=$size?> Katha</option><?php } ?></select></div><div class="form-group col-md-2"><label>Total Plots</label><input name="total_plots" type="number" min="0" class="form-control" required></div><div class="form-group col-md-2"><label>Sold Plots</label><input name="sold_plots" type="number" min="0" value="0" class="form-control" required></div></div><div class="form-group"><label>Note</label><input name="note" class="form-control" placeholder="Optional note"></div><button class="btn btn-primary">Save Plot Entry</button></form>
<hr><h5>Plot Entry History</h5><style>.plot-summary-table{table-layout:fixed}.plot-summary-table th{white-space:nowrap}.plot-summary-table .plot-project{width:19%}.plot-summary-table .plot-block{width:7%}.plot-summary-table .plot-size{width:11%}.plot-summary-table .plot-total{width:10%}.plot-summary-table .plot-sold{width:9%}.plot-summary-table .plot-available{width:12%}.plot-summary-table .plot-note{width:24%;word-break:break-word}.plot-summary-table .plot-action{width:8%}</style><div class="table-responsive"><table class="table table-bordered table-striped plot-summary-table"><thead><tr><th class="plot-project">Project</th><th class="plot-block">Block</th><th class="plot-size">Plot Size</th><th class="plot-total">Total Plots</th><th class="plot-sold">Sold Plots</th><th class="plot-available">Available Plots</th><th class="plot-note">Note</th><th class="plot-action">Action</th></tr></thead><tbody><?php if(mysqli_num_rows($rows)) { while($row=mysqli_fetch_assoc($rows)) { ?><tr><td class="plot-project"><?=htmlspecialchars($row['project_name'])?></td><td class="plot-block"><?=htmlspecialchars($row['block_name'])?></td><td class="plot-size"><?=number_format($row['plot_size'], 2)?> Katha</td><td class="plot-total"><?=$row['total_plots']?></td><td class="plot-sold"><?=$row['sold_plots']?></td><td class="plot-available"><?=$row['total_plots']-$row['sold_plots']?></td><td class="plot-note"><?=htmlspecialchars($row['note'])?></td><td class="plot-action"><form method="post" onsubmit="return confirm('Delete this plot entry?');"><input type="hidden" name="delete_plot" value="1"><input type="hidden" name="plot_id" value="<?=$row['id']?>"><button class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button></form></td></tr><?php }} else { ?><tr><td colspan="8" class="text-center">No plot entry found.</td></tr><?php } ?></tbody></table></div>
</div></div><?php require_once '../includes/footer.php'; ?>
