<?php
// Bangla (Bangabda) calendar as used in Bangladesh, revised 2019:
// Pohela Boishakh = 14 April; Boishakh–Ashwin have 31 days, the rest 30,
// Falgun 29 (30 when that February is in a Gregorian leap year).

function bangla_date_digits($value)
{
    return strtr((string)$value, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
}

function bangla_weekday_name(DateTimeInterface $date)
{
    $days = ['রবিবার', 'সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার'];
    return $days[(int)$date->format('w')];
}

// Returns ['day' => int, 'month' => string, 'year' => int].
function bangla_calendar_date(DateTimeInterface $date)
{
    $months = ['বৈশাখ', 'জ্যৈষ্ঠ', 'আষাঢ়', 'শ্রাবণ', 'ভাদ্র', 'আশ্বিন', 'কার্তিক', 'অগ্রহায়ণ', 'পৌষ', 'মাঘ', 'ফাল্গুন', 'চৈত্র'];
    $year = (int)$date->format('Y');
    $start_year = $date->format('m-d') >= '04-14' ? $year : $year - 1;
    $start = new DateTimeImmutable(sprintf('%04d-04-14', $start_year), $date->getTimezone());
    $today = new DateTimeImmutable($date->format('Y-m-d'), $date->getTimezone());
    $offset = (int)$start->diff($today)->days;

    $next = $start_year + 1;
    $leap = ($next % 4 === 0 && $next % 100 !== 0) || $next % 400 === 0;
    $lengths = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, $leap ? 30 : 29, 30];
    $month = 0;
    while ($month < 11 && $offset >= $lengths[$month]) {
        $offset -= $lengths[$month];
        $month++;
    }
    return ['day' => $offset + 1, 'month' => $months[$month], 'year' => $start_year - 593];
}

// "শুক্রবার, ২৯ কার্তিক ১৪৩৩"
function bangla_date_text(?DateTimeInterface $date = null)
{
    $date = $date ?: new DateTimeImmutable('now');
    $b = bangla_calendar_date($date);
    return bangla_weekday_name($date) . ', ' . bangla_date_digits($b['day']) . ' ' . $b['month'] . ' ' . bangla_date_digits($b['year']);
}
