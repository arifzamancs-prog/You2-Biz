<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';

$user_id = $_SESSION['user_id'];
$catalog_branch_id = ($_SESSION['company_type'] ?? '') === 'Restaurant & Cafe' ? selected_branch_id($conn, true) : 0;
$catalog_branch_column = $catalog_branch_id > 0 ? 'branch_id,' : '';
$catalog_branch_value = $catalog_branch_id > 0 ? $catalog_branch_id . ',' : '';
$is_restaurant_catalog = restaurant_catalog_enabled($conn, $user_id);
$variants_enabled = product_variants_enabled($conn, $user_id);
ensure_fifo_only_product_categories($conn, $user_id);
ensure_product_variant_schema($conn);
ensure_product_subcategory_schema($conn);

$message = '';
$message_type = '';
$is_ajax_request = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

function category_create_json_response($success, $message, $extra = [])
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => (bool)$success, 'message' => $message] + $extra);
    exit;
}

// Table rows for the five most recently created categories, shown under the form.
function category_create_recent_rows($conn, $user_id, $show_sub_category, $show_variants)
{
    $stmt = mysqli_prepare($conn, 'SELECT id, category_name, sub_category, variant_options, status, created_at FROM product_categories WHERE user_id=? ORDER BY id DESC LIMIT 5');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    $columns = 3 + ($show_sub_category ? 1 : 0) + ($show_variants ? 1 : 0);

    if(!$rows){
        return '<tr><td colspan="' . ($columns + 1) . '" class="text-muted text-center">No categories yet.</td></tr>';
    }

    $html = '';

    foreach($rows as $row){
        $html .= '<tr><td>' . htmlspecialchars($row['category_name']) . '</td>';
        if($show_sub_category) $html .= '<td>' . htmlspecialchars(trim((string)$row['sub_category']) !== '' ? $row['sub_category'] : '-') . '</td>';
        if($show_variants) $html .= '<td>' . htmlspecialchars(trim((string)$row['variant_options']) !== '' ? $row['variant_options'] : '-') . '</td>';
        $html .= '<td><span class="badge badge-' . ($row['status'] === 'active' ? 'success' : 'secondary') . '">' . htmlspecialchars(ucfirst($row['status'])) . '</span></td>'
            . '<td>' . (!empty($row['created_at']) ? date('d-m-Y', strtotime($row['created_at'])) : '-') . '</td>'
            . '<td><a href="edit.php?id=' . (int)$row['id'] . '" class="btn btn-warning btn-sm" title="Edit"><i class="fas fa-edit"></i></a></td></tr>';
    }

    return $html;
}

