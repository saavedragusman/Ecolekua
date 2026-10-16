<?php

namespace App\Events\Pricing;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The scheduled fetch of the exchange rate saved nothing (COT-030, design Decision 26). Spec 015 will
 * subscribe to notify it; 004 registers no listener. It carries the reason only, never personal data:
 * `connection`, `status` (a non-2xx answer) or `invalid_rate` (no positive `promedio`).
 */
class ExchangeRateFetchFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $reason) {}
}
