<?php
declare(strict_types=1);

/** Validate a positive water amount for DECIMAL(12,2) without floats. */
function is_valid_irrigation_amount(mixed $amount): bool
{
    if (!is_string($amount)) {
        return false;
    }

    if (preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $amount) !== 1) {
        return false;
    }

    [$wholePart, $fractionPart] = array_pad(explode('.', $amount, 2), 2, '');
    $wholePart = ltrim($wholePart, '0');
    $wholePart = $wholePart === '' ? '0' : $wholePart;
    $fractionPart = str_pad($fractionPart, 2, '0');

    if (strlen($wholePart) > 10 || (strlen($wholePart) === 10 && strcmp($wholePart, '9999999999') > 0)) {
        return false;
    }

    return $wholePart !== '0' || $fractionPart !== '00';
}
