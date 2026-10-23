<?php
// Specification rows are lined up by label so the same attribute sits on one row.
$specMaps=[]; $labels=[];
foreach($products as $p){
    $full=eshop_storefront_product($conn,$cid,(int)$p['id']);
    $map=[];
    foreach(eshop_storefront_spec_rows($full['specification']??'') as $row){
        if($row['value']===null) continue;
        $map[$row['label']]=$row['value']; $labels[$row['label']]=true;
    }
    $specMaps[(int)$p['id']]=$map;
}
?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(shop_url()) ?>">Home</a><span>/</span><span>Compare</span></nav>
    <div class="page-title-row"><h1 class="page-title">Compare Products</h1><?php if($products){ ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="compare_clear"><button class="btn btn-outline btn-sm">Clear all</button></form><?php } ?></div>
    <?php if(!$products){ ?>
    <div class="panel empty-state"><?= icon('compare','icon-lg') ?><h2>No products to compare</h2><p>Add up to 4 products using the compare button.</p><a class="btn btn-primary" href="<?= h(shop_url('products')) ?>">Browse Products</a></div>
    <?php }else{ ?>
    <div class="table-wrap"><table class="compare-table">
        <tr><th>Product</th><?php foreach($products as $p){ ?><td>
            <a href="<?= h(product_url($p)) ?>" class="compare-product"><?php if($p['photo_url']!==''){ ?><img src="<?= h($p['photo_url']) ?>" alt=""><?php } ?><strong><?= h($p['product_name']) ?></strong></a>
            <form method="post"><?= csrf_field().return_field() ?><input type="hidden" name="action" value="compare"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><button class="link-btn text-danger"><?= icon('trash','icon-sm') ?> Remove</button></form>
        </td><?php } ?></tr>
        <tr><th>Price</th><?php foreach($products as $p){ ?><td><span class="price"><?= money($p['price']) ?></span><?php if($p['save']>0){ ?> <del><?= money($p['regular_price']) ?></del><?php } ?></td><?php } ?></tr>
        <tr><th>Brand</th><?php foreach($products as $p){ ?><td><?= h($p['brand']?:'—') ?></td><?php } ?></tr>
        <tr><th>Availability</th><?php foreach($products as $p){ ?><td class="<?= $p['available_quantity']>0?'text-success':'text-danger' ?>"><?= $p['available_quantity']>0?'In Stock':'Out of Stock' ?></td><?php } ?></tr>
        <tr><th>Key Features</th><?php foreach($products as $p){ ?><td><?php if($p['features']){ ?><ul><?php foreach(array_slice($p['features'],0,6) as $feature){ ?><li><?= h($feature) ?></li><?php } ?></ul><?php }else{ ?>—<?php } ?></td><?php } ?></tr>
        <?php foreach(array_keys($labels) as $label){ ?><tr><th><?= h($label) ?></th><?php foreach($products as $p){ ?><td><?= h($specMaps[(int)$p['id']][$label]??'—') ?></td><?php } ?></tr><?php } ?>
        <tr><th></th><?php foreach($products as $p){ ?><td><?php if($p['available_quantity']>0 && !$p['has_variants']){ ?><form method="post"><?= csrf_field().return_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="variant_name" value=""><input type="hidden" name="quantity" value="1"><button class="btn btn-primary btn-sm">Add to Cart</button></form><?php }else{ ?><a class="btn btn-outline btn-sm" href="<?= h(product_url($p)) ?>">View</a><?php } ?></td><?php } ?></tr>
    </table></div>
    <?php } ?>
</div>
