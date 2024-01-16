<?php

namespace Stevebauman\AutodocFacades\Tests;

use Stevebauman\AutodocFacades\Commands\DocumentFacades;
use Stevebauman\AutodocFacades\Tests\Fixtures\FacadeWithSee;

class FacadesTest extends TestCase
{
    protected $originalFileContents = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob($this->fixturePath('*')) as $file) {
            $this->originalFileContents[$file] = file_get_contents($file);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->originalFileContents as $file => $contents) {
            file_put_contents($file, $contents);
        }
    }

    public function testItDocumentsFacadesWithSeeAnnotations()
    {
        $this->artisan(DocumentFacades::class, [
            'paths' => $this->fixturePath(),
        ]);

        $contents = file_get_contents($this->fixturePath('FacadeWithSee.php'));

        $this->assertStringContainsString('@method static void foo()', $contents);
        $this->assertStringContainsString('@method static void bar()', $contents);
    }

    public function testItDoesntDocumentFacadesWhenExcluded()
    {
        $this->artisan(DocumentFacades::class, [
            'paths' => $this->fixturePath(),
            '--except' => [FacadeWithSee::class],
        ]);

        $this->assertStringNotContainsString('@method', file_get_contents($this->fixturePath('FacadeWithSee.php')));
    }

    public function testItDocumentsFacadesWhenIncluded()
    {
        $this->artisan(DocumentFacades::class, [
            'paths' => $this->fixturePath(),
            '--only' => [FacadeWithSee::class],
        ]);

        $this->assertStringContainsString('@method', file_get_contents($this->fixturePath('FacadeWithSee.php')));
    }
}
