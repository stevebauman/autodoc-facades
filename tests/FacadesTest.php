<?php

namespace Stevebauman\AutodocFacades\Tests;

use Stevebauman\AutodocFacades\Commands\DocumentFacades;
use Stevebauman\AutodocFacades\Tests\Fixtures\FacadeWithSee;
use Stevebauman\AutodocFacades\Tests\Fixtures\FacadeWithUseSee;

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

    public function test_it_documents_facades_with_see_annotations()
    {
        $this->artisan(DocumentFacades::class, [
            'paths' => $this->fixturePath(),
        ]);

        $contents = file_get_contents($this->fixturePath('FacadeWithSee.php'));

        $this->assertStringContainsString('@method static void foo()', $contents);
        $this->assertStringContainsString('@method static void bar()', $contents);
    }

    public function test_it_doesnt_document_facades_when_excluded()
    {
        $this->artisan(DocumentFacades::class, [
            'paths' => $this->fixturePath(),
            '--except' => [FacadeWithSee::class],
        ]);

        $this->assertStringNotContainsString('@method', file_get_contents($this->fixturePath('FacadeWithSee.php')));
    }

    public function test_it_documents_facades_with_use_see_annotations()
    {
        $this->artisan(DocumentFacades::class, [
            'paths' => $this->fixturePath(),
            '--only' => [FacadeWithUseSee::class],
        ]);

        $contents = file_get_contents($this->fixturePath('FacadeWithUseSee.php'));

        $this->assertStringContainsString('@method static void foo()', $contents);
        $this->assertStringContainsString('@method static void bar()', $contents);
    }

    public function test_it_documents_facades_when_included()
    {
        $this->artisan(DocumentFacades::class, [
            'paths' => $this->fixturePath(),
            '--only' => [FacadeWithSee::class],
        ]);

        $this->assertStringContainsString('@method', file_get_contents($this->fixturePath('FacadeWithSee.php')));
    }
}
