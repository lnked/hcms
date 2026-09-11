<?php

declare(strict_types=1);

namespace Cms\Core;

final class Env
{
    /** @var array<string, string> */
    private array $values = [];

    public function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $this->values[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (\array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        if (isset($_ENV[$key]) && \is_string($_ENV[$key])) {
            return $_ENV[$key];
        }

        $fromEnv = getenv($key);
        if (!\is_string($fromEnv)) {
            return $default;
        }

        return $fromEnv;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
