<?php
require_once __DIR__ . '/_init.php';

$error = '';
$form = $_POST;
$cash_wallet_id = ensure_default_cash_wallet($conn, $user_id);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    try {
        $package_id = (int)($_POST['package_id'] ?? 0);
        $project_id = 0;
        if ($package_id > 0) {
            $package = mysqli_fetch_assoc(mysqli_query($conn, "SELECT project_id FROM packages WHERE id={$package_id} AND user_id={$user_id}"));
            if (!$package) throw new RuntimeException('Select a valid package.');
            $project_id = (int)$package['project_id'];
        }
        $names = $_POST['passenger_name'] ?? [];
        $passengers = [];
        foreach (is_array($names) ? $names : [] as $index => $name) {
            $passengers[] = [$name, $_POST['passenger_passport'][$index] ?? '', $_POST['passenger_ticket'][$index] ?? ''];
        }
        $data = [
            'service_kind' => (string)($_POST['service_kind'] ?? ''),
            'project_id' => $project_id,
            'package_id' => $package_id,
            'client_id' => (int)($_POST['client_id'] ?? 0),
            'booking_date' => (string)($_POST['booking_date'] ?? ''),
            'travel_date' => (string)($_POST['travel_date'] ?? ''),
            'return_date' => (string)($_POST['return_date'] ?? ''),
            'airline' => (string)($_POST['airline'] ?? ''),
            'pnr' => (string)($_POST['pnr'] ?? ''),
            'route' => (string)($_POST['route'] ?? ''),
            'trip_type' => (string)($_POST['trip_type'] ?? ''),
            'details' => (string)($_POST['details'] ?? ''),
            'vendor_id' => (int)($_POST['vendor_id'] ?? 0),
            'cost' => (string)($_POST['cost'] ?? '0'),
            'price' => (string)($_POST['price'] ?? '0'),
            'note' => (string)($_POST['note'] ?? ''),
            'country' => (string)($_POST['country'] ?? ''),
            'visa_type' => (string)($_POST['visa_type'] ?? ''),
            'application_no' => (string)($_POST['application_no'] ?? ''),
            'expected_date' => (string)($_POST['expected_date'] ?? ''),
            'group_id' => (int)($_POST['group_id'] ?? 0),
            'installment_count' => (int)($_POST['installment_count'] ?? 0),
            'installment_first' => (string)($_POST['installment_first'] ?? ''),
            'installment_every' => (int)($_POST['installment_every'] ?? 1),
            'commission_agent_id' => (int)($_POST['commission_agent_id'] ?? 0),
            'commission' => (string)($_POST['commission'] ?? '0'),
        ];
        $booking_id = travel_booking_create(
            $conn, $user_id, $branch_id, $data, $passengers,
            (float)($_POST['client_paid'] ?? 0), (int)($_POST['client_wallet'] ?? 0),
            (float)($_POST['vendor_paid'] ?? 0), (int)($_POST['vendor_wallet'] ?? 0),
            (string)($_POST['request_key'] ?? '')
        );
        header('Location: booking_view.php?id=' . $booking_id . '&created=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$clients = travel_clients($conn, $user_id);
$vendors = travel_vendors($conn, $user_id);
$groups = travel_groups($conn, $user_id, true);
// Commission agents: B2B agents first, then other clients.
$commission_agents = $clients;
usort($commission_agents, static fn($a, $b) => [(int)!$a['is_agent'], $a['customer_name']] <=> [(int)!$b['is_agent'], $b['customer_name']]);
$packages = [];
$package_result = mysqli_query($conn, "SELECT pk.id, pk.package_name, pk.price, pr.project_name FROM packages pk INNER JOIN projects pr ON pr.id=pk.project_id AND pr.user_id=pk.user_id
    WHERE pk.user_id={$user_id} AND pk.status='active' AND pr.status='active' ORDER BY pr.project_name, pk.package_name");
while ($package_result && ($row = mysqli_fetch_assoc($package_result))) {
    $packages[$row['project_name']][] = $row;
}
// A service type named like a booking kind selects that kind.
$kind_for_project = static function ($name) {
    $name = strtolower($name);
    foreach (['air' => 'air_ticket', 'ticket' => 'air_ticket', 'visa' => 'visa', 'hajj' => 'hajj_umrah', 'umrah' => 'hajj_umrah', 'tour' => 'tour', 'hotel' => 'hotel'] as $word => $kind) {
        if (strpos($name, $word) !== false) return $kind;
    }
    return 'other';
};
$value = static fn($key, $default = '') => htmlspecialchars((string)($form[$key] ?? $default));
$selected_kind = (string)($form['service_kind'] ?? ($_GET['kind'] ?? 'air_ticket'));
$selected_client = (int)($form['client_id'] ?? ($_GET['client_id'] ?? 0));
$passenger_rows = [];
foreach ((array)($form['passenger_name'] ?? ['']) as $index => $name) {
    $passenger_rows[] = [$name, $form['passenger_passport'][$index] ?? '', $form['passenger_ticket'][$index] ?? ''];
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>
.travel-section-title{font-size:.8rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#17a2b8;margin:1rem 0 .6rem}
.travel-profit{font-size:1.15rem;font-weight:700}
.passenger-row .form-control{min-width:120px}
</style>
<section class="content pt-3"><div class="container-fluid">
<form method="post" id="travel-booking-form" autocomplete="off">
    <?= travel_csrf_field(); ?>
    <input type="hidden" name="request_key" value="<?= htmlspecialchars((string)($form['request_key'] ?? bin2hex(random_bytes(24)))); ?>">
    <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-plane-departure mr-2"></i>New Booking</h3>
            <div class="card-tools"><a href="bookings.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-list mr-1"></i>Booking List</a></div></div>
        <div class="card-body">
            <?php if ($error !== '') { ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php } ?>
            <div class="form-row">
                <div class="form-group col-md-3"><label for="service_kind">Booking Type</label>
                    <select id="service_kind" name="service_kind" class="form-control" required>
                        <?php foreach (travel_service_kinds() as $kind => $label) { ?><option value="<?= $kind; ?>"<?= $kind === $selected_kind ? ' selected' : ''; ?>><?= htmlspecialchars($label); ?></option><?php } ?>
                    </select></div>
                <div class="form-group col-md-3"><label for="package_id">Package</label>
                    <select id="package_id" name="package_id" class="form-control">
                        <option value="">— Optional —</option>
                        <?php foreach ($packages as $project_name => $items) { ?><optgroup label="<?= htmlspecialchars($project_name); ?>">
                            <?php foreach ($items as $package) { ?><option value="<?= (int)$package['id']; ?>" data-kind="<?= $kind_for_project($project_name); ?>" data-price="<?= (float)$package['price']; ?>"<?= (int)($form['package_id'] ?? 0) === (int)$package['id'] ? ' selected' : ''; ?>><?= htmlspecialchars($package['package_name']); ?></option><?php } ?>
                        </optgroup><?php } ?>
                    </select></div>
                <div class="form-group col-md-4"><label for="client_id">Client</label>
                    <select id="client_id" name="client_id" class="form-control" required>
                        <option value="">Select client</option>
                        <?php foreach ($clients as $client) { ?><option value="<?= (int)$client['id']; ?>" data-name="<?= htmlspecialchars($client['customer_name']); ?>" data-agent="<?= $client['is_agent'] ? 1 : 0; ?>"<?= (int)$client['id'] === $selected_client ? ' selected' : ''; ?>><?= htmlspecialchars(travel_client_label($client) . ($client['is_agent'] ? ' — Agent (B2B)' : '')); ?></option><?php } ?>
                    </select>
                    <small class="form-text"><a href="<?= htmlspecialchars(app_path('customers/index.php')); ?>" target="_blank"><i class="fas fa-user-plus mr-1"></i>New client</a> <span id="client-agent-badge" class="badge badge-warning ml-1" hidden>B2B Agent</span></small></div>
                <div class="form-group col-md-2"><label for="booking_date">Booking Date</label>
                    <input id="booking_date" type="date" name="booking_date" class="form-control" max="<?= date('Y-m-d'); ?>" value="<?= $value('booking_date', date('Y-m-d')); ?>" required></div>
            </div>

            <div id="air-ticket-fields">
                <div class="travel-section-title"><i class="fas fa-plane mr-1"></i>Flight</div>
                <div class="form-row">
                    <div class="form-group col-md-2"><label for="trip_type">Trip</label>
                        <select id="trip_type" name="trip_type" class="form-control">
                            <?php foreach (['one_way' => 'One Way', 'round_trip' => 'Round Trip', 'multi_city' => 'Multi City'] as $trip => $label) { ?><option value="<?= $trip; ?>"<?= ($form['trip_type'] ?? 'one_way') === $trip ? ' selected' : ''; ?>><?= $label; ?></option><?php } ?>
                        </select></div>
                    <div class="form-group col-md-3"><label for="airline">Airline</label><input id="airline" name="airline" class="form-control" maxlength="100" placeholder="e.g. Biman Bangladesh" value="<?= $value('airline'); ?>"></div>
                    <div class="form-group col-md-2"><label for="pnr">PNR</label><input id="pnr" name="pnr" class="form-control text-uppercase" maxlength="40" value="<?= $value('pnr'); ?>"></div>
                    <div class="form-group col-md-3"><label for="route">Route</label><input id="route" name="route" class="form-control text-uppercase" maxlength="150" placeholder="DAC-DXB-DAC" value="<?= $value('route'); ?>"></div>
                    <div class="form-group col-md-2"><label for="travel_date">Departure</label><input id="travel_date" type="date" name="travel_date" class="form-control" value="<?= $value('travel_date'); ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-2 offset-md-10" id="return-date-group"><label for="return_date">Return</label><input id="return_date" type="date" name="return_date" class="form-control" value="<?= $value('return_date'); ?>"></div>
                </div>
            </div>

            <div id="visa-fields" hidden>
                <div class="travel-section-title"><i class="fas fa-passport mr-1"></i>Visa File</div>
                <div class="form-row">
                    <div class="form-group col-md-3"><label for="country">Country</label><input id="country" name="country" class="form-control" maxlength="80" list="visa-countries" placeholder="e.g. Thailand" value="<?= $value('country'); ?>">
                        <datalist id="visa-countries"><?php foreach (['Thailand', 'Malaysia', 'Singapore', 'India', 'United Arab Emirates', 'Saudi Arabia', 'Qatar', 'Oman', 'China', 'Japan', 'South Korea', 'Indonesia', 'Vietnam', 'Turkey', 'United Kingdom', 'USA', 'Canada', 'Australia', 'Schengen'] as $country) { ?><option value="<?= $country; ?>"><?php } ?></datalist></div>
                    <div class="form-group col-md-3"><label for="visa_type">Visa Type</label><select id="visa_type" name="visa_type" class="form-control"><?php foreach (['Tourist', 'Business', 'Work', 'Student', 'Medical', 'Family / Visit', 'Transit', 'Other'] as $type) { ?><option<?= ($form['visa_type'] ?? 'Tourist') === $type ? ' selected' : ''; ?>><?= $type; ?></option><?php } ?></select></div>
                    <div class="form-group col-md-3"><label for="application_no">Application / Ref No</label><input id="application_no" name="application_no" class="form-control" maxlength="60" value="<?= $value('application_no'); ?>"></div>
                    <div class="form-group col-md-3"><label for="expected_date">Expected Delivery</label><input id="expected_date" type="date" name="expected_date" class="form-control" value="<?= $value('expected_date'); ?>"></div>
                </div>
            </div>

            <div id="group-fields" hidden>
                <div class="travel-section-title"><i class="fas fa-kaaba mr-1"></i>Group &amp; Installments</div>
                <div class="form-row">
                    <div class="form-group col-md-6"><label for="group_id">Group</label>
                        <select id="group_id" name="group_id" class="form-control">
                            <option value="0">— No group —</option>
                            <?php foreach ($groups as $group) { ?><option value="<?= (int)$group['id']; ?>" data-kind="<?= htmlspecialchars($group['service_kind']); ?>" data-price="<?= (float)$group['price_per_person']; ?>" data-cost="<?= (float)$group['cost_per_person']; ?>" data-vendor="<?= (int)$group['vendor_id']; ?>" data-departure="<?= htmlspecialchars((string)$group['departure_date']); ?>" data-name="<?= htmlspecialchars($group['group_name']); ?>" data-left="<?= (int)$group['seats'] > 0 ? (int)$group['seats_left'] : -1; ?>"<?= (int)($form['group_id'] ?? ($_GET['group_id'] ?? 0)) === (int)$group['id'] ? ' selected' : ''; ?>><?= htmlspecialchars($group['group_name'] . ((int)$group['seats'] > 0 ? ' — ' . (int)$group['seats_left'] . ' seat(s) left' : '')); ?></option><?php } ?>
                        </select>
                        <small class="form-text"><a href="groups.php" target="_blank"><i class="fas fa-plus mr-1"></i>New group</a> <span id="group-seats" class="ml-2 text-muted"></span></small></div>
                    <div class="form-group col-md-2"><label for="installment_count">Installments</label>
                        <select id="installment_count" name="installment_count" class="form-control"><option value="0">None</option><?php for ($i = 2; $i <= 12; $i++) { ?><option value="<?= $i; ?>"<?= (int)($form['installment_count'] ?? 0) === $i ? ' selected' : ''; ?>><?= $i; ?></option><?php } ?></select></div>
                    <div class="form-group col-md-2"><label for="installment_first">First due</label><input id="installment_first" type="date" name="installment_first" class="form-control" value="<?= $value('installment_first', date('Y-m-d')); ?>"></div>
                    <div class="form-group col-md-2"><label for="installment_every">Every (months)</label><input id="installment_every" type="number" min="1" max="12" name="installment_every" class="form-control" value="<?= $value('installment_every', '1'); ?>"></div>
                </div>
                <div id="installment-preview" class="small text-muted mb-2"></div>
            </div>

            <div id="other-service-fields" hidden>
                <div class="travel-section-title"><i class="fas fa-info-circle mr-1"></i>Service Details</div>
                <div class="form-row">
                    <div class="form-group col-md-3"><label for="service_date">Travel / Service Date</label><input id="service_date" type="date" class="form-control" value="<?= $value('travel_date'); ?>"></div>
                    <div class="form-group col-md-9"><label for="details">Details</label><input id="details" name="details" class="form-control" maxlength="2000" placeholder="Country, visa type, hotel, package days…" value="<?= $value('details'); ?>"></div>
                </div>
            </div>

            <div class="travel-section-title"><i class="fas fa-users mr-1"></i>Passengers</div>
            <div class="table-responsive"><table class="table table-sm table-bordered mb-2" id="passenger-table">
                <thead class="thead-light"><tr><th>Passenger Name</th><th>Passport No</th><th class="ticket-col">Ticket No</th><th style="width:50px"></th></tr></thead>
                <tbody>
                <?php foreach ($passenger_rows as [$name, $passport, $ticket]) { ?>
                    <tr class="passenger-row">
                        <td><input name="passenger_name[]" class="form-control form-control-sm" maxlength="150" value="<?= htmlspecialchars((string)$name); ?>"></td>
                        <td><input name="passenger_passport[]" class="form-control form-control-sm text-uppercase" maxlength="40" value="<?= htmlspecialchars((string)$passport); ?>"></td>
                        <td class="ticket-col"><input name="passenger_ticket[]" class="form-control form-control-sm" maxlength="60" value="<?= htmlspecialchars((string)$ticket); ?>"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger passenger-remove" title="Remove"><i class="fas fa-times"></i></button></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table></div>
            <button type="button" class="btn btn-sm btn-outline-info mb-2" id="passenger-add"><i class="fas fa-plus mr-1"></i>Add passenger</button>

            <div class="row">
                <div class="col-lg-6">
                    <div class="travel-section-title"><i class="fas fa-truck mr-1"></i>Vendor (Purchase)</div>
                    <div class="form-row">
                        <div class="form-group col-md-7"><label for="vendor_id">Vendor</label>
                            <select id="vendor_id" name="vendor_id" class="form-control">
                                <option value="">Select vendor</option>
                                <?php foreach ($vendors as $vendor) { ?><option value="<?= (int)$vendor['id']; ?>"<?= (int)($form['vendor_id'] ?? 0) === (int)$vendor['id'] ? ' selected' : ''; ?>><?= htmlspecialchars($vendor['supplier_name'] . ($vendor['supplier_code'] ? ' [' . $vendor['supplier_code'] . ']' : '')); ?></option><?php } ?>
                            </select>
                            <small class="form-text"><a href="<?= htmlspecialchars(app_path('suppliers/index.php')); ?>" target="_blank"><i class="fas fa-plus mr-1"></i>New vendor</a></small></div>
                        <div class="form-group col-md-5"><label for="cost">Purchase Price</label><input id="cost" type="number" step="0.01" min="0" name="cost" class="form-control" value="<?= $value('cost'); ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-5"><label for="vendor_paid">Paid to Vendor Now</label><input id="vendor_paid" type="number" step="0.01" min="0" name="vendor_paid" class="form-control" value="<?= $value('vendor_paid', '0'); ?>"></div>
                        <div class="form-group col-md-7"><label for="vendor_wallet">From Wallet</label><select id="vendor_wallet" name="vendor_wallet" class="form-control"><?= travel_wallet_options($conn, $user_id, (int)($form['vendor_wallet'] ?? $cash_wallet_id)); ?></select></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="travel-section-title"><i class="fas fa-user mr-1"></i>Client (Sale)</div>
                    <div class="form-row">
                        <div class="form-group col-md-5"><label for="price">Selling Price</label><input id="price" type="number" step="0.01" min="0.01" name="price" class="form-control" value="<?= $value('price'); ?>" required></div>
                        <div class="form-group col-md-7 d-flex align-items-end"><div class="pb-2">Profit: <span id="profit" class="travel-profit">0.00</span></div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-5"><label for="client_paid">Received Now</label><input id="client_paid" type="number" step="0.01" min="0" name="client_paid" class="form-control" value="<?= $value('client_paid', '0'); ?>"></div>
                        <div class="form-group col-md-7"><label for="client_wallet">To Wallet</label><select id="client_wallet" name="client_wallet" class="form-control"><?= travel_wallet_options($conn, $user_id, (int)($form['client_wallet'] ?? $cash_wallet_id)); ?></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-7"><label for="commission_agent_id">Commission Agent <small class="text-muted">(optional)</small></label>
                            <select id="commission_agent_id" name="commission_agent_id" class="form-control">
                                <option value="0">— No commission —</option>
                                <?php foreach ($commission_agents as $agent) { ?><option value="<?= (int)$agent['id']; ?>"<?= (int)($form['commission_agent_id'] ?? 0) === (int)$agent['id'] ? ' selected' : ''; ?>><?= htmlspecialchars(travel_client_label($agent) . ($agent['is_agent'] ? ' — Agent' : '')); ?></option><?php } ?>
                            </select></div>
                        <div class="form-group col-md-5"><label for="commission">Commission</label>
                            <div class="input-group"><input id="commission" type="number" step="0.01" min="0" name="commission" class="form-control" value="<?= $value('commission', '0'); ?>">
                                <div class="input-group-append"><input id="commission_percent" type="number" step="0.01" min="0" max="100" class="form-control" style="max-width:80px" placeholder="%" title="Commission as % of the selling price"><span class="input-group-text">%</span></div></div></div>
                    </div>
                    <div class="text-muted small">Client due after this booking: <strong id="client-due">0.00</strong></div>
                </div>
            </div>

            <div class="form-group mt-3"><label for="note">Note</label><input id="note" name="note" class="form-control" maxlength="500" placeholder="Optional" value="<?= $value('note'); ?>"></div>
            <button type="submit" class="btn btn-info"><i class="fas fa-save mr-1"></i>Save Booking</button>
        </div>
    </div>
</form>
</div></section>
<script>
(function () {
    const kind = document.getElementById('service_kind');
    const pkg = document.getElementById('package_id');
    const client = document.getElementById('client_id');
    const air = document.getElementById('air-ticket-fields');
    const other = document.getElementById('other-service-fields');
    const travelDate = document.getElementById('travel_date');
    const serviceDate = document.getElementById('service_date');
    const trip = document.getElementById('trip_type');
    const tbody = document.querySelector('#passenger-table tbody');
    const number = id => Number(document.getElementById(id).value || 0);

    function showKind() {
        const isAir = kind.value === 'air_ticket';
        air.hidden = !isAir;
        other.hidden = isAir;
        document.getElementById('visa-fields').hidden = kind.value !== 'visa';
        document.getElementById('group-fields').hidden = !['hajj_umrah', 'tour'].includes(kind.value);
        document.querySelectorAll('.ticket-col').forEach(cell => { cell.hidden = !isAir; });
        // One date field is sent: Departure for flights, Service Date otherwise.
        travelDate.name = isAir ? 'travel_date' : '';
        serviceDate.name = isAir ? '' : 'travel_date';
        document.getElementById('return-date-group').hidden = !isAir || trip.value === 'one_way';
    }
    function totals() {
        const cost = number('cost'), price = number('price');
        // Agent commission is a cost of the sale too.
        const profit = price - cost - number('commission');
        const profitEl = document.getElementById('profit');
        profitEl.textContent = profit.toFixed(2);
        profitEl.className = 'travel-profit ' + (profit < 0 ? 'text-danger' : 'text-success');
        document.getElementById('client-due').textContent = Math.max(0, price - number('client_paid')).toFixed(2);
    }
    // Commission: amount or % of the selling price.
    const commission = document.getElementById('commission'), percent = document.getElementById('commission_percent');
    percent.addEventListener('input', function () { commission.value = (number('price') * Number(percent.value || 0) / 100).toFixed(2); totals(); });
    commission.addEventListener('input', function () { percent.value = number('price') > 0 ? (Number(commission.value || 0) / number('price') * 100).toFixed(2) : ''; totals(); });
    kind.addEventListener('change', showKind);
    trip.addEventListener('change', showKind);
    pkg.addEventListener('change', function () {
        const option = pkg.selectedOptions[0];
        if (option && option.dataset.kind) { kind.value = option.dataset.kind; showKind(); }
        const price = document.getElementById('price');
        if (option && Number(option.dataset.price) > 0 && !price.value) { price.value = option.dataset.price; totals(); }
    });
    function onClient() {
        const option = client.selectedOptions[0];
        document.getElementById('client-agent-badge').hidden = !(option && option.dataset.agent === '1');
        // The first passenger is usually the client (B2C); agents book for others.
        const first = tbody.querySelector('input[name="passenger_name[]"]');
        if (option && option.value && option.dataset.agent !== '1' && first && !first.value) first.value = option.dataset.name;
    }
    client.addEventListener('change', onClient);
    // jQuery and select2 load in the footer.
    document.addEventListener('DOMContentLoaded', function () {
        if (window.jQuery && jQuery.fn.select2) {
            jQuery(client).select2({theme: 'bootstrap4', width: '100%'}).on('select2:select', onClient);
            jQuery('#vendor_id').select2({theme: 'bootstrap4', width: '100%'});
        }
    });
    ['cost', 'price', 'client_paid'].forEach(id => document.getElementById(id).addEventListener('input', totals));
    document.getElementById('passenger-add').addEventListener('click', function () {
        const row = tbody.querySelector('.passenger-row').cloneNode(true);
        row.querySelectorAll('input').forEach(input => { input.value = ''; });
        tbody.appendChild(row);
        showKind();
    });
    tbody.addEventListener('click', function (event) {
        const button = event.target.closest('.passenger-remove');
        if (!button) return;
        if (tbody.querySelectorAll('.passenger-row').length > 1) button.closest('tr').remove();
        else button.closest('tr').querySelectorAll('input').forEach(input => { input.value = ''; });
    });
    document.getElementById('travel-booking-form').addEventListener('submit', function (event) {
        if (number('client_paid') > number('price')) { event.preventDefault(); alert('Received amount cannot be more than the selling price.'); return; }
        if (number('vendor_paid') > number('cost')) { event.preventDefault(); alert('Paid to vendor cannot be more than the purchase price.'); return; }
        if (number('cost') > 0 && !document.getElementById('vendor_id').value) { event.preventDefault(); alert('Select the vendor for the purchase price.'); return; }
        if (number('commission') > 0 && document.getElementById('commission_agent_id').value === '0') { event.preventDefault(); alert('Select the commission agent.'); }
    });
    // Hajj / Umrah / Tour: a group fills price, cost, vendor and date per passenger; installments preview.
    const groupSelect = document.getElementById('group_id');
    const paxCount = () => Array.from(tbody.querySelectorAll('input[name="passenger_name[]"]')).filter(input => input.value.trim() !== '').length || 1;
    let groupPriced = false;
    function applyGroup(force) {
        const option = groupSelect.selectedOptions[0];
        const seats = document.getElementById('group-seats');
        if (!option || option.value === '0') { seats.textContent = ''; return; }
        const left = Number(option.dataset.left);
        seats.textContent = left < 0 ? 'No seat limit' : left + ' seat(s) left';
        seats.className = 'ml-2 ' + (left >= 0 && paxCount() > left ? 'text-danger font-weight-bold' : 'text-muted');
        if (!force && !groupPriced) return;
        groupPriced = true;
        if (option.dataset.kind && kind.value !== option.dataset.kind) { kind.value = option.dataset.kind; showKind(); }
        if (Number(option.dataset.price) > 0) document.getElementById('price').value = (Number(option.dataset.price) * paxCount()).toFixed(2);
        if (Number(option.dataset.cost) > 0) document.getElementById('cost').value = (Number(option.dataset.cost) * paxCount()).toFixed(2);
        if (Number(option.dataset.vendor) > 0) { document.getElementById('vendor_id').value = option.dataset.vendor; if (window.jQuery) jQuery('#vendor_id').trigger('change'); }
        if (option.dataset.departure) serviceDate.value = option.dataset.departure;
        const details = document.getElementById('details');
        if (!details.value) details.value = 'Group: ' + option.dataset.name;
        totals();
        installmentPreview();
    }
    function installmentPreview() {
        const count = Number(document.getElementById('installment_count').value), box = document.getElementById('installment-preview');
        if (count < 2) { box.textContent = ''; return; }
        const total = number('price'), part = Math.floor(total / count * 100) / 100;
        const first = document.getElementById('installment_first').value, every = Math.max(1, Number(document.getElementById('installment_every').value || 1));
        const lines = [];
        for (let i = 0; i < count; i++) {
            const amount = i === count - 1 ? total - part * (count - 1) : part;
            let when = '';
            if (first) { const d = new Date(first + 'T00:00:00'); d.setMonth(d.getMonth() + i * every); when = d.toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}); }
            lines.push('#' + (i + 1) + ' ' + when + ': ' + amount.toFixed(2));
        }
        box.textContent = 'Schedule — ' + lines.join(' | ');
    }
    groupSelect.addEventListener('change', () => applyGroup(true));
    ['installment_count', 'installment_first', 'installment_every', 'price'].forEach(id => document.getElementById(id).addEventListener(id === 'installment_count' ? 'change' : 'input', installmentPreview));
    tbody.addEventListener('change', () => applyGroup(false));
    showKind();
    onClient();
    totals();
    // Opened from a group page: start with that group's prices.
    applyGroup(<?= empty($_POST) && !empty($_GET['group_id']) ? 'true' : 'false'; ?>);
    installmentPreview();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
