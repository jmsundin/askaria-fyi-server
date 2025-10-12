<?php

namespace App\Support;

final class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '1') && strlen($digits) === 11) {
            return '+' . $digits;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        return '+' . $digits;
    }
}

