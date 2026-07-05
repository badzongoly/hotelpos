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
        $this->ok('Expenses loaded.', [
            'categories' => $this->db->fetchAll('SELECT * FROM expense_categories WHERE active = 1 ORDER BY name'),
            'expenses' => $this->db->fetchAll(
                'SELECT e.*, c.name AS category_name, u.name AS user_name
                 FROM expenses e JOIN expense_categories c ON c.id=e.category_id LEFT JOIN users u ON u.id=e.user_id
                 ORDER BY e.expense_date DESC, e.id DESC LIMIT 100'
            ),
        ]);
    }

    public function save(Request $request): void
    {
        $user = $this->role(['administrator', 'manager']);
        $input = $request->input();
        $errors = Validator::required($input, ['expense_date', 'category_id', 'method', 'amount']);
        if ($errors || !is_numeric($input['amount'])) {
            $this->fail('Validation failed.', $errors + ['amount' => 'Valid amount required.'], 422);
            return;
        }
        $audit = new AuditService($this->db);
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

