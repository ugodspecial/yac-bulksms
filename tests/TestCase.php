<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The assets are built separately; rendering views in tests must not
        // require a Vite manifest.
        $this->withoutVite();
    }
}
