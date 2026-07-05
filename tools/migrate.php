<?php

declare(strict_types=1);

/**
 * Migration runner. Applies SQL files in order and records completed migrations for repeat-safe setup.
 */

use App\Core\Database;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$db = new Database($config['database']);
$pdo = $db->pdo();

$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    $exists = $db->fetch("SHOW TABLES LIKE 'schema_migrations'");
    if ($exists) {
        $applied = $db->fetch('SELECT id FROM schema_migrations WHERE migration = ?', [$name]);
        if ($applied) {
            echo "Skipping $name\n";
            continue;
        }
    }

    echo "Applying $name\n";
    $sql = file_get_contents($file);
    $pdo->exec($sql);
    $db->execute('INSERT IGNORE INTO schema_migrations(migration, applied_at) VALUES(?, UTC_TIMESTAMP())', [$name]);
}

echo "Migrations complete.\n";

