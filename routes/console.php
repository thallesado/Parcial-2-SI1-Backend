<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:admision', function () {
    $this->info('API de admision universitaria lista.');
});

