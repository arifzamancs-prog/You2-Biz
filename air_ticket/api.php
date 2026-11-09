<?php
// Dummy Air Ticket API: PHP port of biz-flight/server.js (Explore Flights).
// Routes (?route=...): health, config, flights/search (POST), flights/more,
// flights/offer, airline-logo (&code=XX). The Duffel token never leaves the server.

$air_ticket_json = true;
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/air_ticket_api_helper.php';

const AIR_TICKET_SEARCH_TTL = 1800;     // a search stays usable for 30 minutes
const AIR_TICKET_RATE_LIMIT = 70;       // requests per minute per session
const AIR_TICKET_LOGO_TTL = 21600;      // fetched logos are cached for 6 hours

function air_respond($code, $data)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function air_call_duffel($endpoint, $method = 'GET', $body = null)
{
    $ch = curl_init(air_ticket_duffel_base_url() . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 42,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . air_ticket_duffel_token(),
            'Duffel-Version: v2',
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ]);
    if($body !== null){
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    if($errno === CURLE_OPERATION_TIMEDOUT){
        throw new RuntimeException('The airline search timed out. Please try again.', 504);
    }
    if($raw === false){
        throw new RuntimeException('Flight provider unavailable.', 502);
    }

    $json = json_decode($raw, true);
    if(!is_array($json)){
        $json = ['errors' => [['message' => 'Provider returned an unexpected response']]];
    }
    if($status < 200 || $status >= 300){
        $messages = [];
        foreach(($json['errors'] ?? []) as $error){
            $text = $error['message'] ?? ($error['title'] ?? ($error['code'] ?? ''));
            if($text !== '') $messages[] = $text;
        }
        throw new RuntimeException($messages ? implode(' / ', $messages) : 'Provider error ' . $status, $status ?: 502);
    }

    return $json;
}

// Searches and the offers they returned are remembered in the PHP session
// (server.js kept them in memory), so offers from other searches are refused.
function air_session_write(callable $change)
{
    session_start();
    $change();
    session_write_close();
}

function air_known_request($request_id)
{
    if(!is_string($request_id) || preg_match('/\Aorq_[A-Za-z0-9_]+\z/', $request_id) !== 1) return false;
    return (int)($_SESSION['air_ticket_searches'][$request_id]['expires'] ?? 0) > time();
}

function air_remember_offers($request_id, $offers)
{
    air_session_write(function () use ($request_id, $offers) {
        $searches = $_SESSION['air_ticket_searches'] ?? [];
        foreach($searches as $id => $search){
            if((int)($search['expires'] ?? 0) <= time()) unset($searches[$id]);
        }
        $entry = $searches[$request_id] ?? ['expires' => time() + AIR_TICKET_SEARCH_TTL, 'offers' => []];
        foreach($offers as $offer){
            if(!empty($offer['id'])) $entry['offers'][$offer['id']] = true;
        }
        $searches[$request_id] = $entry;
        $_SESSION['air_ticket_searches'] = array_slice($searches, -20, null, true);
    });
}

function air_list_offers($request_id, $after = '')
{
    $query = ['offer_request_id' => $request_id, 'limit' => '100', 'sort' => 'total_amount', 'max_connections' => '2'];
    if($after !== '') $query['after'] = $after;

    $result = air_call_duffel('/air/offers?' . http_build_query($query));
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    air_remember_offers($request_id, $data);

    return [
        'offers' => array_map('air_safe_offer', $data),
        'after' => $result['meta']['after'] ?? null,
        'requestId' => $request_id,
    ];
}

function air_rate_limit()
{
    $allowed = true;
    air_session_write(function () use (&$allowed) {
        $rate = $_SESSION['air_ticket_rate'] ?? ['since' => 0, 'count' => 0];
        if(time() - (int)$rate['since'] > 60) $rate = ['since' => time(), 'count' => 0];
        $rate['count']++;
        $_SESSION['air_ticket_rate'] = $rate;
        $allowed = $rate['count'] <= AIR_TICKET_RATE_LIMIT;
    });
    if(!$allowed) air_respond(429, ['error' => 'Too many requests. Please retry shortly.']);
}

function air_send_image($data, $type, $source, $max_age)
{
    header('Content-Type: ' . $type);
    header('Cache-Control: private, max-age=' . (int)$max_age);
    header('X-Logo-Source: ' . $source);
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}

