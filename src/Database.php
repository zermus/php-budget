<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = App::config('db.host');
            $name = App::config('db.name');

            self::$pdo = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                App::config('db.user'),
                App::config('db.pass'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }

        return self::$pdo;
    }

    /**
     * Server-level connection without selecting a database (installer only).
     */
    public static function serverPdo(): PDO
    {
        $host = App::config('db.host');

        return new PDO(
            "mysql:host={$host};charset=utf8mb4",
            App::config('db.user'),
            App::config('db.pass'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
}
