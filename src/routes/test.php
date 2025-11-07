<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| License Test Routes
|--------------------------------------------------------------------------
|
| Routes for testing license middleware functionality
|
*/

Route::get('/test-license-middleware', function () {
    return response()->json([
        'message' => 'License middleware test passed',
        'timestamp' => now(),
        'data' => [
            'middleware_enabled' => config('image-domain-replace.license.middleware.enabled'),
            'config_loaded' => config('image-domain-replace.license') ? 'yes' : 'no'
        ]
    ]);
})->middleware(['license.validate']);

Route::get('/test-license-blocked', function () {
    return response()->json([
        'message' => 'This should be blocked by license middleware',
        'timestamp' => now()
    ]);
})->middleware(['license.validate']);

Route::get('/test-no-middleware', function () {
    return response()->json([
        'message' => 'No middleware applied - should always work',
        'timestamp' => now()
    ]);
});