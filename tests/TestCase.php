<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests check what the server sends to the page, not the compiled assets,
        // so they must not depend on a front-end build being present.
        $this->withoutVite();
    }
}
