<?php

require_once __DIR__ . '/product_category_helper.php';

// One fixed barcode per product, and per variant when the product has variants.
function ensure_product_barcode_table($conn)
{
    static $done = false;

    if($done){
        return;
    }

    $done = true;
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS product_barcodes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            variant_name VARCHAR(100) NOT NULL DEFAULT '',
            barcode VARCHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_product_barcode_value (user_id, barcode),
            UNIQUE KEY uniq_product_barcode_item (user_id, product_id, variant_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// Code128 prints plain ASCII only; anything else is left out of the barcode value.
function product_barcode_clean($value)
{
    return strtoupper(preg_replace('/[^A-Za-z0-9\-\.]/', '', (string)$value));
}

// The product Code when it can be printed, otherwise "P" + product id.
function product_barcode_base($product)
{
    $code = product_barcode_clean($product['sku'] ?? '');

    return $code !== '' ? substr($code, 0, 40) : 'P' . str_pad((string)(int)$product['id'], 6, '0', STR_PAD_LEFT);
}

// [product_id => [variant_name => barcode]] for the given products, creating missing barcodes.
// $items: [['product' => product row, 'variant' => variant name], ...]
function product_barcodes_for_items($conn, $user_id, $items)
{
    ensure_product_barcode_table($conn);
    $user_id = (int)$user_id;
    $map = [];
    $used = [];
    $result = mysqli_query($conn, "SELECT product_id, variant_name, barcode FROM product_barcodes WHERE user_id={$user_id}");

    while($row = mysqli_fetch_assoc($result)){
        $map[(int)$row['product_id']][(string)$row['variant_name']] = $row['barcode'];
        $used[strtoupper($row['barcode'])] = true;
    }

    $insert = mysqli_prepare($conn, 'INSERT INTO product_barcodes (user_id, product_id, variant_name, barcode) VALUES (?,?,?,?)');

    foreach($items as $item){
        $product_id = (int)$item['product']['id'];
        $variant = (string)$item['variant'];

        if(isset($map[$product_id][$variant])){
            continue;
        }

        $value = product_barcode_base($item['product']);

        if($variant !== ''){
            $suffix = product_barcode_clean($variant);
            $value .= '-' . ($suffix !== '' ? substr($suffix, 0, 20) : 'V' . ((int)($item['position'] ?? 0) + 1));
        }

        $candidate = $value;

        for($n = 2; isset($used[strtoupper($candidate)]); $n++){
            $candidate = $value . '-' . $n;
        }

        mysqli_stmt_bind_param($insert, 'iiss', $user_id, $product_id, $variant, $candidate);

        if(mysqli_stmt_execute($insert)){
            $map[$product_id][$variant] = $candidate;
            $used[strtoupper($candidate)] = true;
        }
    }

    return $map;
}

// Scanner lookup for the sales screen: [CODE => [product_id, variant]] where variant is the variant
// name, '' for a product without variants, or null when the code names a product whose variant
// still has to be picked. It follows the same rules as the labels, so printed labels and product
// Codes (e.g. a maker's barcode saved as the Code) both scan.
// $products: rows with id and sku; $variant_map: [product_id => [variant names in label order]].
function product_barcode_lookup_map($conn, $user_id, $products, $variant_map)
{
    $map = [];
    $add = function($code, $product_id, $variant) use (&$map){
        $code = strtoupper(trim((string)$code));
        if($code !== '' && !isset($map[$code])){
            $map[$code] = [(int)$product_id, $variant];
        }
    };

    ensure_product_barcode_table($conn);
    $user_id = (int)$user_id;
    $result = mysqli_query($conn, "SELECT product_id, variant_name, barcode FROM product_barcodes WHERE user_id={$user_id}");

    while($row = mysqli_fetch_assoc($result)){
        $add($row['barcode'], $row['product_id'], (string)$row['variant_name']);
    }

    foreach($products as $product){
        $product_id = (int)$product['id'];
        $variants = array_values($variant_map[$product_id] ?? []);
        $whole = $variants ? null : '';
        $base = product_barcode_base($product);
        $add($base, $product_id, $whole);
        $add($product['sku'] ?? '', $product_id, $whole);
        $add('P' . str_pad((string)$product_id, 6, '0', STR_PAD_LEFT), $product_id, $whole);

        foreach($variants as $position => $variant){
            if((string)$variant === ''){
                continue;
            }
            $suffix = product_barcode_clean($variant);
            $add($base . '-' . ($suffix !== '' ? substr($suffix, 0, 20) : 'V' . ($position + 1)), $product_id, (string)$variant);
        }
    }

    return $map;
}

// The scan box shown above the product lines of the sales screen.
function barcode_scan_input_html()
{
    return '<div class="d-flex flex-wrap align-items-center mb-2">
        <div class="input-group" style="max-width:420px">
            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-barcode"></i></span></div>
            <input type="text" id="barcode-scan" class="form-control" placeholder="Scan barcode or type code and press Enter" autocomplete="off">
        </div>
        <small id="barcode-scan-message" class="ml-2"></small>
    </div>';
}

// Adds the scanned product to #productTable: a repeat scan of the same item raises its Qty.
// Works with the product lines of both apps (.product select, .qty, .variant-choice-input, #addRow).
function barcode_scan_script($map)
{
    return '<script>
(function(){
    const barcodeMap = ' . json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT) . ';
    const input = document.getElementById("barcode-scan");
    const message = $("#barcode-scan-message");
    if(!input) return;

    // Each part is its own span, so the fixed words can be translated (Bangla) apart from names and codes.
    function say(ok, parts){
        message.empty().toggleClass("text-success", ok).toggleClass("text-danger", !ok);
        parts.forEach(function(part, i){ message.append(i ? " " : "").append($("<span>").text(part)); });
    }

    function chosenVariant(row){
        const checked = row.find(".variant-choice-input:checked");
        if(!checked.length) return null;
        return checked.val() === "__none__" ? "" : checked.val();
    }

    // Ticks the variant once the line has its variant boxes (they may arrive by AJAX).
    function pickVariant(row, variant, tries){
        const box = row.find(".variant-choice-input").filter(function(){
            return this.value === variant || (variant === "" && this.value === "__none__");
        });
        if(box.length){
            if(!box.prop("checked")) box.prop("checked", true).trigger("change");
            return;
        }
        if(tries > 0) setTimeout(function(){ pickVariant(row, variant, tries - 1); }, 100);
    }

    function addScanned(code){
        code = String(code || "").trim().toUpperCase();
        if(!code) return;
        const hit = barcodeMap[code];
        const productId = hit ? String(hit[0]) : "";
        const variant = hit ? hit[1] : null;
        const option = productId ? $("#productTable tbody tr:first .product option[value=\"" + productId + "\"]") : $();
        if(!hit || !option.length){
            say(false, ["No product found for barcode", code]);
            return;
        }
        const name = $.trim(option.first().text()) + (variant ? " - " + variant : "");
        const rows = $("#productTable tbody tr");

        // Same product (and same variant) already on the invoice: one more of it.
        const same = rows.filter(function(){
            const row = $(this);
            if(row.find(".product").val() !== productId) return false;
            if(variant === null) return false;
            if(row.data("has-variants") === true) return variant !== "" && chosenVariant(row) === variant;
            return variant === "";
        }).first();
        if(same.length){
            const qty = same.find(".qty");
            qty.val((parseFloat(qty.val()) || 0) + 1).trigger("change");
            say(true, [name, "Qty", qty.val()]);
            return;
        }

        let row = rows.filter(function(){ return !$(this).find(".product").val(); }).first();
        if(!row.length){
            $("#addRow").trigger("click");
            row = $("#productTable tbody tr:last");
        }
        row.find(".qty").val(1);
        row.find(".product").val(productId).trigger("change");
        if(variant !== null && variant !== "") pickVariant(row, variant, 100);
        say(true, variant === null ? ["Added:", name, "Select the variant."] : ["Added:", name]);
    }

    input.addEventListener("keydown", function(event){
        if(event.key !== "Enter") return;
        event.preventDefault();
        addScanned(input.value);
        input.value = "";
    });

    // A scanner types very fast and ends with Enter. When nothing else has the focus, a scan still lands here.
    let buffer = "", last = 0;
    document.addEventListener("keydown", function(event){
        const target = event.target;
        if(target === input || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName) || target.isContentEditable) return;
        const now = Date.now();
        if(now - last > 60) buffer = "";
        last = now;
        if(event.key === "Enter"){
            if(buffer.length >= 3){ event.preventDefault(); addScanned(buffer); }
            buffer = "";
        }else if(event.key.length === 1){
            buffer += event.key;
        }
    });
})();
</script>';
}
