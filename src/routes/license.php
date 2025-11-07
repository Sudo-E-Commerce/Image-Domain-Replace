<?php

use Illuminate\Support\Facades\Route;
use Sudo\ImageDomainReplace\Http\Controllers\LicenseController;

/*
|--------------------------------------------------------------------------
| License Management Routes
|--------------------------------------------------------------------------
|
| Các routes cho việc quản lý license từ sudo.vn với token authentication
| Những routes này được bảo vệ bởi middleware và chỉ cho phép 
| các request hợp lệ từ sudo.vn với đúng MARKETPLACE_TOKEN
|
*/

Route::prefix('api/license')->name('api.license.')->group(function () {
    
    // API cho sudo.vn cập nhật theme_validate với token authentication
    Route::post('/update', [LicenseController::class, 'updateLicense'])
        ->middleware(['throttle:license-update'])
        ->name('update');
        
    // API get license status với token authentication
Route::get('/status', [LicenseController::class, 'getLicenseStatus'])
        ->middleware(['throttle:license-info'])
        ->name('status');
    
});