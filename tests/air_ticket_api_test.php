<?php
// Dummy Air Ticket: checks the PHP port of biz-flight/server.js helpers.
// Run: C:\xampp\php\php.exe tests/air_ticket_api_test.php
if(PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/air_ticket_api_helper.php';

function check($label, $actual, $expected)
{
    if($actual !== $expected){
        throw new Exception($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

// Durations are shown exactly as server.js flightDuration() shows them.
check('hours and minutes', air_flight_duration('PT7H30M'), '7h 30m');
check('pads minutes', air_flight_duration('PT2H5M'), '2h 05m');
check('days are ignored like server.js', air_flight_duration('P1DT2H'), '2h 00m');
check('minutes only', air_flight_duration('PT45M'), '0h 45m');
check('empty', air_flight_duration(''), '');
check('not a duration', air_flight_duration('7 hours'), '');

check('valid IATA', air_is_iata('DAC'), true);
check('lowercase IATA accepted', air_is_iata('dxb'), true);
check('too short', air_is_iata('DA'), false);
check('not a string', air_is_iata(123), false);
check('valid date', air_is_iso_date('2026-12-20'), true);
check('impossible date', air_is_iso_date('2026-02-30'), false);
check('wrong format', air_is_iso_date('20-12-2026'), false);

check('png detected', air_image_type("\x89PNG\r\n\x1a\nrest"), 'image/png');
check('jpeg detected', air_image_type("\xFF\xD8\xFFrest"), 'image/jpeg');
check('html rejected', air_image_type('<html>'), null);

// A trimmed Duffel offer (field names as in the Duffel v2 API).
$duffel_offer = [
    'id' => 'off_0000AbC',
    'total_amount' => '612.40',
    'total_currency' => 'USD',
    'expires_at' => '2026-12-01T10:00:00Z',
    'total_duration' => 'PT9H5M',
    'owner' => ['name' => 'should not be passed on'],
    'slices' => [[
        'duration' => 'PT9H5M',
        'segments' => [[
            'marketing_carrier_flight_number' => '585',
            'marketing_carrier' => ['name' => 'Emirates', 'iata_code' => 'EK'],
            'operating_carrier' => ['name' => 'Emirates', 'iata_code' => 'EK'],
            'origin' => ['iata_code' => 'DAC', 'name' => 'Hazrat Shahjalal International Airport', 'city_name' => 'Dhaka'],
            'destination' => ['iata_code' => 'DXB', 'name' => 'Dubai International Airport', 'city' => ['name' => 'Dubai']],
            'departing_at' => '2026-12-20T10:15:00',
            'arriving_at' => '2026-12-20T13:20:00',
            'aircraft' => ['name' => 'Boeing 777-300ER'],
            'origin_terminal' => '1',
            'destination_terminal' => '3',
            'duration' => 'PT5H5M',
            'passengers' => [['baggages' => [
                ['type' => 'checked', 'quantity' => 2],
                ['type' => 'carry_on', 'quantity' => 1],
            ]]],
        ]],
    ]],
];

$safe = air_safe_offer($duffel_offer);
check('offer id', $safe['id'], 'off_0000AbC');
check('amount', $safe['amount'], '612.40');
check('currency', $safe['currency'], 'USD');
check('total duration', $safe['totalDuration'], '9h 05m');
check('no extra provider fields', array_key_exists('owner', $safe), false);
$segment = $safe['slices'][0]['segments'][0];
check('flight number', $segment['flightNumber'], '585');
check('airline', $segment['marketingAirline'], 'Emirates');
check('airline code', $segment['marketingCode'], 'EK');
check('origin city from city_name', $segment['origin']['city'], 'Dhaka');
check('destination city from city.name', $segment['destination']['city'], 'Dubai');
check('aircraft', $segment['aircraft'], 'Boeing 777-300ER');
check('segment duration', $segment['duration'], '5h 05m');
check('baggage text', $segment['baggage'], '2 checked bag(s), 1 carry-on bag(s)');

$empty = air_safe_offer([]);
check('empty offer has no slices', $empty['slices'], []);
check('empty offer duration', $empty['totalDuration'], '');

echo "Dummy Air Ticket API helper tests passed.\n";
