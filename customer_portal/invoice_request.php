<?php
session_start();

require_once '../includes/db.php';
require_once '../includes/customer_portal_helper.php';

require_customer_portal_login();
$customer = customer_portal_current_customer($conn);
if(!$customer){
    session_destroy();
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$customer['user_id'];
$customer_id = (int)$customer['id'];
ensure_invoice_request_table($conn);
ensure_booking_invoice_table($conn);
ensure_booking_invoice_type_table($conn, $user_id);
$invoice_types = booking_invoice_types($conn, $user_id, false);

$message = '';
$message_type = 'success';
$request_action = $_POST['action'] ?? '';
if($_SERVER['REQUEST_METHOD'] === 'POST' && $request_action === 'resubmit_rejected_request'){
    $request_id = (int)($_POST['request_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "UPDATE invoice_requests SET status='pending', completed_invoice_id=NULL, completed_at=NULL WHERE id=? AND user_id=? AND customer_id=? AND status='rejected'");
    mysqli_stmt_bind_param($stmt, 'iii', $request_id, $user_id, $customer_id);
    mysqli_stmt_execute($stmt);
    $_SESSION['invoice_request_flash'] = ['message' => 'Invoice request resubmitted and is pending review.', 'type' => 'success'];
    header('Location: invoice_request.php');
    exit;
}
if($_SERVER['REQUEST_METHOD'] === 'POST' && $request_action === 'remove_rejected_request'){
    $request_id = (int)($_POST['request_id'] ?? 0);
    $photo_stmt = mysqli_prepare($conn, "SELECT photo FROM invoice_requests WHERE id=? AND user_id=? AND customer_id=? AND status='rejected' LIMIT 1");
    mysqli_stmt_bind_param($photo_stmt, 'iii', $request_id, $user_id, $customer_id);
    mysqli_stmt_execute($photo_stmt);
    $photo_row = mysqli_fetch_assoc(mysqli_stmt_get_result($photo_stmt));
    $delete_stmt = mysqli_prepare($conn, "DELETE FROM invoice_requests WHERE id=? AND user_id=? AND customer_id=? AND status='rejected'");
    mysqli_stmt_bind_param($delete_stmt, 'iii', $request_id, $user_id, $customer_id);
    mysqli_stmt_execute($delete_stmt);
    if(mysqli_stmt_affected_rows($delete_stmt) > 0 && !empty($photo_row['photo'])){
        $photo_path = dirname(__DIR__) . '/uploads/invoice_requests/' . basename($photo_row['photo']);
        if(is_file($photo_path)) unlink($photo_path);
    }
    $_SESSION['invoice_request_flash'] = ['message' => 'Rejected invoice request removed.', 'type' => 'success'];
    header('Location: invoice_request.php');
    exit;
}
if($_SERVER['REQUEST_METHOD'] === 'POST' && $request_action === ''){
    $note = trim((string)($_POST['note'] ?? ''));
    $photo_message = '';
    $photo = customer_portal_upload_invoice_request_photo($_FILES['photo'] ?? null, $photo_message);
    if($photo === false){
        $message = $photo_message;
        $message_type = 'danger';
    }else{
        $stmt = mysqli_prepare($conn, 'INSERT INTO invoice_requests (user_id, customer_id, note, photo) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iiss', $user_id, $customer_id, $note, $photo);
        if(mysqli_stmt_execute($stmt)){
            $_SESSION['invoice_request_flash'] = ['message' => 'Invoice request submitted. It is now pending review.', 'type' => 'success'];
            header('Location: invoice_request.php');
            exit;
        }
        $message = 'Invoice request could not be submitted.';
        $message_type = 'danger';
    }
}
if(isset($_SESSION['invoice_request_flash'])){
    $flash = $_SESSION['invoice_request_flash'];
    unset($_SESSION['invoice_request_flash']);
    $message = (string)($flash['message'] ?? '');
    $message_type = (string)($flash['type'] ?? 'success');
}

$requests = [];
$stmt = mysqli_prepare($conn, 'SELECT ir.id, ir.note, ir.photo, ir.status, ir.created_at, ir.completed_at, bi.invoice_type, bi.amount, p.project_name, pk.package_name FROM invoice_requests ir LEFT JOIN booking_invoices bi ON bi.id=ir.completed_invoice_id AND bi.user_id=ir.user_id LEFT JOIN projects p ON p.id=bi.project_id AND p.user_id=bi.user_id LEFT JOIN packages pk ON pk.id=bi.package_id AND pk.user_id=bi.user_id WHERE ir.user_id=? AND ir.customer_id=? ORDER BY ir.id DESC');
mysqli_stmt_bind_param($stmt, 'ii', $user_id, $customer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while($result && $row = mysqli_fetch_assoc($result)) $requests[] = $row;
?>
<!DOCTYPE html>
<html lang="en"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice Requests - You2 Biz</title>
    <link rel="stylesheet" href="../adminlte/plugins/fontawesome-free/css/all.min.css"><link rel="stylesheet" href="../adminlte/dist/css/adminlte.min.css">
    <style>
        body{background:#f1f6fb;color:#0f172a}.shell{max-width:980px;margin:auto;padding:26px}.nav{align-items:center;background:#fff;border-radius:16px;display:flex;justify-content:space-between;margin-bottom:18px;padding:14px 18px}.nav h1{font-size:20px;font-weight:800;margin:0}.card{border:0;border-radius:16px;box-shadow:0 8px 22px rgba(15,23,42,.08)}.card-header{background:#fff;border-bottom:1px solid #e2e8f0;font-weight:800}.request-image{border-radius:8px;height:62px;object-fit:cover;width:62px}.badge{font-size:12px;padding:6px 9px}.hint{font-size:12px}.request-history-table{table-layout:fixed}.request-history-table th:nth-child(1){width:14%}.request-history-table th:nth-child(2){width:42%}.request-history-table th:nth-child(3){width:15%}.request-history-table th:nth-child(4){width:13%}.request-history-table th:nth-child(5){width:16%}.history-note{white-space:pre-wrap;word-break:break-word}.completed-invoice-details{border-top:1px solid #dee2e6;color:#475569;font-size:12px;margin-top:8px;padding-top:8px}@media(max-width:767px){.shell{padding:14px}.table-responsive{font-size:13px}}
    </style>
</head><body><main class="shell">
    <nav class="nav"><h1><i class="fas fa-file-invoice mr-2"></i>Invoice Requests</h1><a class="btn btn-outline-secondary" href="index.php"><i class="fas fa-arrow-left"></i> Dashboard</a></nav>
    <?php if($message !== ''){ ?><div class="alert alert-<?= htmlspecialchars($message_type); ?>"><?= htmlspecialchars($message); ?></div><?php } ?>
    <section class="card mb-4"><div class="card-header">New Invoice Request</div><div class="card-body"><form method="post" enctype="multipart/form-data" id="invoice-request-form">
        <div class="form-group"><label>Photo</label><input type="file" name="photo" id="invoice-request-photo" class="form-control" accept="image/jpeg,image/png,image/webp" required><div id="photo-compression-status" class="hint text-muted mt-1"></div></div>
        <div class="form-group"><label>Note</label><textarea name="note" rows="4" class="form-control" placeholder="Write a note for the invoice team"></textarea></div>
        <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> Submit Request</button>
    </form></div></section>
    <section class="card"><div class="card-header">Request History</div><div class="card-body table-responsive p-0"><table class="table table-bordered mb-0 request-history-table"><thead><tr><th>Photo</th><th>Note</th><th>Submitted</th><th>Status</th><th>Completed</th></tr></thead><tbody>
    <?php if(empty($requests)){ ?><tr><td colspan="5" class="text-center text-muted py-4">No invoice requests yet.</td></tr><?php } ?>
    <?php foreach($requests as $request){ $url = invoice_request_photo_url($request['photo']); $status_class = $request['status'] === 'pending' ? 'warning' : ($request['status'] === 'rejected' ? 'danger' : 'success'); ?><tr><td><?php if($url !== ''){ ?><a href="<?= htmlspecialchars($url); ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($url); ?>" class="request-image" alt="Request photo"></a><?php } ?></td><td class="history-note"><?php if($request['status'] === 'completed' && !empty($request['invoice_type'])){ ?><?= htmlspecialchars(booking_invoice_type_label($request['invoice_type'], $invoice_types) . ' || ' . ($request['project_name'] ?: '-') . ' || ' . ($request['package_name'] ?: '-') . ' || ' . number_format((float)$request['amount'], 2) . '/-'); ?><?php }else{ ?><?= htmlspecialchars($request['note'] ?: '-'); ?><?php } ?></td><td><?= htmlspecialchars(date('d-m-Y', strtotime($request['created_at']))); ?></td><td><span class="badge badge-<?= $status_class; ?>"><?= htmlspecialchars(ucfirst($request['status'])); ?></span></td><td><?php if($request['status'] === 'rejected'){ ?><form method="post" class="d-inline"><input type="hidden" name="action" value="resubmit_rejected_request"><input type="hidden" name="request_id" value="<?= (int)$request['id']; ?>"><button type="submit" class="btn btn-primary btn-sm mb-1"><i class="fas fa-redo"></i> Resubmit</button></form><form method="post" class="d-inline" onsubmit="return confirm('Remove this rejected invoice request?');"><input type="hidden" name="action" value="remove_rejected_request"><input type="hidden" name="request_id" value="<?= (int)$request['id']; ?>"><button type="submit" class="btn btn-danger btn-sm mb-1"><i class="fas fa-trash"></i> Remove</button></form><?php }else{ ?><?= $request['completed_at'] ? htmlspecialchars(date('d-m-Y', strtotime($request['completed_at']))) : '-'; ?><?php } ?></td></tr><?php } ?>
    </tbody></table></div></section>
</main><script>
(function(){
    const form = document.getElementById('invoice-request-form');
    const input = document.getElementById('invoice-request-photo');
    const status = document.getElementById('photo-compression-status');
    let compression = null;
    const compress = function(file){
        return new Promise(function(resolve, reject){
            const image = new Image();
            const objectUrl = URL.createObjectURL(file);
            image.onload = async function(){
                URL.revokeObjectURL(objectUrl);
                try {
                    for(const side of [1280, 960, 720, 540, 400, 300, 220, 180, 140, 110]){
                        const scale = Math.min(1, side / Math.max(image.width, image.height));
                        const canvas = document.createElement('canvas');
                        canvas.width = Math.max(1, Math.round(image.width * scale)); canvas.height = Math.max(1, Math.round(image.height * scale));
                        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
                        for(const quality of [.82, .70, .58, .46, .35, .25, .18, .12]){
                            const blob = await new Promise(function(done){ canvas.toBlob(done, 'image/jpeg', quality); });
                            if(blob && blob.size <= 50 * 1024) return resolve(blob);
                        }
                    }
                    reject(new Error('This image could not be compressed under 50 KB.'));
                } catch(error) { reject(error); }
            };
            image.onerror = function(){ URL.revokeObjectURL(objectUrl); reject(new Error('The selected image could not be read.')); };
            image.src = objectUrl;
        });
    };
    input.addEventListener('change', function(){
        const file = input.files[0]; if(!file) return;
        status.textContent = 'Compressing photo…'; status.className = 'hint text-info mt-1';
        compression = compress(file).then(function(blob){
            const data = new DataTransfer();
            data.items.add(new File([blob], 'invoice-request.jpg', {type:'image/jpeg'})); input.files = data.files;
            status.textContent = 'Photo ready: ' + Math.ceil(blob.size / 1024) + ' KB'; status.className = 'hint text-success mt-1';
        }).catch(function(error){ status.textContent = error.message; status.className = 'hint text-danger mt-1'; throw error; });
    });
    form.addEventListener('submit', function(event){
        if(!compression) return;
        event.preventDefault();
        const button = form.querySelector('[type="submit"]'); button.disabled = true;
        compression.then(function(){ form.submit(); }).catch(function(){ button.disabled = false; });
    });
})();
</script></body></html>
