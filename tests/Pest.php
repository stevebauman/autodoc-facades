<?php

use Stevebauman\AutodocFacades\Tests\TestCase;

use function Pest\testDirectory;

uses(TestCase::class)->in('Feature');

function fixturePath(?string $path = null): string
{
    return getcwd().DIRECTORY_SEPARATOR.testDirectory(
        implode(DIRECTORY_SEPARATOR, ['Fixtures', $path])
    );
}
