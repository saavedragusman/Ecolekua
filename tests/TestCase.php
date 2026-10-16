<?php

namespace Tests;

use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles and the permission catalog exist in every test that refreshes the database.
     */
    protected bool $seed = true;

    protected string $seeder = FoundationSeeder::class;

    /**
     * PHP tests must not depend on compiled frontend assets (public/build), and never reach a real
     * external service (the exchange rate API, COT-030): an HTTP call without a fake fails the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
    }
}
