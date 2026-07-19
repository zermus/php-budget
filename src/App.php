<?php

declare(strict_types=1);

namespace App;

final class App
{
    public const VERSION = '0.2-beta';
    public const SCHEMA_VERSION = 2;

    /** @var array<string, mixed> */
    private static array $config = [];

    /** @param array<string, mixed> $config */
    public static function init(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Fetch a config value by dot path, e.g. config('db.host').
     */
    public static function config(string $path, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
