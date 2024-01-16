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
                                {path : The path of the facades}
                                {--only=* : Class names of the facades to be only from the path}
                                {--except=* : Class names of the facades to be excluded from the path}';

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
            $this->facades()
                ->map(fn (string $class) => str_replace('\\', '\\\\', $class))
                ->join(' ')
        ));

        if ($result->failed()) {
            $this->error($result->output());

            return self::FAILURE;
        }

        $this->info($result->output());

        return self::SUCCESS;
    }

    /**
     * Get a file from base path.
     */
    protected function facades(): Collection
    {
        $except = $this->option('except');
        $only = $this->option('only');

        return collect(Finder::create()->files()->depth(0)->in($this->argument('path'))->name('*.php'))
            ->map(fn (SplFileInfo $class) => $this->getFullNamespace($class->getRealpath()) . '\\' . $class->getFilenameWithoutExtension())
            ->filter(fn (string $class) => class_exists($class))
            ->filter(fn (string $class) => is_subclass_of($class, Facade::class))
            ->when(! empty($except), fn (Collection $collection) => $collection->filter(fn ($class) => ! in_array($class, $except)))
            ->when(! empty($only), fn (Collection $collection) => $collection->filter(fn ($class) => in_array($class, $only)));
    }

    /**
     * Get a namespace based on a real path.
     */
    protected function getFullNamespace(string $filename):string
    {
        $namespaces = preg_grep('/^namespace /', file($filename));

        $match = [];
        preg_match('/^namespace (.*);$/', array_shift($namespaces), $match);

        return array_pop($match);
    }
}
