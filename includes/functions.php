<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Escape HTML
|--------------------------------------------------------------------------
|
| Safely escapes values before rendering them in HTML.
| Accepts strings, integers, floats, booleans and null.
|
*/

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }

    if (!is_scalar($value)) {
        return '';
    }

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/

function csrf_token(): string
{
    if (
        !isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf_token'];
}


/*
|--------------------------------------------------------------------------
| Verify CSRF Token
|--------------------------------------------------------------------------
*/

function verify_csrf_token(?string $token): bool
{
    if (
        !isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token']) ||
        !is_string($token)
    ) {
        return false;
    }

    return hash_equals(
        $_SESSION['csrf_token'],
        $token
    );
}