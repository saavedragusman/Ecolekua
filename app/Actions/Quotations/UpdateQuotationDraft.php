<?php

namespace App\Actions\Quotations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\LineKind;
use App\Enums\QuotationStatus;
use App\Events\Quotations\QuotationRequiresAdvisor;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ComboComponent;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Support\Customers\PhoneNumber;
use App\Support\Quotations\Calculation\CalculationInput;
use App\Support\Quotations\Calculation\CalculationResult;
use App\Support\Quotations\Calculation\LineResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a draft quotation (COT-013, COT-014, design Decision 19): the header and every line are replaced by the
 * ones sent, lines sent with their `id` keep their row, and the draft is saved even when its calculation has
 * errors, keeping the cache of the last calculation (total, requires-advisor flag and each line's resolved
 * combination, which anchors the history of COT-024).
 *
 * Changing the recipient keeps the advisor (DEC-COT-56); the advisor changes only to an active user with
 * `customers.portfolio` and never becomes empty (DEC-COT-65). Only those two changes are audited (DEC-COT-29).
 * A draft whose customer became inactive can still be saved; moving a draft to an inactive customer cannot.
 */
class UpdateQuotationDraft
{
    private const NOT_DRAFT = 'La cotización no está en borrador; duplíquela para cambiarla.';

    /** Positions are moved out of the way first, so reordering never collides with `(quotation_id, position)`. */
    private const POSITION_OFFSET = 30000;

