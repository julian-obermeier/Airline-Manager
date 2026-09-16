<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('airline:status', function (): void {
    $this->info('Airline Empire foundation is available.');
})->purpose('Show the current application bootstrap status');
