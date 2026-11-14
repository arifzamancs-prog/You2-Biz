<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/sms_helper.php';

// Reminder log: what was sent to whom, so the same reminder is not repeated by mistake.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS travel_reminder_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    reminder_key VARCHAR(80) NOT NULL,
    client_id INT NOT NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    message VARCHAR(600) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT '',
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY travel_reminder_key (user_id, reminder_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$types = [
    'due' => ['Due before travel', 'fa-exclamation-circle'],
    'trip' => ['Trip reminder', 'fa-plane-departure'],
    'installment' => ['Installments', 'fa-calendar-check'],
    'passport' => ['Passport expiry', 'fa-passport'],
];
$type = array_key_exists($_GET['type'] ?? ($_POST['type'] ?? ''), $types) ? ($_GET['type'] ?? $_POST['type']) : 'due';
$days = max(1, min(365, (int)($_GET['days'] ?? ['due' => 7, 'trip' => 2, 'installment' => 7, 'passport' => 180][$type])));
$today = date('Y-m-d');
$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, phone FROM users WHERE id={$user_id}"));
$sign = ' - ' . ($company['name'] ?? '') . (!empty($company['phone']) ? ' ' . $company['phone'] : '');
$fmt = static fn($value) => date('d M Y', strtotime($value));
$money = static fn($value) => number_format((float)$value, 0);

// Who should be reminded, for one type. Each row: key, client, phone, detail, message.
function travel_reminder_rows($conn, $user_id, $type, $days, $today, $sign, $fmt, $money)
{
    $rows = [];
    $until = date('Y-m-d', strtotime("+{$days} days"));
    if (in_array($type, ['due', 'trip'], true)) {
        $result = mysqli_query($conn, "SELECT b.id FROM travel_bookings b WHERE b.user_id=" . (int)$user_id . " AND b.status='active'
            AND b.travel_date BETWEEN '{$today}' AND '{$until}' ORDER BY b.travel_date, b.id");
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $booking = travel_booking_get($conn, $user_id, (int)$row['id']);
            $client = $booking['client'];
            $trip = trim(($booking['service_kind'] === 'air_ticket' ? trim($booking['airline'] . ' ' . $booking['route']) : (travel_service_kinds()[$booking['service_kind']] ?? 'trip')) . ($booking['country'] !== '' ? ' ' . $booking['country'] : ''));
            if ($type === 'due') {
                if ($booking['due'] <= 0) continue;
                $message = 'Dear ' . $client['customer_name'] . ', your ' . $trip . ' on ' . $fmt($booking['travel_date']) . ' (' . $booking['booking_no'] . ') has BDT ' . $money($booking['due']) . ' due. Please pay before travel.' . $sign;
                $detail = $booking['booking_no'] . ' · travel ' . $fmt($booking['travel_date']) . ' · due ' . travel_money($booking['due']);
            } else {
                $message = 'Dear ' . $client['customer_name'] . ', your ' . $trip . ' is on ' . $fmt($booking['travel_date']) . ($booking['pnr'] !== '' ? ', PNR ' . $booking['pnr'] : '') . '. Reach the airport 3 hrs early. Safe journey!' . $sign;
                $detail = $booking['booking_no'] . ' · ' . $trip . ' · ' . $fmt($booking['travel_date']) . ($booking['due'] > 0 ? ' · due ' . travel_money($booking['due']) : '');
            }
            $rows[] = ['key' => $type . '-' . $booking['id'] . '-' . $booking['travel_date'], 'client' => $client, 'detail' => $detail, 'message' => $message, 'booking_id' => (int)$booking['id']];
        }
    } elseif ($type === 'installment') {
        $result = mysqli_query($conn, "SELECT DISTINCT b.id FROM travel_bookings b INNER JOIN travel_booking_installments i ON i.booking_id=b.id
            WHERE b.user_id=" . (int)$user_id . " AND b.status='active' AND i.due_date <= '{$until}'");
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $booking = travel_booking_get($conn, $user_id, (int)$row['id']);
            foreach (travel_installment_schedule($conn, $booking) as $step) {
                if ($step['due'] <= 0 || $step['due_date'] > $until) continue;
                $late = $step['due_date'] < $today;
                $message = 'Dear ' . $booking['client']['customer_name'] . ', installment #' . $step['installment_no'] . ' of BDT ' . $money($step['due']) . ' for ' . $booking['booking_no'] . ($late ? ' was due on ' : ' is due on ') . $fmt($step['due_date']) . '. Please pay to keep your booking confirmed.' . $sign;
                $rows[] = ['key' => 'installment-' . $step['id'] . '-' . $step['due_date'], 'client' => $booking['client'], 'booking_id' => (int)$booking['id'],
                    'detail' => $booking['booking_no'] . ' · #' . $step['installment_no'] . ' · ' . $fmt($step['due_date']) . ' · ' . travel_money($step['due']) . ($late ? ' · OVERDUE' : ''), 'message' => $message];
                break; // one reminder per booking: its earliest unpaid installment
            }
        }
    } else {
        $result = mysqli_query($conn, "SELECT id FROM customers WHERE user_id=" . (int)$user_id . " AND extra_data LIKE '%passport_expiry%'");
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $client = travel_client($conn, $user_id, (int)$row['id']);
            $extra = json_decode((string)mysqli_fetch_row(mysqli_query($conn, 'SELECT extra_data FROM customers WHERE id=' . (int)$row['id']))[0], true) ?: [];
            $raw = trim((string)($extra['passport_expiry'] ?? ''));
            $date = DateTime::createFromFormat('!d/m/Y', $raw) ?: DateTime::createFromFormat('!Y-m-d', $raw);
            if (!$date || $date->format('Y-m-d') > $until) continue;
            $expiry = $date->format('Y-m-d');
            $message = 'Dear ' . $client['customer_name'] . ', your passport' . (!empty($extra['passport_no']) ? ' ' . $extra['passport_no'] : '') . ($expiry < $today ? ' expired on ' : ' expires on ') . $fmt($expiry) . '. Please renew it before travel.' . $sign;
            $rows[] = ['key' => 'passport-' . $client['id'] . '-' . $expiry, 'client' => $client, 'booking_id' => 0, 'detail' => 'Passport ' . ($extra['passport_no'] ?? '') . ' · expiry ' . $fmt($expiry), 'message' => $message];
        }
    }
    // When each reminder was last sent.
    foreach ($rows as &$row) {
        $last = mysqli_fetch_assoc(mysqli_query($conn, "SELECT sent_at, status FROM travel_reminder_log WHERE user_id=" . (int)$user_id . " AND reminder_key='" . mysqli_real_escape_string($conn, $row['key']) . "' ORDER BY id DESC LIMIT 1"));
        $row['last_sent'] = $last['sent_at'] ?? '';
        $row['last_status'] = $last['status'] ?? '';
    }
    unset($row);
    return $rows;
}

