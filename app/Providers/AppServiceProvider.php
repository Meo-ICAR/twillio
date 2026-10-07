<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Documents\AnthropicDocumentReader;
use App\Services\Documents\DocumentReader;
use App\Services\Documents\NullDocumentReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Lettura dei documenti con AI: attiva solo se c'è la chiave Anthropic.
        $this->app->bind(DocumentReader::class, function () {
            $key = config('services.anthropic.key');

            return filled($key)
                ? new AnthropicDocumentReader(new Client(apiKey: $key), config('services.anthropic.model'))
                : new NullDocumentReader;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
