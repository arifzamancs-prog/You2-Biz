<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/fifo_inventory_helper.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/product_image_helper.php';
require_once '../includes/product_category_helper.php';
$company_id = (int)$_SESSION['user_id'];
ensure_product_subcategory_schema($conn);
if (project_package_company_type($conn, $company_id) !== 'Fashion house'
    || (is_manager_user() && !manager_has_permission('stock_live_report'))) {
    http_response_code(403); exit('Live report access is required.');
}
$scope = stock_can_manage_warehouse($conn) ? 0 : (int)selected_branch_id($conn, true);
if (!stock_can_manage_warehouse($conn) && $scope <= 0) { http_response_code(403); exit('Branch is not configured.'); }
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    session_write_close();
    try {
        mysqli_begin_transaction($conn, MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        $locations = []; $warehouse = 0;
        $result = mysqli_query($conn, "SELECT id,branch_name,branch_code,is_head_office FROM branches WHERE user_id={$company_id} ORDER BY is_head_office DESC,branch_name");
        while ($b = mysqli_fetch_assoc($result)) {
            if ($b['branch_name'] === 'Main Warehouse') $warehouse = (int)$b['id'];
            if ($scope && $scope !== (int)$b['id']) continue;
            $b['code'] = $b['branch_name'] === 'Main Warehouse' ? 'WH' : ($b['branch_code'] ?: ($b['is_head_office'] ? 'HO' : 'BR-'.$b['id']));
            $locations[(int)$b['id']] = $b;
        }
        // Keep the warehouse column visually central in the stock matrix, while
        // retaining Total Qty as the final column.
        if (!$scope && $locations) {
            $warehouse_location = null;
            $branch_locations = [];
            foreach ($locations as $location) {
                if ($location['code'] === 'WH') {
                    $warehouse_location = $location;
                } else {
                    $branch_locations[] = $location;
                }
            }
            if ($warehouse_location) {
                array_splice($branch_locations, (int)ceil(count($branch_locations) / 2), 0, [$warehouse_location]);
            }
            $locations = [];
            foreach ($branch_locations as $location) {
                $locations[(int)$location['id']] = $location;
            }
        }
        $products = [];
        $result = mysqli_query($conn, "SELECT p.id,p.product_name,p.sku,p.photo_path,p.sub_category,c.id AS category_id,c.category_name FROM products p JOIN product_categories c ON c.id=p.category_id AND c.user_id=p.user_id WHERE p.user_id={$company_id} AND c.category_type='stock_product' ORDER BY c.category_name,p.sub_category,p.product_name");
        while ($p = mysqli_fetch_assoc($result)) { $p['photo'] = product_image_url($conn, $p['photo_path'] ?? ''); unset($p['photo_path']); $p['variants'] = []; $products[(int)$p['id']] = $p; }
        $add = function($pid, $variant, $bid, $metric, $qty) use (&$products, $locations) {
            if (!isset($products[$pid]) || !isset($locations[$bid])) return;
            if (!isset($products[$pid]['variants'][$variant])) $products[$pid]['variants'][$variant] = [];
            if (!isset($products[$pid]['variants'][$variant][$bid])) $products[$pid]['variants'][$variant][$bid] = ['on_hand'=>0,'damaged'=>0,'transit'=>0];
            $products[$pid]['variants'][$variant][$bid][$metric] += (float)$qty;
        };
        $result = mysqli_query($conn, "SELECT product_id,variant_name FROM product_variants WHERE user_id={$company_id} ORDER BY id");
        while ($r = mysqli_fetch_assoc($result)) if (isset($products[$r['product_id']])) $products[$r['product_id']]['variants'][$r['variant_name']] = [];
        $result = mysqli_query($conn, "SELECT product_id,variant_name,branch_id,SUM(remaining_quantity) qty FROM stock_batches WHERE user_id={$company_id} GROUP BY product_id,variant_name,branch_id");
        while ($r = mysqli_fetch_assoc($result)) $add($r['product_id'], $r['variant_name'], $r['branch_id'], 'on_hand', $r['qty']);
        $result = mysqli_query($conn, "SELECT product_id,variant_name,to_branch_id,status,SUM(damaged_quantity) damage,SUM(quantity) qty FROM stock_distributions WHERE user_id={$company_id} GROUP BY product_id,variant_name,to_branch_id,status");
        while ($r = mysqli_fetch_assoc($result)) {
            if ($r['status'] === 'accepted') $add($r['product_id'], $r['variant_name'], $r['to_branch_id'], 'damaged', $r['damage']);
            if ($r['status'] === 'pending') $add($r['product_id'], $r['variant_name'], $r['to_branch_id'], 'transit', $r['qty']);
        }
        $transit_scope = $scope ? " AND d.to_branch_id={$scope}" : '';
        $transit_routes = [];
        $result = mysqli_query($conn, "SELECT d.product_id,d.from_branch_id,d.to_branch_id,
            DATE_FORMAT(MIN(d.created_at),'%d-%m-%Y') AS transfer_date,
            fb.branch_name AS from_name,CASE WHEN fb.branch_name='Main Warehouse' THEN 'WH' WHEN fb.branch_code<>'' THEN fb.branch_code WHEN fb.is_head_office=1 THEN 'HO' ELSE CONCAT('BR-',fb.id) END AS from_code,
            tb.branch_name AS to_name,CASE WHEN tb.branch_name='Main Warehouse' THEN 'WH' WHEN tb.branch_code<>'' THEN tb.branch_code WHEN tb.is_head_office=1 THEN 'HO' ELSE CONCAT('BR-',tb.id) END AS to_code,
            GROUP_CONCAT(CASE WHEN COALESCE(d.variant_name,'')='' THEN NULL ELSE CONCAT(d.variant_name, ': ', FORMAT(d.quantity,0)) END ORDER BY d.id SEPARATOR ' || ') AS variant_summary,
            SUM(d.quantity) AS quantity
            FROM stock_distributions d
            INNER JOIN branches fb ON fb.id=d.from_branch_id AND fb.user_id=d.user_id
            INNER JOIN branches tb ON tb.id=d.to_branch_id AND tb.user_id=d.user_id
            WHERE d.user_id={$company_id} AND d.status='pending' {$transit_scope}
            GROUP BY DATE(d.created_at),d.product_id,d.from_branch_id,d.to_branch_id,fb.branch_name,fb.branch_code,fb.is_head_office,tb.branch_name,tb.branch_code,tb.is_head_office
            ORDER BY DATE(d.created_at) DESC,fb.branch_name,tb.branch_name,d.product_id");
        while ($r = $result ? mysqli_fetch_assoc($result) : null) {
            $r['product_id'] = (int)$r['product_id'];
            $r['quantity'] = (float)$r['quantity'];
            $transit_routes[] = $r;
        }
        $result = mysqli_query($conn, "SELECT product_id,variant_name,branch_id,status,SUM(quantity) qty FROM stock_damage_returns WHERE user_id={$company_id} AND status<>'pending' GROUP BY product_id,variant_name,branch_id,status");
        while ($r = mysqli_fetch_assoc($result)) {
            $add($r['product_id'], $r['variant_name'], $r['branch_id'], 'damaged', -$r['qty']);
            if ($r['status'] !== 'dumped' && $warehouse) $add($r['product_id'], $r['variant_name'], $warehouse, 'damaged', $r['qty']);
        }
        foreach ($products as &$p) { if (!$p['variants']) $p['variants'] = [''=>[]]; $rows=[]; foreach($p['variants'] as $name=>$cells) $rows[]=['name'=>(string)$name,'cells'=>(object)$cells]; $p['variants']=$rows; } unset($p);
        mysqli_commit($conn);
        echo json_encode(['locations'=>array_values($locations),'products'=>array_values($products),'transit_routes'=>$transit_routes,'updated'=>date('d-m-Y h:i:s A')], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) { mysqli_rollback($conn); error_log('Live report: '.$e->getMessage()); http_response_code(500); echo json_encode(['error'=>'Report could not be refreshed. Please retry.']); }
    exit;
}
require_once '../includes/header.php'; require_once '../includes/navbar.php'; require_once '../includes/sidebar.php';
?>
<div class="card live-report" id="live-report">
<div class="card-header"><h3 class="card-title">Stock Live Report</h3><span class="float-right live-indicator" id="live-status" role="status" data-state="loading"><span class="live-dot" aria-hidden="true"></span><span id="live-status-label">Loading…</span></span></div>
<div class="card-body">
<div class="live-print-meta" id="live-print-meta"></div>
<div class="form-row report-controls">
<div class="col-md-2"><label for="live-category">Category</label><select id="live-category" class="form-control"><option value="">All categories</option></select></div>
<div class="col-md-2"><label for="live-sub-category">Sub Category</label><select id="live-sub-category" class="form-control"><option value="">All sub categories</option></select></div>
<div class="col-md-2"><label for="live-product">Product</label><select id="live-product" class="form-control"><option value="">All products</option></select></div>
<div class="col-md-2"><label for="live-branch">Branch</label><select id="live-branch" class="form-control"><option value="">All locations</option></select></div>
<div class="col-md-4"><label for="live-metric">Matrix values</label><select id="live-metric" class="form-control"><option value="on_hand">On Hand</option><option value="pos">Total Stock</option><option value="damaged">Damaged</option><option value="transit">In Transit</option></select></div>
</div>
<div class="my-3 report-controls"><button type="button" id="live-print" class="btn btn-outline-secondary">Print</button> <button type="button" id="live-fullscreen" class="btn btn-outline-primary" aria-pressed="false" aria-controls="live-report">Full Screen</button><span id="live-fullscreen-error" class="text-danger small ml-2" role="status"></span></div>
<div id="live-content" aria-busy="true"></div>
<div class="modal fade" id="live-damage-breakdown-modal" tabindex="-1" role="dialog" aria-labelledby="live-damage-breakdown-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="live-damage-breakdown-title"><i class="fas fa-exclamation-triangle text-danger mr-2" aria-hidden="true"></i>Damaged Stock</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="modal-body p-0"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Branch</th><th>Product</th><th class="text-right">Damaged</th></tr></thead><tbody id="live-damage-breakdown-body"></tbody><tfoot><tr><th colspan="2">Total</th><th class="text-right text-danger" id="live-damage-breakdown-total">0</th></tr></tfoot></table></div></div></div></div></div>
<div class="modal fade" id="live-transit-breakdown-modal" tabindex="-1" role="dialog" aria-labelledby="live-transit-breakdown-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg" role="document"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="live-transit-breakdown-title"><i class="fas fa-truck text-primary mr-2" aria-hidden="true"></i>Stock In Transit</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="modal-body p-0"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Date</th><th>From</th><th>To</th><th>Product / Variant</th><th class="text-right">Qty</th></tr></thead><tbody id="live-transit-breakdown-body"></tbody><tfoot><tr><th colspan="4">Total</th><th class="text-right text-primary" id="live-transit-breakdown-total">0</th></tr></tfoot></table></div></div></div></div></div>
<div class="modal fade" id="live-photo-preview-modal" tabindex="-1" role="dialog" aria-labelledby="live-photo-preview-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg" role="document"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="live-photo-preview-title">Product Photo</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="modal-body text-center p-3"><img id="live-photo-preview-image" src="" alt="" style="max-width:100%;max-height:70vh;object-fit:contain"></div></div></div></div>
</div></div>
<style id="live-print-style">@page {size:A4 landscape;margin:8mm;}</style>
<style>
.live-indicator{display:inline-flex;align-items:center;gap:9px;font-size:14px;font-weight:600;color:#687582}.live-dot{width:10px;height:10px;display:inline-block;border-radius:50%;background:currentColor}.live-indicator[data-state="live"]{color:#218838}.live-indicator[data-state="error"]{color:#c0392b}.live-indicator[data-state="live"] .live-dot{animation:live-report-pulse-green 1.8s ease-out infinite}.live-indicator[data-state="error"] .live-dot{animation:live-report-pulse-red 1.15s ease-out infinite}@keyframes live-report-pulse-green{0%{box-shadow:0 0 0 0 rgba(40,167,69,.55)}70%{box-shadow:0 0 0 8px rgba(40,167,69,0)}100%{box-shadow:0 0 0 0 rgba(40,167,69,0)}}@keyframes live-report-pulse-red{0%{box-shadow:0 0 0 0 rgba(192,57,43,.62)}70%{box-shadow:0 0 0 9px rgba(192,57,43,0)}100%{box-shadow:0 0 0 0 rgba(192,57,43,0)}}@media(prefers-reduced-motion:reduce){.live-indicator .live-dot{animation:none!important}}
.live-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}.live-summary div{flex:1;min-width:130px;background:#eef4fb;padding:14px;border-radius:6px}.live-summary strong{display:block;font-size:24px}.live-damage-summary{display:block;padding:0;border:0;background:transparent;color:#b42318;text-align:left;cursor:pointer}.live-damage-summary strong{text-decoration:underline;text-decoration-style:dotted;text-underline-offset:4px}.live-damage-summary:hover strong,.live-damage-summary:focus strong{color:#dc3545;text-decoration-style:solid}.live-matrix{width:100%;border-collapse:collapse;font-size:13px}.live-matrix th,.live-matrix td{border:1px solid #c4cfda;padding:8px;text-align:right;white-space:nowrap}.live-matrix th{background:#dfebfc}.live-matrix th:first-child,.live-matrix td:first-child{text-align:left}.live-matrix .wh{background:#e6f2df}.live-matrix tfoot{font-weight:bold;background:#edf3fb}.live-matrix .damage{color:#b42318}.live-product-head{display:flex;align-items:center;gap:12px;margin:20px 0 10px}.live-photo-preview{padding:0;border:0;background:transparent;cursor:zoom-in;line-height:0}.live-product-head img{width:52px;height:60px;object-fit:cover;border-radius:4px;transition:transform .15s ease}.live-photo-preview:hover img{transform:scale(1.06)}.live-scroll{overflow:auto;margin-bottom:22px}.live-category-title{border-bottom:2px solid #2072b9;padding-bottom:8px;margin-top:25px}.live-empty{padding:35px;text-align:center;color:#667085}
.live-breakdown-summary{display:block;padding:0;border:0;background:transparent;text-align:left;cursor:pointer}.live-transit-summary{color:#1769aa}.live-breakdown-summary strong{text-decoration:none}.live-damage-summary:hover strong,.live-damage-summary:focus strong{color:#dc3545}.live-transit-summary:hover strong,.live-transit-summary:focus strong{color:#007bff}
@media print{.main-sidebar,.main-header,.main-footer,.report-controls{display:none!important}.content-wrapper{margin-left:0!important}.live-report{border:0;box-shadow:none}.live-report .card-body{padding:0}.live-scroll{overflow:visible}.live-matrix{font-size:9px}.live-matrix th,.live-matrix td{padding:3px;white-space:normal}.live-category-title{break-after:avoid-page;page-break-after:avoid}.live-category-title + .live-product-block{break-before:avoid-page;page-break-before:avoid}.live-product-block{break-inside:avoid}.live-matrix thead{display:table-header-group}}
@media print{.live-category-start{break-inside:avoid;page-break-inside:avoid}.live-category-title + p{break-after:avoid;page-break-after:avoid}.live-product-head{break-after:avoid;page-break-after:avoid}.live-matrix tr{break-inside:avoid;page-break-inside:avoid}}
</style>
<style>
.live-print-meta{display:none}
.live-report:fullscreen{display:block;width:100%;height:100%;overflow:auto;margin:0;border:0;border-radius:0;background:#fff;padding:16px}
.live-report:fullscreen>.card-body{overflow:visible}
@media print{
 body{background:#fff!important}
 .content-wrapper,.content{padding:0!important;min-height:0!important;background:#fff!important}
 .live-report{display:block!important;margin:0!important;color:#182536;font-family:Arial,sans-serif}
 .live-report>.card-header{padding:0 0 3mm!important;border-bottom:2px solid #253d58}
 .live-report .card-title{float:none!important;font-size:20pt;font-weight:700;margin:0}
 .live-indicator{display:none!important}
 .live-print-meta{display:block;font-size:9pt;color:#526174;padding:3mm 0 4mm;border-bottom:1px solid #b8c4d0;margin-bottom:4mm;line-height:1.6}
 .live-summary{gap:3mm;margin-bottom:4mm;break-inside:avoid}
 .live-summary div{border:1px solid #bac7d5;border-top:3px solid #253d58;border-radius:0;padding:3mm;font-size:10pt;min-width:0}
 .live-summary strong{font-size:18pt;margin-top:1mm;font-variant-numeric:tabular-nums}
 .live-category-title{font-size:14pt;font-weight:700;margin:4mm 0 2mm;padding-bottom:1.5mm;border-color:#253d58}
 .live-category-title+p{font-size:9pt;margin-bottom:2mm}
 .live-product-head{margin:3mm 0 2mm;gap:3mm;font-size:11pt}
 .live-product-head img{width:10mm;height:12mm;object-fit:contain}
 .live-product-head .small{font-size:8.5pt;margin-top:1mm}
 .live-scroll{margin-bottom:3mm}
 .live-matrix{font-size:9pt;line-height:1.25;font-variant-numeric:tabular-nums}
 .live-matrix th,.live-matrix td{padding:1.5mm;border-color:#9caec0}
 .live-matrix th{font-weight:700;border-bottom:1.5px solid #253d58}
 .live-matrix tfoot{display:table-row-group;font-weight:700;border-top:1.5px solid #253d58}
 .live-matrix td:last-child,.live-matrix th:last-child{border-left:1.5px solid #253d58}
}
</style>
<script src="../assets/js/live_report.js?v=<?= filemtime(__DIR__ . '/../assets/js/live_report.js') ?>"></script>
<?php require_once '../includes/footer.php'; ?>
