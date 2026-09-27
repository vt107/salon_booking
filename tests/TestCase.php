<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Không phụ thuộc public/build (chưa chạy npm run build trên CI)
        $this->withoutVite();
    }
}
