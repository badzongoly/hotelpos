<?php

declare(strict_types=1);

/**
 * Local first-admin bootstrapper.
 *
 * This script keeps public source control free of shared default credentials. It is
 * intentionally CLI-only and asks the installer to provide a real password during
 * setup. Existing users with the same email are updated instead of duplicated.
 */

use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool can only be run from the command line.\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$db = new Database($config['database']);

$options = getopt('', ['email::', 'name::', 'password::']);
$email = trim((string)($options['email'] ?? prompt('Admin email')));
$name = trim((string)($options['name'] ?? prompt('Admin name', 'System Administrator')));
$password = (string)($options['password'] ?? getenv('HOTELPOS_ADMIN_PASSWORD') ?: prompt('Admin password'));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid email address is required.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = $db->fetch('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);

if ($existing) {
    $db->execute(
        'UPDATE users SET name = ?, role = "administrator", password_hash = ?, active = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?',
        [$name, $hash, (int)$existing['id']]
    );
    echo "Administrator updated: {$email}\n";
    exit(0);
}

$db->execute(
    'INSERT INTO users(email, password_hash, name, role, active, created_at) VALUES(?, ?, ?, "administrator", 1, UTC_TIMESTAMP())',
    [$email, $hash, $name]
);

echo "Administrator created: {$email}\n";

function prompt(string $label, string $default = ''): string
{
    $suffix = $default === '' ? '' : " [{$default}]";
    fwrite(STDOUT, "{$label}{$suffix}: ");
    $value = trim((string)fgets(STDIN));
    return $value === '' ? $default : $value;
}
