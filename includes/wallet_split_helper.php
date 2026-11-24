<?php

/*
|----------------------------------
| Multi-wallet payments
|----------------------------------
| When a company has more than one active wallet, the wallet drop-downs of
| invoices, purchases, due payments and supplier payments offer "Multi-wallet":
| the paid amount is split over several wallets.
|
| Each wallet gets its own transactions row, so wallet ledgers stay correct.
| Invoices and purchases also keep the split in wallet_splits (their wallet
| column is 0): an invoice saved as pending is credited only when it is posted,
| and edits / deletes must undo exactly what each wallet received or paid.
| Documents paid from one wallet keep working exactly as before.
|
| You2-Biz: the wallets offered are the ones of the selected branch (the same list
| as the wallet drop-down), and every wallet of a split must belong to the branch
| of the invoice / purchase, like a single wallet.
*/

require_once __DIR__ . '/wallet_helper.php';
require_once __DIR__ . '/transaction_helper.php';
require_once __DIR__ . '/stock_module_helper.php';
require_once __DIR__ . '/expense_helper.php';

const MULTI_WALLET_VALUE = 'multi';
const MULTI_WALLET_NAME = 'Multi-wallet';

function ensure_wallet_splits_table($conn)
{
    static $checked = false;

    if($checked){
        return;
    }

    $checked = true;

    // CREATE TABLE commits an open transaction, so it only runs when the table is missing.
    // Pages call this before they start their transaction.
    $existing = mysqli_query($conn, "SHOW TABLES LIKE 'wallet_splits'");

    if($existing && mysqli_num_rows($existing) > 0){
        return;
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS wallet_splits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            doc_type VARCHAR(30) NOT NULL,
            doc_id INT NOT NULL,
            wallet_id INT NOT NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_wallet_splits_doc (user_id, doc_type, doc_id),
            KEY idx_wallet_splits_wallet (wallet_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// "Multi-wallet" is the split option; no real wallet may use that name.
function is_multi_wallet_name($name)
{
    return strtolower(preg_replace('/[^a-z]/i', '', (string)$name)) === 'multiwallet';
}

function is_multi_wallet_value($value)
{
    return (string)$value === MULTI_WALLET_VALUE;
}

// Active wallets of the selected branch: [id => ['name' => ..., 'balance' => ...]].
// Same list (and branch scope) as the wallet drop-downs.
function multi_wallet_active_wallets($conn, $user_id)
{
    static $cache = [];
    $user_id = (int)$user_id;
    $key = $user_id . ':' . (int)($GLOBALS['stock_wallet_branch_id'] ?? selected_branch_id($conn, true));

    if(!isset($cache[$key])){
        $cache[$key] = [];
        $result = active_wallets_result($conn, $user_id);

        while($result && $row = mysqli_fetch_assoc($result)){
            $cache[$key][(int)$row['id']] = ['name' => $row['wallet_name'], 'balance' => (float)$row['balance']];
        }
    }

    return $cache[$key];
}

// Offered only when the branch has a wallet besides the system Cash wallet.
function multi_wallet_available($conn, $user_id)
{
    return count(multi_wallet_active_wallets($conn, $user_id)) >= 2;
}

// [wallet_id => amount] from the Multi-wallet panel, checked against the paid total.
// $paying_out: the wallets pay (purchase / supplier payment), so each one needs the balance.
// $branch_id: the branch of the invoice / purchase; every wallet must belong to it.
function multi_wallet_post_splits($conn, $user_id, $total, $paying_out = false, $branch_id = 0, $field = 'wallet_split')
{
    $total = round((float)$total, 2);
    $wallets = multi_wallet_active_wallets($conn, $user_id);
    $splits = [];

    foreach((array)($_POST[$field] ?? []) as $wallet_id => $amount){
        $wallet_id = (int)$wallet_id;
        $amount = round((float)$amount, 2);

        if($amount <= 0){
            continue;
        }

        if((int)$branch_id > 0){
            // Same rule as a single wallet: an active wallet of the transaction's branch.
            stock_require_wallet($conn, $wallet_id, (int)$user_id, (int)$branch_id);

            if(!isset($wallets[$wallet_id])){
                $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wallet_name, balance FROM wallets WHERE id=" . (int)$wallet_id . " AND user_id=" . (int)$user_id));
                $wallets[$wallet_id] = ['name' => $row['wallet_name'] ?? '', 'balance' => (float)($row['balance'] ?? 0)];
            }
        }elseif(!isset($wallets[$wallet_id])){
            throw new Exception('Multi-wallet: select only active wallets of this branch.');
        }

        if($paying_out && $amount > $wallets[$wallet_id]['balance'] + 0.009){
            throw new Exception('Multi-wallet: ' . $wallets[$wallet_id]['name'] . ' does not have enough balance.');
        }

        $splits[$wallet_id] = $amount;
    }

    if(!$splits){
        throw new Exception('Multi-wallet: enter the amount for at least one wallet.');
    }

    if(abs(array_sum($splits) - $total) > 0.009){
        throw new Exception('Multi-wallet: the wallet amounts must add up to ' . number_format($total, 2) . '.');
    }

    return $splits;
}

function wallet_splits_get($conn, $user_id, $doc_type, $doc_id)
{
    ensure_wallet_splits_table($conn);
    $stmt = mysqli_prepare($conn, "SELECT wallet_id, amount FROM wallet_splits WHERE user_id=? AND doc_type=? AND doc_id=? ORDER BY id");
    $user_id = (int)$user_id;
    $doc_id = (int)$doc_id;
    mysqli_stmt_bind_param($stmt, 'isi', $user_id, $doc_type, $doc_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $splits = [];

    while($row = mysqli_fetch_assoc($result)){
        $splits[(int)$row['wallet_id']] = round(($splits[(int)$row['wallet_id']] ?? 0) + (float)$row['amount'], 2);
    }

    return $splits;
}

function wallet_splits_save($conn, $user_id, $doc_type, $doc_id, $splits)
{
    wallet_splits_delete($conn, $user_id, $doc_type, $doc_id);
    $stmt = mysqli_prepare($conn, "INSERT INTO wallet_splits (user_id, doc_type, doc_id, wallet_id, amount) VALUES (?,?,?,?,?)");
    $user_id = (int)$user_id;
    $doc_id = (int)$doc_id;

    foreach($splits as $wallet_id => $amount){
        $wallet_id = (int)$wallet_id;
        $amount = (float)$amount;
        mysqli_stmt_bind_param($stmt, 'isiid', $user_id, $doc_type, $doc_id, $wallet_id, $amount);

        if(!mysqli_stmt_execute($stmt)){
            throw new Exception(mysqli_stmt_error($stmt));
        }
    }
}

function wallet_splits_delete($conn, $user_id, $doc_type, $doc_id)
{
    ensure_wallet_splits_table($conn);
    $stmt = mysqli_prepare($conn, "DELETE FROM wallet_splits WHERE user_id=? AND doc_type=? AND doc_id=?");
    $user_id = (int)$user_id;
    $doc_id = (int)$doc_id;
    mysqli_stmt_bind_param($stmt, 'isi', $user_id, $doc_type, $doc_id);
    mysqli_stmt_execute($stmt);
}

// What each wallet holds for one document: the split, or the single wallet with the whole amount.
function wallet_splits_or_single($splits, $wallet_id, $amount)
{
    if($splits){
        return $splits;
    }

    return ((int)$wallet_id > 0 && round((float)$amount, 2) > 0) ? [(int)$wallet_id => round((float)$amount, 2)] : [];
}

// Moves each wallet from $old to $new. $incoming: money comes in (sales), otherwise it goes out.
// Wallets that gain are updated first, so a wallet that only loses has the money to give back.
function wallet_splits_apply_change($conn, $user_id, $old, $new, $incoming)
{
    $changes = [];

    foreach(array_unique(array_merge(array_keys($old), array_keys($new))) as $wallet_id){
        $difference = round(($new[$wallet_id] ?? 0) - ($old[$wallet_id] ?? 0), 2);

        if(abs($difference) > 0.009){
            // Positive = the wallet balance goes up.
            $changes[$wallet_id] = $incoming ? $difference : -$difference;
        }
    }

    arsort($changes);

    foreach($changes as $wallet_id => $change){
        if($change > 0){
            credit_wallet($conn, (int)$wallet_id, $user_id, $change);
        }else{
            debit_wallet($conn, (int)$wallet_id, $user_id, abs($change));
        }
    }
}

// Name of the first wallet that cannot pay its part back, or '' when all of them can.
function wallet_splits_shortfall($conn, $user_id, $splits)
{
    $wallets = multi_wallet_active_wallets($conn, $user_id);

    foreach($splits as $wallet_id => $amount){
        $balance = isset($wallets[$wallet_id]) ? $wallets[$wallet_id]['balance'] : null;

        if($balance === null){
            $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wallet_name, balance FROM wallets WHERE id=" . (int)$wallet_id . " AND user_id=" . (int)$user_id));

            if(!$row || (float)$row['balance'] + 0.009 < (float)$amount){
                return $row['wallet_name'] ?? 'Wallet #' . (int)$wallet_id;
            }

            continue;
        }

        if($balance + 0.009 < (float)$amount){
            return $wallets[$wallet_id]['name'];
        }
    }

    return '';
}

// One transactions row per wallet of the split. Transaction numbers are unique per company,
// so the second wallet onwards gets "-2", "-3", ...
function wallet_splits_record_transactions($conn, $txn_no, $user_id, $transaction_type, $reference_id, $splits, $note, $txn_date, $branch_id = 0)
{
    $line = 0;

    foreach($splits as $wallet_id => $amount){
        $line++;
        record_wallet_transaction($conn, $line === 1 ? $txn_no : $txn_no . '-' . $line, $user_id, (int)$wallet_id, $transaction_type, $reference_id, (float)$amount, $note, $txn_date, (int)$branch_id);
    }
}

// "Cash: 60.00, AIBL: 60.00" for a document paid with Multi-wallet, '' otherwise.
function wallet_splits_label($conn, $user_id, $doc_type, $doc_id)
{
    $parts = [];

    foreach(wallet_splits_get($conn, $user_id, $doc_type, $doc_id) as $wallet_id => $amount){
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wallet_name FROM wallets WHERE id=" . (int)$wallet_id . " AND user_id=" . (int)$user_id));
        $parts[] = ($row['wallet_name'] ?? ('Wallet #' . (int)$wallet_id)) . ': ' . number_format((float)$amount, 2);
    }

    return implode(', ', $parts);
}

// Supplier payments are also listed as expenses: one expense per wallet of the split.
function wallet_splits_record_supplier_expenses($conn, $user_id, $splits, $txn_date, $note, $source_type, $source_id)
{
    foreach($splits as $wallet_id => $amount){
        record_supplier_payment_expense($conn, $user_id, (int)$wallet_id, (float)$amount, $txn_date, $note, $source_type, $source_id, true);
    }
}

// The red "Multi-wallet" option for a wallet drop-down (only when the company has 2+ wallets).
function multi_wallet_option_html($conn, $user_id, $selected = false)
{
    if(!multi_wallet_available($conn, $user_id)){
        return '';
    }

    return '<option value="' . MULTI_WALLET_VALUE . '" data-balance="" style="color:#dc3545;font-weight:600"' . ($selected ? ' selected' : '') . '>' . MULTI_WALLET_NAME . '</option>';
}

// Amount boxes for every wallet, shown while "Multi-wallet" is selected in #$select_id.
// $amount_selector: the paid amount the boxes must add up to. $paying_out: each box is
// limited to that wallet's balance. $prefill: [wallet_id => amount] when editing.
function multi_wallet_panel_html($conn, $user_id, $select_id, $amount_selector, $paying_out = false, $prefill = [])
{
    if(!multi_wallet_available($conn, $user_id)){
        return '';
    }

    $rows = '';

    foreach(multi_wallet_active_wallets($conn, $user_id) as $wallet_id => $wallet){
        // When editing, the wallet may pay back what this document already took from it.
        $limit = $wallet['balance'] + ($paying_out ? (float)($prefill[$wallet_id] ?? 0) : 0);
        $value = isset($prefill[$wallet_id]) ? number_format((float)$prefill[$wallet_id], 2, '.', '') : '';
        $rows .= '<div class="form-row align-items-center mb-1">'
            . '<div class="col-7 small">' . htmlspecialchars($wallet['name'])
            . ' <span class="text-muted">(BDT ' . number_format($wallet['balance'], 2) . ')</span></div>'
            . '<div class="col-5"><input type="number" step="0.01" min="0" class="form-control form-control-sm multi-wallet-amount"'
            . ' name="wallet_split[' . (int)$wallet_id . ']" data-name="' . htmlspecialchars($wallet['name']) . '"'
            . ' data-limit="' . number_format($limit, 2, '.', '') . '" value="' . $value . '" placeholder="0.00"></div>'
            . '</div>';
    }

    return '<div class="multi-wallet-panel border border-danger rounded p-2 mt-2" style="display:none"'
        . ' data-for="' . htmlspecialchars($select_id) . '" data-amount="' . htmlspecialchars($amount_selector) . '"'
        . ' data-paying-out="' . ($paying_out ? '1' : '0') . '">'
        . '<div class="small font-weight-bold text-danger mb-2">Multi-wallet: amount for each wallet</div>'
        . $rows
        . '<div class="small mt-2 multi-wallet-summary"></div>'
        . '</div>'
        . multi_wallet_script();
}

function multi_wallet_script()
{
    static $printed = false;

    if($printed){
        return '';
    }

    $printed = true;

    return <<<'HTML'
<script>
(function(){
    function ready(fn){ if(window.jQuery){ jQuery(fn); } else { document.addEventListener("DOMContentLoaded", function(){ if(window.jQuery){ jQuery(fn); } }); } }
    ready(function($){
        function state(panel){
            var select = $("#" + panel.data("for"));
            var on = select.val() === "multi";
            var target = Math.round((parseFloat($(panel.data("amount")).val()) || 0) * 100) / 100;
            var sum = 0;
            panel.find(".multi-wallet-amount").each(function(){ sum += parseFloat(this.value) || 0; });
            sum = Math.round(sum * 100) / 100;
            return {on: on, target: target, sum: sum, left: Math.round((target - sum) * 100) / 100};
        }
        function update(panel){
            var s = state(panel);
            panel.toggle(s.on);
            panel.find(".multi-wallet-amount").prop("disabled", !s.on);
            if(!s.on){ return; }
            var text = "Total: " + s.sum.toFixed(2) + " / " + s.target.toFixed(2);
            panel.find(".multi-wallet-summary").html(Math.abs(s.left) > 0.009
                ? text + ' <span class="text-danger">(Remaining: ' + s.left.toFixed(2) + ")</span>"
                : text + ' <span class="text-success">&#10003;</span>');
        }
        function updateAll(){ $(".multi-wallet-panel").each(function(){ update($(this)); }); }
        $(document).on("change", "select", updateAll);
        $(document).on("input change", "input", updateAll);
        // The problem with the Multi-wallet amounts of a form, or "" (also used by the restaurant POS
        // before it clears its cart).
        window.multiWalletError = function(form){
            var error = "";
            $(form).find(".multi-wallet-panel").each(function(){
                var panel = $(this), s = state(panel);
                if(!s.on || s.target <= 0 || error){ return; }
                if(Math.abs(s.left) > 0.009){
                    error = "Multi-wallet: the wallet amounts must add up to " + s.target.toFixed(2) + ".";
                    return;
                }
                if(panel.data("paying-out") == 1){
                    panel.find(".multi-wallet-amount").each(function(){
                        if(!error && (parseFloat(this.value) || 0) > (parseFloat($(this).data("limit")) || 0) + 0.009){
                            error = "Multi-wallet: " + $(this).data("name") + " does not have enough balance.";
                        }
                    });
                }
            });
            return error;
        };
        $(document).on("submit", "form", function(e){
            var error = window.multiWalletError(this);
            if(error){
                alert(error);
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        });
        updateAll();
    });
})();
</script>
HTML;
}
