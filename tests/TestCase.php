<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite does not need the front end to have been built.
     *
     * Every page renders through app.blade.php, which asks Vite for the
     * manifest; without one the response is a 500 and most of the suite fails
     * for a reason that has nothing to do with what it asserts. Stubbing Vite
     * keeps `php artisan test` honest on a fresh clone and in CI, where the PHP
     * job has no reason to run a bundler.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
