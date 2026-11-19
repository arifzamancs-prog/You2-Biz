<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/project_package_helper.php';
require_once '../includes/branch_context_helper.php';
require_once '../includes/barcode_helper.php';

require_admin_user();

$user_id = (int)$_SESSION['user_id'];

// Barcode labels belong to Inventory & Sales and Fashion Retail.
if(!in_array(project_package_company_type($conn, $user_id), ['Stock Product', 'Fashion house'], true)){
    header('Location: ' . app_path('dashboard.php'));
    exit;
}

$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, currency_code FROM users WHERE id={$user_id}")) ?: [];
$shop_name = trim((string)($company['name'] ?? ''));
$currency = trim((string)($company['currency_code'] ?? '')) ?: 'BDT';

$products = mysqli_fetch_all(
    mysqli_query($conn, "SELECT id, product_name, sku, sale_price FROM products WHERE user_id={$user_id} AND status='active' ORDER BY product_name"),
    MYSQLI_ASSOC
);
$variant_map = product_variant_names_by_product($conn, $user_id);

// Stock shown and used by "Add All In Stock": the selected branch, or every branch when none is selected.
$stock_branch_id = selected_branch_id($conn, false);
$stock_map = [];
$stock_rows = mysqli_query(
    $conn,
    "SELECT product_id, variant_name, SUM(remaining_quantity) AS available
     FROM stock_batches
     WHERE user_id={$user_id} AND remaining_quantity > 0" . ($stock_branch_id > 0 ? " AND branch_id={$stock_branch_id}" : '') . "
     GROUP BY product_id, variant_name"
);
while($stock_row = mysqli_fetch_assoc($stock_rows)){
    $stock_map[(int)$stock_row['product_id']][(string)$stock_row['variant_name']] = (float)$stock_row['available'];
}

// Every product has a product-level label; a product with variants also has one per variant.
// The Variant switch decides which of the two lists is offered.
$items = [];
foreach($products as $product){
    $variants = array_values(array_filter(array_map('strval', $variant_map[(int)$product['id']] ?? []), fn($name) => $name !== ''));
    $items[] = ['product' => $product, 'variant' => '', 'position' => 0, 'has_variants' => (bool)$variants];
    foreach($variants as $position => $variant){
        $items[] = ['product' => $product, 'variant' => $variant, 'position' => $position, 'has_variants' => true];
    }
}
$barcodes = product_barcodes_for_items($conn, $user_id, $items);

$label_items = [];
foreach($items as $item){
    $product_id = (int)$item['product']['id'];
    $variant = $item['variant'];
    // A product-level label of a product with variants counts the stock of all its variants.
    $stock = $variant === '' && $item['has_variants']
        ? array_sum($stock_map[$product_id] ?? [])
        : ($stock_map[$product_id][$variant] ?? 0);
    $label_items[] = [
        'key' => $product_id . '|' . $variant,
        'name' => $item['product']['product_name'],
        'variant' => $variant,
        'level' => $variant === '' ? 'product' : 'variant',
        'has_variants' => $item['has_variants'],
        'code' => (string)($item['product']['sku'] ?? ''),
        'barcode' => $barcodes[$product_id][$variant] ?? '',
        'price' => number_format((float)$item['product']['sale_price'], 2, '.', ''),
        'stock' => max(0, (int)floor($stock)),
    ];
}
// The Variant switch only appears when the company uses variants at all.
$company_has_variants = (bool)array_filter($label_items, fn($item) => $item['has_variants']);
if(!$company_has_variants && function_exists('product_variants_enabled') && product_variants_enabled($conn, $user_id)){
    $variant_categories = mysqli_query($conn, "SELECT 1 FROM product_categories WHERE user_id={$user_id} AND TRIM(COALESCE(variant_options,''))<>'' LIMIT 1");
    $company_has_variants = $variant_categories && mysqli_num_rows($variant_categories) > 0;
}
$label_items = array_values(array_filter($label_items, fn($item) => $item['barcode'] !== ''));

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';

?>

