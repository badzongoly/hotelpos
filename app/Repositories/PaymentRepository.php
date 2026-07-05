<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Payment;

final class PaymentRepository extends Repository
{
    public function __construct(Database $db)
    {
        parent::__construct($db, Payment::class);
    }
}
