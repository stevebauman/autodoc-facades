<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

use Lorisleiva\Lody\ClassnameLazyCollection;
use Lorisleiva\Lody\Lody;
use function Termwind\{render};

class Facades extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'facades {paths*} {--only=*} {--except=*}';

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
        Lody::resolvePathUsing(
            fn (string $path) => $this->resolvePath($path)
        );

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

        $result = Process::run(sprintf("php -f vendor/bin/facade.php -- %s", $facades->map(
            fn (string $classname) => str_replace('\\', '\\\\', $classname)
        )->join(' ')));

        $result->successful()
            ? $this->info($result->output())
            : $this->error($result->output());

        return $result->successful()
            ? static::SUCCESS
            : static::FAILURE;
    }

    /**
     * Resolve the given path relative to the commands working directory.
     */
    protected function resolvePath(string $path): string
    {
        if (Str::startsWith($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return implode(DIRECTORY_SEPARATOR, [getcwd(), $path]);
    }
}
