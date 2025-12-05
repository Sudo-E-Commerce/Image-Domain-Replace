<?php

use Illuminate\Support\Facades\Route;
use Sudo\ImageDomainReplace\Http\Controllers\ImageController;

/*
|--------------------------------------------------------------------------
| Image Domain Replace Package Routes
|--------------------------------------------------------------------------
|
| Here are the routes for the Image Domain Replace package.
| These routes handle image processing and fallback functionality.
|
*/

// Group routes with specific middleware to avoid conflicts
Route::group(['middleware' => ['web'], 'namespace' => 'Sudo\ImageDomainReplace\Http\Controllers'], function() {
    // Route for compatibility with existing script.js
    Route::post('/ajax/get-fallback-image-url', 'ImageController@getFallbackImageUrl')
        ->name('image-domain-replace.get-fallback-image-url');
});