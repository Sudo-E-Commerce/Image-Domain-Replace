<?php

use Sudo\ImageDomainReplace\Services\SimpleStorageService;
use Illuminate\Support\Facades\Log;

if (!function_exists('check_storage_usage')) {
    /**
     * Check storage usage từ theme_validate
     * 
     * @return array Storage status
     */
    function check_storage_usage()
    {
        try {
            $service = app(SimpleStorageService::class);
            return $service->getStorageStatus();
        } catch (Exception $e) {
            Log::error('check_storage_usage error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'is_warning' => false,
                'is_full' => false,
                'usage_percentage' => 0,
                'messages' => ['error' => 'Không thể kiểm tra dung lượng']
            ];
        }
    }
}

if (!function_exists('storage_quick_check')) {
    /**
     * Quick check nếu storage cần chú ý
     * 
     * @return array Quick status
     */
    function storage_quick_check()
    {
        // try {
            $service = app(SimpleStorageService::class);
            return $service->quickCheck();
        // } catch (Exception $e) {
        //     Log::error('storage_quick_check error: ' . $e->getMessage());
        //     return [
        //         'needs_attention' => false,
        //         'usage_percentage' => 0,
        //         'status' => 'error',
        //         'messages' => []
        //     ];
        // }
    }
}

if (!function_exists('is_storage_warning')) {
    /**
     * Check nếu storage đang warning
     * 
     * @return bool
     */
    function is_storage_warning()
    {
        $status = check_storage_usage();
        return $status['is_warning'] ?? false;
    }
}

if (!function_exists('is_storage_full')) {
    /**
     * Check nếu storage đã full
     * 
     * @return bool
     */
    function is_storage_full()
    {
        $status = check_storage_usage();
        return $status['is_full'] ?? false;
    }
}

if (!function_exists('get_storage_usage_percentage')) {
    /**
     * Lấy phần trăm sử dụng storage
     * 
     * @return float
     */
    function get_storage_usage_percentage()
    {
        $status = check_storage_usage();
        return $status['usage_percentage'] ?? 0;
    }
}

if (!function_exists('get_storage_messages')) {
    /**
     * Lấy messages từ storage check
     * 
     * @return array
     */
    function get_storage_messages()
    {
        $status = check_storage_usage();
        return $status['messages'] ?? [];
    }
}

if (!function_exists('has_additional_storage_expiring')) {
    /**
     * Check nếu additional storage sắp hết hạn
     * 
     * @return bool
     */
    function has_additional_storage_expiring()
    {
        $status = check_storage_usage();
        $additional = $status['additional_storage'] ?? [];
        return $additional['expiring_soon'] ?? false;
    }
}