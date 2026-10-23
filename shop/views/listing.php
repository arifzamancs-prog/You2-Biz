<?php
$queryBase=array_filter(['q'=>$filters['q'],'sub'=>$filters['sub'],'brand'=>$filters['brands'],'min'=>$filters['min'],'max'=>$filters['max'],'sort'=>$filters['sort']==='newest'?'':$filters['sort']],function($v){ return $v!=='' && $v!==[]; });
$pageUrl=function($page) use ($listingBase,$queryBase){ $params=$queryBase; if($page>1) $params['page']=$page; return $listingBase.($params?'?'.http_build_query($params):''); };
$hasFilters=$filters['sub']!=='' || $filters['brands'] || $filters['min']!=='' || $filters['max']!=='';
?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(shop_url()) ?>">Home</a><?php if($currentCategory){ ?><span>/</span><a href="<?= h(category_url($currentCategory['id'],$currentCategory['name'])) ?>"><?= h($currentCategory['name']) ?></a><?php if($filters['sub']!==''){ ?><span>/</span><span><?= h($filters['sub']) ?></span><?php } ?><?php }else{ ?><span>/</span><span><?= h($listingTitle) ?></span><?php } ?></nav>

    <div class="listing">
        <aside class="filters" data-filters>
            <form method="get" action="<?= h($listingBase) ?>" data-filter-form>
                <?php if($filters['q']!==''){ ?><input type="hidden" name="q" value="<?= h($filters['q']) ?>"><?php } ?>
                <?php if($filters['sort']!=='newest'){ ?><input type="hidden" name="sort" value="<?= h($filters['sort']) ?>"><?php } ?>
                <div class="filter-head"><strong>Filter</strong><button type="button" class="icon-btn filter-close" data-filter-close aria-label="Close filters"><?= icon('close') ?></button></div>
                <section class="filter-box">
                    <h3>Price Range</h3>
                    <div class="price-inputs"><input type="number" name="min" min="0" step="1" placeholder="Min" value="<?= h($filters['min']) ?>" aria-label="Minimum price"><span>–</span><input type="number" name="max" min="0" step="1" placeholder="Max" value="<?= h($filters['max']) ?>" aria-label="Maximum price"></div>
                </section>
                <?php if($currentCategory && $currentCategory['subs']){ ?>
                <section class="filter-box">
                    <h3>Type</h3>
                    <label class="facet"><input class="facet-check" type="radio" name="sub" value="" <?= $filters['sub']===''?'checked':'' ?> data-auto-submit> All</label>
                    <?php foreach($currentCategory['subs'] as $sub){ ?><label class="facet"><input class="facet-check" type="radio" name="sub" value="<?= h($sub['name']) ?>" <?= $filters['sub']===$sub['name']?'checked':'' ?> data-auto-submit> <?= h($sub['name']) ?> <small>(<?= (int)$sub['total'] ?>)</small></label><?php } ?>
                </section>
                <?php }elseif($filters['sub']!==''){ ?><input type="hidden" name="sub" value="<?= h($filters['sub']) ?>"><?php } ?>
                <?php if($brands){ ?>
                <section class="filter-box">
                    <h3>Brand</h3>
                    <?php foreach($brands as $brand){ ?><label class="facet"><input class="facet-check" type="checkbox" name="brand[]" value="<?= h($brand['brand']) ?>" <?= in_array($brand['brand'],$filters['brands'],true)?'checked':'' ?> data-auto-submit> <?= h($brand['brand']) ?> <small>(<?= (int)$brand['total'] ?>)</small></label><?php } ?>
                </section>
                <?php } ?>
                <?php if(!$currentCategory && $categories){ ?>
                <section class="filter-box">
                    <h3>Categories</h3>
                    <?php foreach($categories as $category){ ?><a class="facet-link" href="<?= h(category_url($category['id'],$category['name'])) ?>"><?= h($category['name']) ?> <small>(<?= (int)$category['total'] ?>)</small></a><?php } ?>
                </section>
                <?php } ?>
                <div class="filter-actions"><button class="btn btn-primary">Apply</button><?php if($hasFilters){ ?><a class="btn btn-outline" href="<?= h($listingBase.($filters['q']!==''?'?q='.rawurlencode($filters['q']):'')) ?>">Reset</a><?php } ?></div>
            </form>
        </aside>

        <section class="listing-main">
            <div class="listing-bar">
                <div><h1><?= h($listingTitle) ?></h1><small><?= $productTotal ?> product<?= $productTotal===1?'':'s' ?> found</small></div>
                <div class="listing-tools">
                    <button type="button" class="btn btn-outline filter-open" data-filter-open><?= icon('filter','icon-sm') ?> Filter</button>
                    <form method="get" action="<?= h($listingBase) ?>">
                        <?php foreach($queryBase as $key=>$value){ if($key==='sort') continue; foreach((array)$value as $item){ ?><input type="hidden" name="<?= h($key.(is_array($value)?'[]':'')) ?>" value="<?= h($item) ?>"><?php } } ?>
                        <label>Sort By: <select name="sort" data-auto-submit><option value="newest" <?= $filters['sort']==='newest'?'selected':'' ?>>Newest</option><option value="price_low" <?= $filters['sort']==='price_low'?'selected':'' ?>>Price (Low &gt; High)</option><option value="price_high" <?= $filters['sort']==='price_high'?'selected':'' ?>>Price (High &gt; Low)</option><option value="name" <?= $filters['sort']==='name'?'selected':'' ?>>Name (A - Z)</option></select></label>
                        <noscript><button class="btn btn-outline">Sort</button></noscript>
                    </form>
                </div>
            </div>
            <?php if($products){ ?>
                <?= product_grid($products,'grid grid-listing') ?>
                <?php if($pageCount>1){ ?>
                <nav class="pagination" aria-label="Pages">
                    <?php if($page>1){ ?><a href="<?= h($pageUrl($page-1)) ?>">Prev</a><?php } ?>
                    <?php for($i=max(1,$page-2);$i<=min($pageCount,$page+2);$i++){ ?><a href="<?= h($pageUrl($i)) ?>" class="<?= $i===$page?'is-active':'' ?>" <?= $i===$page?'aria-current="page"':'' ?>><?= $i ?></a><?php } ?>
                    <?php if($page<$pageCount){ ?><a href="<?= h($pageUrl($page+1)) ?>">Next</a><?php } ?>
                </nav>
                <?php } ?>
            <?php }else{ ?>
                <div class="panel empty-state"><h2>No products found</h2><p>Try another search or remove some filters.</p><a class="btn btn-primary" href="<?= h(shop_url('products')) ?>">View all products</a></div>
            <?php } ?>
        </section>
    </div>
</div>
