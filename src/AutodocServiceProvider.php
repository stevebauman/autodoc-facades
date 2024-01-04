<?php

namespace Stevebauman\AutodocFacades;

use Illuminate\Support\ServiceProvider;
use Stevebauman\AutodocFacades\Commands\DocumentFacades;

class AutodocServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->commands(DocumentFacades::class);
    }
}
