<?php

declare(strict_types=1);

namespace App\Tests;

use App\Hr\HrApiClientInterface;

/**
 * In-memory HR API client for tests — records calls instead of making HTTP requests.
 */
final class FakeHrApiClient implements HrApiClientInterface
{
    /** @var list<array{decision: array<string, mixed>, key: string}> */
    public array $calls = [];

    #[\Override]
    public function postDecision(array $decision, string $idempotencyKey): array
    {
        $this->calls[] = ['decision' => $decision, 'key' => $idempotencyKey];

        return ['id' => 'fake_'.\count($this->calls)];
    }
}
