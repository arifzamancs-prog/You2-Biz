<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/customer_portal_helper.php';
require_admin_user();
$user_id = (int)$_SESSION['user_id'];
ensure_customer_access_table($conn);
$message = $_SESSION['customer_login_message'] ?? '';
$message_type = $_SESSION['customer_login_message_type'] ?? 'success';
unset($_SESSION['customer_login_message'], $_SESSION['customer_login_message_type']);
function customer_login_redirect($message, $type = 'success'){ $_SESSION['customer_login_message']=$message; $_SESSION['customer_login_message_type']=$type; header('Location: customer_login_settings.php'); exit; }
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='create_customer_access'){
        $customer_id=(int)($_POST['customer_id']??0); $username_base=normalize_customer_access_username($_POST['customer_username']??''); $username=build_customer_access_username($username_base,$user_id); $password=(string)($_POST['customer_password']??'');
        $check=mysqli_prepare($conn,"SELECT id FROM customers WHERE id=? AND user_id=? AND status='active' LIMIT 1"); mysqli_stmt_bind_param($check,'ii',$customer_id,$user_id); mysqli_stmt_execute($check);
        if(!mysqli_fetch_assoc(mysqli_stmt_get_result($check)) || $username_base==='' || strlen($password)<6) customer_login_redirect('Select a customer and enter a password of at least 6 characters.','danger');
        $hash=password_hash($password,PASSWORD_DEFAULT); $insert=mysqli_prepare($conn,"INSERT INTO customer_access_accounts (user_id,customer_id,username,password,status) VALUES (?,?,?,?,'active')"); mysqli_stmt_bind_param($insert,'iiss',$user_id,$customer_id,$username,$hash);
        try{if(mysqli_stmt_execute($insert)) customer_login_redirect('Customer access created successfully.');}catch(mysqli_sql_exception $e){customer_login_redirect('This customer or username already has access.','danger');}
        customer_login_redirect('Customer access could not be created.','danger');
    }
    if($action==='delete_customer_access'){ $id=(int)($_POST['access_id']??0); $stmt=mysqli_prepare($conn,'DELETE FROM customer_access_accounts WHERE id=? AND user_id=? LIMIT 1'); mysqli_stmt_bind_param($stmt,'ii',$id,$user_id); mysqli_stmt_execute($stmt); $ok=mysqli_stmt_affected_rows($stmt)>0; customer_login_redirect($ok?'Customer access deleted successfully.':'Customer access could not be deleted.',$ok?'success':'danger'); }
}
if(isset($_GET['customer_access_status'],$_GET['customer_access_id'])){ $status=$_GET['customer_access_status']==='active'?'active':'inactive'; $id=(int)$_GET['customer_access_id']; $stmt=mysqli_prepare($conn,'UPDATE customer_access_accounts SET status=? WHERE id=? AND user_id=?'); mysqli_stmt_bind_param($stmt,'sii',$status,$id,$user_id); mysqli_stmt_execute($stmt); customer_login_redirect('Customer access status updated.'); }
$options=mysqli_query($conn,"SELECT c.id,c.customer_name,c.phone FROM customers c WHERE c.user_id={$user_id} AND c.status='active' AND NOT EXISTS (SELECT 1 FROM customer_access_accounts ca WHERE ca.user_id=c.user_id AND ca.customer_id=c.id) ORDER BY c.customer_name ASC");
$access=mysqli_query($conn,"SELECT ca.*,c.customer_name,c.customer_code,c.phone,c.email FROM customer_access_accounts ca INNER JOIN customers c ON c.id=ca.customer_id AND c.user_id=ca.user_id WHERE ca.user_id={$user_id} ORDER BY ca.id DESC");
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<?php if($message){ ?><div class="alert alert-<?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div><?php } ?>
<div class="row">

    <div class="col-lg-4">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">Create Customer Login Access</h3>
            </div>

            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="create_customer_access">

                    <div class="form-group">
                        <label>Customer Name</label>
                        <select name="customer_id" class="form-control customer-select" required>
                            <option value="">Search and select customer</option>
                            <?php while($customer_option = mysqli_fetch_assoc($options)){ ?>
                                <option value="<?= (int)$customer_option['id']; ?>">
                                    <?= htmlspecialchars($customer_option['customer_name'] . (!empty($customer_option['phone']) ? ' (' . $customer_option['phone'] . ')' : '')); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Username</label>
                        <div class="input-group" style="max-width: 280px;">
                            <input type="text" name="customer_username" class="form-control" required>
                            <div class="input-group-append">
                                <span class="input-group-text">@c<?= (int)$user_id; ?></span>
                            </div>
                        </div>
                        <small class="text-muted">Customer must login with this username, not email or phone.</small>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="customer_password" class="form-control" style="max-width: 280px;" minlength="6" required>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i>
                        Create Customer Access
                    </button>
                </form>
            </div>

        </div>

    </div>

    <div class="col-lg-8">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">Customer Access Management</h3>
            </div>

            <div class="card-body">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Login Username</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($customer_access = mysqli_fetch_assoc($access)){ ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($customer_access['customer_name']); ?>
                                    <small class="text-muted d-block">CID: <?= htmlspecialchars($customer_access['customer_code'] ?: '-'); ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($customer_access['phone'] ?: '-'); ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($customer_access['email'] ?: '-'); ?></small>
                                </td>
                                <td><?= htmlspecialchars($customer_access['username']); ?></td>
                                <td>
                                    <?php if($customer_access['status'] === 'active'){ ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php }else{ ?>
                                        <span class="badge badge-secondary">Inactive</span>
                                    <?php } ?>
                                </td>
                                <td><?= htmlspecialchars(app_datetime($customer_access['last_login'] ?? null)); ?></td>
                                <td><?= htmlspecialchars(app_datetime($customer_access['created_at'])); ?></td>
                                <td>
                                    <?php if($customer_access['status'] === 'active'){ ?>
                                        <a href="customer_login_settings.php?customer_access_id=<?= (int)$customer_access['id']; ?>&customer_access_status=inactive" class="btn btn-sm btn-warning" title="Deactivate Customer Access" aria-label="Deactivate Customer Access">
                                            <i class="fas fa-ban"></i>
                                        </a>
                                    <?php }else{ ?>
                                        <a href="customer_login_settings.php?customer_access_id=<?= (int)$customer_access['id']; ?>&customer_access_status=active" class="btn btn-sm btn-success" title="Activate Customer Access" aria-label="Activate Customer Access">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php } ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this customer access?');">
                                        <input type="hidden" name="action" value="delete_customer_access">
                                        <input type="hidden" name="access_id" value="<?= (int)$customer_access['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete Customer Access" aria-label="Delete Customer Access">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

        </div>

    </div>

</div>
<?php $page_script="<script>$(function(){ $('.customer-select').select2({theme:'bootstrap4',width:'100%',placeholder:'Search and select customer'}); });</script>"; require_once '../includes/footer.php'; ?>
