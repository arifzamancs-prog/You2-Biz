<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';

$user_id = $_SESSION['user_id'];
ensure_fifo_inventory_tables($conn);

$message = '';
$message_type = '';

$sql = "SELECT
            id,
            product_name,
            current_stock
        FROM products
        WHERE user_id=?
        AND status='active'
        ORDER BY product_name ASC";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $user_id
);

mysqli_stmt_execute($stmt);

$products =
mysqli_stmt_get_result($stmt);

if($_SERVER['REQUEST_METHOD']=='POST'){

    $product_id =
    (int)$_POST['product_id'];

    $quantity =
    (int)$_POST['quantity'];

    $txn_date =
    $_POST['txn_date'];

    $note =
    trim($_POST['note']);

    $sql = "SELECT current_stock
            FROM products
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "ii",
        $product_id,
        $user_id
    );

    mysqli_stmt_execute($stmt);

    $result =
    mysqli_stmt_get_result($stmt);

    $product =
    mysqli_fetch_assoc($result);

    if($quantity <= 0){

        $message =
        "Quantity must be at least 1";

        $message_type =
        "danger";

    }elseif($quantity > $product['current_stock']){

        $message =
        "Stock Out quantity exceeds available stock.";

        $message_type =
        "danger";

    }else{

        mysqli_begin_transaction($conn);

        try{

            $sql = "INSERT INTO stock_transactions
                    (
                        user_id,
                        product_id,
                        transaction_type,
                        quantity,
                        note,
                        txn_date
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        'stock_out',
                        ?,
                        ?,
                        ?
                    )";

            $stmt = mysqli_prepare(
                $conn,
                $sql
            );

            mysqli_stmt_bind_param(
                $stmt,
                "iidss",
                $user_id,
                $product_id,
                $quantity,
                $note,
                $txn_date
            );

            mysqli_stmt_execute($stmt);

            $stock_transaction_id = mysqli_insert_id($conn);

            $sql = "UPDATE products
                    SET current_stock =
                    current_stock - ?
                    WHERE id=?
                    AND user_id=?";

            $stmt = mysqli_prepare(
                $conn,
                $sql
            );

            mysqli_stmt_bind_param(
                $stmt,
                "dii",
                $quantity,
                $product_id,
                $user_id
            );

            mysqli_stmt_execute($stmt);

            $allocation = fifo_inventory_allocate_sale(
                $conn,
                $user_id,
                -$stock_transaction_id,
                $product_id,
                $quantity
            );

            if(!$allocation['success']){
                throw new Exception($allocation['error'] ?? 'FIFO stock out failed.');
            }

            mysqli_commit($conn);

            $message =
            "Stock Out Successful";

            $message_type =
            "success";

        }catch(Exception $e){

            mysqli_rollback($conn);

            $message =
            "Failed To Process Stock Out";

            $message_type =
            "danger";
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<div class="card">

    <div class="card-header">

        <h3 class="card-title">

            Stock Out

        </h3>

    </div>

    <div class="card-body">

        <?php if($message){ ?>

            <div class="alert alert-<?= $message_type; ?>">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <form method="post">

            <div class="form-group">

                <label>
                    Product
                </label>

                <select
                    name="product_id"
                    class="form-control"
                    required>

                    <option value="">
                        Select Product
                    </option>

                    <?php while($row=mysqli_fetch_assoc($products)){ ?>

                    <option
                        value="<?= $row['id']; ?>">

                        <?= htmlspecialchars($row['product_name']); ?>
                        (Stock:
                        <?= number_format($row['current_stock'],0); ?>)

                    </option>

                    <?php } ?>

                </select>

            </div>

            <div class="form-group">

                <label>
                    Quantity
                </label>

                <input
                    type="number"
                    step="1"
                    min="1"
                    name="quantity"
                    class="form-control"
                    required>

            </div>

            <div class="form-group">

                <label>
                    Date
                </label>

                <input
                    type="date"
                    name="txn_date"
                    value="<?= date('Y-m-d'); ?>"
                    class="form-control"
                    required>

            </div>

            <div class="form-group">

                <label>
                    Note
                </label>

                <textarea
                    name="note"
                    class="form-control"></textarea>

            </div>

            <button
                type="submit"
                class="btn btn-danger">

                <i class="fas fa-minus"></i>

                Stock Out

            </button>

        </form>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>