<style id="barcode-label-style">
    #barcode-preview{background:#eef1f4;border:1px solid #dee2e6;border-radius:4px;padding:12px;max-height:520px;overflow:auto}
    #barcode-preview .barcode-sheet{background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.15);margin:0 auto 12px}
    #barcode-preview .barcode-label{outline:1px dashed #c3c9cf}
    .barcode-label{box-sizing:border-box;overflow:hidden;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;font-family:Arial,Helvetica,sans-serif;color:#000;line-height:1.1}
    .barcode-label .bl-shop,.barcode-label .bl-name,.barcode-label .bl-code,.barcode-label .bl-price{flex:0 0 auto}
    .barcode-label .bl-shop{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
    .barcode-label .bl-name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
    .barcode-label .bl-bars{flex:1 1 auto;min-height:0;width:100%;margin:.4mm 0;overflow:hidden;display:flex;align-items:stretch;justify-content:center}
    .barcode-label .bl-bars svg{flex:0 0 auto;height:100%}
    .barcode-label .bl-code{letter-spacing:.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;flex:0 0 auto}
    .barcode-label .bl-price{font-weight:700}
    .barcode-label .bl-extra{flex:0 0 auto;font-size:1em;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
    .barcode-grid{display:grid;box-sizing:border-box}
    #barcode-items td{vertical-align:middle}
</style>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-barcode mr-2"></i>Barcode Labels</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">Each product (and each variant) has its own barcode. Choose the products, the number of labels and the printer, then print.</p>

        <div class="row">
            <div class="col-md-3 form-group">
                <label for="print-type">Print On</label>
                <select id="print-type" class="form-control">
                    <option value="thermal">Thermal Label Printer</option>
                    <option value="a4">A4 Sticker Sheet</option>
                </select>
            </div>
            <div class="col-md-3 form-group" id="thermal-size-group">
                <label for="thermal-size">Label Size</label>
                <select id="thermal-size" class="form-control">
                    <option value="38x25">38 × 25 mm</option>
                    <option value="40x30">40 × 30 mm</option>
                    <option value="50x25" selected>50 × 25 mm</option>
                    <option value="50x30">50 × 30 mm</option>
                    <option value="custom">Custom</option>
                </select>
            </div>
            <div class="col-md-3 form-group d-none" id="thermal-custom-group">
                <label>Width × Height (mm)</label>
                <div class="input-group">
                    <input type="number" id="thermal-width" class="form-control" min="20" max="120" step="1" value="50">
                    <div class="input-group-prepend input-group-append"><span class="input-group-text">×</span></div>
                    <input type="number" id="thermal-height" class="form-control" min="15" max="100" step="1" value="25">
                </div>
            </div>
            <div class="col-md-3 form-group d-none" id="a4-layout-group">
                <label for="a4-layout">Sheet Layout</label>
                <select id="a4-layout" class="form-control">
                    <option value="3x7">21 labels (3 × 7)</option>
                    <option value="3x8">24 labels (3 × 8)</option>
                    <option value="3x10" selected>30 labels (3 × 10)</option>
                    <option value="4x10">40 labels (4 × 10)</option>
                    <option value="5x13">65 labels (5 × 13)</option>
                </select>
            </div>
            <div class="col-md-3 form-group d-none" id="a4-start-group">
                <label for="a4-start">Start From Label No.</label>
                <input type="number" id="a4-start" class="form-control" min="1" value="1">
                <small class="text-muted">To use a sheet that is partly used.</small>
            </div>
        </div>

        <!-- Print adjustment: remembered in this browser. -->
        <div class="row">
            <div class="col-md-3 form-group">
                <label for="printer-dpi">Printer Resolution</label>
                <select id="printer-dpi" class="form-control print-setting">
                    <option value="203">203 dpi (most thermal printers)</option>
                    <option value="300">300 dpi</option>
                    <option value="600">600 dpi (laser / inkjet)</option>
                </select>
                <small class="text-muted">Bars are sized to the printer's dots so they scan.</small>
            </div>
            <div class="col-md-3 form-group">
                <label>Shift Print: Right × Down (mm)</label>
                <div class="input-group">
                    <input type="number" id="offset-x" class="form-control print-setting" min="-10" max="10" step="0.5" value="0">
                    <div class="input-group-prepend input-group-append"><span class="input-group-text">×</span></div>
                    <input type="number" id="offset-y" class="form-control print-setting" min="-10" max="10" step="0.5" value="0">
                </div>
                <small class="text-muted">If the print lands off the label. Minus moves left / up.</small>
            </div>
            <div class="col-md-3 form-group thermal-only">
                <label for="label-rotate">Rotate Print</label>
                <select id="label-rotate" class="form-control print-setting">
                    <option value="0">No rotation</option>
                    <option value="90">90° (turn right)</option>
                    <option value="270">90° (turn left)</option>
                    <option value="180">180° (upside down)</option>
                </select>
                <small class="text-muted">If the label prints sideways or upside down.</small>
            </div>
            <div class="col-md-3 form-group a4-only d-none">
                <label>Sheet Margin: Top × Left (mm)</label>
                <div class="input-group">
                    <input type="number" id="a4-margin-top" class="form-control print-setting" min="0" max="40" step="0.5" value="10">
                    <div class="input-group-prepend input-group-append"><span class="input-group-text">×</span></div>
                    <input type="number" id="a4-margin-left" class="form-control print-setting" min="0" max="40" step="0.5" value="5">
                </div>
                <small class="text-muted">Measure on your sticker sheet.</small>
            </div>
            <div class="col-md-3 form-group a4-only d-none">
                <label>Gap Between Labels: Across × Down (mm)</label>
                <div class="input-group">
                    <input type="number" id="a4-gap-x" class="form-control print-setting" min="0" max="20" step="0.5" value="0">
                    <div class="input-group-prepend input-group-append"><span class="input-group-text">×</span></div>
                    <input type="number" id="a4-gap-y" class="form-control print-setting" min="0" max="20" step="0.5" value="0">
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="d-block">Show On Label</label>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input label-part" id="show-shop" data-part="shop" checked><label class="custom-control-label" for="show-shop">Shop Name</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input label-part" id="show-name" data-part="name" checked><label class="custom-control-label" for="show-name">Product Name</label></div>
            <?php if($company_has_variants){ ?><div class="custom-control custom-checkbox custom-control-inline" title="On: each variant gets its own barcode. Off: one barcode per product."><input type="checkbox" class="custom-control-input label-part" id="show-variant" data-part="variant"><label class="custom-control-label" for="show-variant">Variant</label></div><?php } ?>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input label-part" id="show-price" data-part="price" checked><label class="custom-control-label" for="show-price">Price</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input label-part" id="show-code" data-part="code" checked><label class="custom-control-label" for="show-code">Barcode Number</label></div>
            <div class="d-inline-flex align-items-center mt-2 mt-md-0" title="Printed on every label, e.g. VAT included">
                <div class="custom-control custom-checkbox custom-control-inline mr-2"><input type="checkbox" class="custom-control-input label-part" id="show-extra" data-part="extra"><label class="custom-control-label" for="show-extra">Extra Text</label></div>
                <input type="text" id="label-extra-text" class="form-control form-control-sm" maxlength="40" placeholder="e.g. VAT included" style="width:190px">
            </div>
        </div>

        <hr>

        <div class="row align-items-end">
            <div class="col-md-6 form-group">
                <label for="barcode-product">Product</label>
                <select id="barcode-product" class="form-control">
                    <option value="">Search product name or code</option>
                </select>
            </div>
            <div class="col-md-2 form-group">
                <label for="barcode-qty">Labels</label>
                <input type="number" id="barcode-qty" class="form-control" min="1" max="500" value="1">
            </div>
            <div class="col-md-4 form-group">
                <button type="button" id="barcode-add" class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add</button>
                <button type="button" id="barcode-add-stock" class="btn btn-outline-primary ml-1" title="Add every product in stock, one label per unit">Add All In Stock</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm" id="barcode-items">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="variant-col">Variant</th>
                        <th>Barcode</th>
                        <th style="width:130px">Price</th>
                        <th style="width:110px">Labels</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="barcode-empty"><td colspan="6" class="text-center text-muted">No product added yet.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="d-flex align-items-center mb-2">
            <h5 class="mb-0">Preview</h5>
            <span class="text-muted ml-2" id="barcode-summary"></span>
            <button type="button" id="barcode-clear" class="btn btn-outline-secondary btn-sm ml-auto">Clear</button>
            <button type="button" id="barcode-print" class="btn btn-success ml-2" disabled><i class="fas fa-print mr-1"></i>Print Labels</button>
        </div>
        <div id="barcode-warning" class="alert alert-warning py-2 d-none"></div>
        <div id="barcode-preview"><p class="text-muted text-center mb-0">Add products to see the labels.</p></div>
        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle mr-1"></i>In the print window set <strong>Margins: None</strong> and <strong>Scale: 100%</strong> (not "Fit to page"), and choose the paper size that matches the label. Scaling changes the bar widths and the barcode may not scan.</small>
    </div>
</div>

<?php

$barcode_script_url = app_path('adminlte/plugins/jsbarcode/JsBarcode.code128.min.js');
$page_script = '<script src="' . htmlspecialchars($barcode_script_url) . '"></script>
<script>
(function(){
    const items = ' . json_encode($label_items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
    const shopName = ' . json_encode($shop_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
    const currency = ' . json_encode($currency, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
    const MAX_LABELS = 2000;
    const byKey = {};
    items.forEach(function(item){ byKey[item.key] = item; });
    const rows = [];
    const tbody = document.querySelector("#barcode-items tbody");
    const preview = document.getElementById("barcode-preview");
    const printButton = document.getElementById("barcode-print");

    // The Variant switch decides the list: one entry per product, or one per variant.
    const variantSwitch = document.getElementById("show-variant");
    function variantMode(){ return !!(variantSwitch && variantSwitch.checked); }
    function offered(){
        return items.filter(function(item){
            return variantMode() ? (item.level === "variant" || !item.has_variants) : item.level === "product";
        });
    }
    function renderOptions(){
        const select = document.getElementById("barcode-product");
        select.innerHTML = "";
        select.appendChild(new Option("Search product name or code", ""));
        offered().forEach(function(item){
            const text = item.name + (item.variant ? " - " + item.variant : "") + (item.code ? " [" + item.code + "]" : "") + " (Stock: " + item.stock + ")";
            select.appendChild(new Option(text, item.key));
        });
        $(select).val("").trigger("change");
    }
    renderOptions();
    if(variantSwitch) variantSwitch.addEventListener("change", renderOptions);
    $("#barcode-product").select2({theme: "bootstrap4", width: "100%", placeholder: "Search product name or code", allowClear: true});

    function el(tag, cls, text){
        const node = document.createElement(tag);
        if(cls) node.className = cls;
        if(text !== undefined) node.textContent = text;
        return node;
    }

    function numberSetting(id, fallback, min, max){
        const value = parseFloat($("#" + id).val());
        return isFinite(value) ? Math.min(max, Math.max(min, value)) : fallback;
    }
    function printerDpi(){ return parseInt($("#printer-dpi").val(), 10) || 203; }
    function printRotation(){ const r = parseInt($("#label-rotate").val(), 10); return [90, 180, 270].indexOf(r) >= 0 ? r : 0; }
    function printOffset(){ return {x: numberSetting("offset-x", 0, -10, 10), y: numberSetting("offset-y", 0, -10, 10)}; }

    function layout(){
        if($("#print-type").val() === "a4"){
            const parts = $("#a4-layout").val().split("x").map(Number);
            const cols = parts[0], rowsPerSheet = parts[1];
            // Margins (top/bottom and left/right are the same) and label gaps are set to match the sticker sheet.
            const padY = numberSetting("a4-margin-top", 10, 0, 40), padX = numberSetting("a4-margin-left", 5, 0, 40);
            const gapX = numberSetting("a4-gap-x", 0, 0, 20), gapY = numberSetting("a4-gap-y", 0, 0, 20);
            return {type: "a4", cols: cols, rows: rowsPerSheet, padX: padX, padY: padY, gapX: gapX, gapY: gapY,
                w: (210 - padX * 2 - gapX * (cols - 1)) / cols, h: (297 - padY * 2 - gapY * (rowsPerSheet - 1)) / rowsPerSheet};
        }
        let size = $("#thermal-size").val();
        let w, h;
        if(size === "custom"){
            w = Math.min(120, Math.max(20, parseFloat($("#thermal-width").val()) || 50));
            h = Math.min(100, Math.max(15, parseFloat($("#thermal-height").val()) || 25));
        }else{
            const parts = size.split("x").map(Number);
            w = parts[0]; h = parts[1];
        }
        return {type: "thermal", w: w, h: h};
    }

    // A part whose checkbox is not on the page (e.g. Variant for a company without variants) is off.
    function shown(part){
        const box = document.querySelector(".label-part[data-part=\"" + part + "\"]");
        return !!(box && box.checked);
    }

    function makeLabel(item, size){
        const label = el("div", "barcode-label");
        const font = Math.max(5, Math.min(9, size.h * 0.27));
        label.style.width = size.w + "mm";
        label.style.height = size.h + "mm";
        label.style.padding = "1.2mm 1.5mm";
        label.style.fontSize = font + "pt";
        if(shown("shop") && shopName) label.appendChild(el("div", "bl-shop", shopName));
        const name = (shown("name") ? item.name : "") + (shown("variant") && item.variant ? (shown("name") ? " - " : "") + item.variant : "");
        if(name) label.appendChild(el("div", "bl-name", name));
        const bars = el("div", "bl-bars");
        const barcode = barcodeSvg(item.barcode, size.w - 3, printerDpi());
        bars.appendChild(barcode.svg);
        label.appendChild(bars);
        if(barcode.quality !== "good") weakBarcodes[item.barcode] = barcode.quality;
        if(shown("code")) label.appendChild(el("div", "bl-code", item.barcode));
        if(shown("price") && item.price !== "") label.appendChild(el("div", "bl-price", currency + " " + Number(item.price).toFixed(2)));
        const extraText = ($("#label-extra-text").val() || "").trim();
        if(shown("extra") && extraText !== "") label.appendChild(el("div", "bl-extra", extraText));
        return label;
    }

    // Bars are sized in whole printer dots. Stretching a barcode to any width makes bars 1.6 or 2.2 dots
    // wide, the printer rounds them unevenly and scanners fail. Each narrow bar is now exactly N dots,
    // with a 10-bar quiet zone on both sides. quality: "good" (2+ dots), "thin" (1 dot), "too-long".
    const svgCache = {};
    let weakBarcodes = {};
    function barcodeSvg(value, innerWidthMm, dpi){
        const cacheKey = value + "|" + innerWidthMm + "|" + dpi;
        if(!svgCache[cacheKey]){
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            JsBarcode(svg, value, {format: "CODE128", width: 1, height: 60, margin: 0, displayValue: false});
            const modules = parseFloat(svg.getAttribute("width")) || 1;
            const dotMm = 25.4 / dpi;
            const availableDots = Math.floor(innerWidthMm / dotMm);
            let dots = Math.floor(availableDots / (modules + 20));
            let quality = dots >= 2 ? "good" : (dots === 1 ? "thin" : "too-long");
            if(dots < 1) dots = 1;
            // Too long even at one dot per bar: shrink to fit (it may not scan, a warning is shown).
            const barsMm = Math.min(modules * dots * dotMm, innerWidthMm);
            svg.setAttribute("viewBox", "0 0 " + modules + " 60");
            svg.setAttribute("preserveAspectRatio", "none");
            svg.setAttribute("shape-rendering", "crispEdges");
            svg.setAttribute("width", barsMm.toFixed(3) + "mm");
            svg.setAttribute("height", "100%");
            svg.removeAttribute("style");
            svgCache[cacheKey] = {svg: svg, quality: quality};
        }
        return {svg: svgCache[cacheKey].svg.cloneNode(true), quality: svgCache[cacheKey].quality};
    }

    // The thermal page: the label size, or turned (h × w) when the print is rotated 90° either way.
    function pageSize(size){
        const r = printRotation();
        return (r === 90 || r === 270) ? {w: size.h, h: size.w} : {w: size.w, h: size.h};
    }

    // The printable sheets: one page per thermal label, or A4 pages of labels.
    function buildSheets(){
        const size = layout();
        const labels = [];
        rows.forEach(function(row){
            for(let i = 0; i < row.qty; i++) labels.push(row);
        });
        const sheets = [];
        const offset = printOffset();
        if(size.type === "thermal"){
            // Rotation turns the label on the page; at 90° the page itself is h × w, for printers
            // (drivers) that feed the label the other way and would otherwise print it sideways.
            const rotate = printRotation();
            const page = pageSize(size);
            const turn = {0: "", 90: "translate(" + size.h + "mm,0) rotate(90deg)",
                180: "translate(" + size.w + "mm," + size.h + "mm) rotate(180deg)", 270: "translate(0," + size.w + "mm) rotate(-90deg)"}[rotate];
            labels.forEach(function(row){
                const sheet = el("div", "barcode-sheet");
                sheet.style.position = "relative";
                sheet.style.width = page.w + "mm";
                // A hair under the page height, so rounding never pushes a blank label out between labels.
                sheet.style.height = (page.h - 0.2) + "mm";
                const label = makeLabel(row.item, size);
                const transform = ((offset.x || offset.y) ? "translate(" + offset.x + "mm," + offset.y + "mm) " : "") + turn;
                if(transform.trim()){
                    label.style.position = "absolute";
                    label.style.left = "0";
                    label.style.top = "0";
                    label.style.transformOrigin = "0 0";
                    label.style.transform = transform;
                }
                sheet.appendChild(label);
                sheets.push(sheet);
            });
            return {sheets: sheets, size: size, count: labels.length};
        }
        const perSheet = size.cols * size.rows;
        const skip = Math.min(perSheet - 1, Math.max(0, (parseInt($("#a4-start").val(), 10) || 1) - 1));
        const slots = new Array(skip).fill(null).concat(labels);
        for(let start = 0; start < slots.length; start += perSheet){
            const sheet = el("div", "barcode-sheet barcode-grid");
            sheet.style.width = "210mm";
            sheet.style.height = "297mm";
            // The shift moves the whole grid; the far margins shrink by the same amount so the page size stays A4.
            sheet.style.padding = (size.padY + offset.y) + "mm " + (size.padX - offset.x) + "mm " + (size.padY - offset.y) + "mm " + (size.padX + offset.x) + "mm";
            sheet.style.gridTemplateColumns = "repeat(" + size.cols + ", " + size.w + "mm)";
            sheet.style.gridTemplateRows = "repeat(" + size.rows + ", " + size.h + "mm)";
            sheet.style.columnGap = size.gapX + "mm";
            sheet.style.rowGap = size.gapY + "mm";
            slots.slice(start, start + perSheet).forEach(function(row){
                sheet.appendChild(row ? makeLabel(row.item, size) : el("div", "barcode-label"));
            });
            sheets.push(sheet);
        }
        return {sheets: sheets, size: size, count: labels.length};
    }

    // Barcodes too long for this label at this printer resolution are named, with what to change.
    function showBarcodeWarning(){
        const box = $("#barcode-warning");
        const codes = Object.keys(weakBarcodes);
        if(!codes.length){ box.addClass("d-none").empty(); return; }
        const tooLong = codes.filter(function(code){ return weakBarcodes[code] === "too-long"; });
        box.removeClass("d-none").empty()
            .append($("<strong>").text(tooLong.length ? "Some barcodes may not scan: " : "Thin barcodes: "))
            .append(document.createTextNode(codes.join(", ") + ". "))
            .append(document.createTextNode(tooLong.length
                ? "They are too long for this label size. Use a wider label, a 300 dpi printer, or a shorter product Code."
                : "Bars are 1 printer dot wide. A wider label or a 300 dpi printer scans more reliably."));
    }

    function render(){
        const total = rows.reduce(function(sum, row){ return sum + row.qty; }, 0);
        preview.innerHTML = "";
        if(total === 0 || total > MAX_LABELS) $("#barcode-warning").addClass("d-none").empty();
        printButton.disabled = total === 0 || total > MAX_LABELS;
        if(total === 0){
            preview.appendChild(el("p", "text-muted text-center mb-0", "Add products to see the labels."));
            $("#barcode-summary").text("");
            return;
        }
        if(total > MAX_LABELS){
            preview.appendChild(el("p", "text-danger text-center mb-0", "Print at most " + MAX_LABELS + " labels at a time."));
            $("#barcode-summary").text(total + " labels");
            return;
        }
        weakBarcodes = {};
        const built = buildSheets();
        showBarcodeWarning();
        // The preview shows the first few pages; printing uses all of them.
        built.sheets.slice(0, built.size.type === "a4" ? 3 : 24).forEach(function(sheet){ preview.appendChild(sheet); });
        if(built.size.type === "thermal"){
            preview.style.display = "flex";
            preview.style.flexWrap = "wrap";
            preview.style.gap = "8px";
        }else{
            preview.style.display = "";
        }
        $("#barcode-summary").text(built.count + " labels" + (built.size.type === "a4" ? ", " + built.sheets.length + " sheet(s)" : ""));
    }

    function renderTable(){
        tbody.innerHTML = "";
        if(!rows.length){
            const tr = el("tr", "barcode-empty");
            const td = el("td", "text-center text-muted", "No product added yet.");
            td.colSpan = 6;
            tr.appendChild(td);
            tbody.appendChild(tr);
        }
        const hasVariant = rows.some(function(row){ return row.item.variant !== ""; });
        document.querySelectorAll("#barcode-items .variant-col").forEach(function(cell){ cell.classList.toggle("d-none", !hasVariant); });
        rows.forEach(function(row, index){
            const tr = el("tr");
            tr.appendChild(el("td", "", row.item.name + (row.item.code ? " [" + row.item.code + "]" : "")));
            const variantCell = el("td", "variant-col", row.item.variant || "-");
            variantCell.classList.toggle("d-none", !hasVariant);
            tr.appendChild(variantCell);
            tr.appendChild(el("td", "text-monospace", row.item.barcode));
            const priceCell = el("td");
            const price = el("input", "form-control form-control-sm");
            price.type = "number"; price.step = "0.01"; price.min = "0"; price.value = row.item.price;
            price.addEventListener("input", function(){ row.item = Object.assign({}, row.item, {price: price.value}); render(); });
            priceCell.appendChild(price);
            tr.appendChild(priceCell);
            const qtyCell = el("td");
            const qty = el("input", "form-control form-control-sm");
            qty.type = "number"; qty.min = "1"; qty.max = "500"; qty.value = row.qty;
            qty.addEventListener("input", function(){ row.qty = Math.max(0, Math.min(500, parseInt(qty.value, 10) || 0)); render(); });
            qtyCell.appendChild(qty);
            tr.appendChild(qtyCell);
            const removeCell = el("td", "text-center");
            const remove = el("button", "btn btn-danger btn-sm");
            remove.type = "button"; remove.title = "Remove"; remove.innerHTML = "<i class=\"fas fa-times\"></i>";
            remove.addEventListener("click", function(){ rows.splice(index, 1); renderTable(); render(); });
            removeCell.appendChild(remove);
            tr.appendChild(removeCell);
            tbody.appendChild(tr);
        });
    }

    function addItem(key, qty){
        const item = byKey[key];
        if(!item || qty <= 0) return;
        const existing = rows.find(function(row){ return row.item.key === key; });
        if(existing){
            existing.qty = Math.min(500, existing.qty + qty);
        }else{
            rows.push({item: Object.assign({}, item), qty: Math.min(500, qty)});
        }
    }

    $("#barcode-add").on("click", function(){
        const key = $("#barcode-product").val();
        if(!key){ alert("Select a product."); return; }
        addItem(key, parseInt($("#barcode-qty").val(), 10) || 1);
        $("#barcode-product").val("").trigger("change");
        $("#barcode-qty").val(1);
        renderTable(); render();
    });

    $("#barcode-add-stock").on("click", function(){
        let added = 0;
        offered().forEach(function(item){ if(item.stock > 0){ addItem(item.key, item.stock); added++; } });
        if(!added) alert("No product is in stock.");
        renderTable(); render();
    });

    $("#barcode-clear").on("click", function(){ rows.length = 0; renderTable(); render(); });

    // Print settings are remembered in this browser (paper, size, printer, shift, sheet margins).
    const settingIds = ["print-type", "thermal-size", "thermal-width", "thermal-height", "a4-layout", "printer-dpi", "offset-x", "offset-y", "label-rotate", "a4-margin-top", "a4-margin-left", "a4-gap-x", "a4-gap-y"];
    try {
        const saved = JSON.parse(localStorage.getItem("barcode_label_print_settings") || "{}");
        settingIds.forEach(function(id){ if(saved[id] !== undefined && saved[id] !== null) $("#" + id).val(saved[id]); });
    } catch(e) {}
    function saveSettings(){
        const values = {};
        settingIds.forEach(function(id){ values[id] = $("#" + id).val(); });
        try { localStorage.setItem("barcode_label_print_settings", JSON.stringify(values)); } catch(e) {}
    }
    $("#" + settingIds.join(", #")).on("change", saveSettings);
    $("#printer-dpi, #offset-x, #offset-y, #label-rotate, #a4-margin-top, #a4-margin-left, #a4-gap-x, #a4-gap-y").on("input change", render);

    $("#print-type").on("change", function(event){
        const a4 = this.value === "a4";
        $("#thermal-size-group").toggleClass("d-none", a4);
        $("#thermal-custom-group").toggleClass("d-none", a4 || $("#thermal-size").val() !== "custom");
        $("#a4-layout-group, #a4-start-group, .a4-only").toggleClass("d-none", !a4);
        $(".thermal-only").toggleClass("d-none", a4);
        // A user switch of paper also suggests the usual printer: thermal 203 dpi, sheet printers 600 dpi.
        if(event.originalEvent){
            $("#printer-dpi").val(a4 ? "600" : "203");
            saveSettings();
        }
        render();
    });
    $("#thermal-size").on("change", function(){
        $("#thermal-custom-group").toggleClass("d-none", this.value !== "custom");
        render();
    });
    $("#thermal-width, #thermal-height, #a4-layout, #a4-start").on("input change", render);
    $(".label-part").on("change", render);
    // Extra text (e.g. "VAT included") is remembered in this browser.
    try {
        $("#label-extra-text").val(localStorage.getItem("barcode_label_extra_text") || "");
        $("#show-extra").prop("checked", localStorage.getItem("barcode_label_extra_on") === "1");
    } catch(e) {}
    $("#label-extra-text").on("input", function(){
        if(this.value.trim() !== "") $("#show-extra").prop("checked", true);
        try { localStorage.setItem("barcode_label_extra_text", this.value); localStorage.setItem("barcode_label_extra_on", $("#show-extra").prop("checked") ? "1" : "0"); } catch(e) {}
        render();
    });
    $("#show-extra").on("change", function(){ try { localStorage.setItem("barcode_label_extra_on", this.checked ? "1" : "0"); } catch(e) {} });

    printButton.addEventListener("click", function(){
        const built = buildSheets();
        const size = built.size;
        const thermalPage = pageSize(size);
        const page = size.type === "a4" ? "210mm 297mm" : thermalPage.w + "mm " + thermalPage.h + "mm";
        const css = document.getElementById("barcode-label-style").textContent
            + "@page{size:" + page + ";margin:0}"
            + "html,body{margin:0;padding:0;background:#fff}"
            + ".barcode-sheet{overflow:hidden;page-break-after:always;break-after:page}"
            + ".barcode-sheet:last-child{page-break-after:auto;break-after:auto}"
            + "*{-webkit-print-color-adjust:exact;print-color-adjust:exact}";
        const win = window.open("", "barcodeLabelPrint");
        if(!win){ alert("Allow pop-ups for this site to print labels."); return; }
        win.document.open();
        win.document.write("<!doctype html><html><head><meta charset=\"utf-8\"><title>Barcode Labels</title><style>" + css + "</style></head><body></body></html>");
        win.document.close();
        built.sheets.forEach(function(sheet){ win.document.body.appendChild(win.document.importNode(sheet, true)); });
        win.focus();
        win.setTimeout(function(){ win.print(); }, 300);
    });

    $("#print-type").trigger("change");
})();
</script>';

require_once '../includes/footer.php';
