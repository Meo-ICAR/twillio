<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('conversazioni:chiudi-abbandonate')->hourly();
Schedule::command('finanziamento:purge')->dailyAt('03:30');
Schedule::command('crm:send-documents')->hourly();

// Firma dei documenti: il core chiude le richieste scadute e riconcilia gli stati; noi chiudiamo le pratiche con tutte le firme complete.
Schedule::command('signature:sync')->everyTenMinutes();
Schedule::command('signatures:finalize')->everyFiveMinutes();
