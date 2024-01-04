<?php

namespace Stevebauman\AutodocFacades\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Stevebauman\AutodocFacades\AutodocServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            AutodocServiceProvider::class,
        ];
    }

    protected function fixturePath(?string $path = null): string
    {
        return implode(DIRECTORY_SEPARATOR, [getcwd(), 'tests', 'Fixtures', $path]);
    }
}
