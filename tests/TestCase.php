<?php

namespace Stevebauman\AutodocFacades\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Stevebauman\AutodocFacades\AutodocFacadeServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            AutodocFacadeServiceProvider::class,
        ];
    }
}
