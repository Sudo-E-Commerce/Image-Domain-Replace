<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'storage-check', 'namespace' => 'Sudo\ImageDomainReplace\Controllers'], function () {
    
    // Simple storage check endpoint
    Route::get('/status', function() {
        if (!function_exists('check_storage_usage')) {
            return response()->json(['error' => 'Storage helpers not loaded'], 500);
        }
        
        return response()->json(check_storage_usage());
    })->name('storage.check.status');
    
    // Quick check endpoint
    Route::get('/quick', function() {
        if (!function_exists('storage_quick_check')) {
            return response()->json(['error' => 'Storage helpers not loaded'], 500);
        }
        
        return response()->json(storage_quick_check());
    })->name('storage.check.quick');
    
    // Clear cache endpoint  
    Route::post('/clear-cache', function() {
        if (!function_exists('clear_storage_cache')) {
            return response()->json(['error' => 'Storage helpers not loaded'], 500);
        }
        
        $result = clear_storage_cache();
        return response()->json([
            'success' => $result,
            'message' => $result ? 'Cache cleared successfully' : 'Failed to clear cache'
        ]);
    })->name('storage.check.clear-cache');
    
    // Simple test view
    Route::get('/test-view', function() {
        return view('license::storage-notification');
    })->name('storage.check.test-view');
    
});