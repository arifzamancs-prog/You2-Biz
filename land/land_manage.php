<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';

$uid = (int)$_SESSION['user_id'];
if(project_package_company_type($conn, $uid) !== 'Housing' || (is_manager_user() && !manager_has_permission('land_ledger'))) die('Access denied');

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS land_entries (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,project_id INT UNSIGNED NOT NULL,entry_type ENUM('Land Purchase','Land Sale','Unused Land') NOT NULL,land_size DECIMAL(12,2) NOT NULL,unit VARCHAR(20) NOT NULL,party VARCHAR(255) NULL,entry_date DATE NULL,note TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(user_id),INDEX(project_id))");
function land_add_column($conn, $column, $definition){
    $result = mysqli_query($conn, "SHOW COLUMNS FROM land_entries LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
    if(!$result || mysqli_num_rows($result) === 0) mysqli_query($conn, "ALTER TABLE land_entries ADD COLUMN `{$column}` {$definition}");
}
land_add_column($conn, 'file_no', 'VARCHAR(100) NULL AFTER entry_type');
land_add_column($conn, 'dag_no', 'VARCHAR(100) NULL AFTER project_id');
land_add_column($conn, 'khatian_no', 'VARCHAR(100) NULL AFTER dag_no');
land_add_column($conn, 'mouza', 'VARCHAR(150) NULL AFTER khatian_no');

function land_values($conn, $uid, $field){
    if(!in_array($field, ['file_no','dag_no','khatian_no','mouza'], true)) return [];
    $stmt = mysqli_prepare($conn, "SELECT DISTINCT TRIM(`{$field}`) value FROM land_entries WHERE user_id=? AND TRIM(COALESCE(`{$field}`,''))<>'' ORDER BY value");
    mysqli_stmt_bind_param($stmt, 'i', $uid); mysqli_stmt_execute($stmt); $result = mysqli_stmt_get_result($stmt); $values=[];
    while($row=mysqli_fetch_assoc($result)) $values[]=$row['value'];
    return $values;
}

$error='';
$form=['entry_type'=>'Land Purchase','file_no'=>'','project_id'=>'','dag_no'=>'','khatian_no'=>'','mouza'=>'','land_size'=>'','unit'=>'Decimal','party'=>'','entry_date'=>date('d-m-Y'),'note'=>''];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(isset($_POST['delete_land_entry'])){
        $id=(int)($_POST['land_entry_id']??0); $stmt=mysqli_prepare($conn,'DELETE FROM land_entries WHERE id=? AND user_id=?'); mysqli_stmt_bind_param($stmt,'ii',$id,$uid); mysqli_stmt_execute($stmt); header('Location: land_manage.php?deleted=1'); exit;
    }
    foreach($form as $key=>$default) if(isset($_POST[$key])) $form[$key]=trim((string)$_POST[$key]);
    $project_id=(int)$form['project_id']; $type=$form['entry_type']; $size=(float)$form['land_size']; $unit=$form['unit']; $date=DateTime::createFromFormat('d-m-Y',$form['entry_date']);
    if(!$project_id||!in_array($type,['Land Purchase','Land Sale','Unused Land'],true)||$size<=0||!in_array($unit,['Decimal','Katha','Bigha'],true)||!$date||($form['dag_no']!==''&&!in_array($form['dag_no'],['CS/SA','RS','BRS'],true))||($form['khatian_no']!==''&&!in_array($form['khatian_no'],['CS','SA','RS','BRS'],true))) $error='সবগুলো প্রয়োজনীয় তথ্য সঠিকভাবে দিন।';
    else{
        if($unit==='Katha') $size*=1.65; elseif($unit==='Bigha') $size*=33; $unit='Decimal'; $db_date=$date->format('Y-m-d');
        $stmt=mysqli_prepare($conn,'INSERT INTO land_entries (user_id,project_id,entry_type,file_no,dag_no,khatian_no,mouza,land_size,unit,party,entry_date,note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt,'iisssssdssss',$uid,$project_id,$type,$form['file_no'],$form['dag_no'],$form['khatian_no'],$form['mouza'],$size,$unit,$form['party'],$db_date,$form['note']);
        if(mysqli_stmt_execute($stmt)){header('Location: land_manage.php?saved=1');exit;} $error='Land entry save করা যায়নি。';
    }
}
$projects=mysqli_query($conn,"SELECT id,project_name FROM projects WHERE user_id={$uid} ORDER BY project_name");
$history=mysqli_query($conn,"SELECT le.*,p.project_name FROM land_entries le JOIN projects p ON p.id=le.project_id WHERE le.user_id={$uid} ORDER BY le.entry_date DESC,le.id DESC");
$file_numbers=land_values($conn,$uid,'file_no');
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card"><div class="card-header"><h3>Land Purchase / Sale / Unused Land</h3></div><div class="card-body">
<?php if(isset($_GET['saved'])){ ?><div class="alert alert-success">Land entry saved successfully.</div><script>history.replaceState&&history.replaceState({},document.title,'land_manage.php');</script><?php } ?><?php if(isset($_GET['deleted'])){ ?><div class="alert alert-success">Land entry deleted successfully.</div><script>history.replaceState&&history.replaceState({},document.title,'land_manage.php');</script><?php } ?><?php if($error){ ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php } ?>
<form method="post">
<div class="form-row"><div class="form-group col-md-6"><label>Entry Type</label><select name="entry_type" class="form-control"><?php foreach(['Land Purchase','Land Sale','Unused Land'] as $v){ ?><option <?=$form['entry_type']===$v?'selected':''?>><?=$v?></option><?php } ?></select></div><div class="form-group col-md-6"><label>File No.</label><input name="file_no" list="file_options" value="<?=htmlspecialchars($form['file_no'])?>" class="form-control" placeholder="Select or enter File No."><datalist id="file_options"><?php foreach($file_numbers as $v){ ?><option value="<?=htmlspecialchars($v)?>"><?php } ?></datalist></div></div>
<div class="form-row"><div class="form-group col-md-3"><label>Select Project</label><select name="project_id" class="form-control" required><option value="">Select Project</option><?php while($p=mysqli_fetch_assoc($projects)){ ?><option value="<?=$p['id']?>" <?=strval($form['project_id'])===strval($p['id'])?'selected':''?>><?=htmlspecialchars($p['project_name'])?></option><?php } ?></select></div><div class="form-group col-md-3"><label>Dag No.</label><select name="dag_no" class="form-control"><option value="">Select Dag No.</option><?php foreach(['CS/SA','RS','BRS'] as $v){ ?><option value="<?=$v?>" <?=$form['dag_no']===$v?'selected':''?>><?=$v?></option><?php } ?></select></div><div class="form-group col-md-3"><label>Khatian No.</label><select name="khatian_no" class="form-control"><option value="">Select Khatian No.</option><?php foreach(['CS','SA','RS','BRS'] as $v){ ?><option value="<?=$v?>" <?=$form['khatian_no']===$v?'selected':''?>><?=$v?></option><?php } ?></select></div><div class="form-group col-md-3"><label>Mouza</label><input name="mouza" value="<?=htmlspecialchars($form['mouza'])?>" class="form-control" placeholder="Enter Mouza"></div></div>
<div class="form-row"><div class="form-group col-md-4"><label>Land Size</label><input name="land_size" id="land_size" type="number" step="0.01" value="<?=htmlspecialchars($form['land_size'])?>" class="form-control" required oninput="showDecimalConversion()"><small id="decimal_conversion" class="form-text text-muted" style="display:none"></small></div><div class="form-group col-md-4"><label>Unit</label><select name="unit" id="land_unit" class="form-control" onchange="showDecimalConversion()"><?php foreach(['Decimal','Katha','Bigha'] as $v){ ?><option <?=$form['unit']===$v?'selected':''?>><?=$v?></option><?php } ?></select></div><div class="form-group col-md-4"><label>Purchase from / Sale to</label><input name="party" value="<?=htmlspecialchars($form['party'])?>" class="form-control"></div></div>
<div class="form-row"><div class="form-group col-md-4"><label>Date <small class="text-muted">(DD-MM-YYYY)</small></label><div class="input-group"><input name="entry_date" id="entry_date_text" type="text" value="<?=htmlspecialchars($form['entry_date'])?>" placeholder="DD-MM-YYYY" pattern="\d{2}-\d{2}-\d{4}" class="form-control"><div class="input-group-append"><input id="entry_date_picker" type="date" value="<?=date('Y-m-d')?>" class="form-control" style="max-width:52px;padding:6px" onchange="var d=this.value.split('-');if(d.length===3)document.getElementById('entry_date_text').value=d[2]+'-'+d[1]+'-'+d[0];"></div></div></div><div class="form-group col-md-8"><label>Note</label><input name="note" value="<?=htmlspecialchars($form['note'])?>" class="form-control"></div></div><button class="btn btn-primary">Save Entry</button></form>
<hr><h4>Land Entry History</h4><style>.land-history-table{table-layout:fixed}.land-history-party{width:11%;word-break:break-word}.land-history-note{width:20%;word-break:break-word}</style><div class="table-responsive"><table class="table table-bordered table-striped land-history-table"><thead><tr><th>Date</th><th>Project</th><th>Entry Type</th><th>File No.</th><th>Dag No.</th><th>Khatian No.</th><th>Mouza</th><th>Land Size</th><th class="land-history-party">Purchase From / Sale To</th><th class="land-history-note">Note</th><th>Action</th></tr></thead><tbody><?php if(mysqli_num_rows($history)){ while($row=mysqli_fetch_assoc($history)){ ?><tr><td><?=$row['entry_date']?date('d-m-Y',strtotime($row['entry_date'])):''?></td><td><?=htmlspecialchars($row['project_name'])?></td><td><?=htmlspecialchars($row['entry_type'])?></td><td><?=htmlspecialchars($row['file_no']??'')?></td><td><?=htmlspecialchars($row['dag_no']??'')?></td><td><?=htmlspecialchars($row['khatian_no']??'')?></td><td><?=htmlspecialchars($row['mouza']??'')?></td><td><?=number_format((float)$row['land_size'],2)?> <?=htmlspecialchars($row['unit'])?></td><td class="land-history-party"><?=htmlspecialchars($row['party'])?></td><td class="land-history-note"><?=htmlspecialchars($row['note'])?></td><td><form method="post" onsubmit="return confirm('Delete this land entry?');"><input type="hidden" name="delete_land_entry" value="1"><input type="hidden" name="land_entry_id" value="<?=$row['id']?>"><button class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button></form></td></tr><?php }}else{ ?><tr><td colspan="11" class="text-center">No land entry found.</td></tr><?php } ?></tbody></table></div></div></div>
<script>
function showDecimalConversion(){var s=parseFloat(document.getElementById('land_size').value)||0,u=document.getElementById('land_unit').value,h=document.getElementById('decimal_conversion');if(u==='Katha'&&s>0){h.textContent='Converted value: '+(s*1.65).toFixed(2)+' Decimal';h.style.display='block';}else if(u==='Bigha'&&s>0){h.textContent='Converted value: '+(s*33).toFixed(2)+' Decimal';h.style.display='block';}else{h.textContent='';h.style.display='none';}}
</script>
<?php require_once '../includes/footer.php'; ?>