    public function __construct(
        private readonly CalculateQuotation $calculateQuotation,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (recipient, advisor, notes and `lines`)
     *
     * @throws BusinessRuleViolation when the quotation is not a draft
     * @throws ValidationException when the recipient, the advisor or a line id is not acceptable
     */
    public function handle(Quotation $quotation, array $data, User $actor): Quotation
    {
        $saved = DB::transaction(function () use ($quotation, $data, $actor): Quotation {
            $locked = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== QuotationStatus::Draft) {
                throw new BusinessRuleViolation(self::NOT_DRAFT);
            }

            $wasRequiringAdvisor = $locked->requires_advisor;
            $lines = array_values($data['lines'] ?? []);

            $this->ensureOwnLines($locked, $lines);
            $this->ensureSizelessRows($lines);
            $this->applyRecipient($locked, $data, $actor);
            $this->applyAdvisor($locked, $data['advisor_id'] ?? null, $actor);

            $result = $this->calculateQuotation->handle(CalculationInput::fromArray(['lines' => $lines]));

            $this->replaceLines($locked, $lines, $result);

            $locked->forceFill([
                'notes' => $data['notes'] ?? null,
                'total' => $result->total(),
                'requires_advisor' => $result->requiresAdvisor(),
            ])->save();

            if (! $wasRequiringAdvisor && $locked->requires_advisor) {
                QuotationRequiresAdvisor::dispatch($locked);
            }

            return $locked;
        });

        return $saved->fresh() ?? $saved;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function ensureOwnLines(Quotation $quotation, array $lines): void
    {
        $own = $quotation->lines()->pluck('id')->all();

        foreach ($lines as $i => $line) {
            if (isset($line['id']) && ! in_array((int) $line['id'], $own, true)) {
                throw ValidationException::withMessages(["lines.{$i}.id" => __('validation.quotation_line_foreign')]);
            }
        }
    }

    /**
     * A product without a size order attribute takes a single row without size (design Decision 19, COT-014).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function ensureSizelessRows(array $lines): void
    {
        $productIds = array_values(array_unique(array_filter(array_map(
            fn (array $line): ?int => ($line['kind'] ?? null) === LineKind::Product->value && isset($line['product_id']) ? (int) $line['product_id'] : null,
            $lines,
        ))));

        $withSizes = $productIds === [] ? [] : DB::table('product_attributes')
            ->join('catalog_attributes', 'catalog_attributes.id', '=', 'product_attributes.catalog_attribute_id')
            ->whereIn('product_attributes.product_id', $productIds)
            ->where('catalog_attributes.special_use', AttributeSpecialUse::Size->value)
            ->where('product_attributes.role', AttributeRole::Order->value)
            ->pluck('product_attributes.product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($lines as $i => $line) {
            if (($line['kind'] ?? null) !== LineKind::Product->value || in_array((int) $line['product_id'], $withSizes, true)) {
                continue;
            }

            foreach ($line['rows'] ?? [] as $row) {
                if (($row['size_value_id'] ?? null) !== null) {
                    throw ValidationException::withMessages(["lines.{$i}.rows" => __('validation.quotation_rows_sizeless')]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyRecipient(Quotation $quotation, array $data, User $actor): void
    {
        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
        $includeContact = (bool) ($data['include_contact'] ?? false);
        $customer = $customerId === null ? null : Customer::query()->with('contact')->find($customerId);

        if ($customerId !== null && $customer === null) {
            throw ValidationException::withMessages(['customer_id' => __('validation.quotation_customer_missing')]);
        }

        // A draft keeps its customer even after it was deactivated; it cannot be moved to an inactive one (E-33).
        if ($customer !== null && $customer->id !== $quotation->customer_id && $customer->status !== CustomerStatus::Active) {
            throw ValidationException::withMessages(['customer_id' => __('validation.quotation_customer_inactive')]);
        }

        if ($includeContact && ($customer === null || $customer->type !== CustomerType::Company || $customer->contact === null)) {
            throw ValidationException::withMessages(['include_contact' => __('validation.quotation_include_contact_invalid')]);
        }

        $recipient = $customer === null
            ? [
                'customer_id' => null,
                'include_contact' => false,
                'prospect_name' => $data['prospect_name'],
                'prospect_phone' => PhoneNumber::parse((string) $data['prospect_phone'])->e164(),
                'prospect_email' => $data['prospect_email'] ?? null,
            ]
            : ['customer_id' => $customer->id, 'include_contact' => $includeContact, 'prospect_name' => null, 'prospect_phone' => null, 'prospect_email' => null];

        $previous = array_intersect_key($quotation->only(array_keys($recipient)), $recipient);

        if ($previous == $recipient) {
            return;
        }

        $quotation->forceFill($recipient)->save();

        if ($previous['customer_id'] !== $recipient['customer_id'] || $previous['prospect_name'] !== $recipient['prospect_name']
            || $previous['prospect_phone'] !== $recipient['prospect_phone'] || $previous['prospect_email'] !== $recipient['prospect_email']) {
            $this->audit->handle(AuditAction::QuotationRecipientChanged, $actor, $quotation, oldValues: $previous, newValues: $recipient);
        }
    }

    /**
     * The advisor changes only to another active user with `customers.portfolio`; an empty value keeps it
     * (COT-013, DEC-COT-56, DEC-COT-65).
     */
    private function applyAdvisor(Quotation $quotation, mixed $advisorId, User $actor): void
    {
        if ($advisorId === null || (int) $advisorId === $quotation->advisor_id) {
            return;
        }

        if (! User::query()->eligibleAdvisors()->whereKey((int) $advisorId)->exists()) {
            throw ValidationException::withMessages(['advisor_id' => __('validation.quotation_advisor_ineligible')]);
        }

        $previous = $quotation->advisor_id;
        $quotation->forceFill(['advisor_id' => (int) $advisorId])->save();

        $this->audit->handle(AuditAction::QuotationAdvisorChanged, $actor, $quotation, oldValues: ['advisor_id' => $previous], newValues: ['advisor_id' => (int) $advisorId]);
    }

    /**
     * Full replacement of the lines: kept ids keep their row, missing ones are deleted, new ones are created.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(Quotation $quotation, array $lines, CalculationResult $result): void
    {
        $kept = array_values(array_filter(array_map(fn (array $line): ?int => isset($line['id']) ? (int) $line['id'] : null, $lines)));

        $quotation->lines()->whereNotIn('id', $kept)->delete();
        QuotationLine::query()->whereIn('id', $kept)->update(['position' => DB::raw('`position` + '.self::POSITION_OFFSET)]);

        foreach ($lines as $i => $input) {
            $this->saveLine($quotation, $input, $i + 1, $result->lines[$i]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function saveLine(Quotation $quotation, array $input, int $position, LineResult $calculated): void
    {
        $kind = LineKind::from((string) $input['kind']);
        $line = isset($input['id']) ? QuotationLine::query()->findOrFail((int) $input['id']) : new QuotationLine(['quotation_id' => $quotation->id]);

        $line->forceFill([
            'quotation_id' => $quotation->id,
            'position' => $position,
            'kind' => $kind,
            'product_id' => $kind === LineKind::Product ? (int) $input['product_id'] : null,
            'combo_id' => $kind === LineKind::Combo ? (int) $input['combo_id'] : null,
            'combination_id' => $kind === LineKind::Product ? $calculated->combinationId : null,
            'selection' => $kind === LineKind::Product && is_array($input['selection'] ?? null) ? $input['selection'] : [],
            'combo_quantity' => $kind === LineKind::Combo ? (int) $input['combo_quantity'] : null,
            'note' => $input['note'] ?? null,
        ])->save();

        $line->rows()->delete();
        $line->components()->delete();
        $line->customizations()->detach();

        foreach ($kind === LineKind::Product ? $input['rows'] : [] as $row) {
            $line->rows()->create([
                'size_value_id' => isset($row['size_value_id']) ? (int) $row['size_value_id'] : null,
                'quantity' => (int) $row['quantity'],
            ]);
        }

        foreach ($kind === LineKind::Product ? ($input['customizations'] ?? []) : [] as $customization) {
            $line->customizations()->attach((int) $customization['service_product_id'], [
                'quantity_per_garment' => (int) ($customization['quantity_per_garment'] ?? 1),
            ]);
        }

        $position = 0;
        $ownComponents = $kind === LineKind::Combo
            ? ComboComponent::query()->where('combo_id', (int) $input['combo_id'])->pluck('id')->all()
            : [];

        foreach ($kind === LineKind::Combo && is_array($input['components'] ?? null) ? $input['components'] : [] as $componentId => $selection) {
            $line->components()->create([
                // A component the combo does not have stays as entered; the calculation reports it as an error.
                'combo_component_id' => in_array((int) $componentId, $ownComponents, true) ? (int) $componentId : null,
                'combination_id' => $calculated->componentCombinationIds[(int) $componentId] ?? null,
                'position' => ++$position,
                'selection' => is_array($selection) ? $selection : [],
            ]);
        }
    }
}