// Airline logo: bundled file first, then a short-lived file cache, then the
// same vetted public sources server.js used. Never a fabricated emblem.
function air_airline_logo($code)
{
    $assets = __DIR__ . '/app/assets/';
    $local = [$assets . "airlines/$code.png", $assets . "airlines/$code.jpg", $assets . "airlines/$code.jpeg"];
    if($code === 'EK') $local[] = $assets . 'EK.jpg';
    foreach($local as $file){
        if(is_file($file) && ($size = filesize($file)) > 100 && $size < 800000){
            $data = file_get_contents($file);
            if($type = air_image_type($data)) air_send_image($data, $type, 'local', 86400);
        }
    }

    $cache_dir = sys_get_temp_dir() . '/you2biz_air_ticket_logos';
    $cache_file = "$cache_dir/$code.img";
    if(is_file($cache_file) && filemtime($cache_file) > time() - AIR_TICKET_LOGO_TTL){
        $data = file_get_contents($cache_file);
        if($type = air_image_type($data)) air_send_image($data, $type, 'cache', 3600);
    }

    air_rate_limit();

    $sources = [];
    if(($airhex = air_ticket_airhex_key()) !== ''){
        $signature = md5("{$code}_350_100_r_{$airhex}");
        $sources['airhex'] = "https://content.airhex.com/content/logos/airlines_{$code}_350_100_r.png?md5apikey={$signature}";
    }
    $sources += [
        'aviasales' => "https://pics.avs.io/350/100/{$code}.png",
        'aviasales-mobile' => "https://ios.aviasales.com/logos/xxhdpi/{$code}.png",
        'airlines-logo-cdn' => "https://cdn.jsdelivr.net/gh/spydogenesis/airlines-logo@main/airlines-logo/200x200_v2/{$code}.png",
        'airlines-logo-raw' => "https://raw.githubusercontent.com/spydogenesis/airlines-logo/main/airlines-logo/200x200_v2/{$code}.png",
        'kiwi' => "https://images.kiwi.com/airlines/128/{$code}.png",
        'wway' => "https://img.wway.io/pics/root/{$code}@png?exar=1&rs=fit:350:100",
    ];

    foreach($sources as $name => $url){
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'You2Biz-AirTicket/1.0 (airline-branding)',
        ]);
        $data = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if($data === false || $status !== 200 || strlen($data) < 250 || strlen($data) > 800000) continue;
        $type = air_image_type($data);
        if(!$type) continue;

        if(!is_dir($cache_dir)) @mkdir($cache_dir, 0700, true);
        @file_put_contents($cache_file, $data, LOCK_EX);
        air_send_image($data, $type, $name, 3600);
    }

    air_respond(404, ['error' => "Verified airline logo for {$code} unavailable. Add air_ticket/app/assets/airlines/{$code}.png or configure a licensed Airhex key."]);
}

// ---------------------------------------------------------------------------

// Long Duffel calls must not lock the rest of You2-Biz for this user.
session_write_close();

