<?php

namespace Stevebauman\AutodocFacades\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
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

        $result = Process::run(sprintf(
            'php -f vendor/bin/facade.php -- %s',
            $this->getFacades()->map(fn (string $class) => (
                windows_os() ? $class : str_replace('\\', '\\\\', $class)
            ))->join(' ')
        ));

        if ($result->failed()) {
            $this->error($result->output());

            return self::FAILURE;
        }

        $this->info($result->output());

        return self::SUCCESS;
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

        if (! preg_match('/^namespace (.*);$/', array_shift($namespaces), $match)) {
            return null;
        }

        return array_pop($match);
    }
}
