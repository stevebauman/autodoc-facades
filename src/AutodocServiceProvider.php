<?php

namespace Stevebauman\AutodocFacades;

use Illuminate\Support\ServiceProvider;
use Stevebauman\AutodocFacades\Commands\DocumentFacades;

class AutodocServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->commands(DocumentFacades::class);
    }
}
