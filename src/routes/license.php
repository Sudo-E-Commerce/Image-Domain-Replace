<?php

use Illuminate\Support\Facades\Route;
use Sudo\ImageDomainReplace\Controllers\LicenseController;

/*
|--------------------------------------------------------------------------
| License Management Routes
|--------------------------------------------------------------------------
|
| Các routes cho việc quản lý license từ sudo.vn
| Những routes này được bảo vệ bởi middleware và chỉ cho phép 
| các request hợp lệ từ sudo.vn
|
*/

Route::prefix('api/license')->name('api.license.')->group(function () {
    
    // API cho sudo.vn cập nhật theme_validate
    Route::post('/update', [LicenseController::class, 'updateLicense'])
        // ->middleware(['throttle:license-update'])
        ->name('update');
    
});