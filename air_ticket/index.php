<?php
// Dummy Air Ticket: the Biz-Flights app runs inside the You2-Biz layout.
// It is shown in an iframe so its own styles never clash with the admin theme.
require_once __DIR__ . '/bootstrap.php';

require_once '../includes/header.php';
require_once '../includes/navbar.php';
require_once '../includes/sidebar.php';
?>
<style>
    .air-ticket-frame-wrap{border-radius:.5rem;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12)}
    .air-ticket-frame{display:block;width:100%;height:calc(100vh - 150px);min-height:560px;border:0}
</style>
<div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
    <h5 class="mb-2 mb-sm-0"><i class="fas fa-plane-departure mr-2 text-primary"></i>Dummy Air Ticket</h5>
    <div>
        <span class="badge <?= air_ticket_duffel_token() !== '' ? 'badge-success' : 'badge-warning' ?>">
            <?= air_ticket_duffel_token() !== '' ? 'Live flight search connected' : 'Demo mode – sample flights only' ?>
        </span>
    </div>
</div>
<div class="air-ticket-frame-wrap">
    <iframe class="air-ticket-frame" src="<?= htmlspecialchars(app_path('air_ticket/app/index.php')) ?>" title="Dummy Air Ticket"></iframe>
</div>
<p class="small text-muted mt-2 mb-0">
    Itineraries prepared here are not airline reservations. A ticket is confirmed only after a real booking with an airline or authorised provider.
</p>
<?php require_once '../includes/footer.php'; ?>
