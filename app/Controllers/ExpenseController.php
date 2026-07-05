<?php

declare(strict_types=1);

/**
 * Expense API controller. Expenses are recorded and voided; they are not hard-deleted through normal UI.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Validator;
use App\Services\AuditService;

final class ExpenseController extends Controller
{
    public function index(Request $request): void
    {
        $this->role(['administrator', 'manager', 'auditor']);

        $query = $request->query();
        $page = max(1, (int)($query['page'] ?? 1));
        $perPage = min(50, max(5, (int)($query['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        // Categories are sent with each page so the inline form can be populated
        // even when the expense list is empty or filtered down in the future.
        $categories = $this->db->fetchAll('SELECT * FROM expense_categories WHERE active = 1 ORDER BY name');

        // Expenses are paginated server-side to keep the page fast as operating
        // history grows. The UI intentionally hides description and void dates
        // from the list, but keeps them in the payload for View/Edit details.
        $countRow = $this->db->fetch('SELECT COUNT(*) c FROM expenses');
        $total = (int)($countRow['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $perPage));

        $expenses = $this->db->fetchAll(
            "SELECT e.*, c.name AS category_name, u.name AS user_name
             FROM expenses e JOIN expense_categories c ON c.id=e.category_id LEFT JOIN users u ON u.id=e.user_id
             ORDER BY e.expense_date DESC, e.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );

        $this->ok('Expenses loaded.', [
            'categories' => $categories,
            'expenses' => $expenses,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
                'total' => $total,
            ],
        ]);
    }

    public function save(Request $request): void
    {
        $user = $this->role(['administrator', 'manager']);
        $input = $request->input();
        $errors = Validator::required($input, ['expense_date', 'category_id', 'method', 'amount']);
        if (!is_numeric($input['amount'] ?? null) || (float)$input['amount'] <= 0) {
            $errors['amount'] = 'Valid amount greater than zero required.';
        }
        if ($errors) {
            $this->fail('Validation failed.', $errors, 422);
            return;
        }

        $id = (int)($input['id'] ?? 0);
        $audit = new AuditService($this->db);

        if ($id > 0) {
            $old = $this->db->fetch('SELECT * FROM expenses WHERE id = ?', [$id]);
            if (!$old || $old['voided_at']) {
                $this->fail('Expense not found or already voided.');
                return;
            }

            // Existing expenses are edited in place because the SRS only requires
            // void/reversal for deletion-like changes. Every edit is still audited.
            $this->db->execute(
                'UPDATE expenses SET expense_date=?, category_id=?, method=?, amount=?, vendor=?, reference_no=?, description=? WHERE id=?',
                [
                    $input['expense_date'],
                    (int)$input['category_id'],
                    $input['method'],
                    (float)$input['amount'],
                    $input['vendor'] ?? null,
                    $input['reference_no'] ?? null,
                    $input['description'] ?? null,
                    $id,
                ]
            );
            $audit->log($user['id'], 'expense.updated', 'expense', $id, $old, $input);
            $this->ok('Expense updated.', ['id' => $id]);
            return;
        }

        $this->db->execute(
            'INSERT INTO expenses(expense_date, category_id, method, amount, vendor, reference_no, description, user_id, created_at)
             VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
            [
                $input['expense_date'],
                (int)$input['category_id'],
                $input['method'],
                (float)$input['amount'],
                $input['vendor'] ?? null,
                $input['reference_no'] ?? null,
                $input['description'] ?? null,
                (int)$user['id'],
            ]
        );
        $id = (int)$this->db->pdo()->lastInsertId();
        $audit->log($user['id'], 'expense.created', 'expense', $id, [], $input);
        $this->ok('Expense recorded.', ['id' => $id]);
    }

    public function void(Request $request): void
    {
        $user = $this->role(['administrator', 'manager']);
        $input = $request->input();
        $old = $this->db->fetch('SELECT * FROM expenses WHERE id = ?', [(int)$input['expense_id']]);
        if (!$old || $old['voided_at']) {
            $this->fail('Expense not found or already voided.');
            return;
        }
        $this->db->execute(
            'UPDATE expenses SET voided_at=UTC_TIMESTAMP(), voided_by=?, void_reason=? WHERE id=?',
            [(int)$user['id'], $input['reason'] ?? 'Voided', (int)$input['expense_id']]
        );
        (new AuditService($this->db))->log($user['id'], 'expense.voided', 'expense', (int)$input['expense_id'], $old, $input);
        $this->ok('Expense voided.');
    }
}

