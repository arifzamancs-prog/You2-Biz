<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
$is_restaurant_catalog = restaurant_catalog_enabled($conn, $user_id);
ensure_fifo_only_product_categories($conn, $user_id);
ensure_product_variant_schema($conn);
ensure_product_subcategory_schema($conn);

$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD']=='POST'){

    $category_name = trim($_POST['category_name']);
    $sub_category = $is_restaurant_catalog
        ? ''
        : implode(', ', product_variant_options_from_text($_POST['sub_category_options'] ?? ''));
    $category_type = $is_restaurant_catalog && ($_POST['category_type'] ?? '') === 'non_stock'
        ? 'non_stock' : 'stock_product';
    $status = $_POST['status'];
    $variant_options = $is_restaurant_catalog
        ? ''
        : implode(', ', product_variant_options_from_text($_POST['variant_options'] ?? ''));

    if($category_name === ''){
        $message = 'Category name is required.';
        $message_type = 'danger';
    }elseif(product_category_name_exists($conn, $user_id, $category_name)){
        $message = 'This category name already exists.';
        $message_type = 'danger';
    }else{
    $sql = "INSERT INTO product_categories
            (
                user_id,
                category_name,
                sub_category,
                variant_options,
                category_type,
                status
            )
            VALUES
            (
                ?,?, ?,
                ?,
                ?,
                ?
            )";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(
        $stmt,
        "isssss",
        $user_id,
        $category_name,
        $sub_category,
        $variant_options,
        $category_type,
        $status
    );

    if(mysqli_stmt_execute($stmt)){

        header(
            "Location: index.php"
        );

        exit;

    }else{

        $message = "Failed to save category";
        $message_type = "danger";

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

            <i class="fas fa-plus-circle mr-2"></i>

            Add Product Category

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

                <label for="category_name">
                    Category Name
                </label>

                <input
                    type="text"
                    id="category_name"
                    name="category_name"
                    class="form-control"
                    required>

            </div>

            <?php if ($is_restaurant_catalog) { ?>
                <div class="form-group">
                    <label for="category_type">Category Type</label>
                    <select id="category_type" name="category_type" class="form-control">
                        <option value="non_stock">Non Stock</option>
                        <option value="stock_product">Stock Product</option>
                    </select>
                </div>
            <?php } else { ?>
                <div class="form-group">
                    <label>Sub Category <small class="text-muted">(Optional)</small></label>
                    <input type="hidden" name="sub_category_options" id="sub_category_options" value="">
                    <div class="input-group">
                        <input type="text" id="sub_category_value" class="form-control" placeholder="Type a sub category">
                        <div class="input-group-append"><button class="btn btn-primary" id="add_sub_category" type="button" title="Add sub category"><i class="fas fa-plus"></i></button></div>
                    </div>
                    <div id="sub_category_list" class="mt-2 d-flex flex-wrap"></div>
                </div>
                <div class="form-group">
                    <label>Variants <small class="text-muted">(Optional)</small></label>
                    <input type="hidden" name="variant_options" id="variant_options" value="">
                    <div class="input-group">
                        <input type="text" id="variant_value" class="form-control" list="common_variants" placeholder="Select or type a variant">
                        <datalist id="common_variants"><option value="Small"><option value="Medium"><option value="Large"><option value="XL"><option value="XXL"></datalist>
                        <div class="input-group-append"><button class="btn btn-primary" id="add_variant" type="button" title="Add variant"><i class="fas fa-plus"></i></button></div>
                    </div>
                    <div id="variant_list" class="mt-2 d-flex flex-wrap"></div>
                </div>
            <?php } ?>

            <div class="form-group">

                <label for="status">
                    Status
                </label>

                <select
                    id="status"
                    name="status"
                    class="form-control">

                    <option value="active">
                        Active
                    </option>

                    <option value="inactive">
                        Inactive
                    </option>

                </select>

            </div>

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-save"></i>

                Save Category

            </button>

            <a
                href="index.php"
                class="btn btn-secondary">

                Back

            </a>

        </form>

    </div>

</div>

<?php if (!$is_restaurant_catalog) { ?>
<script>
(function(){
    const source=document.getElementById('sub_category_value'), hidden=document.getElementById('sub_category_options'), list=document.getElementById('sub_category_list'), add=document.getElementById('add_sub_category');
    let subCategories=[];
    function render(){ hidden.value=subCategories.join(', '); list.innerHTML=''; subCategories.forEach((name,index)=>{const tag=document.createElement('span'); tag.className='badge badge-info mr-2 mb-2 p-2'; tag.textContent=name+' '; const remove=document.createElement('button'); remove.type='button'; remove.className='btn btn-link btn-sm p-0 ml-1 text-white'; remove.setAttribute('aria-label','Remove '+name); remove.innerHTML='<i class="fas fa-times"></i>'; remove.onclick=()=>{subCategories.splice(index,1);render();}; tag.appendChild(remove);list.appendChild(tag);}); }
    function addSubCategory(){const name=source.value.trim();if(!name)return; if(!subCategories.some(item=>item.toLowerCase()===name.toLowerCase())){subCategories.push(name);render();} source.value='';source.focus();}
    add.addEventListener('click',addSubCategory);source.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();addSubCategory();}});
})();
(function(){
    const source=document.getElementById('variant_value'), hidden=document.getElementById('variant_options'), list=document.getElementById('variant_list'), add=document.getElementById('add_variant');
    let variants=[];
    function render(){ hidden.value=variants.join(', '); list.innerHTML=''; variants.forEach((name,index)=>{const tag=document.createElement('span'); tag.className='badge badge-info mr-2 mb-2 p-2'; tag.textContent=name+' '; const remove=document.createElement('button'); remove.type='button'; remove.className='btn btn-link btn-sm p-0 ml-1 text-white'; remove.setAttribute('aria-label','Remove '+name); remove.innerHTML='<i class="fas fa-times"></i>'; remove.onclick=()=>{variants.splice(index,1);render();}; tag.appendChild(remove);list.appendChild(tag);}); }
    function addVariant(){const name=source.value.trim();if(!name)return; if(!variants.some(item=>item.toLowerCase()===name.toLowerCase())){variants.push(name);render();} source.value='';source.focus();}
    add.addEventListener('click',addVariant);source.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();addVariant();}});
})();
</script>
<?php } ?>

<?php
require_once '../includes/footer.php';
?>
