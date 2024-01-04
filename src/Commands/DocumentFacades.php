<?php

namespace Stevebauman\AutodocFacades\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Lody\Lody;

class DocumentFacades extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'autodoc:facades {paths*} {--only=*} {--except=*}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Auto-generate doc annotations for Laravel facades';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $facades = Lody::classes($this->argument('paths'))
            ->isNotAbstract()
            ->isInstanceOf(Facade::class);

        if ($only = $this->option('only')) {
            $facades = $facades->filter(
                fn (string $classname) => in_array($classname, $only)
            );
        } elseif ($except = $this->option('except')) {
            $facades = $facades->filter(
                fn (string $classname) => ! in_array($classname, $except)
            );
        }

        $result = Process::run(sprintf('php -f src/facade.php -- %s', $facades->map(
            fn (string $classname) => str_replace('\\', '\\\\', $classname)
        )->join(' ')));

        $result->successful()
            ? $this->info($result->output())
            : $this->error($result->output());

        return $result->successful()
            ? static::SUCCESS
            : static::FAILURE;
    }
}
