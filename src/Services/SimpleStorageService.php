<?php

namespace Sudo\ImageDomainReplace\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class SimpleStorageService
{
    protected $settingKey = 'theme_validate';
    /**
     * Cache duration: 30 minutes
     */
    const CACHE_DURATION = 1800;
    
    /**
     * Get theme_validate data
     * 
     * @return array
     */
    private function getThemeValidateData()
    {
        try {
            $setting = null;
            
            // Kiểm tra bảng 'settings' trước (ưu tiên)
            if (Schema::hasTable('settings')) {
                $setting = DB::table('settings')
                    ->where('key', $this->settingKey)
                    ->first();
                Log::debug('Checked settings table', ['found' => !is_null($setting)]);
            }
            
            // Nếu không tìm thấy trong settings, kiểm tra bảng 'options'
            if (!$setting && Schema::hasTable('options')) {
                $setting = DB::table('options')
                    ->where('name', $this->settingKey)
                    ->first();
                Log::debug('Checked options table', ['found' => !is_null($setting)]);
            }
            
            // Nếu không tìm thấy record nào
            if (!$setting) {
                Log::info('No license data found in any table');
                return [];
            }
            
            // Decode dữ liệu
            $decodedData = json_decode(base64_decode($setting->value), true);
            
            Log::info('License data retrieved successfully', [
                'data_keys' => array_keys($decodedData ?: []),
                'data_length' => strlen($setting->value)
            ]);
            
            return $decodedData ?: [];
            
        } catch (Exception $e) {
            Log::error('Failed to get license data', [
                'error' => $e->getMessage(),
                'setting_key' => $this->settingKey
            ]);
            return [];
        }
    }
    
    /**
     * Analyze storage status based on theme_validate data
     * 
     * @param int $currentSize
     * @param array $themeData
     * @return array
     */
    private function analyzeStorageStatus($currentSize, $themeData)
    {
        // Lấy storage capacity từ theme_validate
        $storageCapacity = isset($themeData['storage_capacity']) ? (int) $themeData['storage_capacity'] : 0;
        $storageAdditional = isset($themeData['storage_additional']) ? $themeData['storage_additional'] : [];
        $package = isset($themeData['package']) ? $themeData['package'] : 'basic';
        
        // Xác định storage limit theo package nếu không có storage_capacity
        if (!$storageCapacity) {
            $storageCapacity = 2147483648; // Mặc định 2GB
        }
        
        // Xử lý additional storage
        $additionalInfo = [];
        $totalStorage = $storageCapacity;
        $additionalExpired = false;
        $additionalExpiringSoon = false;
        
        if (!empty($storageAdditional['storage_capacity']) && !empty($storageAdditional['addition_end_time'])) {
            $endTime = date('Y-m-d', strtotime($storageAdditional['addition_end_time']));
            $today = date('Y-m-d');
            
            if ($endTime < $today) {
                $additionalExpired = true;
            } else {
                // Check nếu sắp hết hạn trong 15 ngày
                $daysLeft = (strtotime($endTime) - strtotime($today)) / (60 * 60 * 24);
                $additionalExpiringSoon = $daysLeft <= 15;
                
                if (!$additionalExpired) {
                    $totalStorage += (int) $storageAdditional['storage_capacity'];
                }
            }
            
            $additionalInfo = [
                'capacity' => (int) $storageAdditional['storage_capacity'],
                'capacity_formatted' => $this->formatBytes((int) $storageAdditional['storage_capacity']),
                'start_date' => isset($storageAdditional['addition_start_time']) ? date('Y-m-d', strtotime($storageAdditional['addition_start_time'])) : null,
                'end_date' => $endTime,
                'expired' => $additionalExpired,
                'expiring_soon' => $additionalExpiringSoon,
                'days_left' => $additionalExpired ? 0 : max(0, (int) $daysLeft)
            ];
        }
        
        // Tính toán usage percentage
        $usagePercentage = $totalStorage > 0 ? ($currentSize / $totalStorage) * 100 : 0;
        
        // Xác định status
        $status = 'ok';
        $isWarning = false;
        $isFull = false;
        
        if ($usagePercentage >= 100) {
            $status = 'full';
            $isFull = true;
            $isWarning = true;
        } elseif ($usagePercentage >= 90) {
            $status = 'warning';
            $isWarning = true;
        }
        
        // Tạo messages
        $messages = $this->generateMessages($currentSize, $totalStorage, $usagePercentage, $additionalInfo);
        
        return [
            'package' => $package,
            'current_size' => $currentSize,
            'current_size_formatted' => $this->formatBytes($currentSize),
            'storage_capacity' => $storageCapacity,
            'storage_capacity_formatted' => $this->formatBytes($storageCapacity),
            'total_storage' => $totalStorage,
            'total_storage_formatted' => $this->formatBytes($totalStorage),
            'available_space' => max(0, $totalStorage - $currentSize),
            'available_space_formatted' => $this->formatBytes(max(0, $totalStorage - $currentSize)),
            'usage_percentage' => round($usagePercentage, 2),
            'status' => $status,
            'is_warning' => $isWarning,
            'is_full' => $isFull,
            'additional_storage' => $additionalInfo,
            'messages' => $messages,
            'last_checked' => date('c')
        ];
    }
    
