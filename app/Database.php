<?php
declare(strict_types=1);

namespace VibrantHub;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $databasePath = dirname(__DIR__) . '/database/database.sqlite';

        try {
            self::$connection = new PDO(
                'sqlite:' . $databasePath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            self::$connection->exec('PRAGMA foreign_keys = ON');
            self::$connection->exec('PRAGMA journal_mode = WAL');

            return self::$connection;
        } catch (PDOException $e) {
            http_response_code(500);
            die('Database connection failed.');
        }
    }
}
