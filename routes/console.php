<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Exchange rate of the day (COT-030, DEC-COT-43, design Decision 11): 07:00 in Caracas, plus later runs
// that end at once when today already has a rate. At most 12 API calls per day, only while it has none.
foreach (['07:00', '07:30', '08:00', '09:00'] as $time) {
    Schedule::command('exchange-rate:fetch')->dailyAt($time)->timezone('America/Caracas')->withoutOverlapping();
}
