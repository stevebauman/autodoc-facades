<?php

namespace Stevebauman\AutodocFacades\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class DocumentFacades extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'autodoc:facades 
                            {paths : The paths of the facades}
                            {--only=* : The class names of facades to include for documentation}
                            {--except=* : The class names of facades to exclude from documentation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-generate doc annotations for Laravel facades';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Generating document annotations...');

        $process = new Process([
            'php',
            '-f',
            'vendor/bin/facade.php',
            '--',
            ...$this->getFacades()
        ]);

        $process->run();

        if ($process->isSuccessful()) {
            $this->info($process->getOutput());

            return self::SUCCESS;
        }

        $this->error($process->getErrorOutput());

        return self::FAILURE;
    }

    /**
     * Get all the facades in the command paths.
     */
    protected function getFacades(): Collection
    {
        return collect($this->getFiles())->map(fn (SplFileInfo $file) => (
            $this->getNamespace($file->getRealpath()) . '\\' . $file->getFilenameWithoutExtension()
        ))->filter(fn (string $class) => (
            class_exists($class) && is_subclass_of($class, Facade::class)
        ))->when($this->option('only'), fn (Collection $facades, array $only) => (
            $facades->filter(fn ($class) => in_array($class, $only))
        ))->when($this->option('except'), fn (Collection $facades, array $except) => (
            $facades->filter(fn ($class) => ! in_array($class, $except))
        ));
    }

    /**
     * Get all the PHP files in the command paths.
     */
    protected function getFiles(): Finder
    {
        return Finder::create()
            ->in($this->argument('paths'))
            ->name('*.php')
            ->files();
    }

    /**
     * Get the namespace of the given file.
     */
    protected function getNamespace(string $filename): ?string
    {
        if (! $namespaces = preg_grep('/^namespace /', file($filename))) {
            return null;
        }

        $namespace = trim(array_shift($namespaces));

        if (! preg_match('/^namespace (?P<namespace>.*);$/', $namespace, $match)) {
            return null;
        }

        return $match['namespace'];
    }
}
