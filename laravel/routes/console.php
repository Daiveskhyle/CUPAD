<?php

use Illuminate\Foundation\In\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;

Artisan::command('cupad:status', function () {
    $this->info('CUPAD Laravel migration is active.');
})->purpose('Check the CUPAD Laravel migration.');