    /**
     * Generate appropriate messages
     * 
     * @param int $currentSize
     * @param int $totalStorage
     * @param float $percentage
     * @param array $additionalInfo
     * @return array
     */
    private function generateMessages($currentSize, $totalStorage, $percentage, $additionalInfo)
    {
        $messages = [];
        
        // Storage usage messages
        if ($percentage >= 100) {
            $messages['storage_full'] = "Dung lượng đã vượt giới hạn! Bạn đang sử dụng {$this->formatBytes($currentSize)} / {$this->formatBytes($totalStorage)} ({$percentage}%).";
        } elseif ($percentage >= 90) {
            $messages['storage_warning'] = "Cảnh báo: Dung lượng sắp đầy! Bạn đang sử dụng {$this->formatBytes($currentSize)} / {$this->formatBytes($totalStorage)} ({$percentage}%).";
        }
        
        // Additional storage messages
        if (!empty($additionalInfo)) {
            if ($additionalInfo['expired']) {
                $messages['additional_expired'] = "Dung lượng bổ sung {$additionalInfo['capacity_formatted']} đã hết hạn vào ngày {$additionalInfo['end_date']}.";
            } elseif ($additionalInfo['expiring_soon']) {
                $messages['additional_expiring'] = "Dung lượng bổ sung {$additionalInfo['capacity_formatted']} sẽ hết hạn vào ngày {$additionalInfo['end_date']} (còn {$additionalInfo['days_left']} ngày).";
            }
        }
        
        return $messages;
    }
    
    /**
     * Format bytes to human readable
     * 
     * @param int $bytes
     * @return string
     */
    public function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        if ($bytes == 0) {
            return '0 B';
        }
        
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Quick check if storage needs attention
     * Sử dụng dữ liệu từ DB (storage_cdn) thay vì gọi S3
     * Cache trong 1 ngày
     * 
     * @return array
     */
    public function quickCheck()
    {
        $cacheKey = 'storage_quick_check';
        $cacheDuration = 86400; // 1 ngày = 86400 giây
        
        // Kiểm tra cache
        // if (Cache::has($cacheKey)) {
        //     return Cache::get($cacheKey);
        // }
        
        try {
            // Lấy dữ liệu từ theme_validate
            $themeData = $this->getThemeValidateData();
            
            // Lấy current size từ storage_cdn trong DB thay vì gọi S3
            $currentSize = $this->getStorageCdnFromDB();
            // Phân tích storage status
            $result = $this->analyzeStorageStatus($currentSize, $themeData);
            
            // Tạo response
            $response = [
                'needs_attention' => $result['is_warning'] || $result['is_full'] || !empty($result['additional_storage']['expiring_soon']),
                'usage_percentage' => $result['usage_percentage'],
                'status' => $result['status'],
                'messages' => $result['messages'],
                'current_size' => $result['current_size'],
                'current_size_formatted' => $result['current_size_formatted'],
                'total_storage' => $result['total_storage'],
                'total_storage_formatted' => $result['total_storage_formatted'],
            ];
            
            // Cache kết quả trong 1 ngày
            Cache::put($cacheKey, $response, $cacheDuration);
            
            return $response;
            
        } catch (Exception $e) {
            Log::error('Quick check failed: ' . $e->getMessage());
            return [
                'needs_attention' => false,
                'usage_percentage' => 0,
                'status' => 'error',
                'messages' => ['error' => 'Không thể kiểm tra dung lượng']
            ];
        }
    }
    
    /**
     * Lấy dung lượng CDN từ DB (key: storage_cdn)
     * 
     * @return int Size in bytes
     */
    private function getStorageCdnFromDB()
    {
        try {
            $setting = null;
            
            // Kiểm tra bảng 'settings' trước
            if (Schema::hasTable('settings')) {
                $setting = DB::table('settings')
                    ->where('key', 'storage_cdn')
                    ->first();
            }
            
            // Nếu không tìm thấy trong settings, kiểm tra bảng 'options'
            if (!$setting && Schema::hasTable('options')) {
                $setting = DB::table('options')
                    ->where('name', 'storage_cdn')
                    ->first();
            }
            
            // Nếu không tìm thấy, trả về 0
            if (!$setting) {
                Log::warning('storage_cdn not found in database');
                return 0;
            }
            
            // Parse dữ liệu
            $decoded = base64_decode($setting->value);
            $data = json_decode($decoded, true);
            
            if (isset($data['size'])) {
                return (int) $data['size'];
            }
            if(isset($data['size_bytes'])) {
                return (int) $data['size_bytes'];
            }
            return 0;
            
        } catch (Exception $e) {
            Log::error('Failed to get storage_cdn from DB: ' . $e->getMessage());
            return 0;
        }
    }
}