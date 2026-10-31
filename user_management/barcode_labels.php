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

// One label item per product, or per variant when the product has variants.
$items = [];
foreach($products as $product){
    $variants = $variant_map[(int)$product['id']] ?? [''];
    foreach(array_values($variants) as $position => $variant){
        $items[] = ['product' => $product, 'variant' => (string)$variant, 'position' => $position];
    }
}
$barcodes = product_barcodes_for_items($conn, $user_id, $items);

$label_items = [];
foreach($items as $item){
    $product_id = (int)$item['product']['id'];
    $variant = $item['variant'];
    $label_items[] = [
        'key' => $product_id . '|' . $variant,
        'name' => $item['product']['product_name'],
        'variant' => $variant,
        'code' => (string)($item['product']['sku'] ?? ''),
        'barcode' => $barcodes[$product_id][$variant] ?? '',
        'price' => number_format((float)$item['product']['sale_price'], 2, '.', ''),
        'stock' => max(0, (int)floor($stock_map[$product_id][$variant] ?? 0)),
    ];
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
    .barcode-label .bl-bars svg{width:92%;height:100%}
    .barcode-label .bl-code{letter-spacing:.5px}
    .barcode-label .bl-price{font-weight:700}
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

        <div class="form-group">
            <label class="d-block">Show On Label</label>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input label-part" id="show-shop" data-part="shop" checked><label class="custom-control-label" for="show-shop">Shop Name</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input label-part" id="show-name" data-part="name" checked><label class="custom-control-label" for="show-name">Product Name</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input label-part" id="show-variant" data-part="variant" checked><label class="custom-control-label" for="show-variant">Variant</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input label-part" id="show-price" data-part="price" checked><label class="custom-control-label" for="show-price">Price</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input label-part" id="show-code" data-part="code" checked><label class="custom-control-label" for="show-code">Barcode Number</label></div>
        </div>

        <hr>

        <div class="row align-items-end">
            <div class="col-md-6 form-group">
                <label for="barcode-product">Product</label>
                <select id="barcode-product" class="form-control">
                    <option value="">Search product name or code</option>
                    <?php foreach($label_items as $item){ ?>
                        <option value="<?= htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?= htmlspecialchars($item['name'] . ($item['variant'] !== '' ? ' - ' . $item['variant'] : '') . ($item['code'] !== '' ? ' [' . $item['code'] . ']' : '') . ' (Stock: ' . $item['stock'] . ')'); ?>
                        </option>
                    <?php } ?>
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
        <div id="barcode-preview"><p class="text-muted text-center mb-0">Add products to see the labels.</p></div>
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

    $("#barcode-product").select2({theme: "bootstrap4", width: "100%", placeholder: "Search product name or code", allowClear: true});

    function el(tag, cls, text){
        const node = document.createElement(tag);
        if(cls) node.className = cls;
        if(text !== undefined) node.textContent = text;
        return node;
    }

    function layout(){
        if($("#print-type").val() === "a4"){
            const parts = $("#a4-layout").val().split("x").map(Number);
            const cols = parts[0], rowsPerSheet = parts[1];
            // Common A4 sticker sheets keep about 10 mm top/bottom and 5 mm side margins.
            const padX = 5, padY = 10;
            return {type: "a4", cols: cols, rows: rowsPerSheet, padX: padX, padY: padY,
                w: (210 - padX * 2) / cols, h: (297 - padY * 2) / rowsPerSheet};
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

    function shown(part){
        return document.querySelector(".label-part[data-part=\"" + part + "\"]").checked;
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
        bars.appendChild(barcodeSvg(item.barcode));
        label.appendChild(bars);
        if(shown("code")) label.appendChild(el("div", "bl-code", item.barcode));
        if(shown("price") && item.price !== "") label.appendChild(el("div", "bl-price", currency + " " + Number(item.price).toFixed(2)));
        return label;
    }

    const svgCache = {};
    function barcodeSvg(value){
        if(!svgCache[value]){
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            JsBarcode(svg, value, {format: "CODE128", width: 2, height: 60, margin: 0, displayValue: false});
            // Stretch to the label: bar widths keep their ratio, so scanners still read it.
            svg.setAttribute("viewBox", "0 0 " + svg.getAttribute("width") + " " + svg.getAttribute("height"));
            svg.setAttribute("preserveAspectRatio", "none");
            svg.removeAttribute("width");
            svg.removeAttribute("height");
            svg.removeAttribute("style");
            svgCache[value] = svg;
        }
        return svgCache[value].cloneNode(true);
    }

    // The printable sheets: one page per thermal label, or A4 pages of labels.
    function buildSheets(){
        const size = layout();
        const labels = [];
        rows.forEach(function(row){
            for(let i = 0; i < row.qty; i++) labels.push(row);
        });
        const sheets = [];
        if(size.type === "thermal"){
            labels.forEach(function(row){
                const sheet = el("div", "barcode-sheet");
                sheet.style.width = size.w + "mm";
                sheet.style.height = size.h + "mm";
                sheet.appendChild(makeLabel(row.item, size));
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
            sheet.style.padding = size.padY + "mm " + size.padX + "mm";
            sheet.style.gridTemplateColumns = "repeat(" + size.cols + ", " + size.w + "mm)";
            sheet.style.gridTemplateRows = "repeat(" + size.rows + ", " + size.h + "mm)";
            slots.slice(start, start + perSheet).forEach(function(row){
                sheet.appendChild(row ? makeLabel(row.item, size) : el("div", "barcode-label"));
            });
            sheets.push(sheet);
        }
        return {sheets: sheets, size: size, count: labels.length};
    }

    function render(){
        const total = rows.reduce(function(sum, row){ return sum + row.qty; }, 0);
        preview.innerHTML = "";
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
        const built = buildSheets();
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
        items.forEach(function(item){ if(item.stock > 0){ addItem(item.key, item.stock); added++; } });
        if(!added) alert("No product is in stock.");
        renderTable(); render();
    });

    $("#barcode-clear").on("click", function(){ rows.length = 0; renderTable(); render(); });

    $("#print-type").on("change", function(){
        const a4 = this.value === "a4";
        $("#thermal-size-group").toggleClass("d-none", a4);
        $("#thermal-custom-group").toggleClass("d-none", a4 || $("#thermal-size").val() !== "custom");
        $("#a4-layout-group, #a4-start-group").toggleClass("d-none", !a4);
        render();
    });
    $("#thermal-size").on("change", function(){
        $("#thermal-custom-group").toggleClass("d-none", this.value !== "custom");
        render();
    });
    $("#thermal-width, #thermal-height, #a4-layout, #a4-start").on("input change", render);
    $(".label-part").on("change", render);

    printButton.addEventListener("click", function(){
        const built = buildSheets();
        const size = built.size;
        const page = size.type === "a4" ? "210mm 297mm" : size.w + "mm " + size.h + "mm";
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
