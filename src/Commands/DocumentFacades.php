<?php

namespace Stevebauman\AutodocFacades\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

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

        return collect((new Finder)->files()->depth(0)->in($this->argument('path'))->name('*.php'))
            ->map(function ($class) {
                $namespace = $this->laravel->getNamespace();

                return $namespace.str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    Str::after($class->getRealPath(), realpath(app_path()).DIRECTORY_SEPARATOR)
                );
            })
            ->filter(fn (string $class) => class_exists($class))
            ->filter(fn (string $class) => is_subclass_of($class, Facade::class))
            ->when(! empty($except), fn ($classes) => $classes->reject(fn ($class) => ! in_array($class, $except)))
            ->when(! empty($only), fn ($classes) => $classes->reject(fn ($class) => in_array($class, $only)));
    }
}
