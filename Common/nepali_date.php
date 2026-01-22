<?php
/**
 * nepali_date.php
 *
 * Helper functions for Nepali (Bikram Sambat) dates.
 * ----------------------------------------------------
 * 1. ad_to_bs()          – AD → BS (YYYY-MM-DD)
 * 2. nepali_date_time()  – Current Kathmandu datetime in BS + time
 * 3. get_fiscal_year()   – BS date → fiscal year string (e.g. 2081/82)
 *
 * Requires: composer require nilambar/nepali-date
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nilambar\NepaliDate\NepaliDate;

/**
 * Convert AD date (YYYY-MM-DD) → BS date (YYYY-MM-DD)
 */
function ad_to_bs(string $adDate): string
{
    [$year, $month, $day] = explode('-', $adDate);
    $nepali = new NepaliDate();
    $bs     = $nepali->convertAdToBs((int)$year, (int)$month, (int)$day);

    return sprintf('%04d-%02d-%02d', $bs['year'], $bs['month'], $bs['day']);
}

/**
 * Current Kathmandu datetime in BS + time (YYYY-MM-DD HH:MM:SS)
 */
function nepali_date_time(): string
{
    $nepali = new NepaliDate();
    $now    = new DateTime('now', new DateTimeZone('Asia/Kathmandu'));

    $bs = $nepali->convertAdToBs(
        (int)$now->format('Y'),
        (int)$now->format('m'),
        (int)$now->format('d')
    );

    return sprintf(
        '%04d-%02d-%02d %02d:%02d:%02d',
        $bs['year'],
        $bs['month'],
        $bs['day'],
        $now->format('H'),
        $now->format('i'),
        $now->format('s')
    );
}

/**
 * Return Nepali fiscal year for a BS date (YYYY-MM-DD)
 *
 * Rules (Nepal):
 *   • Month 1-3  → previous fiscal year  (e.g. 2082-03-30 → 2081/82)
 *   • Month 4-12 → current fiscal year   (e.g. 2082-04-01 → 2082/83)
 *
 * @param string $bsDate  BS date in YYYY-MM-DD format
 * @return string         Fiscal year in format YYYY/YY
 */
function get_fiscal_year(string $bsDate): string
{
    [$year, $month, $day] = explode('-', $bsDate);

    $year  = (int)$year;
    $month = (int)$month;

    if ($month >= 1 && $month <= 3) {
        // 1-3 → previous / current
        $fyStart = $year - 1;
        $fyEnd   = $year;
    } else {
        // 4-12 → current / next
        $fyStart = $year;
        $fyEnd   = $year + 1;
    }

    // Return as 2081/82 (last two digits of end year)
    return $fyStart . '/' . substr($fyEnd, -2);
}