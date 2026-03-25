<?php

namespace AdManager;

use PDO;

class DB
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance) return self::$instance;

        $dbPath = getenv('ADMANAGER_DB_PATH') ?: dirname(__DIR__) . '/db/admanager.db';
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);

        self::$instance = new PDO("sqlite:{$dbPath}", null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$instance->exec('PRAGMA journal_mode=WAL');
        self::$instance->exec('PRAGMA foreign_keys=ON');

        return self::$instance;
    }

    public static function init(): void
    {
        $db = self::get();
        $schema = file_get_contents(dirname(__DIR__) . '/db/schema.sql');
        $db->exec($schema);
    }

    /**
     * Reset the singleton instance (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
