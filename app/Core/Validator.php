<?php

declare(strict_types=1);

/**
 * Small validation helper for common request checks used by controllers before service calls.
 */

namespace App\Core;

final class Validator
{
    public static function required(array $input, array $fields): array
    {
        $errors = [];
        foreach ($fields as $field) {
            if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
                $errors[$field] = 'Required.';
            }
        }
        return $errors;
    }

    public static function positiveMoney(mixed $value): bool
    {
        return is_numeric($value) && (float)$value > 0;
    }

    public static function enum(mixed $value, array $allowed): bool
    {
        return is_string($value) && in_array($value, $allowed, true);
    }
}

