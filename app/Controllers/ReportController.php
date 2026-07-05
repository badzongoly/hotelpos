<?php

declare(strict_types=1);

/**
 * Report API controller. Manager/auditor-facing summaries use service-layer aggregate queries.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\ReportService;

final class ReportController extends Controller
{
    public function summary(Request $request): void
    {
        $this->role(['administrator', 'manager', 'auditor']);
        $query = $request->query();
        $start = $query['start'] ?? gmdate('Y-m-01');
        $end = $query['end'] ?? gmdate('Y-m-d');
        $this->ok('Report summary loaded.', (new ReportService($this->db))->summary($start, $end));
    }

    public function analytics(Request $request): void
    {
        $user = $this->role(['administrator', 'manager', 'auditor', 'reception']);
        $this->ok('Reports loaded.', (new ReportService($this->db))->analytics($request->query(), (string)$user['role']));
    }
}

