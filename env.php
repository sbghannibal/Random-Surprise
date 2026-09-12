<?php

declare(strict_types=1);

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $path): void
    {
        static $loaded = [];

        if (isset($loaded[$path])) {
            return;
        }

        $loaded[$path] = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '' || str_starts_with($trimmedLine, '#') || !str_contains($trimmedLine, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $trimmedLine, 2);
            $name = trim($name);
            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            $value = trim($value);
            $length = strlen($value);
            if ($length >= 2) {
                $quote = $value[0];
                if (($quote === '"' || $quote === '\'') && $value[$length - 1] === $quote) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

if (!function_exists('env')) {
    function env(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);
        return $value === false ? $default : $value;
    }
}

loadEnvFile(__DIR__ . '/.env');
