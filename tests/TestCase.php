<?php

namespace Tests;

use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles and the permission catalog exist in every test that refreshes the database.
     */
    protected bool $seed = true;

    protected string $seeder = FoundationSeeder::class;

    /**
     * PHP tests must not depend on compiled frontend assets (public/build).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
