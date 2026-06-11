<?php

declare(strict_types=1);

namespace App\Leave\AbsenceRun;

/**
 * Outcome of one absence run: the decisions recorded, and how many requests were
 * skipped (logged failures). The skipped count is what the command turns into a
 * non-zero exit code, so a scheduler notices when requests couldn't be processed.
 */
final readonly class RunReport
{
    /**
     * @param list<array{request: int, status: string, days: float, reason: string}> $decisions
     */
    public function __construct(
        public array $decisions,
        public int $skipped,
    ) {
    }

    public function decisionCount(): int
    {
        return \count($this->decisions);
    }

    public function hasSkips(): bool
    {
        return $this->skipped > 0;
    }

    public function isEmpty(): bool
    {
        return [] === $this->decisions && 0 === $this->skipped;
    }
}
