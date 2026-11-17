<?php
// Print of Visa Processing entries: one entry (?id=) as a full form, or a list
// (?ids=1,2,3 for selected rows, else every entry matching ?q=) as a table.
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/visa_processing_helper.php';

if (!visa_process_schema($conn)) {
    exit('Visa processing tables could not be prepared.');
}
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fields = visa_process_fields($conn, $user_id);
$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, phone, email, address, avatar FROM users WHERE id={$user_id}")) ?: [];
$logo = '';
if (!empty($company['avatar']) && is_file(__DIR__ . '/../uploads/avatars/' . basename($company['avatar']))) {
    $logo = app_path('uploads/avatars/' . rawurlencode(basename($company['avatar'])));
}

$single = null;
$rows = [];
if ((int)($_GET['id'] ?? 0) > 0) {
    $single = visa_process_entry($conn, $user_id, (int)$_GET['id']);
    if (!$single) {
        exit('Entry not found.');
    }
} else {
    $ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))));
    $search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    // A printed list reads from SL 1 upwards.
    $rows = array_reverse(visa_process_search($conn, $user_id, $ids ? '' : $search, 5000, $ids));
    $fields = array_values(array_filter($fields, static fn($f) => (int)$f['show_in_list'] === 1));
}
$wide = !$single && count($fields) > 7;
// Single entry: fields in two columns; long text takes a full row.
$pairs = [];
if ($single) {
    $row = [];
    foreach ($fields as $field) {
        $cell = [$field['label'], visa_process_display($field, $single['values'][$field['field_key']] ?? '')];
        if ($field['field_type'] === 'textarea') {
            if ($row) { $pairs[] = $row; $row = []; }
            $pairs[] = [$cell, null, true];
            continue;
        }
        $row[] = $cell;
        if (count($row) === 2) { $pairs[] = $row; $row = []; }
    }
    if ($row) { $pairs[] = [$row[0], null]; }
}
$address = trim(implode(' | ', array_filter([$company['address'] ?? '', !empty($company['phone']) ? 'Phone: ' . $company['phone'] : '', !empty($company['email']) ? 'Email: ' . $company['email'] : ''])));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $single ? 'Visa Processing - SL ' . (int)$single['serial_no'] : 'Visa Processing List'; ?></title>
<style>
@page{size:A4 <?= $wide ? 'landscape' : 'portrait'; ?>;margin:12mm}
:root{--ink:#1d2b3a;--accent:#14466e;--soft:#eef3f8;--line:#c9d3dd}
*{box-sizing:border-box}
body{font-family:"Segoe UI",Arial,Helvetica,sans-serif;color:var(--ink);margin:0;background:#e9edf1;font-size:13px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.sheet{background:#fff;width:<?= $wide ? '277mm' : '186mm'; ?>;min-height:<?= $wide ? '186mm' : '270mm'; ?>;margin:16px auto;padding:10mm;box-shadow:0 2px 12px rgba(0,0,0,.12);display:flex;flex-direction:column}
.head{display:flex;align-items:center;gap:16px;padding-bottom:12px;border-bottom:3px solid var(--accent)}
.head img{height:64px;max-width:150px;object-fit:contain}
.head .company{flex:1}
.head .name{font-size:24px;font-weight:700;color:var(--accent);letter-spacing:.02em;line-height:1.15}
.head .meta{font-size:11.5px;color:#55616d;margin-top:4px}
.band{margin:16px 0 14px;background:var(--accent);color:#fff;text-align:center;font-size:15px;font-weight:700;letter-spacing:.18em;padding:8px 0;border-radius:3px}
.facts{display:flex;gap:10px;margin-bottom:16px}
.fact{flex:1;border:1px solid var(--line);border-radius:4px;padding:7px 10px;background:var(--soft)}
.fact .k{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#6b7785}
.fact .v{font-size:15px;font-weight:700;margin-top:2px}
.section{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--accent);border-bottom:1px solid var(--line);padding-bottom:4px;margin-bottom:8px}
table{width:100%;border-collapse:collapse}
.details td{border:1px solid var(--line);padding:8px 10px;vertical-align:top}
.details td.k{width:19%;background:var(--soft);font-weight:600;color:#3b4855;font-size:12px}
.details td.v{width:31%;font-size:13.5px}
.sign{display:flex;justify-content:space-between;gap:30px;margin-top:auto;padding-top:60px}
.sign div{flex:0 1 58mm;border-top:1.5px solid var(--ink);padding-top:6px;text-align:center;font-size:12px;font-weight:600}
.foot{display:flex;justify-content:space-between;border-top:1px solid var(--line);margin-top:22px;padding-top:6px;font-size:10px;color:#7a8591}
.list th{background:var(--accent);color:#fff;font-weight:600;border:1px solid var(--accent);padding:6px;text-align:left;font-size:<?= $wide ? '10.5px' : '11.5px'; ?>}
.list td{border:1px solid var(--line);padding:5px 6px;font-size:<?= $wide ? '10.5px' : '11.5px'; ?>}
.list tbody tr:nth-child(even) td{background:#f6f8fb}
.list td.sl{font-weight:700;text-align:center;width:42px}
.list-meta{display:flex;justify-content:space-between;font-size:11.5px;color:#55616d;margin-bottom:8px}
.tools{text-align:center;margin:14px}
.tools button,.tools a{font-size:14px;padding:7px 18px;margin:0 4px;cursor:pointer;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:4px;text-decoration:none;display:inline-block;font-family:inherit}
.tools a{background:#fff;color:var(--accent)}
@media print{
  body{background:#fff}
  .sheet{width:auto;min-height:<?= $wide ? '184mm' : '271mm'; ?>;margin:0;padding:0;box-shadow:none}
  .tools{display:none}
  tr{break-inside:avoid}
  thead{display:table-header-group}
}
</style>
</head>
<body>
<div class="tools">
    <button type="button" onclick="window.print()">Print</button>
    <a href="visa_processing.php">Back to list</a>
</div>
<div class="sheet">
    <div class="head">
        <?php if ($logo !== '') { ?><img src="<?= $h($logo); ?>" alt=""><?php } ?>
        <div class="company">
            <div class="name"><?= $h($company['name'] ?? ''); ?></div>
            <?php if ($address !== '') { ?><div class="meta"><?= $h($address); ?></div><?php } ?>
        </div>
    </div>

<?php if ($single) { ?>
    <div class="band">VISA PROCESSING FORM</div>
    <div class="facts">
        <div class="fact"><div class="k">SL No.</div><div class="v"><?= (int)$single['serial_no']; ?></div></div>
        <div class="fact"><div class="k">Entry Date</div><div class="v"><?= $h(date('d M Y', strtotime($single['created_at']))); ?></div></div>
        <div class="fact"><div class="k">Printed On</div><div class="v"><?= date('d M Y'); ?></div></div>
    </div>
    <div class="section">Applicant Details</div>
    <table class="details">
        <?php foreach ($pairs as $pair) { ?>
            <tr>
                <?php if (!empty($pair[2])) { ?>
                    <td class="k"><?= $h($pair[0][0]); ?></td><td class="v" colspan="3"><?= nl2br($h($pair[0][1])); ?></td>
                <?php } else { ?>
                    <td class="k"><?= $h($pair[0][0]); ?></td><td class="v"><?= $h($pair[0][1]); ?></td>
                    <?php if ($pair[1]) { ?><td class="k"><?= $h($pair[1][0]); ?></td><td class="v"><?= $h($pair[1][1]); ?></td><?php } else { ?><td class="k"></td><td class="v"></td><?php } ?>
                <?php } ?>
            </tr>
        <?php } ?>
    </table>
    <div class="sign"><div>Applicant Signature</div><div>Prepared By</div><div>Authorized Signature</div></div>
<?php } else { ?>
    <div class="band">VISA PROCESSING LIST</div>
    <div class="list-meta"><span>Total: <strong><?= count($rows); ?></strong> entr<?= count($rows) === 1 ? 'y' : 'ies'; ?><?= !empty($search) && empty($ids) ? ' &nbsp;|&nbsp; Search: ' . $h($search) : ''; ?></span><span>Printed: <?= date('d M Y, h:i A'); ?></span></div>
    <table class="list">
        <thead><tr><th style="text-align:center">SL</th><?php foreach ($fields as $field) { ?><th><?= $h($field['label']); ?></th><?php } ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $row) { ?>
            <tr><td class="sl"><?= (int)$row['serial_no']; ?></td><?php foreach ($fields as $field) { ?><td><?= $h(visa_process_display($field, $row['values'][$field['field_key']] ?? '')); ?></td><?php } ?></tr>
        <?php } ?>
        <?php if (!$rows) { ?><tr><td colspan="<?= count($fields) + 1; ?>" style="text-align:center;color:#777">No entry.</td></tr><?php } ?>
        </tbody>
    </table>
    <div class="sign"><div>Prepared By</div><div>Authorized Signature</div></div>
<?php } ?>
    <div class="foot"><span><?= $h($company['name'] ?? ''); ?></span><span>Powered by You2technologies.com</span></div>
</div>
<?php if (!empty($_GET['auto'])) { ?><script>window.addEventListener("load", function(){ window.print(); });</script><?php } ?>
</body>
</html>
