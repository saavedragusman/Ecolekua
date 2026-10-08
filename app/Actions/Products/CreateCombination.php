<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Products\AxisSignature;
use App\Support\Products\CombinationAudit;
use App\Support\Products\CombinationRules;
use App\Support\Products\ProductCombinations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a commercial combination of a product (PRD-005, design Decisions 4, 5 and 12). Everything
 * runs in one transaction under the product lock, which serializes it with every other write that
 * can create an overlap (DEC-PRD-39): axes and restrictions are checked against the structure of
 * the product (E-10, E-56), the overlap check runs against the other active combinations (E-09,
 * E-48) and the code goes to the shared registry (DT-01, E-08). The combination starts active.
 * The unique indexes are the backstop of both rules: a concurrent duplicate becomes the same field
 * error.
 */
class CreateCombination
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see CombinationRules::rules()): code, description, axes, restrictions
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when a combination rule rejects the data
     */
    public function handle(Product $product, array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): Combination
    {
        try {
            return DB::transaction(fn (): Combination => $this->create($product, $data, $actor, $origin, $auditContext));
        } catch (UniqueConstraintViolationException $exception) {
            throw CombinationRules::duplicateKeyError($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $auditContext
     */
    private function create(Product $product, array $data, User $actor, ?AuditOrigin $origin, array $auditContext): Combination
    {
        $product = Product::query()->lockForUpdate()->findOrFail($product->id);

        ['axes' => $axes, 'restrictions' => $restrictions] = CombinationRules::validate(
            ProductCombinations::declared($product),
            $data['axes'] ?? [],
            $data['restrictions'] ?? [],
        );

        $overlapping = ProductCombinations::overlappingCode($product, $axes);

        if ($overlapping !== null) {
            throw ValidationException::withMessages(['axes' => __('validation.combination_overlap', ['code' => $overlapping])]);
        }

        $combination = Combination::query()->create([
            'product_id' => $product->id,
            'description' => $data['description'] ?? null,
            'status' => CatalogStatus::Active,
            'axis_signature' => AxisSignature::of($axes),
        ]);

        CatalogCode::query()->create(['code' => $data['code'], 'combination_id' => $combination->id]);
        ProductCombinations::syncValues($combination, $axes, $restrictions);

        $this->audit->handle(
            AuditAction::CombinationCreated,
            $actor,
            $combination,
            newValues: CombinationAudit::snapshot($combination),
            context: $auditContext,
            origin: $origin,
        );

        return $combination;
    }
}
