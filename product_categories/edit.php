<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
ensure_fifo_only_product_categories($conn, $user_id);
ensure_product_variant_schema($conn);
ensure_product_subcategory_schema($conn);

$id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

$sql = "SELECT *
        FROM product_categories
        WHERE id=?
        AND user_id=?";

$stmt = mysqli_prepare($conn,$sql);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $user_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$category = mysqli_fetch_assoc($result);

if(!$category){

    die('Category Not Found');

}

if(product_category_has_usage($conn, $id, $user_id)){
    die('This category cannot be edited because it has products or transactions.');
}

$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD']=='POST'){

    $category_name =
    trim($_POST['category_name']);
    $sub_category = trim($_POST['sub_category'] ?? '');

    $category_type = 'stock_product';

    $status =
    $_POST['status'];
    $variant_options = implode(', ', product_variant_options_from_text($_POST['variant_options'] ?? ''));

    if($category_name === ''){
        $message = 'Category name is required.';
        $message_type = 'danger';
    }elseif(product_category_name_exists($conn, $user_id, $category_name, $id)){
        $message = 'This category name already exists.';
        $message_type = 'danger';
    }else{
    $sql = "UPDATE product_categories
            SET
                category_name=?,
                sub_category=?,
                variant_options=?,
                category_type=?,
                status=?
            WHERE id=?
            AND user_id=?";

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    mysqli_stmt_bind_param(
        $stmt,
        "sssssii",
        $category_name,
        $sub_category,
        $variant_options,
        $category_type,
        $status,
        $id,
        $user_id
    );

    if(mysqli_stmt_execute($stmt)){

        header(
            "Location: index.php"
        );

        exit;

    }else{

        $message =
        "Update Failed";

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

            <i class="fas fa-edit mr-2"></i>

            Edit Category

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
                    Category Name
                </label>

                <input
                    type="text"
                    name="category_name"
                    class="form-control"
                    value="<?= htmlspecialchars($category['category_name']); ?>"
                    required>

            </div>

            <div class="form-group">
                <label>Sub Category <small class="text-muted">(Optional)</small></label>
                <input type="text" name="sub_category" class="form-control" maxlength="100" value="<?= htmlspecialchars($category['sub_category'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Variants <small class="text-muted">(Optional)</small></label>
                <input type="hidden" name="variant_options" id="variant_options" value="<?= htmlspecialchars($category['variant_options'] ?? ''); ?>">
                <div class="input-group">
                    <input type="text" id="variant_value" class="form-control" list="common_variants" placeholder="Select or type a variant">
                    <datalist id="common_variants"><option value="Small"><option value="Medium"><option value="Large"><option value="XL"><option value="XXL"></datalist>
                    <div class="input-group-append"><button class="btn btn-primary" id="add_variant" type="button" title="Add variant"><i class="fas fa-plus"></i></button></div>
                </div>
                <div id="variant_list" class="mt-2 d-flex flex-wrap"></div>
            </div>

            <div class="form-group">

                <label>
                    Status
                </label>

                <select
                    name="status"
                    class="form-control">

                    <option
                        value="active"
                        <?= $category['status']=='active'?'selected':''; ?>>

                        Active

                    </option>

                    <option
                        value="inactive"
                        <?= $category['status']=='inactive'?'selected':''; ?>>

                        Inactive

                    </option>

                </select>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-save"></i>

                Update Category

            </button>

            <a
                href="index.php"
                class="btn btn-secondary">

                Back

            </a>

        </form>

    </div>

</div>

<script>
(function(){
    const source=document.getElementById('variant_value'), hidden=document.getElementById('variant_options'), list=document.getElementById('variant_list'), add=document.getElementById('add_variant');
    let variants=hidden.value.split(/[,\r\n]+/).map(value=>value.trim()).filter(Boolean);
    function render(){ hidden.value=variants.join(', '); list.innerHTML=''; variants.forEach((name,index)=>{const tag=document.createElement('span'); tag.className='badge badge-info mr-2 mb-2 p-2'; tag.textContent=name+' '; const remove=document.createElement('button'); remove.type='button'; remove.className='btn btn-link btn-sm p-0 ml-1 text-white'; remove.setAttribute('aria-label','Remove '+name); remove.innerHTML='<i class="fas fa-times"></i>'; remove.onclick=()=>{variants.splice(index,1);render();}; tag.appendChild(remove);list.appendChild(tag);}); }
    function addVariant(){const name=source.value.trim();if(!name)return; if(!variants.some(item=>item.toLowerCase()===name.toLowerCase())){variants.push(name);render();} source.value='';source.focus();}
    add.addEventListener('click',addVariant);source.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();addVariant();}});render();
})();
</script>

<?php
require_once '../includes/footer.php';
?>
