<?php

declare(strict_types=1);

/**
 * Focused billing rule test. Protects the noon-cutoff edge cases listed in the SRS.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Services\BillingService;

$svc = new BillingService();
$tz = new DateTimeZone('UTC');
$cases = [
    ['2026-07-01 15:00:00', '2026-07-02 11:59:00', 1],
    ['2026-07-01 15:00:00', '2026-07-02 12:00:00', 1],
    ['2026-07-01 15:00:00', '2026-07-02 12:01:00', 2],
    ['2026-07-01 09:00:00', '2026-07-02 12:00:00', 1],
    ['2026-07-01 09:00:00', '2026-07-03 12:01:00', 3],
];

foreach ($cases as [$in, $out, $expected]) {
    $actual = $svc->computeNights(new DateTimeImmutable($in, $tz), new DateTimeImmutable($out, $tz));
    if ($actual !== $expected) {
        fwrite(STDERR, "Failed: $in to $out expected $expected got $actual\n");
        exit(1);
    }
}

echo "Billing noon cutoff tests passed.\n";
