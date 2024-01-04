<?php

use App\Commands\Facades;
use Tests\Fixtures\FacadeWithSee;
use Tests\TestCase;
use function Pest\testDirectory;

uses(TestCase::class);

beforeEach(function () {
    // Store the current state of the files in the Fixtures directory
    $this->originalFileContents = [];

    foreach (glob(__DIR__ . '/Fixtures/*') as $file) {
        $this->originalFileContents[$file] = file_get_contents($file);
    }
});

afterEach(function () {
    // Restore the original contents of the files in the Fixtures directory
    foreach ($this->originalFileContents as $file => $contents) {
        file_put_contents($file, $contents);
    }
});

test('it documents facades with @see annotations', function () {
    $this->artisan(Facades::class, [
        'paths' => testDirectory('Fixtures')
    ]);

    $contents = file_get_contents(testDirectory('Fixtures/FacadeWithSee.php'));

    expect($contents)->toContain('@method static void foo()');
    expect($contents)->toContain('@method static void bar()');
});

test('it doesnt document facades when excluded', function () {
    $this->artisan(Facades::class, [
        'paths' => testDirectory('Fixtures'),
        '--except' => [FacadeWithSee::class],
    ]);

    expect(
        file_get_contents(testDirectory('Fixtures/FacadeWithSee.php'))
    )->not->toContain('@method');
});

test('it documents facades when included', function () {
    $this->artisan(Facades::class, [
        'paths' => testDirectory('Fixtures'),
        '--only' => [FacadeWithSee::class],
    ]);

    expect(
        file_get_contents(testDirectory('Fixtures/FacadeWithSee.php'))
    )->toContain('@method');
});
