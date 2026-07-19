<?php

declare(strict_types=1);

namespace App\Installer;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Current schema version of the connected database.
     * 0 = fresh, otherwise the stored version.
     */
    public function currentVersion(): int
    {
        if (!$this->tableExists('settings')) {
            return 0;
        }

        $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE name = 'schema_version'");
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return $value !== false ? (int) $value : 0;
    }

    /**
     * Run all pending migrations. Returns descriptions of the ones applied.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $current = $this->currentVersion();
        $applied = [];

        foreach ($this->migrations() as $migration) {
            if ($migration['version'] <= $current) {
                continue;
            }

            ($migration['up'])($this->pdo, $this);
            $this->setVersion($migration['version']);
            $applied[] = "v{$migration['version']}: {$migration['description']}";
        }

        return $applied;
    }

    public function setVersion(int $version): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM settings WHERE name = 'schema_version'");
        $stmt->execute();

        if ((int) $stmt->fetchColumn() > 0) {
            $this->pdo->prepare("UPDATE settings SET value = ? WHERE name = 'schema_version'")
                ->execute([(string) $version]);
        } else {
            $this->pdo->prepare("INSERT INTO settings (name, value) VALUES ('schema_version', ?)")
                ->execute([(string) $version]);
        }
    }

    /**
     * Ordered migration definitions loaded from the migrations/ directory.
     *
     * @return list<array{version: int, description: string, up: callable}>
     */
    private function migrations(): array
    {
        $files = glob(APP_ROOT . '/migrations/*.php') ?: [];
        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $migration = require $file;
            if (is_array($migration) && isset($migration['version'], $migration['up'])) {
                $migrations[] = $migration;
            }
        }

        usort($migrations, static fn (array $a, array $b): int => $a['version'] <=> $b['version']);

        return $migrations;
    }

    // --- information_schema helpers for re-runnable migrations ----------

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
