<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            Add Supplier

        </h3>

    </div>

<div class="card-body">

        <?php if(isset($_SESSION['error'])){ ?>

            <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['error']); ?>
            </div>

            <?php unset($_SESSION['error']); ?>

        <?php } ?>

        <form
            method="post"
            action="save.php">

            <div class="form-group">

                <label>
                    Supplier Name
                </label>

                <input
                    type="text"
                    name="supplier_name"
                    class="form-control"
                    minlength="2"
                    pattern=".*[A-Za-z].*"
                    required>

            </div>

            <div class="form-group">

                <label>
                    Phone
                </label>

                <input
                    type="text"
                    name="phone"
                    class="form-control"
                    inputmode="numeric">

            </div>

            <div class="form-group">

                <label>
                    Email
                </label>

                <input
                    type="email"
                    name="email"
                    class="form-control">

            </div>

            <div class="form-group">

                <label>
                    Address
                </label>

                <textarea
                    name="address"
                    class="form-control"></textarea>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                Save Supplier

            </button>

        </form>

    </div>

</div>

<?php
$page_script = '
<script>
$(function(){
    $("input[name=\"supplier_name\"]").attr("title", "Supplier name must contain letters.");
    $("input[name=\"phone\"]").attr("title", "Phone must contain at least 11 digits.");
});
</script>
';
require_once '../includes/footer.php';
?>
