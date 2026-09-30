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
}
