<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tattali\PresignedUrl\Bridge\Laravel\Http\Controllers\ServeController;

// Laravel registers GET routes for HEAD requests too.
Route::get('/storage/serve/{bucket}/{path}', ServeController::class)
    ->where('path', '.*')
    ->name('presigned-url.serve');
