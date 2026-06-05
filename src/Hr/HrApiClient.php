<?php

declare(strict_types=1);

namespace App\Hr;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HrApiClient implements HrApiClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $token,
    ) {
    }

    #[\Override]
    public function postDecision(array $decision, string $idempotencyKey): array
    {
        $response = $this->httpClient->request(
            'POST',
            rtrim($this->baseUrl, '/').'/v1/leave-decisions',
            [
                'auth_bearer' => $this->token,
                'headers' => ['Idempotency-Key' => $idempotencyKey],
                'json' => $decision,
            ],
        );

        return $response->toArray();
    }
}
