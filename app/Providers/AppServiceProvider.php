<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Checks\CheckRegistry;
use App\Services\Crm\CrmGateway;
use App\Services\Crm\MediafacileLeadGateway;
use App\Services\Crm\SimulatedCrmGateway;
use App\Services\Documents\AnthropicDocumentReader;
use App\Services\Documents\DocumentReader;
use App\Services\Documents\NullDocumentReader;
use App\Services\Flows\FlowRepository;
use App\Services\Documents\LoggingSharePointUploader;
use App\Services\Documents\SharePointUploader;
use App\Services\Loans\LoanEstimator;
use App\Services\Loans\MediafacileLoanEstimator;
use App\Services\Loans\RandomLoanEstimator;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // L'albero delle conversazioni resta in memoria per la richiesta; i modelli lo invalidano quando cambiano.
        $this->app->singleton(FlowRepository::class);
        $this->app->singleton(CheckRegistry::class);

        // Invio al CRM del committente: per ora una simulazione.
        $this->app->bind(CrmGateway::class, fn ($app) => config('finanziamento.crm.driver') === 'mediafacile'
            ? $app->make(MediafacileLeadGateway::class)
            : new SimulatedCrmGateway);

        // Archiviazione dei documenti su SharePoint: per ora una simulazione che scrive nel log.
        $this->app->bind(SharePointUploader::class, LoggingSharePointUploader::class);

        // Calcolo degli importi ottenibili: simulazione di base, servizio Mediafacile con QUOTE_DRIVER=mediafacile.
        $this->app->bind(LoanEstimator::class, fn ($app) => config('finanziamento.quote.driver') === 'mediafacile'
            ? $app->make(MediafacileLoanEstimator::class)
            : new RandomLoanEstimator);

        // Lettura dei documenti con AI: attiva solo se c'è la chiave Anthropic.
        $this->app->bind(DocumentReader::class, function () {
            $key = config('services.anthropic.key');

            return filled($key)
                ? new AnthropicDocumentReader(new Client(apiKey: $key, requestOptions: [
                    // Il timeout lo impone il client HTTP (PSR-18), non l'SDK.
                    'transporter' => new GuzzleClient(['timeout' => 90, 'connect_timeout' => 10]),
                    'maxRetries' => 1,
                ]), config('services.anthropic.model'))
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