if($_SERVER['REQUEST_METHOD']=='POST'){

    $category_name = trim($_POST['category_name']);
    $sub_category = $is_restaurant_catalog
        ? ''
        : implode(', ', product_variant_options_from_text($_POST['sub_category_options'] ?? ''));
    $category_type = $is_restaurant_catalog && ($_POST['category_type'] ?? '') === 'non_stock'
        ? 'non_stock' : 'stock_product';
    $status = $_POST['status'];
    $variant_options = !$variants_enabled || $is_restaurant_catalog
        ? ''
        : implode(', ', product_variant_options_from_text($_POST['variant_options'] ?? ''));

    if($category_name === ''){
        $message = 'Category name is required.';
        $message_type = 'danger';
        if($is_ajax_request){
            category_create_json_response(false, $message);
        }
    }elseif(product_category_name_exists($conn, $user_id, $category_name)){
        $message = 'This category name already exists.';
        $message_type = 'danger';
        if($is_ajax_request){
            category_create_json_response(false, $message);
        }
    }else{
    $sql = "INSERT INTO product_categories
            (
                {$catalog_branch_column}
                user_id,
                category_name,
                sub_category,
                variant_options,
                category_type,
                status
            )
            VALUES
            (
                {$catalog_branch_value}?,?, ?,
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

        if($is_ajax_request){
            category_create_json_response(true, 'Category saved successfully.', [
                'recent_html' => category_create_recent_rows($conn, $user_id, !$is_restaurant_catalog, $variants_enabled && !$is_restaurant_catalog),
            ]);
        }

        header(
            "Location: index.php"
        );

        exit;

    }else{

        $message = "Failed to save category";
        $message_type = "danger";
        if($is_ajax_request){
            category_create_json_response(false, $message);
        }

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

        <div id="category-feedback" class="alert d-none" role="alert"></div>

        <?php if($message){ ?>

            <div class="alert alert-<?= $message_type; ?>">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php } ?>

        <form method="post" id="category-create-form">
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
                <?php if ($variants_enabled) { ?><div class="form-group">
                    <label>Variants <small class="text-muted">(Optional)</small></label>
                    <input type="hidden" name="variant_options" id="variant_options" value="">
                    <div class="input-group">
                        <input type="text" id="variant_value" class="form-control" list="common_variants" placeholder="Select or type a variant">
                        <datalist id="common_variants"><option value="Small"><option value="Medium"><option value="Large"><option value="XL"><option value="XXL"></datalist>
                        <div class="input-group-append"><button class="btn btn-primary" id="add_variant" type="button" title="Add variant"><i class="fas fa-plus"></i></button></div>
                    </div>
                    <div id="variant_list" class="mt-2 d-flex flex-wrap"></div>
                </div><?php } ?>
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

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recently Added Categories</h3>
        <a href="index.php" class="float-right">View all</a>
    </div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm table-striped mb-0">
            <thead>
                <tr>
                    <th>Category Name</th>
                    <?php if(!$is_restaurant_catalog){ ?><th>Sub Category</th><?php } ?>
                    <?php if($variants_enabled && !$is_restaurant_catalog){ ?><th>Variants</th><?php } ?>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="recent-category-rows">
                <?= category_create_recent_rows($conn, $user_id, !$is_restaurant_catalog, $variants_enabled && !$is_restaurant_catalog); ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!$is_restaurant_catalog) { ?>
<script>
(function(){
    const source=document.getElementById('sub_category_value'), hidden=document.getElementById('sub_category_options'), list=document.getElementById('sub_category_list'), add=document.getElementById('add_sub_category');
    let subCategories=[];
    function render(){ hidden.value=subCategories.join(', '); list.innerHTML=''; subCategories.forEach((name,index)=>{const tag=document.createElement('span'); tag.className='badge badge-info mr-2 mb-2 p-2'; tag.textContent=name+' '; const remove=document.createElement('button'); remove.type='button'; remove.className='btn btn-link btn-sm p-0 ml-1 text-white'; remove.setAttribute('aria-label','Remove '+name); remove.innerHTML='<i class="fas fa-times"></i>'; remove.onclick=()=>{subCategories.splice(index,1);render();}; tag.appendChild(remove);list.appendChild(tag);}); }
    function addSubCategory(){let added=false; source.value.split(/[,;]/).map(item=>item.trim()).filter(Boolean).forEach(name=>{if(!subCategories.some(item=>item.toLowerCase()===name.toLowerCase())){subCategories.push(name);added=true;}}); if(added)render(); source.value='';source.focus();}
    add.addEventListener('click',addSubCategory);source.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();addSubCategory();}});
    document.addEventListener('category-form-reset',()=>{subCategories=[];render();source.value='';});
})();
<?php if ($variants_enabled) { ?>
(function(){
    const source=document.getElementById('variant_value'), hidden=document.getElementById('variant_options'), list=document.getElementById('variant_list'), add=document.getElementById('add_variant');
    let variants=[];
    function render(){ hidden.value=variants.join(', '); list.innerHTML=''; variants.forEach((name,index)=>{const tag=document.createElement('span'); tag.className='badge badge-info mr-2 mb-2 p-2'; tag.textContent=name+' '; const remove=document.createElement('button'); remove.type='button'; remove.className='btn btn-link btn-sm p-0 ml-1 text-white'; remove.setAttribute('aria-label','Remove '+name); remove.innerHTML='<i class="fas fa-times"></i>'; remove.onclick=()=>{variants.splice(index,1);render();}; tag.appendChild(remove);list.appendChild(tag);}); }
    function addVariant(){let added=false; source.value.split(/[,;]/).map(item=>item.trim()).filter(Boolean).forEach(name=>{if(!variants.some(item=>item.toLowerCase()===name.toLowerCase())){variants.push(name);added=true;}}); if(added)render(); source.value='';source.focus();}
    add.addEventListener('click',addVariant);source.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();addVariant();}});
    document.addEventListener('category-form-reset',()=>{variants=[];render();source.value='';});
})();
<?php } ?>
</script>
<?php } ?>

<script>
(function(){
    const form = document.getElementById('category-create-form');
    const feedback = document.getElementById('category-feedback');
    if (!form || !feedback) return;

    form.addEventListener('submit', async function(event){
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        const original = submit.innerHTML;
        submit.disabled = true;
        submit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        feedback.className = 'alert d-none';

        try {
            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: new FormData(form)
            });
            const result = await response.json();
            feedback.textContent = result.message || 'Unable to save category.';
            feedback.className = 'alert alert-' + (result.success ? 'success' : 'danger');
            if (result.success) {
                if (result.recent_html) document.getElementById('recent-category-rows').innerHTML = result.recent_html;
                form.reset();
                document.dispatchEvent(new Event('category-form-reset'));
                document.getElementById('category_name').focus();
            }
        } catch (error) {
            feedback.textContent = 'Unable to save category. Please try again.';
            feedback.className = 'alert alert-danger';
        } finally {
            submit.disabled = false;
            submit.innerHTML = original;
        }
    });
})();
</script>

<?php
require_once '../includes/footer.php';
?>
