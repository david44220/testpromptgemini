<?php

declare(strict_types=1);

namespace App\Services\AntiCheat;

final readonly class AuditResult
{
    /**
     * @param  array<string, mixed>  $telemetry
     */
    public function __construct(
        public bool $passed,
        public ?string $failureReason = null,
        public array $telemetry = [],
    ) {}

    /**
     * @param  array<string, mixed>  $telemetry
     */
    public static function success(array $telemetry = []): self
    {
        return new self(true, null, $telemetry);
    }

    /**
     * @param  array<string, mixed>  $telemetry
     */
    public static function failure(string $reason, array $telemetry = []): self
    {
        return new self(false, $reason, $telemetry);
    }
}
