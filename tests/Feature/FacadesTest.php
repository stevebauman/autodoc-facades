<?php

use Stevebauman\AutodocFacades\Commands\DocumentFacades;
use Stevebauman\AutodocFacades\Tests\Fixtures\FacadeWithSee;

use function Pest\testDirectory;

beforeEach(function () {
    // Store the current state of the files in the Fixtures directory
    $this->originalFileContents = [];

    foreach (glob(fixturePath('*')) as $file) {
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
    $this->artisan(DocumentFacades::class, [
        'paths' => fixturePath(),
    ]);

    $contents = file_get_contents(testDirectory('Fixtures/FacadeWithSee.php'));

    expect($contents)->toContain('@method static void foo()');
    expect($contents)->toContain('@method static void bar()');
});

test('it doesnt document facades when excluded', function () {
    $this->artisan(DocumentFacades::class, [
        'paths' => fixturePath(),
        '--except' => [FacadeWithSee::class],
    ]);

    expect(
        file_get_contents(testDirectory('Fixtures/FacadeWithSee.php'))
    )->not->toContain('@method');
});

test('it documents facades when included', function () {
    $this->artisan(DocumentFacades::class, [
        'paths' => fixturePath(),
        '--only' => [FacadeWithSee::class],
    ]);

    expect(
        file_get_contents(testDirectory('Fixtures/FacadeWithSee.php'))
    )->toContain('@method');
});
