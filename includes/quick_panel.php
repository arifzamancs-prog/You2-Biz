<?php
// Quick Panel loader (included by footer.php). The panel itself is built by
// assets/js/quick_panel.js from the sidebar menu the user can see.
require_once __DIR__ . '/quick_panel_helper.php';

if (isset($conn) && $conn instanceof mysqli && quick_panel_enabled() && function_exists('stock_csrf_token')) {
    $quick_panel_saved = null;
    try {
        $quick_panel_saved = quick_panel_load($conn);
    } catch (Throwable $error) {
        error_log('Quick panel: ' . $error->getMessage());
    }
    $quick_panel_asset = function ($file) {
        $full = dirname(__DIR__) . '/' . $file;
        return app_path($file) . (is_file($full) ? '?v=' . filemtime($full) : '');
    };
    $quick_panel_config = [
        'api' => app_path('quick_panel/api.php'),
        'root' => app_root_path(),
        'token' => stock_csrf_token(),
        'owner' => (int)($_SESSION['user_id'] ?? 0) . '-' . (int)($_SESSION['login_user_id'] ?? 0),
        'saved' => $quick_panel_saved,
        'supplierLabel' => function_exists('supplier_display_text') ? supplier_display_text('Supplier') : 'Supplier',
        'customerLabel' => (($_SESSION['company_type'] ?? '') === 'Travel Agency') ? 'Client' : 'Customer',
    ];
?>
<link rel="stylesheet" href="<?= htmlspecialchars($quick_panel_asset('assets/css/quick_panel.css')); ?>">
<script>window.QUICK_PANEL = <?= json_encode($quick_panel_config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?= htmlspecialchars($quick_panel_asset('assets/js/quick_panel.js')); ?>"></script>
<?php
}
