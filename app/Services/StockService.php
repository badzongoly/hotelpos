<?php

declare(strict_types=1);

/**
 * Stock service. Records inventory movements and prevents accidental negative stock unless explicitly configured.
 */

namespace App\Services;

use App\Core\Database;

final class StockService
{
    private const TYPES = ['in', 'out', 'adjustment', 'return', 'waste'];

    public function __construct(private Database $db, private AuditService $audit, private bool $allowNegative = false)
    {
    }

    public function movement(int $extraId, string $type, float $qty, ?float $unitCost, ?string $note, ?string $refType, ?int $refId, int $userId): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid stock movement type.');
        }
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        $extra = $this->db->fetch('SELECT * FROM extras WHERE id = ?', [$extraId]);
        if (!$extra) {
            throw new \RuntimeException('Extra not found.');
        }

        // Convert the business movement type into a signed quantity change.
        // Returns and stock-ins increase stock; sales/waste decrease stock.
        $delta = match ($type) {
            'in', 'return' => $qty,
            'out', 'waste' => -$qty,
            'adjustment' => $qty,
        };
        $newQty = (float)$extra['stock_qty'] + $delta;
        // Negative stock is blocked by default because it usually means a sale,
        // adjustment, or import is wrong and needs management review.
        if (!$this->allowNegative && $newQty < 0) {
            throw new \RuntimeException('Stock cannot go negative.');
        }

        $this->db->execute('UPDATE extras SET stock_qty = ? WHERE id = ?', [$newQty, $extraId]);
        $this->db->execute(
            'INSERT INTO stock_movements(extra_id, movement_type, qty, unit_cost, note, ref_type, ref_id, created_by, created_at)
             VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
            [$extraId, $type, $qty, $unitCost, $note, $refType, $refId, $userId]
        );
        $id = (int)$this->db->pdo()->lastInsertId();
        $this->audit->log($userId, 'stock.movement', 'stock_movement', $id, $extra, compact('extraId', 'type', 'qty', 'newQty'));
        return $id;
    }
}

