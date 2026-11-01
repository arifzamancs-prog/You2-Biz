<?php
/*
|--------------------------------------------------------------------------
| CloudPOS Product & Store Stock Import (chunked)
|--------------------------------------------------------------------------
| The browser reads the (large) CloudPOS JSON file itself and sends it to the
| server in small batches, so PHP upload_max_filesize / post_max_size /
| max_execution_time are never hit. Existing company data is NOT deleted.
| Re-running is safe: products whose Code already exists are skipped.
*/

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/schema_cache_helper.php';
require_once '../includes/super_admin_config.php';
require_once '../includes/product_expiry_helper.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/branch_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$is_super = is_super_admin_user();
$target_company_name = '';

if($is_super){
    $user_id = (int)($_POST['company_id'] ?? ($_GET['company_id'] ?? 0));
    $stmt = mysqli_prepare($conn, "SELECT id, name FROM users WHERE id=? AND role='admin' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $company = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if(!$company) die('Invalid company selected.');
    $target_company_name = (string)$company['name'];
}else{
    $stmt = mysqli_prepare($conn, "SELECT role FROM users WHERE id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $me = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if(!$me || $me['role'] !== 'admin') die('Only the company admin can import data.');
}

if(empty($_SESSION['cloudpos_csrf'])){
    $_SESSION['cloudpos_csrf'] = bin2hex(random_bytes(16));
}

function cp_json($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/*
|--------------------------------------------------------------------------
| AJAX actions
|--------------------------------------------------------------------------
*/
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])){
    @set_time_limit(180);
    @ini_set('memory_limit', '512M');
    mysqli_report(MYSQLI_REPORT_OFF);

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if(!is_array($payload)) cp_json(['ok' => false, 'error' => 'Invalid request.'], 400);
    if(!hash_equals($_SESSION['cloudpos_csrf'], (string)($payload['csrf'] ?? ''))){
        cp_json(['ok' => false, 'error' => 'Session expired. Reload the page.'], 403);
    }

    $action = (string)($payload['action'] ?? '');
    $session_key = 'cloudpos_import_' . $user_id;

    if($action === 'start'){
        $password = (string)($payload['password'] ?? '');
        if($is_super){
            $valid = password_verify($password, SUPER_ADMIN_PASSWORD_HASH);
        }else{
            $stmt = mysqli_prepare($conn, 'SELECT password FROM users WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'i', $user_id);
            mysqli_stmt_execute($stmt);
            $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $valid = $u && password_verify($password, $u['password']);
        }
        if(!$valid) cp_json(['ok' => false, 'error' => 'Invalid Password'], 403);

        if(restaurant_catalog_enabled($conn, $user_id)){
            cp_json(['ok' => false, 'error' => 'This importer does not support Restaurant & Cafe catalogs.']);
        }

        // Product limit of the subscription.
        $incoming = (int)($payload['total'] ?? 0);
        $stmt = mysqli_prepare($conn, 'SELECT u.max_products, (SELECT COUNT(*) FROM products p WHERE p.user_id=u.id) AS cnt FROM users u WHERE u.id=?');
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $lim = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if($lim && (int)$lim['max_products'] > 0 && (int)$lim['cnt'] + $incoming > (int)$lim['max_products']){
            cp_json(['ok' => false, 'error' => 'Product limit is ' . (int)$lim['max_products'] . ' (currently ' . (int)$lim['cnt'] . ' products, importing up to ' . $incoming . '). Ask Super Admin to raise the Product limit for this company first.']);
        }

        ensure_head_office_branch($conn, $user_id);
        ensure_fifo_inventory_tables($conn);
        ensure_product_management_columns($conn);
        ensure_product_variant_schema($conn);
        ensure_product_subcategory_schema($conn);
        ensure_product_category_type_column($conn);

        $_SESSION[$session_key] = time() + 7200;
        cp_json(['ok' => true]);
    }

    if($action !== 'chunk' || (int)($_SESSION[$session_key] ?? 0) < time()){
        cp_json(['ok' => false, 'error' => 'Import not started or expired. Start again.'], 403);
    }

    $products = $payload['products'] ?? [];
    if(!is_array($products)) cp_json(['ok' => false, 'error' => 'Bad chunk.'], 400);
    $code_field = ($payload['code_field'] ?? 'barcode') === 'product_code' ? 'product_code' : 'barcode';
    $fifo = fifo_inventory_is_enabled();

    mysqli_begin_transaction($conn);
    try{
        // ---- categories (name => [id, subs]) ----
        $categories = [];
        $res = mysqli_query($conn, "SELECT id, category_name, sub_category FROM product_categories WHERE user_id={$user_id}");
        while($r = mysqli_fetch_assoc($res)){
            $categories[mb_strtolower(trim($r['category_name']))] = ['id' => (int)$r['id'], 'subs' => product_variant_options_from_text($r['sub_category'] ?? '')];
        }

        // ---- branches by code ----
        $branches = [];
        $res = mysqli_query($conn, "SELECT id, branch_code FROM branches WHERE user_id={$user_id}");
        while($r = mysqli_fetch_assoc($res)){
            if(trim((string)$r['branch_code']) !== '') $branches[trim($r['branch_code'])] = (int)$r['id'];
        }

        // ---- existing codes in this chunk ----
        $skus = [];
        foreach($products as $p){
            $s = strtoupper(trim((string)($p[$code_field] ?? '')));
            if($s !== '') $skus[$s] = true;
        }
        $existing = [];
        if($skus){
            $list = array_keys($skus);
            $ph = implode(',', array_fill(0, count($list), '?'));
            $stmt = mysqli_prepare($conn, "SELECT id, UPPER(TRIM(sku)) AS s FROM products WHERE user_id=? AND UPPER(TRIM(sku)) IN ({$ph})");
            $types = 'i' . str_repeat('s', count($list));
            mysqli_stmt_bind_param($stmt, $types, $user_id, ...$list);
            mysqli_stmt_execute($stmt);
            $rs = mysqli_stmt_get_result($stmt);
            while($r = mysqli_fetch_assoc($rs)) $existing[$r['s']] = (int)$r['id'];
            mysqli_stmt_close($stmt);
        }

        $ins_cat = mysqli_prepare($conn, "INSERT INTO product_categories (user_id, category_name, category_type, status) VALUES (?, ?, 'stock_product', 'active')");
        $upd_sub = mysqli_prepare($conn, 'UPDATE product_categories SET sub_category=? WHERE id=? AND user_id=?');
        $ins_branch = mysqli_prepare($conn, "INSERT INTO branches (user_id, branch_name, branch_code, is_head_office, status) VALUES (?, ?, ?, 0, 'active')");
        $ins_prod = mysqli_prepare($conn, "INSERT INTO products (user_id, category_id, sub_category, product_name, sku, purchase_price, sale_price, current_stock, opening_stock_quantity, opening_stock_unit_cost, minimum_stock, status) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'active')");
        $ins_batch = $fifo ? mysqli_prepare($conn, "INSERT INTO stock_batches (user_id, branch_id, product_id, variant_name, source_type, source_id, source_no, quantity, remaining_quantity, unit_cost, batch_date) VALUES (?,?,?,'', 'product_opening', ?, ?, ?, ?, ?, CURDATE())") : null;

        $ins_batch_sync = $fifo ? mysqli_prepare($conn, "INSERT INTO stock_batches (user_id, branch_id, product_id, variant_name, source_type, source_id, source_no, quantity, remaining_quantity, unit_cost, batch_date) VALUES (?,?,?,'', 'cloudpos_sync', ?, ?, ?, ?, ?, CURDATE())") : null;
        $inserted = 0; $updated = 0; $skipped = 0; $batches = 0; $no_code = 0;
        $seen = [];

        foreach($products as $p){
            $name = trim((string)($p['product_name'] ?? ''));
            $sku = trim((string)($p[$code_field] ?? ''));
            if($name === '' || $sku === ''){ $no_code++; continue; }
            $key = strtoupper($sku);
            if(isset($seen[$key])){ $skipped++; continue; }
            $seen[$key] = true;

            // category
            $cat_name = trim((string)($p['category_name'] ?? ''));
            if($cat_name === '') $cat_name = 'General';
            $cat_key = mb_strtolower($cat_name);
            if(!isset($categories[$cat_key])){
                $cn = mb_substr($cat_name, 0, 100);
                mysqli_stmt_bind_param($ins_cat, 'is', $user_id, $cn);
                if(!mysqli_stmt_execute($ins_cat)) throw new Exception('Category: ' . mysqli_stmt_error($ins_cat));
                $categories[$cat_key] = ['id' => (int)mysqli_insert_id($conn), 'subs' => []];
            }
            $category_id = $categories[$cat_key]['id'];

            // sub category (kept inside the category's sub-category list)
            $sub = trim(str_replace([',', "\r", "\n"], ' ', (string)($p['sub_category_name'] ?? '')));
            $sub = mb_substr($sub, 0, 100);
            if($sub !== ''){
                $found = false;
                foreach($categories[$cat_key]['subs'] as $s){ if(mb_strtolower($s) === mb_strtolower($sub)){ $sub = $s; $found = true; break; } }
                if(!$found){
                    $categories[$cat_key]['subs'][] = $sub;
                    $text = implode(', ', $categories[$cat_key]['subs']);
                    mysqli_stmt_bind_param($upd_sub, 'sii', $text, $category_id, $user_id);
                    mysqli_stmt_execute($upd_sub);
                }
            }

            // stock per branch (only positive quantities are sent)
            $stock_rows = [];
            $total_qty = 0;
            foreach(($p['stock'] ?? []) as $sr){
                $store = trim((string)($sr[0] ?? ''));
                $qty = (float)($sr[1] ?? 0);
                if($store === '' || $qty < 0) continue;
                $stock_rows[] = [$store, $qty];
                $total_qty += $qty;
            }

            $purchase = (float)($p['purchase_price'] ?? 0);
            $is_existing = isset($existing[$key]);
            $sale = (float)($p['sale_price'] ?? 0);
            $min = (int)round((float)($p['minimum_stock'] ?? 0));
            $total_int = (int)round($total_qty);
            $nm = mb_substr($name, 0, 150);
            $sk = mb_substr($sku, 0, 50);

            if($is_existing){
                // Existing product: update details and sync store stock. Nothing is deleted.
                $product_id = $existing[$key];
                $upd = mysqli_prepare($conn, 'UPDATE products SET category_id=?, sub_category=?, product_name=?, purchase_price=?, sale_price=?, minimum_stock=? WHERE id=? AND user_id=?');
                mysqli_stmt_bind_param($upd, 'issddiii', $category_id, $sub, $nm, $purchase, $sale, $min, $product_id, $user_id);
                if(!mysqli_stmt_execute($upd)) throw new Exception('Update ' . $sku . ': ' . mysqli_stmt_error($upd));
                mysqli_stmt_close($upd);
                $updated++;

                if($fifo){
                    foreach($stock_rows as [$store, $qty]){
                        if(!isset($branches[$store])){
                            if($qty <= 0) continue;
                            $bn = 'Store ' . $store;
                            mysqli_stmt_bind_param($ins_branch, 'iss', $user_id, $bn, $store);
                            if(!mysqli_stmt_execute($ins_branch)) throw new Exception('Branch ' . $store . ': ' . mysqli_stmt_error($ins_branch));
                            $branches[$store] = (int)mysqli_insert_id($conn);
                        }
                        $bid = $branches[$store];
                        $cur = (float)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(remaining_quantity),0) AS q FROM stock_batches WHERE user_id={$user_id} AND product_id={$product_id} AND branch_id={$bid}"))['q'];
                        $delta = $qty - $cur;
                        if($delta > 0.0001){
                            $src_no = 'CPOS-SYNC';
                            mysqli_stmt_bind_param($ins_batch_sync, 'iiiisddd', $user_id, $bid, $product_id, $product_id, $src_no, $delta, $delta, $purchase);
                            if(!mysqli_stmt_execute($ins_batch_sync)) throw new Exception('Stock ' . $sku . ': ' . mysqli_stmt_error($ins_batch_sync));
                            $batches++;
                        }elseif($delta < -0.0001){
                            $need = -$delta;
                            $rows = mysqli_query($conn, "SELECT id, remaining_quantity FROM stock_batches WHERE user_id={$user_id} AND product_id={$product_id} AND branch_id={$bid} AND remaining_quantity>0 ORDER BY batch_date, id");
                            while($need > 0.0001 && ($b = mysqli_fetch_assoc($rows))){
                                $take = min($need, (float)$b['remaining_quantity']);
                                mysqli_query($conn, "UPDATE stock_batches SET remaining_quantity=remaining_quantity-{$take} WHERE id=" . (int)$b['id']);
                                $need -= $take;
                            }
                            $batches++;
                        }
                    }
                    mysqli_query($conn, "UPDATE products SET current_stock=ROUND((SELECT COALESCE(SUM(remaining_quantity),0) FROM stock_batches WHERE user_id={$user_id} AND product_id={$product_id})) WHERE id={$product_id} AND user_id={$user_id}");
                }
                continue;
            }

            $stock_rows = array_values(array_filter($stock_rows, function($r){ return $r[1] > 0; }));
            $total_qty = 0;
            foreach($stock_rows as $r){ $total_qty += $r[1]; }
            $total_int = (int)round($total_qty);

            mysqli_stmt_bind_param($ins_prod, 'iisssddiddi', $user_id, $category_id, $sub, $nm, $sk, $purchase, $sale, $total_int, $total_int, $purchase, $min);
            if(!mysqli_stmt_execute($ins_prod)) throw new Exception('Product ' . $sku . ': ' . mysqli_stmt_error($ins_prod));
            $product_id = (int)mysqli_insert_id($conn);
            $inserted++;

            foreach($stock_rows as [$store, $qty]){
                if(!isset($branches[$store])){
                    $bn = 'Store ' . $store;
                    mysqli_stmt_bind_param($ins_branch, 'iss', $user_id, $bn, $store);
                    if(!mysqli_stmt_execute($ins_branch)) throw new Exception('Branch ' . $store . ': ' . mysqli_stmt_error($ins_branch));
                    $branches[$store] = (int)mysqli_insert_id($conn);
                }
                if($ins_batch){
                    $bid = $branches[$store];
                    $src_no = 'OPEN-' . $product_id;
                    mysqli_stmt_bind_param($ins_batch, 'iiiisddd', $user_id, $bid, $product_id, $product_id, $src_no, $qty, $qty, $purchase);
                    if(!mysqli_stmt_execute($ins_batch)) throw new Exception('Stock ' . $sku . ': ' . mysqli_stmt_error($ins_batch));
                    $batches++;
                }
            }
        }

        mysqli_commit($conn);
        cp_json(['ok' => true, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'no_code' => $no_code, 'batches' => $batches]);
    }catch(Throwable $e){
        mysqli_rollback($conn);
        cp_json(['ok' => false, 'error' => $e->getMessage()]);
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
$csrf = $_SESSION['cloudpos_csrf'];
$ajax_url = 'import_cloudpos.php?ajax=1' . ($is_super ? '&company_id=' . (int)$user_id : '');
?>

<div class="card">
    <div class="card-header"><h3 class="card-title">Import CloudPOS Products &amp; Store Stock</h3></div>
    <div class="card-body">

        <div class="alert alert-info">
            Nothing is deleted. Products already in your company (same Code) are <strong>updated</strong> from the file (name, category, price, store stock); new ones are added; products and stock that are not in the file stay as they are. Safe to run again.
            Stock is created as opening stock for each store (a branch named <em>Store &lt;store_code&gt;</em> is created automatically; rename it later in Branches).
            Negative CloudPOS stock is ignored.
        </div>

        <?php if($is_super){ ?>
            <div class="alert alert-secondary">Target company: <strong><?= htmlspecialchars($target_company_name); ?></strong></div>
        <?php } ?>

        <div class="form-group">
            <label>CloudPOS JSON file</label>
            <input type="file" id="cp_file" accept=".json,application/json" class="form-control">
        </div>
        <div class="form-group">
            <label>Product Code to use</label>
            <select id="cp_code_field" class="form-control">
                <option value="barcode">Barcode (e.g. A000001) - recommended for scanning</option>
                <option value="product_code">Product code (e.g. 1000001)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" id="cp_password" class="form-control">
        </div>

        <button type="button" id="cp_start" class="btn btn-primary">Start Import</button>

        <div class="progress mt-3" style="height:22px;display:none" id="cp_bar_wrap">
            <div class="progress-bar" id="cp_bar" style="width:0%">0%</div>
        </div>
        <pre id="cp_log" class="mt-3" style="max-height:260px;overflow:auto;display:none"></pre>
    </div>
</div>

<script>
(function(){
    var AJAX = <?= json_encode($ajax_url); ?>;
    var CSRF = <?= json_encode($csrf); ?>;
    var CHUNK = 200;
    var btn = document.getElementById('cp_start');
    var logEl = document.getElementById('cp_log');
    var barWrap = document.getElementById('cp_bar_wrap');
    var bar = document.getElementById('cp_bar');

    function log(t){ logEl.style.display = 'block'; logEl.textContent += t + "\n"; logEl.scrollTop = logEl.scrollHeight; }
    function progress(p){ bar.style.width = p + '%'; bar.textContent = p + '%'; }
    function post(body){
        body.csrf = CSRF;
        return fetch(AJAX, {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify(body)})
            .then(function(r){ return r.text(); })
            .then(function(t){ try{ return JSON.parse(t); }catch(e){ throw new Error('Server error: ' + t.substring(0, 300)); } });
    }

    btn.addEventListener('click', async function(){
        var file = document.getElementById('cp_file').files[0];
        var password = document.getElementById('cp_password').value;
        var codeField = document.getElementById('cp_code_field').value;
        if(!file){ alert('Choose the CloudPOS JSON file.'); return; }
        if(!password){ alert('Enter your password.'); return; }
        btn.disabled = true; logEl.textContent = ''; barWrap.style.display = 'flex'; progress(0);
        try{
            log('Reading file ...');
            var data = JSON.parse(await file.text());
            if(!Array.isArray(data.products) || !Array.isArray(data.product_stock_by_store)){
                throw new Error('This is not a CloudPOS products/stock file (products + product_stock_by_store missing).');
            }
            var stockMap = {}, negative = 0;
            data.product_stock_by_store.forEach(function(s){
                var q = parseFloat(s.current_stock);
                if(q < 0){ negative++; return; }
                var k = s.product_code;
                (stockMap[k] = stockMap[k] || []).push([String(s.store_code), q]);
            });
            var products = data.products;
            log(products.length + ' products, ' + negative + ' negative stock rows will be ignored.');

            var r = await post({action:'start', password:password, total:products.length});
            if(!r.ok) throw new Error(r.error || 'Failed to start.');

            var totals = {inserted:0, updated:0, skipped:0, no_code:0, batches:0};
            for(var i = 0; i < products.length; i += CHUNK){
                var part = products.slice(i, i + CHUNK).map(function(p){
                    return {product_code:p.product_code, barcode:p.barcode, product_name:p.product_name,
                        category_name:p.category_name, sub_category_name:p.sub_category_name,
                        sale_price:p.sale_price, purchase_price:p.purchase_price, minimum_stock:p.minimum_stock,
                        stock: stockMap[p.product_code] || []};
                });
                var res = await post({action:'chunk', code_field:codeField, products:part});
                if(!res.ok) throw new Error('Stopped at product ' + (i + 1) + ': ' + (res.error || 'unknown error') + '\nNothing from this batch was saved. Fix the issue and run again; already imported products are skipped.');
                ['inserted','updated','skipped','no_code','batches'].forEach(function(k){ totals[k] += res[k]; });
                var done = Math.min(i + CHUNK, products.length);
                progress(Math.round(done * 100 / products.length));
                log(done + ' / ' + products.length + ' done');
            }
            log('\nFinished. New products: ' + totals.inserted + ', existing products updated: ' + totals.updated + ', duplicate in file skipped: ' + totals.skipped +
                ', without name/code: ' + totals.no_code + ', stock rows created: ' + totals.batches + '.');
        }catch(e){
            log('ERROR: ' + e.message);
        }
        btn.disabled = false;
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
