<?php

declare(strict_types=1);

final class SchemaMigrator
{
    public static function executeFile(PDO $pdo, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException(basename($file) . ' konnte nicht gelesen werden.');
        }

        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }

    public static function migrate(PDO $pdo, string $directory): array
    {
        self::ensureTrackingTable($pdo);

        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $alreadyApplied = $pdo->query('SELECT migration_name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $known = array_fill_keys(array_map('strval', $alreadyApplied), true);
        $applied = [];

        foreach ($files as $file) {
            $name = basename($file);
            if (isset($known[$name])) {
                continue;
            }

            self::executeFile($pdo, $file);
            $statement = $pdo->prepare('INSERT INTO schema_migrations (migration_name) VALUES (:name)');
            $statement->execute(['name' => $name]);
            $applied[] = $name;
        }

        return $applied;
    }

    public static function status(PDO $pdo, string $directory): array
    {
        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $available = array_map('basename', $files);
        $applied = [];
        try {
            $applied = array_map('strval', $pdo->query('SELECT migration_name FROM schema_migrations ORDER BY migration_name')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            $applied = [];
        }
        return [
            'available' => $available,
            'applied' => $applied,
            'pending' => array_values(array_diff($available, $applied)),
        ];
    }

    private static function ensureTrackingTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration_name VARCHAR(190) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
