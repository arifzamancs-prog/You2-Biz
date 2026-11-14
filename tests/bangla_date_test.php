<?php
// Checks includes/bangla_date_helper.php against fixed dates of the Bangladesh
// calendar revised in 2019 (Bangla Academy / Ministry of Public Administration).
// Run: php tests/bangla_date_test.php
require __DIR__ . '/../includes/bangla_date_helper.php';
date_default_timezone_set('Asia/Dhaka');

$cases = [
    // National days the 2019 revision fixed to the same Bangla date every year.
    ['2026-02-21', '৮ ফাল্গুন ১৪৩২'],   // International Mother Language Day
    ['2026-03-26', '১২ চৈত্র ১৪৩২'],    // Independence Day
    ['2026-04-14', '১ বৈশাখ ১৪৩৩'],     // Pohela Boishakh
    ['2026-08-15', '৩১ শ্রাবণ ১৪৩৩'],    // National Mourning Day
    ['2026-12-16', '১ পৌষ ১৪৩৩'],       // Victory Day
    ['2027-02-21', '৮ ফাল্গুন ১৪৩৩'],
    ['2027-12-16', '১ পৌষ ১৪৩৪'],
    // Ashwin had 31 days for the first time on 16 Oct 2019.
    ['2019-10-16', '৩১ আশ্বিন ১৪২৬'],
    // Published dates for 1432.
    ['2025-10-26', '১০ কার্তিক ১৪৩২'],
    ['2025-11-18', '৩ অগ্রহায়ণ ১৪৩২'],
    // Year boundary and leap-year Falgun (30 days when February has 29).
    ['2026-04-13', '৩০ চৈত্র ১৪৩২'],
    ['2024-03-14', '৩০ ফাল্গুন ১৪৩০'],
    ['2024-03-15', '১ চৈত্র ১৪৩০'],
    ['2025-03-14', '২৯ ফাল্গুন ১৪৩১'],
    ['2025-03-15', '১ চৈত্র ১৪৩১'],
];

$failed = 0;
foreach ($cases as [$gregorian, $expected]) {
    $b = bangla_calendar_date(new DateTimeImmutable($gregorian));
    $actual = bangla_date_digits($b['day']) . ' ' . $b['month'] . ' ' . bangla_date_digits($b['year']);
    $ok = $actual === $expected;
    $failed += $ok ? 0 : 1;
    echo ($ok ? 'PASS' : 'FAIL') . ": {$gregorian} => {$actual}" . ($ok ? '' : " (expected {$expected})") . "\n";
}

// Every year 1990–2100 must have 365/366 days, start on 14 April and never skip a day.
for ($year = 1990; $year <= 2100; $year++) {
    $day = new DateTimeImmutable("{$year}-04-14");
    $previous = null;
    for ($i = 0; $i < 366; $i++, $day = $day->modify('+1 day')) {
        $b = bangla_calendar_date($day);
        if ($previous && !($b['day'] === $previous['day'] + 1 || $b['day'] === 1)) {
            echo "FAIL: {$day->format('Y-m-d')} jumps from day {$previous['day']} to {$b['day']}\n";
            $failed++;
            break;
        }
        $previous = $b;
    }
}
echo $failed ? "{$failed} check(s) failed\n" : "All Bangla date checks passed\n";
exit($failed ? 1 : 0);
