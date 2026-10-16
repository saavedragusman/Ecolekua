<?php

namespace App\Events\Quotations;

use App\Models\Quotation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A saved draft started requiring an advisor (COT-012): a negotiation tier or a custom color. Dispatched after
 * the save commits, only when the stored flag goes from no to yes, so integrations (005, notifications) can
 * react without the core depending on them (AGENTS.md §7.11).
 */
class QuotationRequiresAdvisor implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Quotation $quotation) {}
}
