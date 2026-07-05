<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\User;

final class UserRepository extends Repository
{
    public function __construct(Database $db)
    {
        parent::__construct($db, User::class);
    }

    public function findActiveByEmail(string $email): ?User
    {
        $row = $this->db->fetch('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1', [$email]);
        return $row ? new User($row) : null;
    }
}
