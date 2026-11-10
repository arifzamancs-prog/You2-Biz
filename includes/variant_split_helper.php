<?php
/*
|----------------------------------
| Variant split
|----------------------------------
| When variants are added to a category that had none, every product in it is put on
| the first variant (stock, pending sales and pending transfers follow). Each branch
| and the warehouse may then split that stock across the variants once per product;
| the split quantities always add up to the product's on-hand total.
*/

function variant_split_schema($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_variant_autoassign (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        category_id INT NOT NULL,
        first_variant VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY company_product (user_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_variant_splits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        branch_id BIGINT NOT NULL,
        quantities TEXT NOT NULL,
        actor_id INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY company_product_branch (user_id, product_id, branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function variant_split_query($conn, $sql, $types = '', $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if(!$stmt){
        throw new RuntimeException('Variant split query failed.');
    }
    if($types !== ''){
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if(!mysqli_stmt_execute($stmt)){
        throw new RuntimeException('Variant split query failed.');
    }
    return $stmt;
}

function variant_split_rows($conn, $sql, $types = '', $params = [])
{
    $result = mysqli_stmt_get_result(variant_split_query($conn, $sql, $types, $params));
    return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}

// Puts every product of the category that has no variants yet on the first variant.
// Call inside the transaction that saves the category. Returns the number of products moved.
function variant_split_autoassign_category($conn, $company_id, $category_id, array $variants)
{
    $company_id = (int)$company_id;
    $category_id = (int)$category_id;
    $variants = array_values(array_filter(array_map('trim', array_map('strval', $variants)), fn($name) => $name !== ''));
    if(!$variants){
        return 0;
    }
    $first = $variants[0];
    $products = variant_split_rows(
        $conn,
        'SELECT p.id FROM products p WHERE p.user_id=? AND p.category_id=?
         AND NOT EXISTS (SELECT 1 FROM product_variants pv WHERE pv.user_id=p.user_id AND pv.product_id=p.id)',
        'ii',
        [$company_id, $category_id]
    );
    foreach($products as $product){
        $product_id = (int)$product['id'];
        foreach($variants as $variant){
            variant_split_query($conn, 'INSERT INTO product_variants(user_id,product_id,variant_name) VALUES(?,?,?)', 'iis', [$company_id, $product_id, $variant]);
        }
        // Existing stock, unconfirmed sales and transfers on the way all become the first variant.
        variant_split_query($conn, "UPDATE stock_batches SET variant_name=? WHERE user_id=? AND product_id=? AND variant_name=''", 'sii', [$first, $company_id, $product_id]);
        variant_split_query(
            $conn,
            "UPDATE invoice_items ii JOIN invoices i ON i.id=ii.invoice_id SET ii.variant_name=?
             WHERE i.user_id=? AND i.accounting_status='pending' AND ii.product_id=? AND COALESCE(ii.variant_name,'')=''",
            'sii',
            [$first, $company_id, $product_id]
        );
        variant_split_query($conn, "UPDATE stock_distributions SET variant_name=? WHERE user_id=? AND product_id=? AND status='pending' AND COALESCE(variant_name,'')=''", 'sii', [$first, $company_id, $product_id]);
        variant_split_query(
            $conn,
            'INSERT INTO product_variant_autoassign(user_id,product_id,category_id,first_variant) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), first_variant=VALUES(first_variant)',
            'iiis',
            [$company_id, $product_id, $category_id, $first]
        );
    }
    return count($products);
}

// The split this branch can still do for a product, or null when it is not offered.
function variant_split_candidate($conn, $company_id, $product_id, $branch_id)
{
    $company_id = (int)$company_id;
    $product_id = (int)$product_id;
    $branch_id = (int)$branch_id;
    $auto = variant_split_rows($conn, 'SELECT first_variant FROM product_variant_autoassign WHERE user_id=? AND product_id=?', 'ii', [$company_id, $product_id]);
    if(!$auto || $branch_id <= 0){
        return null;
    }
    if(variant_split_rows($conn, 'SELECT id FROM product_variant_splits WHERE user_id=? AND product_id=? AND branch_id=?', 'iii', [$company_id, $product_id, $branch_id])){
        return null;
    }
    $variants = array_column(variant_split_rows($conn, 'SELECT variant_name FROM product_variants WHERE user_id=? AND product_id=? ORDER BY id', 'ii', [$company_id, $product_id]), 'variant_name');
    if(count($variants) < 2){
        return null;
    }
    $first = (string)$auto[0]['first_variant'];
    if(!in_array($first, $variants, true)){
        return null;
    }
    $stock = array_fill_keys($variants, 0);
    foreach(variant_split_rows($conn, 'SELECT variant_name, COALESCE(SUM(remaining_quantity),0) AS qty FROM stock_batches WHERE user_id=? AND product_id=? AND branch_id=? AND remaining_quantity>0 GROUP BY variant_name', 'iii', [$company_id, $product_id, $branch_id]) as $row){
        if(array_key_exists((string)$row['variant_name'], $stock)){
            $stock[(string)$row['variant_name']] = (int)floor((float)$row['qty']);
        }
    }
    if($stock[$first] <= 0){
        return null;
    }
    return ['product_id' => $product_id, 'branch_id' => $branch_id, 'first' => $first, 'variants' => $variants, 'stock' => $stock, 'total' => array_sum($stock)];
}

// Splits a branch's stock of the first variant across the variants. Call inside a transaction.
// $final: [variant => quantity after the split]; the quantities must add up to the on-hand total.
function variant_split_apply($conn, $company_id, $product_id, $branch_id, array $final, $actor_id)
{
    $candidate = variant_split_candidate($conn, $company_id, $product_id, $branch_id);
    if(!$candidate){
        throw new RuntimeException('This product cannot be split here (already split, or no stock on its first variant).');
    }
    $first = $candidate['first'];
    $quantities = [];
    foreach($candidate['variants'] as $variant){
        $qty = filter_var($final[$variant] ?? 0, FILTER_VALIDATE_INT);
        if($qty === false || $qty < 0){
            throw new RuntimeException('Enter whole, non-negative quantities.');
        }
        if($variant !== $first && $qty < $candidate['stock'][$variant]){
            throw new RuntimeException($variant . ' already has ' . $candidate['stock'][$variant] . '; it cannot go lower.');
        }
        $quantities[$variant] = $qty;
    }
    if(array_sum($quantities) !== (int)$candidate['total']){
        throw new RuntimeException('The variant quantities must add up to the on-hand total (' . (int)$candidate['total'] . ').');
    }
    $reserved = variant_split_rows(
        $conn,
        "SELECT COALESCE(SUM(ii.quantity),0) AS q FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id
         WHERE i.user_id=? AND i.branch_id=? AND i.accounting_status='pending' AND ii.product_id=? AND ii.quantity>0",
        'iii',
        [(int)$company_id, (int)$branch_id, (int)$product_id]
    );
    if((float)($reserved[0]['q'] ?? 0) > 0){
        throw new RuntimeException('Pending sales invoices hold this product. Confirm or cancel them before splitting.');
    }

    // Units move from the first variant's batches (oldest first) into new batches of the
    // other variants with the same cost, source and date, so stock value stays the same.
    $batches = variant_split_rows(
        $conn,
        'SELECT * FROM stock_batches WHERE user_id=? AND product_id=? AND branch_id=? AND variant_name=? AND remaining_quantity>0 ORDER BY batch_date, id FOR UPDATE',
        'iiis',
        [(int)$company_id, (int)$product_id, (int)$branch_id, $first]
    );
    foreach($quantities as $variant => $qty){
        if($variant === $first){
            continue;
        }
        $move = $qty - $candidate['stock'][$variant];
        foreach($batches as &$batch){
            if($move <= 0){
                break;
            }
            $take = min($move, (int)floor((float)$batch['remaining_quantity']));
            if($take <= 0){
                continue;
            }
            variant_split_query($conn, 'UPDATE stock_batches SET quantity=quantity-?, remaining_quantity=remaining_quantity-? WHERE id=?', 'ddi', [$take, $take, (int)$batch['id']]);
            variant_split_query(
                $conn,
                'INSERT INTO stock_batches(user_id,branch_id,product_id,variant_name,source_type,source_id,source_no,quantity,remaining_quantity,unit_cost,batch_date) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                'iiissisddds',
                [(int)$company_id, (int)$branch_id, (int)$product_id, $variant, (string)$batch['source_type'], (int)$batch['source_id'], (string)$batch['source_no'], $take, $take, (float)$batch['unit_cost'], (string)$batch['batch_date']]
            );
            $batch['remaining_quantity'] -= $take;
            $move -= $take;
        }
        unset($batch);
        if($move > 0){
            throw new RuntimeException('Stock changed while splitting. Refresh and try again.');
        }
    }

    variant_split_query(
        $conn,
        'INSERT INTO product_variant_splits(user_id,product_id,branch_id,quantities,actor_id) VALUES(?,?,?,?,?)',
        'iiisi',
        [(int)$company_id, (int)$product_id, (int)$branch_id, json_encode($quantities, JSON_UNESCAPED_UNICODE), (int)$actor_id]
    );
    return $quantities;
}

// The On Hand cell: a button that opens the split dialog when this branch may still split.
function variant_split_cell_html($candidate, $label, $product_name, $scope)
{
    if(!$candidate){
        return htmlspecialchars($label);
    }
    $data = ['product_id' => $candidate['product_id'], 'name' => (string)$product_name, 'variants' => $candidate['variants'], 'stock' => $candidate['stock'], 'total' => $candidate['total'], 'first' => $candidate['first'], 'scope' => $scope];
    return '<button type="button" class="btn btn-link p-0 variant-split-open" title="Split this stock by variant (once)" data-split="' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label) . '</button>'
        . '<small class="d-block text-primary">Split by variant</small>';
}

// The split dialog and its script, shared by My Stock and the warehouse page.
function variant_split_modal_html($endpoint)
{
    ob_start(); ?>
<div class="modal fade" id="variant-split-modal" tabindex="-1" role="dialog" aria-labelledby="variant-split-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="variant-split-form" method="post" action="<?= htmlspecialchars($endpoint) ?>">
            <div class="modal-header"><h5 class="modal-title" id="variant-split-title">Split by variant</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
            <div class="modal-body">
                <p class="mb-2"><strong id="variant-split-name"></strong></p>
                <p class="small text-muted">All of this product's stock is on <strong id="variant-split-first"></strong>. Share it across the variants. This can be done <strong>only once</strong> here, and the quantities must add up to the on-hand total.</p>
                <div id="variant-split-fields"></div>
                <div class="d-flex justify-content-between border-top pt-2 mt-2"><span>Total</span><strong><span id="variant-split-sum">0</span> / <span id="variant-split-total">0</span></strong></div>
                <div class="alert alert-danger mt-2 mb-0 d-none" id="variant-split-error"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="variant-split-save" disabled>Save Split</button></div>
        </form>
    </div>
</div>
<script>
(function(){
    var current = null;
    var form = document.getElementById('variant-split-form');
    var fields = document.getElementById('variant-split-fields');
    var error = document.getElementById('variant-split-error');
    var save = document.getElementById('variant-split-save');
    function sum(){
        var total = 0, ok = true;
        fields.querySelectorAll('input').forEach(function(input){
            var value = parseInt(input.value, 10);
            if(isNaN(value) || value < parseInt(input.min, 10)) ok = false;
            total += isNaN(value) ? 0 : value;
        });
        document.getElementById('variant-split-sum').textContent = total;
        save.disabled = !ok || total !== current.total;
    }
    document.addEventListener('click', function(event){
        var button = event.target.closest('.variant-split-open');
        if(!button || !window.jQuery) return;
        current = JSON.parse(button.dataset.split);
        document.getElementById('variant-split-name').textContent = current.name;
        document.getElementById('variant-split-first').textContent = current.first;
        document.getElementById('variant-split-total').textContent = current.total;
        error.classList.add('d-none');
        fields.innerHTML = '';
        current.variants.forEach(function(variant){
            var row = document.createElement('div');
            row.className = 'form-group row mb-2';
            var label = document.createElement('label');
            label.className = 'col-5 col-form-label';
            label.textContent = variant;
            var col = document.createElement('div');
            col.className = 'col-7';
            var input = document.createElement('input');
            input.type = 'number'; input.className = 'form-control'; input.step = '1';
            input.dataset.variant = variant;
            input.min = variant === current.first ? 0 : (current.stock[variant] || 0);
            input.value = current.stock[variant] || 0;
            input.addEventListener('input', sum);
            col.appendChild(input); row.appendChild(label); row.appendChild(col); fields.appendChild(row);
        });
        sum();
        jQuery('#variant-split-modal').modal('show');
    });
    form.addEventListener('submit', function(event){
        event.preventDefault();
        if(!current || save.disabled) return;
        if(!confirm('Save this split? It can be done only once for this product here.')) return;
        var data = new FormData();
        data.append('product_id', current.product_id);
        data.append('scope', current.scope);
        fields.querySelectorAll('input').forEach(function(input){ data.append('quantities[' + input.dataset.variant + ']', input.value); });
        var token = form.querySelector('input[name="stock_csrf"]');
        if(token) data.append('stock_csrf', token.value);
        save.disabled = true;
        fetch(<?= json_encode($endpoint) ?>, {method: 'POST', body: data, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}})
            .then(function(response){ return response.json(); })
            .then(function(result){
                if(result.ok){ window.location.reload(); return; }
                error.textContent = result.message || 'The split could not be saved.';
                error.classList.remove('d-none');
                save.disabled = false;
            })
            .catch(function(){ error.textContent = 'Network error. Please try again.'; error.classList.remove('d-none'); save.disabled = false; });
    });
})();
</script>
<?php
    return ob_get_clean();
}
