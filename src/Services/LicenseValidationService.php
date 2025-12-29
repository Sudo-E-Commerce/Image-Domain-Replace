<?php

namespace Sudo\ImageDomainReplace\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

class LicenseValidationService
{
    protected $settingKey = 'theme_validate';
    
    /**
     * Cập nhật thông tin license
     * Đây là method chính được gọi từ API updateLicense
     * 
     * @param array $data
     * @return string
     * @throws Exception
     */
    public function updateLicense(array $data)
    {
        try {
            // Xử lý dữ liệu theo logic từ license.md
            $processedData = $this->processLicenseData($data);
            
            // Encode dữ liệu
            $encodedData = base64_encode(json_encode($processedData));
            
            // Cập nhật hoặc tạo setting
            $this->updateOrCreateSetting($this->settingKey, $encodedData);
            
            // Clear cache và các tác vụ liên quan
            $this->clearCacheAndRefresh();
            
            return $encodedData;
            
        } catch (Exception $e) {
            Log::error('Failed to update license', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Validate license hiện tại
     * Logic theo license.md
     * 
     * @return bool|array
     */
    public function validateLicense()
    {
        try {
            $data = $this->getLicenseData();
            
            if (empty($data)) {
                Log::warning('No license data found');
                return $this->getDefaultLicenseInfo();
            }
            
            // Kiểm tra domain theo logic từ license.md
            if (isset($data['domain']) && !$this->validateDomain($data['domain'])) {
                Log::warning('Domain validation failed', [
                    'expected' => $data['domain'],
                    'current' => $this->getCurrentDomain()
                ]);
                return $this->getDefaultLicenseInfo();
            }
            
            // Kiểm tra thời hạn theo logic từ license.md
            if (!$this->validateExpiry($data)) {
                Log::warning('License expired', [
                    'end_time' => $data['end_time'] ?? 'not set'
                ]);
                return $this->getDefaultLicenseInfo();
            }
            
            return $data;
            
        } catch (Exception $e) {
            Log::error('License validation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Lấy thông tin license hiện tại
     * 
     * @return array
     */
    public function getLicenseInfo()
    {
        $data = $this->getLicenseData();
        
        if (empty($data)) {
            return [
                'status' => 'not_found',
                'message' => 'No license data found',
                'domain' => null,
                'end_time' => null,
                'current_domain' => $this->getCurrentDomain(),
                'is_expired' => true
            ];
        }
        
        $isValid = $this->validateLicense();
        
        return [
            'status' => (is_array($isValid) && !empty($isValid)) ? 'valid' : 'invalid',
            'domain' => $data['domain'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'current_domain' => $this->getCurrentDomain(),
            'is_expired' => !$this->validateExpiry($data),
            'validation_result' => is_array($isValid) ? 'passed' : 'failed'
        ];
    }

    /**
     * Process license data theo logic từ license.md
     * 
     * @param array $data
     * @return array
     */
    protected function processLicenseData(array $data)
    {
        try {
            // Loại bỏ các trường không cần thiết theo license.md
            $unset = ['_token', 'redirect', 'setLanguage'];
            foreach ($unset as $value) {
                unset($data[$value]);
            }
            
            // XSS protection - sử dụng fallback an toàn
            $data = $this->removeScriptArrayFallback($data);
            
            return $data;
            
        } catch (Exception $e) {
            Log::error('processLicenseData failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input_data' => $data
            ]);
            // Trả về dữ liệu gốc nếu xử lý thất bại
            return $data;
        }
    }

    /**
     * Fallback cho removeScriptArray function
     * Xử lý an toàn với nested arrays và các data types khác nhau
     */
    protected function removeScriptArrayFallback(array $data)
    {
        try {
            Log::debug('removeScriptArrayFallback: Processing array', [
                'array_keys' => array_keys($data)
            ]);
            
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    // XSS protection cho string
                    $data[$key] = $this->cleanString($value);
                } elseif (is_array($value)) {
                    // Đệ quy xử lý nested arrays
                    $data[$key] = $this->removeScriptArrayFallback($value);
                } elseif (is_object($value)) {
                    // Convert object thành array rồi xử lý
                    $data[$key] = $this->removeScriptArrayFallback((array) $value);
                } else {
                    // Giữ nguyên các types khác (int, bool, null, etc.)
                    $data[$key] = $value;
                }
            }
            
            Log::debug('removeScriptArrayFallback: Processing completed');
            return $data;
            
        } catch (Exception $e) {
            Log::error('removeScriptArrayFallback failed', [
                'error' => $e->getMessage(),
                'data_type' => gettype($data)
            ]);
            // Trả về dữ liệu gốc nếu xử lý thất bại
            return $data;
        }
    }

    /**
     * Clean string data - loại bỏ script tags và XSS
     */
    protected function cleanString($value)
    {
        if (!is_string($value)) {
            return $value;
        }
        
        // Basic XSS protection
        $cleaned = strip_tags($value, '<p><br><strong><em><u><a>');
        $cleaned = htmlspecialchars($cleaned, ENT_QUOTES, 'UTF-8', false);
        
        return $cleaned;
    }

    /**
     * Lấy dữ liệu license từ database
     * Support cả 2 trường hợp: settings table và option table
     * 
     * @return array
     */
    public function getLicenseData()
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
                return [];
            }
            
            // Decode dữ liệu
            $decodedData = json_decode(base64_decode($setting->value), true);
            
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
     * Cập nhật hoặc tạo setting
     * Support cả 2 trường hợp:
     * - Bảng 'settings' với column 'key' 
     * - Bảng 'option' với column 'name'
     * 
     * @param string $key
     * @param string $value
     */
    protected function updateOrCreateSetting($key, $value)
    {
        try {
            // Kiểm tra bảng 'settings' trước (ưu tiên)
            if (Schema::hasTable('settings')) {
                return $this->updateSettingsTable($key, $value);
            }
            
            // Nếu không có bảng 'settings', kiểm tra bảng 'options'
            if (Schema::hasTable('options')) {
                return $this->updateOptionsTable($key, $value);
            }
            
            // Nếu cả 2 bảng đều không có
            throw new Exception('Neither settings table nor options table exists');
            
        } catch (Exception $e) {
            Log::error('updateOrCreateSetting failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'key' => $key,
                'value_type' => gettype($value)
            ]);
            throw $e;
        }
    }

    /**
     * Cập nhật bảng 'settings' với column 'key'
     */
    protected function updateSettingsTable($key, $value)
    {
        try {
            $exists = DB::table('settings')->where('key', $key)->exists();
            
            if ($exists) {
                DB::table('settings')
                    ->where('key', $key)
                    ->update([
                        'value' => $value,
                    ]);
            } else {
                DB::table('settings')->insert([
                    'key' => $key,
                    'locale' => '',
                    'value' => $value,
                ]);
            }
            
        } catch (Exception $e) {
            Log::error('updateSettingsTable failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'key' => $key,
                'value_type' => gettype($value)
            ]);
            throw $e;
        }
    }

    /**
     * Cập nhật bảng 'options' với column 'name'
     */
    protected function updateOptionsTable($key, $value)
    {
        $exists = DB::table('options')->where('name', $key)->exists();
        
        if ($exists) {
            DB::table('options')
                ->where('name', $key)
                ->update([
                    'value' => $value
                ]);
            Log::info('Updated existing record in options table', ['name' => $key]);
        } else {
            DB::table('options')->insert([
                'name' => $key,
                'value' => $value,
            ]);
            Log::info('Created new record in options table', ['name' => $key]);
        }
    }

    /**
     * Validate domain theo logic từ license.md
     * 
     * @param string $expectedDomain
     * @return bool
     */
    protected function validateDomain($expectedDomain)
    {
        $currentDomain = $this->getCurrentDomain();
        return $expectedDomain === $currentDomain;
    }

    /**
     * Lấy domain hiện tại
     * Sử dụng logic từ license.md: eval(base64_decode('cmV0dXJuIGdldEhvc3RGcm9tQ29uZmlnKCk7'))
     * 
     * @return string
     */
    protected function getCurrentDomain()
    {
        // Kiểm tra nếu có function getHostFromConfig
        if (function_exists('getHostFromConfig')) {
            return getHostFromConfig();
        }
        
        // Fallback: lấy từ config hoặc request
        return config('app.url') ? parse_url(config('app.url'), PHP_URL_HOST) : 
               (request() ? request()->getHost() : 'localhost');
    }

    /**
     * Validate thời hạn license theo logic từ license.md
     * Chỉ sử dụng end_time
     * 
     * @param array $data
     * @return bool
     */
    protected function validateExpiry($data)
    {
        // Chỉ sử dụng end_time
        $expiryTime = $data['end_time'] ?? null;
        
        if (!$expiryTime || empty($expiryTime)) {
            return true; // Không có thời hạn = vĩnh viễn
        }
        
        try {
            $endDate = date('Y-m-d', strtotime($expiryTime));
            $currentDate = date('Y-m-d');
            
            Log::debug('License expiry check', [
                'end_time' => $expiryTime,
                'end_date' => $endDate,
                'current_date' => $currentDate,
                'is_valid' => $endDate >= $currentDate
            ]);
            
            return $endDate >= $currentDate;
            
        } catch (Exception $e) {
            Log::error('Failed to validate expiry date', [
                'end_time' => $expiryTime,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get default license info theo logic từ license.md
     * Gọi BaseHelper::Z2V0SW5mb21hdGlvbkxpY2Vuc2U()
     */
    protected function getDefaultLicenseInfo()
    {
        try {
            if (class_exists('\Sudo\Base\Facades\BaseHelper')) {
                return \Sudo\Base\Facades\BaseHelper::Z2V0SW5mb21hdGlvbkxpY2Vuc2U();
            }
        } catch (Exception $e) {
            Log::warning('Could not get default license info', [
                'error' => $e->getMessage()
            ]);
        }
        
        return [];
    }

    /**
     * Get current license (for API endpoints)
     * 
     * @return array
     */
    public function getCurrentLicense()
    {
        return $this->getLicenseInfo();
    }

    /**
     * Clear cache và refresh theo logic từ license.md
     */
    protected function clearCacheAndRefresh()
    {
        try {
            // Clear các cache cơ bản
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            
            // Gọi command sudo:clear nếu có (từ license.md)
            try {
                Artisan::call('sudo:clear');
            } catch (Exception $e) {
            }
            
            // Trigger ClearCacheEvent nếu có
            if (class_exists('\Sudo\PluginManagement\Events\ClearCacheEvent')) {
                event(new \Sudo\PluginManagement\Events\ClearCacheEvent());
            }
            
            // Update SettingStore nếu có
            if (class_exists('\Sudo\Base\Supports\SettingStore')) {
                $settingStore = app(\Sudo\Base\Supports\SettingStore::class);
                $settingStore->set('media_size_calculator', null);
                $settingStore->save();
            }
            
        } catch (Exception $e) {
            Log::warning('Failed to clear cache', [
                'error' => $e->getMessage()
            ]);
        }
    }
}