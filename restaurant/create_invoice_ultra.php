<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/product_category_helper.php';
require_once '../includes/product_image_helper.php';
require_once '../includes/wallet_helper.php';
require_once '../includes/restaurant_table_helper.php';
require_once '../includes/staff_helper.php';
require_once '../includes/invoice_charge_helper.php';

$user_id = (int)$_SESSION['user_id'];
if(!restaurant_catalog_enabled($conn, $user_id)){
    header('Location: ../sales/create_invoice.php');
    exit;
}
ensure_staff_table($conn);
ensure_restaurant_tables_table($conn);
ensure_invoice_charge_columns($conn);
$invoice_reference_type = restaurant_invoice_reference_type($conn, $user_id);
$invoice_reference_enabled = restaurant_invoice_reference_enabled($conn, $user_id);
$reference_staff = mysqli_query($conn, "SELECT id, staff_code, name FROM staff WHERE user_id={$user_id} AND status='active' ORDER BY name");
$reference_tables = mysqli_query($conn, "SELECT id, staff_id, table_name FROM restaurant_tables WHERE user_id={$user_id} AND status='active' ORDER BY table_name");

$products_stmt = mysqli_prepare($conn, "SELECT p.id,p.product_name,p.sku,p.sale_price,p.photo_path,c.category_name,c.category_type
    FROM products p INNER JOIN product_categories c ON c.id=p.category_id AND c.user_id=p.user_id
    WHERE p.user_id=? AND p.status='active' AND c.status='active' ORDER BY c.category_name,p.product_name");
mysqli_stmt_bind_param($products_stmt, 'i', $user_id);
mysqli_stmt_execute($products_stmt);
$products = mysqli_stmt_get_result($products_stmt);
$wallets = active_wallets_result($conn, $user_id);
$wallet_rows = $wallets ? mysqli_fetch_all($wallets, MYSQLI_ASSOC) : [];
$default_wallet_id = 0;
foreach($wallet_rows as $wallet_row){
    if(strtolower(trim((string)$wallet_row['wallet_name'])) === 'cash'){
        $default_wallet_id = (int)$wallet_row['id'];
        break;
    }
}
if($default_wallet_id === 0 && !empty($wallet_rows)){
    $default_wallet_id = (int)$wallet_rows[0]['id'];
}

$charges_stmt = mysqli_prepare($conn, "SELECT id, charge_name, charge_type, charge_value_type, default_value FROM invoice_charge_types WHERE user_id=? AND status='active' AND show_on_invoice=1 ORDER BY charge_name");
mysqli_stmt_bind_param($charges_stmt, 'i', $user_id);
mysqli_stmt_execute($charges_stmt);
$invoice_charges = mysqli_fetch_all(mysqli_stmt_get_result($charges_stmt), MYSQLI_ASSOC);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>

<div class="ultra-pos">

    <form method="post" action="../sales/save_invoice.php" id="ultra-pos-form" target="_blank">
        <input type="hidden" name="stock_csrf" value="<?= htmlspecialchars(stock_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="print">
        <input type="hidden" name="auto_print" value="1">
        <input type="hidden" name="customer_name" value="">
        <input type="hidden" name="customer_phone" value="">
        <input type="hidden" name="customer_address" value="">
        <input type="hidden" name="notes" value="Ultra POS sale">
        <input type="hidden" name="grand_total" id="grand_total" value="0">
        <input type="hidden" name="paid_amount" id="paid_amount" value="0">
        <input type="hidden" name="due_amount" id="due_amount" value="0">
        <input type="hidden" name="payment_status" value="paid">

        <div class="row">
            <div class="col-lg-8 mb-3">
                <div class="card h-100 mb-0">
                    <div class="card-body">
                        <input id="ultra-search" type="search" class="form-control form-control-lg mb-3" placeholder="Search product or code">
                        <div id="ultra-categories" class="ultra-categories mb-3">
                            <button type="button" class="btn btn-success active" data-category="">All Category</button>
                            <?php $category_names=[]; $product_rows=[]; while($product=mysqli_fetch_assoc($products)){ $product_rows[]=$product; $category_names[$product['category_name']]=true; } foreach(array_keys($category_names) as $category_name){ ?>
                                <button type="button" class="btn btn-light" data-category="<?= htmlspecialchars($category_name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category_name) ?></button>
                            <?php } ?>
                        </div>
                        <div class="ultra-product-grid" id="ultra-product-grid">
                            <?php foreach($product_rows as $product){ ?>
                                <button type="button" class="ultra-product-card" data-id="<?= (int)$product['id'] ?>" data-name="<?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?>" data-code="<?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>" data-price="<?= number_format((float)$product['sale_price'], 2, '.', '') ?>" data-category="<?= htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <img src="<?= htmlspecialchars(product_image_url($conn, $product['photo_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <span class="ultra-product-name"><?= htmlspecialchars($product['product_name']) ?></span>
                                    <span class="ultra-product-code"><?= htmlspecialchars($product['sku'] ?: 'No code') ?></span>
                                    <strong>BDT <?= number_format((float)$product['sale_price'], 2) ?></strong>
                                </button>
                            <?php } ?>
                        </div>
                        <?php if(!$product_rows){ ?><p class="text-muted mb-0">No active products found.</p><?php } ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 mb-3">
                <div class="card ultra-cart-card mb-0">
                    <div class="card-header"><h3 class="card-title">Current Invoice</h3></div>
                    <div class="card-body p-0">
                        <div id="ultra-cart" class="ultra-cart"><p class="text-muted text-center mt-4" id="ultra-empty">Select products from the left.</p></div>
                        <div class="p-3 border-top">
                            <?php if($invoice_charges){ ?>
                                <div class="ultra-invoice-charges mb-3">
                                    <?php foreach($invoice_charges as $charge){ ?>
                                        <div class="form-group mb-2">
                                            <label for="ultra-charge-<?= (int)$charge['id'] ?>">
                                                <?= htmlspecialchars($charge['charge_name']) ?>
                                                <small class="text-muted">(<?= $charge['charge_type'] === 'less' ? 'Less' : 'Add' ?><?= ($charge['charge_value_type'] ?? 'fixed') === 'percent' ? ', %' : '' ?>)</small>
                                            </label>
                                            <input type="hidden" name="charge_id[]" value="<?= (int)$charge['id'] ?>">
                                            <input
                                                type="number"
                                                id="ultra-charge-<?= (int)$charge['id'] ?>"
                                                name="charge_amount[]"
                                                class="form-control ultra-charge"
                                                min="0"
                                                step="0.01"
                                                data-charge-type="<?= htmlspecialchars($charge['charge_type'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-value-type="<?= htmlspecialchars($charge['charge_value_type'] ?? 'fixed', ENT_QUOTES, 'UTF-8') ?>"
                                                value="<?= number_format((float)($charge['default_value'] ?? 0), 2, '.', '') ?>"
                                                placeholder="<?= ($charge['charge_value_type'] ?? 'fixed') === 'percent' ? 'Percentage' : 'Amount' ?>">
                                        </div>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                            <div class="form-group mb-3">
                                <label for="receive_wallet_id">Payment Wallet</label>
                                <select name="receive_wallet_id" id="receive_wallet_id" class="form-control" required>
                                    <option value="">Select wallet</option>
                                    <?php foreach($wallet_rows as $wallet){ ?><option value="<?= (int)$wallet['id'] ?>" <?= (int)$wallet['id'] === $default_wallet_id ? 'selected' : '' ?>><?= htmlspecialchars($wallet['wallet_name']) ?></option><?php } ?>
                                </select>
                            </div>
                            <div class="ultra-total"><span>Grand Total</span><strong id="ultra-total-value">BDT 0.00</strong></div>
                            <?php if($invoice_reference_enabled){ ?><div class="form-group mt-3 mb-0">
                                <label for="ultra_reference_select">Ref.</label>
                                <?php if($invoice_reference_type === 'table'){ ?>
                                    <input type="hidden" name="staff_id" id="ultra_reference_staff_id" value="">
                                    <select name="restaurant_table_id" id="ultra_reference_select" class="form-control"><option value="">Search ref.</option><?php while($table=mysqli_fetch_assoc($reference_tables)){ ?><option value="<?= (int)$table['id'] ?>" data-staff-id="<?= (int)$table['staff_id'] ?>" <?= (int)$table['staff_id'] <= 0 ? 'disabled' : '' ?>><?=htmlspecialchars($table['table_name'])?><?= (int)$table['staff_id'] <= 0 ? ' (Unassigned)' : '' ?></option><?php } ?></select>
                                <?php }else{ ?>
                                    <select name="staff_id" id="ultra_reference_select" class="form-control"><option value="">Search ref.</option><?php while($staff=mysqli_fetch_assoc($reference_staff)){ ?><option value="<?= (int)$staff['id'] ?>"><?=htmlspecialchars($staff['name'])?><?= $staff['staff_code'] ? ' (' . htmlspecialchars($staff['staff_code']) . ')' : '' ?></option><?php } ?></select>
                                <?php } ?>
                            </div><?php } ?>
                            <div class="row mt-3">
                                <div class="col-8 pr-1"><button type="submit" id="ultra-save-print" class="btn btn-primary btn-lg btn-block" <?= $invoice_reference_enabled ? 'disabled' : '' ?>><i class="fas fa-print mr-1"></i>Save & Print Invoice</button></div>
                                <div class="col-4 pl-1"><button type="button" id="ultra-reset" class="btn btn-secondary btn-lg btn-block text-nowrap"><i class="fas fa-redo mr-1"></i>Reset</button></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
 .ultra-categories{display:flex;gap:8px;overflow-x:auto;padding-bottom:3px}.ultra-categories .btn{white-space:nowrap}.ultra-product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(142px,1fr));gap:12px;max-height:68vh;overflow-y:auto;padding-right:4px}.ultra-product-card{border:1px solid #dfe5eb;border-radius:8px;background:#fff;padding:8px;text-align:left;min-height:205px;display:flex;flex-direction:column;gap:5px;transition:.15s}.ultra-product-card:hover,.ultra-product-card:focus{border-color:#007bff;box-shadow:0 3px 11px rgba(0,123,255,.16);outline:0}.ultra-product-card img{width:100%;height:103px;object-fit:cover;border-radius:5px;background:#f0f2f5}.ultra-product-name{font-weight:600;line-height:1.18}.ultra-product-code{font-size:12px;color:#6c757d}.ultra-product-card strong{margin-top:auto;color:#05733d}.ultra-cart-card{position:sticky;top:72px}.ultra-cart{max-height:48vh;overflow-y:auto}.ultra-cart-line{display:grid;grid-template-columns:1fr auto;gap:8px;padding:12px 16px;border-bottom:1px solid #edf0f2}.ultra-cart-line-name{font-weight:600}.ultra-cart-line-meta{font-size:12px;color:#6c757d}.ultra-qty{display:flex;align-items:center;justify-content:flex-end;gap:6px}.ultra-qty button{width:30px;height:30px;border:0;border-radius:50%;background:#e9f6ef;color:#16753d;font-size:18px}.ultra-qty input{width:42px;border:1px solid #ced4da;border-radius:4px;text-align:center;padding:4px}.ultra-remove{border:0;background:transparent;color:#dc3545;font-size:16px}.ultra-invoice-charges{border-bottom:1px solid #e5e9ed;padding-bottom:8px}.ultra-invoice-charges label{font-weight:600;margin-bottom:4px}.ultra-total{display:flex;align-items:center;justify-content:space-between;font-size:19px}.ultra-total strong{font-size:24px;color:#08793e}@media(max-width:991px){.ultra-cart-card{position:static}.ultra-product-grid{max-height:none}}
</style>

<script>
(function(){
 document.body.classList.remove('ultra-pos-active');
 document.body.classList.add('sidebar-collapse');
 const salesTree=Array.from(document.querySelectorAll('.main-sidebar .nav-item.has-treeview')).find(function(item){
   const label=item.querySelector(':scope > .nav-link > p');
   return label && label.textContent.trim().indexOf('Sales') === 0;
 });
 const updateSalesMenu=function(){
   if(!salesTree)return;
   if(document.body.classList.contains('sidebar-collapse')){
     salesTree.classList.remove('menu-open');
     return;
   }
   salesTree.classList.add('menu-open');
   const link=salesTree.querySelector(':scope > .nav-link');
   if(link)link.classList.add('active');
 };
 updateSalesMenu();
 const menuButton=document.querySelector('[data-widget="pushmenu"]');
 if(menuButton)menuButton.addEventListener('click',function(){window.setTimeout(updateSalesMenu,250);});
})();
(function(){
 const cart=new Map(), grid=document.getElementById('ultra-product-grid'), cartBox=document.getElementById('ultra-cart'), empty=document.getElementById('ultra-empty'), search=document.getElementById('ultra-search'), form=document.getElementById('ultra-pos-form');
 const totalEl=document.getElementById('ultra-total-value'), grand=document.getElementById('grand_total'), paid=document.getElementById('paid_amount'), due=document.getElementById('due_amount');
 let category='';
 const money=value=>'BDT '+Number(value).toFixed(2);
 function render(){let subtotal=0;cartBox.innerHTML='';cart.forEach(item=>{const line=item.price*item.qty;subtotal+=line;const row=document.createElement('div');row.className='ultra-cart-line';row.innerHTML='<div><div class="ultra-cart-line-name"></div><div class="ultra-cart-line-meta"></div></div><div class="text-right"><button type="button" class="ultra-remove" title="Remove">&times;</button><div class="ultra-qty"><button type="button" data-change="-1">−</button><input type="number" min="1" value="'+item.qty+'"><button type="button" data-change="1">+</button></div><strong>'+money(line)+'</strong></div>';row.querySelector('.ultra-cart-line-name').textContent=item.name;row.querySelector('.ultra-cart-line-meta').textContent=(item.code||'No code')+' · '+money(item.price);row.querySelector('.ultra-remove').onclick=()=>{cart.delete(item.id);render()};row.querySelectorAll('[data-change]').forEach(button=>button.onclick=()=>{item.qty=Math.max(1,item.qty+Number(button.dataset.change));render()});row.querySelector('input').onchange=e=>{item.qty=Math.max(1,parseInt(e.target.value,10)||1);render()};cartBox.append(row);});if(!cart.size){cartBox.append(empty.cloneNode(true));}let total=subtotal;document.querySelectorAll('.ultra-charge').forEach(input=>{const inputValue=Math.max(0,Number(input.value)||0);const chargeAmount=input.dataset.valueType==='percent'?(subtotal*inputValue/100):inputValue;total+=input.dataset.chargeType==='less'?-chargeAmount:chargeAmount;});total=Math.max(0,total);totalEl.textContent=money(total);grand.value=total.toFixed(2);paid.value=total.toFixed(2);due.value='0';}
 grid.addEventListener('click',event=>{const card=event.target.closest('.ultra-product-card');if(!card)return;const id=card.dataset.id,item=cart.get(id)||{id,name:card.dataset.name,code:card.dataset.code,price:Number(card.dataset.price),qty:0};item.qty++;cart.set(id,item);render()});
 document.querySelectorAll('.ultra-charge').forEach(input=>input.addEventListener('input',render));
 function filter(){const term=search.value.trim().toLowerCase();grid.querySelectorAll('.ultra-product-card').forEach(card=>{const matchesCategory=!category||card.dataset.category===category;const text=(card.dataset.name+' '+card.dataset.code).toLowerCase();card.hidden=!matchesCategory||!text.includes(term)})} search.addEventListener('input',filter);document.getElementById('ultra-categories').addEventListener('click',event=>{const button=event.target.closest('button');if(!button)return;category=button.dataset.category;document.querySelectorAll('#ultra-categories button').forEach(item=>item.classList.toggle('active',item===button));filter()});
 const resetInvoice=()=>{cart.clear();search.value='';category='';document.querySelectorAll('#ultra-categories button').forEach((item,index)=>item.classList.toggle('active',index===0));document.getElementById('receive_wallet_id').value='<?= (int)$default_wallet_id ?>';document.querySelectorAll('.ultra-charge').forEach(input=>input.value=input.defaultValue);const reference=document.getElementById('ultra_reference_select');if(reference){reference.value='';if(window.jQuery&&jQuery.fn.select2){jQuery(reference).val(null).trigger('change');}else{reference.dispatchEvent(new Event('change'));}}form.querySelectorAll('.ultra-item-input').forEach(input=>input.remove());filter();render();search.focus()};
 document.getElementById('ultra-reset').addEventListener('click',resetInvoice);
 document.getElementById('ultra-save-print').addEventListener('click',function(){window.setTimeout(resetInvoice,300)});
 form.addEventListener('submit',event=>{if(!cart.size){event.preventDefault();alert('Select at least one product.');return} if(!document.getElementById('receive_wallet_id').value){event.preventDefault();alert('Select a payment wallet.');return} form.querySelectorAll('.ultra-item-input').forEach(input=>input.remove());cart.forEach(item=>{[['product_id',item.id],['qty',item.qty],['price',item.price],['variant_name','']].forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.className='ultra-item-input';input.name=name+'[]';input.value=value;form.append(input)})});window.setTimeout(resetInvoice,150)});render();
})();
(function(){
 const reference=document.getElementById('ultra_reference_select'),staff=document.getElementById('ultra_reference_staff_id'),save=document.getElementById('ultra-save-print');
 if(!reference) return;
 const syncReference=function(){const selected=reference.options[reference.selectedIndex];if(staff)staff.value=selected&&selected.dataset.staffId?selected.dataset.staffId:'';if(save)save.disabled=!reference.value;};
 reference.addEventListener('change',syncReference);syncReference();
})();
document.addEventListener('DOMContentLoaded',function(){
 if(window.jQuery && jQuery.fn.select2){
  jQuery('#ultra_reference_select').select2({theme:'bootstrap4',width:'100%',placeholder:'Search ref.',allowClear:true}).on('change',function(){
   const button=document.getElementById('ultra-save-print');
   if(button) button.disabled=!this.value;
  });
 }
});
</script>

<?php require_once '../includes/footer.php'; ?>
