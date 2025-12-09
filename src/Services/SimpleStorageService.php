<?php

namespace Sudo\ImageDomainReplace\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Simple Storage Check Service
 * 
 * Chỉ check dung lượng từ theme_validate và ngày hết hạn
 * Tương thích với PHP 7.1+
 */

    
class SimpleStorageService
{
    protected $settingKey = 'theme_validate';
    /**
     * Cache duration: 30 minutes
     */
    const CACHE_DURATION = 1800;
    
    /**
     * Get storage status from theme_validate
     * 
     * @return array
     */
    public function getStorageStatus()
    {
        // try {
            // Lấy dữ liệu từ theme_validate
            $themeData = $this->getThemeValidateData();

            // $licenseData = $this->licenseService->getLicenseData();
            
            // Tính dung lượng hiện tại
            $currentSize = $this->getCurrentStorageSize();

            // Phân tích storage status
            $result = $this->analyzeStorageStatus($currentSize, $themeData);
            return $result;
            
        // } catch (Exception $e) {
        //     Log::error('Simple storage check failed: ' . $e->getMessage());
        //     return $this->getErrorStatus();
        // }
    }
    
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
     * Get current storage size
     * 
     * @return int Size in bytes
     */
    private function getCurrentStorageSize()
    {
        try {
            // Sử dụng phương pháp đơn giản nhất
            $driver = config('app.storage_type') ?? config('SudoMedia.storage_type', 'do');
            if ($driver == 'digitalocean') {
                $driver = 'do';
            }

            if ($driver === 'local') {
                return $this->getLocalStorageSize();
            } else {
                return $this->getCloudStorageSize();
            }
            
        } catch (Exception $e) {
            Log::warning('Cannot calculate storage size: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get local storage size using shell or PHP
     * 
     * @return int
     */
    private function getLocalStorageSize()
    {
        $basePath = public_path();
        
        // Try shell command first (faster)
        if (function_exists('shell_exec') && PHP_OS_FAMILY !== 'Windows') {
            $command = "du -sb " . escapeshellarg($basePath) . " 2>/dev/null | cut -f1";
            $output = shell_exec($command);
            
            if ($output !== null && is_numeric(trim($output))) {
                return (int) trim($output);
            }
        }
        
        // Fallback to PHP calculation
        return $this->calculateDirectorySize($basePath);
    }
    
    /**
     * Simple directory size calculation
     * 
     * @param string $directory
     * @param int $depth
     * @return int
     */
    private function calculateDirectorySize($directory, $depth = 0)
    {
        if ($depth > 5 || !is_dir($directory)) {
            return 0;
        }
        
        $size = 0;
        $excludeDirs = ['vendor', 'node_modules', '.git', 'storage/logs'];
        
        try {
            $iterator = new \DirectoryIterator($directory);
            
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot()) {
                    continue;
                }
                
                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $fileInfo->getPathname());
                
                // Skip excluded directories
                $skip = false;
                foreach ($excludeDirs as $excludeDir) {
                    if (strpos($relativePath, $excludeDir) === 0) {
                        $skip = true;
                        break;
                    }
                }
                
                if ($skip) {
                    continue;
                }
                
                if ($fileInfo->isFile()) {
                    $size += $fileInfo->getSize();
                } elseif ($fileInfo->isDir()) {
                    $size += $this->calculateDirectorySize($fileInfo->getPathname(), $depth + 1);
                }
            }
        } catch (Exception $e) {
            // Silent fail
        }
        
        return $size;
    }
    
    /**
     * Get cloud storage size (simplified)
     * 
     * @return int
     */
    private function getCloudStorageSize()
    {
        try {
            $disk = getStorageDiskIDR();
            // $files = $disk->allFiles();
            $config = config("image-domain-replace.license.storage");
            
            $s3Client = new S3Client([
                'region'  => $config['region'],
                'endpoint'  => $config['endpoint'],
                'version' => 'latest',
                'credentials' => [
                    'key'    => $config['key'],
                    'secret' => $config['secret'],
                ],
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false)
            ]);

            $bucket = $config['bucket'];
            $size = 0;

            $params = [
                'Bucket' => $bucket,
                'MaxKeys' => 1000,
            ];

            $size = 0;

            do {
                $result = $s3Client->listObjectsV2($params);

                if (!empty($result['Contents'])) {
                    foreach ($result['Contents'] as $obj) {
                        $size += $obj['Size'];
                    }
                }

                // Nếu còn trang tiếp theo → lấy ContinuationToken
                if ($result['IsTruncated']) {
                    $params['ContinuationToken'] = $result['NextContinuationToken'];
                } else {
                    break;
                }

            } while (true);

            return $size;
        } catch (Exception $e) {
            return 0;
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
     * Get error status when something fails
     * 
     * @return array
     */
    private function getErrorStatus()
    {
        return [
            'package' => 'unknown',
            'current_size' => 0,
            'current_size_formatted' => '0 B',
            'storage_capacity' => 0,
            'storage_capacity_formatted' => '0 B',
            'total_storage' => 0,
            'total_storage_formatted' => '0 B',
            'available_space' => 0,
            'available_space_formatted' => '0 B',
            'usage_percentage' => 0,
            'status' => 'error',
            'is_warning' => false,
            'is_full' => false,
            'additional_storage' => [],
            'messages' => [
                'error' => 'Không thể kiểm tra dung lượng lúc này.'
            ],
            'last_checked' => date('c')
        ];
    }
    
    /**
     * Quick check if storage needs attention
     * 
     * @return array
     */
    public function quickCheck()
    {
        $status = $this->getStorageStatus();
        
        return [
            'needs_attention' => $status['is_warning'] || $status['is_full'] || !empty($status['additional_storage']['expiring_soon']),
            'usage_percentage' => $status['usage_percentage'],
            'status' => $status['status'],
            'messages' => $status['messages']
        ];
    }
}