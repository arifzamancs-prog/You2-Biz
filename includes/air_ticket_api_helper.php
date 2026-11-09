<?php

// Dummy Air Ticket: pure helpers used by air_ticket/api.php (PHP port of
// biz-flight/server.js). No database, session or network access here, so the
// functions can be unit-tested (tests/air_ticket_api_test.php).

function air_capped($value, $length)
{
    return mb_substr((string)($value ?? ''), 0, $length);
}

function air_is_iata($value)
{
    return is_string($value) && preg_match('/\A[A-Z]{3}\z/', strtoupper($value)) === 1;
}

function air_is_iso_date($value)
{
    if(!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

// ISO-8601 duration (e.g. P1DT2H05M / PT7H30M) -> "7h 30m", same as server.js.
function air_flight_duration($value)
{
    if(!is_string($value) || !preg_match('/\AP(?:\d+D)?T(?:(\d+)H)?(?:(\d+)M)?/', $value, $m)) return '';
    return (int)($m[1] ?? 0) . 'h ' . str_pad((string)(int)($m[2] ?? 0), 2, '0', STR_PAD_LEFT) . 'm';
}

// Only the fields the browser needs, as in server.js safeOffer().
function air_safe_offer($offer)
{
    $slices = is_array($offer['slices'] ?? null) ? $offer['slices'] : [];

    return [
        'id' => $offer['id'] ?? null,
        'amount' => $offer['total_amount'] ?? null,
        'currency' => $offer['total_currency'] ?? null,
        'expiresAt' => $offer['expires_at'] ?? null,
        'totalDuration' => air_flight_duration($offer['total_duration'] ?? ''),
        'slices' => array_map(function ($slice) {
            return [
                'duration' => air_flight_duration($slice['duration'] ?? ''),
                'segments' => array_map(function ($g) {
                    $bags = [];
                    foreach(($g['passengers'] ?? []) as $passenger){
                        foreach(($passenger['baggages'] ?? []) as $bag){
                            $type = $bag['type'] ?? '';
                            $label = $type === 'checked' ? 'checked bag(s)' : ($type === 'carry_on' ? 'carry-on bag(s)' : $type);
                            $bags[] = ($bag['quantity'] ?? 0) . ' ' . $label;
                        }
                    }
                    return [
                        'flightNumber' => air_capped($g['marketing_carrier_flight_number'] ?? ($g['operating_carrier_flight_number'] ?? ''), 10),
                        'marketingAirline' => air_capped($g['marketing_carrier']['name'] ?? '', 120),
                        'marketingCode' => air_capped($g['marketing_carrier']['iata_code'] ?? '', 3),
                        'operatingAirline' => air_capped($g['operating_carrier']['name'] ?? '', 120),
                        'operatingCode' => air_capped($g['operating_carrier']['iata_code'] ?? '', 3),
                        'origin' => [
                            'code' => $g['origin']['iata_code'] ?? '',
                            'name' => $g['origin']['name'] ?? '',
                            'city' => $g['origin']['city_name'] ?? ($g['origin']['city']['name'] ?? ''),
                        ],
                        'destination' => [
                            'code' => $g['destination']['iata_code'] ?? '',
                            'name' => $g['destination']['name'] ?? '',
                            'city' => $g['destination']['city_name'] ?? ($g['destination']['city']['name'] ?? ''),
                        ],
                        'departingAt' => is_string($g['departing_at'] ?? null) ? $g['departing_at'] : '',
                        'arrivingAt' => is_string($g['arriving_at'] ?? null) ? $g['arriving_at'] : '',
                        'aircraft' => $g['aircraft']['name'] ?? '',
                        'originTerminal' => $g['origin_terminal'] ?? '',
                        'destinationTerminal' => $g['destination_terminal'] ?? '',
                        'duration' => air_flight_duration($g['duration'] ?? ''),
                        'baggage' => implode(', ', $bags),
                    ];
                }, is_array($slice['segments'] ?? null) ? $slice['segments'] : []),
            ];
        }, $slices),
    ];
}

function air_image_type($data)
{
    if(strncmp($data, "\x89PNG\r\n\x1a\n", 8) === 0) return 'image/png';
    if(strncmp($data, "\xFF\xD8", 2) === 0) return 'image/jpeg';
    return null;
}
