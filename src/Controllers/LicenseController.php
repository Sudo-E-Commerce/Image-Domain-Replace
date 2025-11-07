<?php

namespace Sudo\ImageDomainReplace\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sudo\ImageDomainReplace\Services\LicenseValidationService;
use Sudo\ImageDomainReplace\Events\LicenseUpdatedEvent;
use Illuminate\Support\Facades\Log;

class LicenseController extends Controller
{
    protected $licenseService;

    public function __construct(LicenseValidationService $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    /**
     * API để cập nhật thông tin license từ sudo.vn
     * Đây là API chính mà sudo.vn sẽ gọi để update license
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateLicense(Request $request)
    {
        try {
            Log::info('License update API called from sudo.vn', [
                'request_data' => $request->all(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString()
            ]);
            
            // Validate request data nếu cần
            $this->validateUpdateRequest($request);
            
            // Gọi service để update license
            $result = $this->licenseService->updateLicense($request->all());
            
            // Trigger event sau khi update thành công
            event(new LicenseUpdatedEvent($result, $request->all()));
            
            Log::info('License updated successfully', [
                'result_length' => strlen($result),
                'timestamp' => now()->toISOString()
            ]);
            
            // Trả về response theo format chuẩn từ license.md
            return response()->json([
                'error' => false,
                'message' => 'License updated successfully',
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (Exception $e) {
            Log::error('License update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'error' => true, 
                'message' => $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    /**
     * API để lấy thông tin license hiện tại
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLicenseInfo()
    {
        try {
            $licenseInfo = $this->licenseService->getLicenseInfo();
            
            Log::debug('License info requested', [
                'status' => $licenseInfo['status'] ?? 'unknown'
            ]);
            
            return response()->json([
                'error' => false,
                'data' => $licenseInfo,
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (Exception $e) {
            Log::error('Get license info failed', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => true,
                'message' => 'Failed to get license information',
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    /**
     * API để validate license hiện tại
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateLicense()
    {
        try {
            $validationResult = $this->licenseService->validateLicense();
            $isValid = is_array($validationResult) ? true : (bool) $validationResult;
            
            Log::debug('License validation requested', [
                'is_valid' => $isValid
            ]);
            
            return response()->json([
                'error' => false,
                'valid' => $isValid,
                'data' => is_array($validationResult) ? $validationResult : null,
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (Exception $e) {
            Log::error('License validation failed', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => true,
                'valid' => false,
                'message' => $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    /**
     * Validate request data cho updateLicense
     * 
     * @param Request $request
     * @throws Exception
     */
    protected function validateUpdateRequest(Request $request)
    {
        // Basic validation
        if (empty($request->all())) {
            throw new Exception('Request data is empty');
        }

        // Có thể thêm validation khác nếu cần
        // Ví dụ: kiểm tra IP whitelist, API key, etc.
        
        $allowedIps = config('image-domain-replace-license.allowed_update_ips', []);
        if (!empty($allowedIps) && !in_array($request->ip(), $allowedIps)) {
            Log::warning('License update from unauthorized IP', [
                'ip' => $request->ip(),
                'allowed_ips' => $allowedIps
            ]);
            // Có thể uncomment dòng dưới nếu muốn strict IP checking
            // throw new Exception('Unauthorized IP address');
        }
    }

    /**
     * API để clear cache (admin only)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearCache()
    {
        try {
            // Gọi các cache clear commands
            \Artisan::call('cache:clear');
            \Artisan::call('config:clear');
            \Artisan::call('view:clear');
            
            // Gọi sudo:clear nếu có
            try {
                \Artisan::call('sudo:clear');
            } catch (Exception $e) {
                Log::info('sudo:clear command not available');
            }
            
            Log::info('Cache cleared successfully');
            
            return response()->json([
                'error' => false,
                'message' => 'Cache cleared successfully',
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (Exception $e) {
            Log::error('Cache clear failed', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => true,
                'message' => 'Failed to clear cache: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }
}