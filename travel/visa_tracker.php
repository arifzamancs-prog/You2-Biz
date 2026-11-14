<?php
require_once __DIR__ . '/_init.php';

$statuses = travel_visa_statuses();
$message = $_SESSION['travel_visa_message'] ?? '';
$message_type = $_SESSION['travel_visa_message_type'] ?? 'success';
unset($_SESSION['travel_visa_message'], $_SESSION['travel_visa_message_type']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    try {
        $status = (string)($_POST['status'] ?? '');
        $sms = travel_visa_update_status($conn, $user_id, $booking_id, $status, (string)($_POST['status_date'] ?? ''), (string)($_POST['note'] ?? ''), !empty($_POST['send_sms']));
        $_SESSION['travel_visa_message'] = 'Status changed to "' . $statuses[$status] . '".' . ($sms !== '' ? ' ' . $sms : '');
        $_SESSION['travel_visa_message_type'] = $sms !== '' && strpos($sms, 'SMS sent') !== 0 ? 'warning' : 'success';
    } catch (Throwable $e) {
        $_SESSION['travel_visa_message'] = $e->getMessage();
        $_SESSION['travel_visa_message_type'] = 'danger';
    }
    $back = (string)($_POST['return_to'] ?? '');
    header('Location: ' . ($back === 'view' ? 'booking_view.php?id=' . $booking_id : 'visa_tracker.php?' . http_build_query(array_intersect_key($_GET, ['status' => 1, 'q' => 1]))));
    exit;
}

