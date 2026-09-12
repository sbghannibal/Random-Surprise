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

function isValidHttpsUrl(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    return (string)parse_url($url, PHP_URL_SCHEME) === 'https';
}

function addFormError(array &$errors, array &$fieldErrors, ?string $field, string $message): void
{
    $errors[] = $message;

    if ($field === null) {
        return;
    }

    $fieldErrors[$field] ??= [];
    $fieldErrors[$field][] = $message;
}

function firstFieldError(array $fieldErrors, string $field): ?string
{
    if (!isset($fieldErrors[$field][0])) {
        return null;
    }

    return (string)$fieldErrors[$field][0];
}

function fieldErrorClass(array $fieldErrors, string $field, string $baseClass = 'form-control'): string
{
    return firstFieldError($fieldErrors, $field) !== null ? $baseClass . ' is-invalid' : $baseClass;
}

function fieldErrorId(string $field): string
{
    return 'field-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $field);
}

function renderErrorSummary(
    array $errors,
    array $fieldErrors = [],
    string $title = 'Opslaan mislukt. Controleer de gemarkeerde velden.',
    string $summaryId = 'form-error-summary'
): void
{
    if ($errors === []) {
        return;
    }

    $errorLinks = [];
    foreach ($fieldErrors as $field => $messages) {
        foreach ($messages as $message) {
            if (!isset($errorLinks[$message])) {
                $errorLinks[$message] = '#' . fieldErrorId((string)$field);
            }
        }
    }

    echo '<div class="alert alert-danger" role="alert" tabindex="-1" id="' . h($summaryId) . '">';
    echo '<div class="fw-semibold mb-2">' . h($title) . '</div>';

    $uniqueErrors = array_values(array_unique($errors));

    if (count($uniqueErrors) === 1) {
        $message = (string)$uniqueErrors[0];
        if (isset($errorLinks[$message])) {
            echo '<div><a class="alert-link" href="' . h($errorLinks[$message]) . '">' . h($message) . '</a></div>';
        } else {
            echo '<div>' . h($message) . '</div>';
        }
    } else {
        echo '<ul class="mb-0 ps-3">';
        foreach ($uniqueErrors as $error) {
            $message = (string)$error;
            if (isset($errorLinks[$message])) {
                echo '<li><a class="alert-link" href="' . h($errorLinks[$message]) . '">' . h($message) . '</a></li>';
            } else {
                echo '<li>' . h($message) . '</li>';
            }
        }
        echo '</ul>';
    }

    echo '</div>';
    echo '<script>window.addEventListener("DOMContentLoaded", function () { document.getElementById("' . h($summaryId) . '")?.focus(); });</script>';
}

function logApplicationError(string $context, \Throwable $e): void
{
    error_log(sprintf('%s: [%s] %s', $context, get_class($e), $e->getMessage()));
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

    $parts = parse_url((string)$validated);
    if (($parts['scheme'] ?? '') !== 'https') {
        throw new RuntimeException('APP_BASE_URL must use https.');
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
