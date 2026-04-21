<?php

use App\Http\Controllers\E2E\StudioVersionsGoldenPathController;
use Illuminate\Support\Facades\Route;

Route::get('/__e2e__/studio-versions/bootstrap', [StudioVersionsGoldenPathController::class, 'bootstrap'])
    ->name('e2e.studio-versions.bootstrap');
