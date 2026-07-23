<?php

namespace App\Support;

/**
 * Outcome of a spam-protection check.
 *
 * Used by FormSpamProtectionService to communicate a pass / fail result
 * back to the submission service. The reason is intentionally
 * non-specific when delivered to the public API so bots cannot probe
 * which check failed.
 */
final class SpamCheckResult
{
    private function __construct(
        public readonly bool $passed,
        public readonly ?string $reason,
        public readonly int $status,
    ) {}

    /**
     * Build a passing result.
     */
    public static function pass(): self
    {
        return new self(true, null, 200);
    }

    /**
     * Build a failing result.
     */
    public static function fail(string $reason, int $status = 422): self
    {
        return new self(false, $reason, $status);
    }
}
