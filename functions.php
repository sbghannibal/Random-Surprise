<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function generateToken(): string
{
    return bin2hex(random_bytes(32));
}

function isValidDate(?string $date): bool
{
    if ($date === null || $date === '') {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function sendMailSafe(string $to, string $subject, string $message): bool
{
    $to = str_replace(["\r", "\n"], '', $to);
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/plain; charset=UTF-8',
        'From: noreply@random-surprise.local',
    ];

    return mail($to, $subject, $message, implode("\r\n", $headers));
}

function baseUrl(): string
{
    $configured = getenv('APP_BASE_URL');
    if (!$configured) {
        throw new RuntimeException('APP_BASE_URL is not configured.');
    }

    $validated = filter_var($configured, FILTER_VALIDATE_URL);
    if ($validated === false) {
        throw new RuntimeException('APP_BASE_URL is invalid.');
    }

    return rtrim((string)$validated, '/');
}

function absoluteUrl(string $path): string
{
    return baseUrl() . '/' . ltrim($path, '/');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function isValidCsrfToken(?string $token): bool
{
    if ($token === null || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals((string)$_SESSION['csrf_token'], $token);
}
