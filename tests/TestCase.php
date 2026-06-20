<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Put shared bootstrapping here if needed
        // Example:
        // require_once __DIR__.'/../prestashop/config/config.inc.php';
    }
}
