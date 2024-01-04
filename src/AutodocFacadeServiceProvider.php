<?php

namespace Stevebauman\AutodocFacades;

use Illuminate\Support\ServiceProvider;
use Stevebauman\AutodocFacades\Commands\DocumentFacades;

class AutodocFacadeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->commands(DocumentFacades::class);
    }
}
