<?php

declare(strict_types=1);

namespace App\Hr;

use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HrApiClient implements HrApiClientInterface
{
    /** Per-request timeout, seconds — caps how long the run blocks on a slow HR API. */
    private const float TIMEOUT = 10.0;

    /** Retries for transient failures (see {@see RetryableHttpClient}). */
    private const int MAX_RETRIES = 3;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $token,
    ) {
        // Retry only *transient* failures — timeouts and 5xx/429 — with backoff.
        // 4xx (e.g. 401 auth, 400 bad request) are NOT retried: they're terminal and
        // need intervention, so the run skips them rather than hammering HR. Retrying
        // is safe because every post carries the same Idempotency-Key, so HR dedupes.
        $this->httpClient = new RetryableHttpClient($httpClient, maxRetries: self::MAX_RETRIES);
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
                'timeout' => self::TIMEOUT,
            ],
        );

        return $response->toArray();
    }
}