$filter = (string)($_GET['status'] ?? 'open');
$search = trim((string)($_GET['q'] ?? ''));
$today = date('Y-m-d');
$counts = ['open' => 0, 'overdue' => 0, 'all' => 0] + array_fill_keys(array_keys($statuses), 0);
$count_result = mysqli_query($conn, "SELECT process_status, COUNT(*) n, SUM(expected_date < '{$today}' AND process_status NOT IN ('delivered','rejected')) late
    FROM travel_bookings WHERE user_id={$user_id} AND service_kind='visa' AND status='active' GROUP BY process_status");
while ($count_result && ($row = mysqli_fetch_assoc($count_result))) {
    $key = $row['process_status'] ?: 'received';
    $counts[$key] = ($counts[$key] ?? 0) + (int)$row['n'];
    $counts['all'] += (int)$row['n'];
    $counts['overdue'] += (int)$row['late'];
    if (!in_array($key, ['delivered', 'rejected'], true)) $counts['open'] += (int)$row['n'];
}

$where = ["b.user_id={$user_id}", "b.service_kind='visa'", "b.status='active'"];
if ($filter === 'open') $where[] = "b.process_status NOT IN ('delivered','rejected')";
elseif ($filter === 'overdue') $where[] = "b.expected_date < '{$today}' AND b.process_status NOT IN ('delivered','rejected')";
elseif (isset($statuses[$filter])) $where[] = "b.process_status='" . mysqli_real_escape_string($conn, $filter) . "'";
if ($search !== '') {
    $like = "'%" . mysqli_real_escape_string($conn, $search) . "%'";
    $where[] = "(b.booking_no LIKE {$like} OR b.country LIKE {$like} OR b.application_no LIKE {$like} OR c.customer_name LIKE {$like} OR c.phone LIKE {$like}
        OR EXISTS (SELECT 1 FROM travel_booking_passengers tp WHERE tp.booking_id=b.id AND (tp.passenger_name LIKE {$like} OR tp.passport_no LIKE {$like})))";
}
$rows = [];
$result = mysqli_query($conn, "SELECT b.*, c.customer_name, c.phone,
        (SELECT GROUP_CONCAT(CONCAT(tp.passenger_name, IF(tp.passport_no<>'', CONCAT(' (', tp.passport_no, ')'), '')) ORDER BY tp.id SEPARATOR ', ') FROM travel_booking_passengers tp WHERE tp.booking_id=b.id) AS passengers,
        (SELECT MAX(l.status_date) FROM travel_booking_status_log l WHERE l.booking_id=b.id) AS last_change
    FROM travel_bookings b LEFT JOIN customers c ON c.id=b.client_id AND c.user_id=b.user_id
    WHERE " . implode(' AND ', $where) . " ORDER BY (b.expected_date IS NULL), b.expected_date, b.id LIMIT 500");
while ($result && ($row = mysqli_fetch_assoc($result))) {
    $rows[] = $row;
}
require_once __DIR__ . '/../includes/sms_helper.php';
$sms_ready = sms_get_system_api_token($conn) !== '';
$sms_left = (int)sms_get_user_quota($conn, $user_id)['remaining'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<style>.visa-update .form-control{min-width:0}.visa-late{background:#fff3f3!important}</style>
<section class="content pt-3"><div class="container-fluid">
    <?php if ($message !== '') { ?><div class="alert alert-<?= htmlspecialchars($message_type); ?>"><?= htmlspecialchars($message); ?></div><?php } ?>
    <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-passport mr-2"></i>Visa Tracker</h3>
            <div class="card-tools"><span class="text-muted small mr-2">SMS <?= $sms_ready ? 'left: ' . $sms_left : 'gateway not configured'; ?></span><a href="booking_create.php?kind=visa" class="btn btn-sm btn-info"><i class="fas fa-plus mr-1"></i>New Visa File</a></div></div>
        <div class="card-body">
            <div class="d-flex flex-wrap mb-3" style="gap:.4rem">
                <?php foreach (['open' => 'In Process', 'overdue' => 'Overdue'] + $statuses + ['all' => 'All'] as $key => $label) { ?>
                    <a href="?status=<?= $key; ?>" class="btn btn-sm <?= $filter === $key ? 'btn-info' : ($key === 'overdue' && $counts['overdue'] ? 'btn-outline-danger' : 'btn-outline-secondary'); ?>"><?= htmlspecialchars($label); ?> <span class="badge badge-light"><?= (int)($counts[$key] ?? 0); ?></span></a>
                <?php } ?>
                <form class="ml-auto form-inline" method="get"><input type="hidden" name="status" value="<?= htmlspecialchars($filter); ?>"><input name="q" class="form-control form-control-sm" placeholder="Client, passport, country, ref…" value="<?= htmlspecialchars($search); ?>"><button class="btn btn-sm btn-primary ml-1"><i class="fas fa-search"></i></button></form>
            </div>
            <div class="table-responsive"><table class="table table-bordered table-sm">
                <thead class="thead-light"><tr><th>File</th><th>Client</th><th>Passenger(s)</th><th>Visa</th><th>Received</th><th>Expected</th><th>Status</th><th style="min-width:330px">Update</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row) {
                    $status = $row['process_status'] ?: 'received';
                    $closed = in_array($status, ['delivered', 'rejected'], true);
                    $late = !$closed && $row['expected_date'] && $row['expected_date'] < $today; ?>
                    <tr class="<?= $late ? 'visa-late' : ''; ?>">
                        <td class="text-nowrap"><a href="booking_view.php?id=<?= (int)$row['id']; ?>"><?= htmlspecialchars($row['booking_no']); ?></a></td>
                        <td><?= htmlspecialchars($row['customer_name'] ?? ''); ?><div class="small text-muted"><?= htmlspecialchars($row['phone'] ?? ''); ?></div></td>
                        <td class="small"><?= htmlspecialchars((string)$row['passengers']); ?></td>
                        <td><?= htmlspecialchars($row['country'] ?: '—'); ?><div class="small text-muted"><?= htmlspecialchars(trim($row['visa_type'] . ($row['application_no'] !== '' ? ' · ' . $row['application_no'] : ''))); ?></div></td>
                        <td class="text-nowrap"><?= htmlspecialchars(date('d-m-Y', strtotime($row['booking_date']))); ?></td>
                        <td class="text-nowrap <?= $late ? 'text-danger font-weight-bold' : ''; ?>"><?= $row['expected_date'] ? htmlspecialchars(date('d-m-Y', strtotime($row['expected_date']))) : '—'; ?><?= $late ? '<div class="small">Overdue</div>' : ''; ?></td>
                        <td><span class="badge badge-<?= travel_visa_status_badge($status); ?>"><?= htmlspecialchars($statuses[$status] ?? $status); ?></span><?php if ($row['last_change']) { ?><div class="small text-muted"><?= htmlspecialchars(date('d M', strtotime($row['last_change']))); ?></div><?php } ?></td>
                        <td>
                            <form method="post" class="visa-update form-row" onsubmit="return confirm('Change the status of <?= htmlspecialchars($row['booking_no']); ?>?');">
                                <?= travel_csrf_field(); ?>
                                <input type="hidden" name="booking_id" value="<?= (int)$row['id']; ?>">
                                <div class="col-5 mb-1"><select name="status" class="form-control form-control-sm">
                                    <?php foreach ($statuses as $key => $label) { if ($key === $status) continue; ?><option value="<?= $key; ?>"<?= $key === travel_visa_next_status($status) ? ' selected' : ''; ?>><?= htmlspecialchars($label); ?></option><?php } ?>
                                </select></div>
                                <div class="col-4 mb-1"><input type="date" name="status_date" class="form-control form-control-sm" max="<?= $today; ?>" value="<?= $today; ?>"></div>
                                <div class="col-3 mb-1"><button class="btn btn-sm btn-info btn-block">Save</button></div>
                                <div class="col-8"><input name="note" class="form-control form-control-sm" maxlength="200" placeholder="Note (optional, goes in SMS)"></div>
                                <div class="col-4 pt-1"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="sms-<?= (int)$row['id']; ?>" name="send_sms" value="1"<?= $sms_ready ? ' checked' : ' disabled'; ?>><label class="custom-control-label small" for="sms-<?= (int)$row['id']; ?>">SMS client</label></div></div>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">No visa file here.</td></tr><?php } ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
