<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/restaurant_table_helper.php';
require_once '../includes/staff_helper.php';

ensure_staff_table($conn);
ensure_restaurant_tables_table($conn);
$user_id = (int)$_SESSION['user_id'];

if (!table_system_enabled($conn, $user_id)) {
    header('Location: ../dashboard.php');
    exit;
}

if (isset($_GET['toggle']) && manager_can_modify()) {
    $id = (int)$_GET['toggle'];
    $stmt = mysqli_prepare($conn, "UPDATE restaurant_tables SET status=IF(status='active','inactive','active') WHERE id=? AND user_id=?");
    mysqli_stmt_bind_param($stmt, 'ii', $id, $user_id);
    mysqli_stmt_execute($stmt);
    header('Location: index.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT rt.*, s.name AS staff_name, s.staff_code FROM restaurant_tables rt LEFT JOIN staff s ON s.id=rt.staff_id AND s.user_id=rt.user_id WHERE rt.user_id=? ORDER BY rt.table_name ASC");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$tables = mysqli_stmt_get_result($stmt);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<div class="card">
  <div class="card-header"><h3 class="card-title"><i class="fas fa-chair mr-2"></i>Restaurant Tables</h3><?php if(manager_can_modify()){ ?><div class="card-tools"><a href="assign.php" class="btn btn-success btn-sm mr-1"><i class="fas fa-user-check"></i> Assign Tables</a><a href="create.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Create Table</a></div><?php } ?></div>
  <div class="card-body">
    <table id="example1" class="table table-bordered table-striped">
      <thead><tr><th>Table Name / No.</th><th>Capacity</th><th>Assigned Staff</th><th>Status</th><th>Action</th></tr></thead>
      <tbody><?php while($row=mysqli_fetch_assoc($tables)){ ?><tr><td><?=htmlspecialchars($row['table_name'])?></td><td><?= $row['capacity'] !== null ? (int)$row['capacity'] : '-' ?></td><td><?php if($row['staff_name']){ ?><span class="badge badge-info"><?=htmlspecialchars($row['staff_name'])?><?= $row['staff_code'] ? ' (' . htmlspecialchars($row['staff_code']) . ')' : '' ?></span><?php }else{ ?><span class="text-muted">Unassigned</span><?php } ?></td><td><span class="badge badge-<?=$row['status']==='active'?'success':'secondary'?>"><?=htmlspecialchars(ucfirst($row['status']))?></span></td><td><?php if(manager_can_modify()){ ?><a class="btn btn-sm btn-<?=$row['status']==='active'?'secondary':'success'?>" href="index.php?toggle=<?=$row['id']?>"><?=$row['status']==='active'?'Disable':'Enable'?></a><?php } ?></td></tr><?php } ?></tbody>
    </table>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