$result_message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    travel_verify_csrf();
    $chosen = array_flip(array_map('strval', (array)($_POST['send'] ?? [])));
    $sent = 0;
    $failed = [];
    foreach (travel_reminder_rows($conn, $user_id, $type, $days, $today, $sign, $fmt, $money) as $row) {
        if (!isset($chosen[$row['key']])) continue;
        [$ok, $info] = travel_send_client_sms($conn, $user_id, $row['client']['phone'] ?? '', $row['message']);
        $stmt = mysqli_prepare($conn, 'INSERT INTO travel_reminder_log (user_id, reminder_key, client_id, phone, message, status) VALUES (?,?,?,?,?,?)');
        $client_id = (int)$row['client']['id'];
        $phone = (string)($row['client']['phone'] ?? '');
        $message = mb_substr($row['message'], 0, 600);
        $status = $ok ? 'sent' : 'failed';
        mysqli_stmt_bind_param($stmt, 'isisss', $user_id, $row['key'], $client_id, $phone, $message, $status);
        mysqli_stmt_execute($stmt);
        if ($ok) $sent++; else $failed[$info] = ($failed[$info] ?? 0) + 1;
        if (!$ok && strpos($info, 'quota') !== false) break;
    }
    $_SESSION['travel_reminder_message'] = 'SMS sent: ' . $sent . '.' . ($failed ? ' Not sent: ' . implode('; ', array_map(static fn($k, $v) => $v . ' × ' . $k, array_keys($failed), $failed)) : '');
    header('Location: reminders.php?type=' . $type . '&days=' . $days);
    exit;
}
$result_message = $_SESSION['travel_reminder_message'] ?? '';
unset($_SESSION['travel_reminder_message']);
$rows = travel_reminder_rows($conn, $user_id, $type, $days, $today, $sign, $fmt, $money);
$sms_ready = sms_get_system_api_token($conn) !== '';
$quota = sms_get_user_quota($conn, $user_id);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<section class="content pt-3"><div class="container-fluid">
    <?php if ($result_message !== '') { ?><div class="alert alert-info"><?= htmlspecialchars($result_message); ?></div><?php } ?>
    <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-sms mr-2"></i>SMS Reminders</h3>
            <div class="card-tools small text-muted"><?= $sms_ready ? 'SMS left: <strong>' . (int)$quota['remaining'] . '</strong>' : '<span class="text-danger">SMS gateway is not configured</span>'; ?></div></div>
        <div class="card-body">
            <ul class="nav nav-pills mb-3">
                <?php foreach ($types as $key => [$label, $icon]) { ?><li class="nav-item"><a class="nav-link<?= $key === $type ? ' active' : ''; ?>" href="?type=<?= $key; ?>"><i class="fas <?= $icon; ?> mr-1"></i><?= $label; ?></a></li><?php } ?>
            </ul>
            <form method="get" class="form-inline mb-3">
                <input type="hidden" name="type" value="<?= $type; ?>">
                <label class="mr-2"><?= ['due' => 'Travelling within', 'trip' => 'Travelling within', 'installment' => 'Due within', 'passport' => 'Expiring within'][$type]; ?></label>
                <input type="number" name="days" min="1" max="365" class="form-control form-control-sm mr-1" style="width:90px" value="<?= $days; ?>"><span class="mr-2">days</span>
                <button class="btn btn-sm btn-outline-primary">Show</button>
            </form>
            <form method="post" id="reminder-form">
                <?= travel_csrf_field(); ?>
                <input type="hidden" name="type" value="<?= $type; ?>">
                <div class="table-responsive"><table class="table table-bordered table-sm">
                    <thead class="thead-light"><tr><th style="width:36px"><input type="checkbox" id="check-all"></th><th>Client</th><th>Details</th><th>Message</th><th>Last sent</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) {
                        $recent = $row['last_sent'] !== '' && $row['last_status'] === 'sent' && strtotime($row['last_sent']) > strtotime('-3 days');
                        $no_phone = trim((string)($row['client']['phone'] ?? '')) === ''; ?>
                        <tr class="<?= $recent ? 'text-muted' : ''; ?>">
                            <td><input type="checkbox" name="send[]" value="<?= htmlspecialchars($row['key']); ?>" class="reminder-check"<?= !$recent && !$no_phone ? ' checked' : ''; ?><?= $no_phone ? ' disabled' : ''; ?>></td>
                            <td><?= htmlspecialchars($row['client']['customer_name'] ?? ''); ?><div class="small <?= $no_phone ? 'text-danger' : 'text-muted'; ?>"><?= $no_phone ? 'No phone' : htmlspecialchars($row['client']['phone']); ?></div></td>
                            <td class="small"><?php if ($row['booking_id'] > 0) { ?><a href="booking_view.php?id=<?= (int)$row['booking_id']; ?>"><?= htmlspecialchars($row['detail']); ?></a><?php } else { echo htmlspecialchars($row['detail']); } ?></td>
                            <td class="small" style="max-width:420px"><?= htmlspecialchars($row['message']); ?><div class="text-muted"><?= mb_strlen($row['message']); ?> chars · <?= (int)sms_estimate_message_parts($row['message']); ?> SMS</div></td>
                            <td class="small text-nowrap"><?= $row['last_sent'] !== '' ? htmlspecialchars(date('d M, h:i A', strtotime($row['last_sent']))) . ($row['last_status'] === 'failed' ? ' <span class="text-danger">(failed)</span>' : '') : '—'; ?></td>
                        </tr>
                    <?php } ?>
                    <?php if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted py-4">Nobody to remind for this period.</td></tr><?php } ?>
                    </tbody>
                </table></div>
                <?php if ($rows) { ?><button class="btn btn-info"<?= $sms_ready ? '' : ' disabled'; ?>><i class="fas fa-paper-plane mr-1"></i>Send SMS to selected</button>
                    <small class="text-muted ml-2">Rows sent in the last 3 days are not ticked, to avoid repeating the same SMS.</small><?php } ?>
            </form>
        </div>
    </div>
</div></section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const all = document.getElementById('check-all');
    if (all) all.addEventListener('change', () => document.querySelectorAll('.reminder-check:not(:disabled)').forEach(box => { box.checked = all.checked; }));
    const form = document.getElementById('reminder-form');
    if (form) form.addEventListener('submit', function (event) {
        const count = document.querySelectorAll('.reminder-check:checked').length;
        if (!count) { event.preventDefault(); alert('Select at least one client.'); return; }
        if (!confirm('Send SMS to ' + count + ' client(s)?')) event.preventDefault();
    });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
