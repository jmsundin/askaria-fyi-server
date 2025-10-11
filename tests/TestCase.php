<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function refreshApplication(): void
    {
        $this->app = require __DIR__.'/../bootstrap/app.php';

        $this->app->make(Kernel::class)->bootstrap();

        config(['jwt.secret' => 'test-secret-key-should-be-at-least-32-characters']);
    }
}
