<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_expiry_helper.php';

require_admin_user();
ensure_product_management_columns($conn);

$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $option = normalize_product_expiry_option($_POST['product_expiry_option'] ?? 'active');

    if(save_product_expiry_option($conn, $option)){
        $message = 'Product management option updated successfully.';
        $message_type = 'success';
    }else{
        $message = 'Product management option could not be updated.';
        $message_type = 'danger';
    }
}

$current_option = current_product_expiry_option($conn);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="row">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Product Management</h3>
            </div>

            <div class="card-body">
                <?php if($message){ ?>
                    <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                        <?= htmlspecialchars($message); ?>
                    </div>
                <?php } ?>

                <form method="post">
                    <div class="form-group">
                        <label>Product Expiry Option</label>

                        <div class="custom-control custom-radio mb-2">
                            <input
                                type="radio"
                                id="expiry_active"
                                name="product_expiry_option"
                                value="active"
                                class="custom-control-input"
                                <?= $current_option === 'active' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="expiry_active">
                                Active
                            </label>
                        </div>

                        <div class="custom-control custom-radio">
                            <input
                                type="radio"
                                id="expiry_inactive"
                                name="product_expiry_option"
                                value="inactive"
                                class="custom-control-input"
                                <?= $current_option === 'inactive' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="expiry_inactive">
                                Inactive
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Save Option
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
