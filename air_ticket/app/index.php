<?php
// The Biz-Flights page (originally biz-flight/index.html), served by PHP so
// only a permitted company administrator can open it.
require_once __DIR__ . '/../bootstrap.php';

$air_ticket_config = [
    'api' => app_path('air_ticket/api.php') . '?route=',
    'assets' => app_path('air_ticket/app/assets/'),
];
// Cache-busting so browsers pick up new versions of the bundled files.
$v = static fn($file) => rawurlencode((string)@filemtime(__DIR__ . '/' . $file));
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#09234b" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Dummy Air Ticket | Flight Search & Travel Itineraries</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="icon" type="image/svg+xml" href="favicon.svg?v=<?= $v('favicon.svg') ?>" />
    <link rel="stylesheet" href="styles.css?v=<?= $v('styles.css') ?>" />
    <script>window.AIR_TICKET = <?= json_encode($air_ticket_config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
  </head>
  <body>
    <div id="app"></div>
    <script src="pdf-export.js?v=<?= $v('pdf-export.js') ?>" defer></script>
    <script src="passport-ocr.js?v=<?= $v('passport-ocr.js') ?>" defer></script>
    <script src="passport-crop.js?v=<?= $v('passport-crop.js') ?>" defer></script>
    <script src="app.js?v=<?= $v('app.js') ?>" defer></script>
  </body>
</html>
