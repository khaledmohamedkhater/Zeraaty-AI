<?php
declare(strict_types=1);

/** Parse a strict inclusive date range and return its SQL half-open bounds. */
function parse_irrigation_report_period(mixed $startInput, mixed $endInput, int $maxDays = 366): ?array
{
    if ($maxDays < 1) {
        return null;
    }

    $startDate = parse_irrigation_report_date($startInput);
    $endDate = parse_irrigation_report_date($endInput);
    if ($startDate === null || $endDate === null || $startDate > $endDate) {
        return null;
    }

    // MySQL DATETIME begins at 1000-01-01. Keep the exclusive bound representable.
    $minimumDate = new DateTimeImmutable('1000-01-01');
    $exclusiveMaximumDate = new DateTimeImmutable('9999-12-31');
    if ($startDate < $minimumDate || $endDate >= $exclusiveMaximumDate) {
        return null;
    }

    $inclusiveDays = (int) $startDate->diff($endDate)->format('%a') + 1;
    if ($inclusiveDays > $maxDays) {
        return null;
    }

    return [
        'start_date' => $startDate->format('Y-m-d'),
        'end_date' => $endDate->format('Y-m-d'),
        'start_at' => $startDate->format('Y-m-d 00:00:00'),
        'end_exclusive' => $endDate->modify('+1 day')->format('Y-m-d 00:00:00'),
        'days' => $inclusiveDays,
    ];
}

function parse_irrigation_report_date(mixed $input): ?DateTimeImmutable
{
    if (!is_string($input) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $input) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $input) {
        return null;
    }

    return $date;
}
