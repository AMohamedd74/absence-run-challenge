<?php

declare(strict_types=1);

/**
 * THROWAWAY SPIKE — proves the HR idempotency contract the spec relies on.
 * Requires the mock running: php -S 127.0.0.1:8081 mock-hr-api/server.php
 *
 * Validates:
 *   1. same key replays (no duplicate) on a re-run
 *   2. a NEW key for the same request creates a new record
 *      → so the compound `{requestId}:{decision}` key lets a later
 *        cancellation land instead of being deduped against the approval
 */

const BASE = 'http://127.0.0.1:8081';
const TOKEN = 'demo-secret-token-7Qx2';

function post(array $decision, string $key): array
{
    $ch = curl_init(BASE . '/v1/leave-decisions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . TOKEN,
            'Idempotency-Key: ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($decision, JSON_THROW_ON_ERROR),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return ['status' => $status, 'body' => json_decode((string) $body, true)];
}

function get(string $path): array
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . TOKEN],
    ]);
    $body = curl_exec($ch);

    return json_decode((string) $body, true);
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'OK' : 'XX', $label);
    if (!$ok) {
        $failures++;
    }
};

// Clean slate.
$ch = curl_init(BASE . '/v1/_reset');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . TOKEN]]);
curl_exec($ch);

$approval = ['employeeId' => 1, 'requestId' => 7, 'decision' => 'approved', 'days' => 2.5, 'type' => 'vacation'];

echo "Re-run idempotency (same key)\n";
$r1 = post($approval, '7:approved');
$check('first post → 201, replayed:false', $r1['status'] === 201 && $r1['body']['replayed'] === false);
$r2 = post($approval, '7:approved');
$check('re-run same key → 200, replayed:true', $r2['status'] === 200 && $r2['body']['replayed'] === true);
$check('replay returns the original id', $r1['body']['id'] === $r2['body']['id']);

echo "\nCompound key lets a later cancellation land\n";
$cancel = ['employeeId' => 1, 'requestId' => 7, 'decision' => 'cancelled', 'days' => -2.5, 'type' => 'vacation'];
$r3 = post($cancel, '7:cancelled');
$check('cancellation (new key) → 201, replayed:false', $r3['status'] === 201 && $r3['body']['replayed'] === false);
$check('cancellation got a NEW id', $r3['body']['id'] !== $r1['body']['id']);

echo "\nLedger state\n";
$all = get('/v1/leave-decisions');
$check('exactly 2 records for request 7 (approval + cancellation)',
    count(array_filter($all['decisions'], static fn ($d) => ($d['payload']['requestId'] ?? null) === 7)) === 2);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
