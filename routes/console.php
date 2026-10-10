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
