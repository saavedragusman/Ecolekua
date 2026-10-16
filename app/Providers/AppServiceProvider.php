<?php

namespace App\Providers;

use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\CombinationPrice;
use App\Models\ComboItemPrice;
use App\Models\CommercialParameters;
use App\Models\DetailLocation;
use App\Models\ExchangeRate;
use App\Models\PriceScheme;
use App\Models\ProductCategory;
use App\Policies\CatalogPolicy;
use App\Policies\PricingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerCatalogPolicy();
        $this->registerPricingPolicy();
    }

    /**
     * The four catalog models share one policy (design Decision 8), so it cannot be auto-discovered.
     */
    protected function registerCatalogPolicy(): void
    {
        foreach ([ProductCategory::class, CatalogAttribute::class, AttributeValue::class, DetailLocation::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }
    }

    /**
     * The pricing models share one policy (design Decision 6), so it cannot be auto-discovered.
     * `PriceImport` joins the list in the phase that creates it.
     */
    protected function registerPricingPolicy(): void
    {
        foreach ([PriceScheme::class, CombinationPrice::class, ComboItemPrice::class, CommercialParameters::class, ExchangeRate::class] as $model) {
            Gate::policy($model, PricingPolicy::class);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // FND-013: at least 10 characters in every environment; no other complexity rules.
        Password::defaults(fn (): Password => Password::min(10));
    }
}