$route = (string)($_GET['route'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token = air_ticket_duffel_token();

if($route === 'airline-logo' && $method === 'GET'){
    $code = strtoupper((string)($_GET['code'] ?? ''));
    if(preg_match('/\A[A-Z0-9]{2,3}\z/', $code) !== 1) air_respond(400, ['error' => 'Invalid airline code.']);
    air_airline_logo($code);
}

if($route === 'health' && $method === 'GET'){
    air_respond(200, ['ok' => true, 'service' => 'Dummy Air Ticket (You2-Biz)', 'php' => PHP_VERSION, 'duffelConfigured' => $token !== '', 'localLogoFiles' => true]);
}

if($route === 'config'){
    air_respond(200, [
        'connected' => $token !== '',
        'provider' => 'Duffel',
        'message' => $token !== ''
            ? 'Provider token configured. Search results identify test versus live inventory.'
            : 'Live inventory requires a Duffel API token on the server.',
    ]);
}

air_rate_limit();

if($token === ''){
    air_respond(503, ['error' => 'Live flight inventory is not connected yet. Ask the system administrator to set DUFFEL_ACCESS_TOKEN on the server. Demo flights are still available.']);
}

try{
    if($route === 'flights/search' && $method === 'POST'){
        $raw = file_get_contents('php://input', false, null, 0, 12001);
        if(strlen((string)$raw) > 12000) air_respond(400, ['error' => 'Search request is too large.']);
        $d = json_decode((string)$raw, true);
        if(!is_array($d)) air_respond(400, ['error' => 'Invalid search request.']);

        $legs = $d['legs'] ?? [];
        $valid_legs = is_array($legs) && $legs && count($legs) <= 5;
        foreach(($valid_legs ? $legs : []) as $leg){
            $from = $leg['from'] ?? null;
            $to = $leg['to'] ?? null;
            if(!air_is_iata($from) || !air_is_iata($to) || $from === $to || !air_is_iso_date($leg['date'] ?? null)){
                $valid_legs = false;
                break;
            }
        }
        if(!$valid_legs) air_respond(400, ['error' => 'Enter 1–5 valid routes, airports and travel dates.']);

        $counts = [];
        foreach(['adults', 'children', 'infants'] as $key){
            $n = $d[$key] ?? 0;
            $counts[] = is_numeric($n) && (int)$n == $n ? (int)$n : -1;
        }
        if($counts[0] < 1 || array_sum($counts) > 9 || min($counts) < 0 || $counts[2] > $counts[0]){
            air_respond(400, ['error' => 'Enter a valid number of travelers (maximum nine).']);
        }

        $cabin = str_replace(' ', '_', strtolower(air_capped($d['cabin'] ?? '', 30)));
        if(!in_array($cabin, ['economy', 'premium_economy', 'business', 'first'], true)){
            air_respond(400, ['error' => 'Unsupported cabin class.']);
        }

        $passengers = array_merge(
            array_fill(0, $counts[0], ['type' => 'adult']),
            $counts[1] ? array_fill(0, $counts[1], ['type' => 'child']) : [],
            $counts[2] ? array_fill(0, $counts[2], ['type' => 'infant_without_seat']) : []
        );
        $slices = array_map(fn($leg) => [
            'origin' => strtoupper($leg['from']),
            'destination' => strtoupper($leg['to']),
            'departure_date' => $leg['date'],
        ], array_values($legs));

        $created = air_call_duffel('/air/offer_requests?return_offers=false&supplier_timeout=25000', 'POST', [
            'data' => ['slices' => $slices, 'passengers' => $passengers, 'cabin_class' => $cabin, 'max_connections' => 2],
        ]);
        $request_id = $created['data']['id'] ?? '';
        if(!is_string($request_id) || $request_id === '') throw new RuntimeException('No offer request ID returned by provider.', 502);

        air_session_write(function () use ($request_id) {
            $_SESSION['air_ticket_searches'][$request_id] = ['expires' => time() + AIR_TICKET_SEARCH_TTL, 'offers' => []];
        });
        $offers = air_list_offers($request_id);

        air_respond(200, $offers + [
            'liveMode' => ($created['data']['live_mode'] ?? false) === true,
            'source' => 'Duffel',
            'searchedAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    if($route === 'flights/more' && $method === 'GET'){
        $request_id = (string)($_GET['requestId'] ?? '');
        $after = (string)($_GET['after'] ?? '');
        if(!air_known_request($request_id)) air_respond(400, ['error' => 'The search expired. Please run a new search.']);
        if(strlen($after) > 2500) air_respond(400, ['error' => 'Invalid page cursor.']);
        air_respond(200, air_list_offers($request_id, $after));
    }

    if($route === 'flights/offer' && $method === 'GET'){
        $id = (string)($_GET['id'] ?? '');
        $request_id = (string)($_GET['requestId'] ?? '');
        if(
            !air_known_request($request_id) ||
            preg_match('/\Aoff_[A-Za-z0-9_]+\z/', $id) !== 1 ||
            empty($_SESSION['air_ticket_searches'][$request_id]['offers'][$id])
        ){
            air_respond(400, ['error' => 'Invalid or expired offer.']);
        }
        $offer = air_call_duffel('/air/offers/' . rawurlencode($id));
        $offer_request = $offer['data']['offer_request_id'] ?? null;
        if($offer_request && $offer_request !== $request_id) air_respond(400, ['error' => 'Offer does not belong to this search.']);
        air_respond(200, ['offer' => air_safe_offer($offer['data'] ?? [])]);
    }

    air_respond(404, ['error' => 'Unknown API endpoint.']);
}catch(RuntimeException $e){
    error_log('Dummy Air Ticket API: ' . $e->getMessage());
    $code = (int)$e->getCode();
    air_respond($code >= 400 && $code < 600 ? $code : 502, ['error' => $e->getMessage() ?: 'Flight provider unavailable.']);
}
