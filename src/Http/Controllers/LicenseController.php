<?php

namespace Sudo\ImageDomainReplace\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Sudo\ImageDomainReplace\Services\LicenseValidationService;

class LicenseController extends Controller
{
    protected $licenseService;

    public function __construct(LicenseValidationService $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    /**
     * API endpoint để sudo.vn update license
     * POST /api/license/update
     */
    public function updateLicense(Request $request): JsonResponse
    {
        try {

            // Validate marketplace token
            if (!$this->validateMarketplaceToken($request)) {
                Log::warning('[License API] Invalid marketplace token');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access - Invalid token'
                ], 401);
            }

            // Validate marketplace URL
            if (!$this->validateMarketplaceURL($request)) {
                Log::warning('[License API] Invalid marketplace URL');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access - Invalid source'
                ], 401);
            }

            // Validate request data
            $validation = $this->validateUpdateRequest($request);
            if (!$validation['valid']) {
                Log::warning('[License API] Invalid request data', $validation);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid request data',
                    'errors' => $validation['errors']
                ], 422);
            }

            // Map sudo.vn data format to our license format
            $licenseData = $this->mapSudoDataToLicense($request->all());

            // Update license
            $result = $this->licenseService->updateLicense($licenseData);

            if ($result) {
                Log::info('[License API] License updated successfully', $licenseData);
                
                // Clear cache if configured
                if (config('image-domain-replace.license.auto_clear_cache', true)) {
                    Artisan::call('cache:clear');
                    Log::info('[License API] Cache cleared after license update');
                }

                return response()->json([
                    'success' => true,
                    'message' => 'License updated successfully'
                ]);
            }

            Log::error('[License API] Failed to update license');
            return response()->json([
                'success' => false,
                'message' => 'Failed to update license'
            ], 500);

        } catch (\Exception $e) {
            Log::error('[License API] Exception during license update', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Validate marketplace token from sudo.vn
     */
    protected function validateMarketplaceToken(Request $request): bool
    {
        $token = $request->header('token');
        $expectedToken = config('image-domain-replace.license.marketplace.token');
        
        return !empty($token) && !empty($expectedToken) && hash_equals($expectedToken, $token);
    }

    /**
     * Validate marketplace URL from sudo.vn
     */
    protected function validateMarketplaceURL(Request $request): bool
    {
        $referer = $request->header('referer');
        $origin = $request->header('origin');
        $expectedUrl = config('image-domain-replace.license.marketplace.url', 'https://sudo.vn');
        
        // Check referer hoặc origin có khớp với MARKETPLACE_URL không
        if (!empty($referer) && strpos($referer, $expectedUrl) === 0) {
            return true;
        }

        if (!empty($origin) && $origin === $expectedUrl) {
            return true;
        }

        // Fallback: cho phép nếu không có referer/origin (có thể là curl/API call)
        // Nhưng vẫn cần token đúng
        return empty($referer) && empty($origin);
    }

    /**
     * Validate request data from sudo.vn
     */
    protected function validateUpdateRequest(Request $request): array
    {
        $errors = [];
        $data = $request->all();

        // Required fields from sudo.vn - updated to use end_time
        $requiredFields = ['domain', 'end_time'];
        
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Validate domain format
        if (!empty($data['domain']) && !filter_var($data['domain'], FILTER_VALIDATE_DOMAIN)) {
            $errors[] = "Invalid domain format";
        }

        // Validate end_time format
        if (!empty($data['end_time'])) {
            try {
                $expiryTime = \Carbon\Carbon::parse($data['end_time']);
                if ($expiryTime->isPast()) {
                    Log::warning('[License API] License already expired', ['end_time' => $data['end_time']]);
                }
            } catch (\Exception $e) {
                $errors[] = "Invalid end_time format";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Map sudo.vn data format to our license format
     */
    protected function mapSudoDataToLicense(array $sudoData): array
    {
        $licenseData = [
            'domain' => $sudoData['domain'],
            'license_key' => $sudoData['package'] ?? 'sudo-license',
            'status' => 'active',
            'end_time' => $sudoData['end_time'],
            'package' => $sudoData['package'] ?? '',
            'contact_name' => $sudoData['contact_name'] ?? '',
            'contact_phone' => $sudoData['contact_phone'] ?? '',
            'contact_site' => $sudoData['contact_site'] ?? '',
            'type' => $sudoData['type'] ?? '',
            'role_disable' => $sudoData['role_disable'] ?? '',
            'theme_active' => $sudoData['theme_active'] ?? '',
            'storage_capacity' => $sudoData['storage_capacity'] ?? 0,
            'storage_additional' => $sudoData['storage_additional'] ?? []
        ];

        return $licenseData;
    }

    /**
     * Get current license status
     * GET /api/license/status
     */
    public function getLicenseStatus(Request $request): JsonResponse
    {
        try {
            // Validate marketplace token
            if (!$this->validateMarketplaceToken($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access - Invalid token'
                ], 401);
            }

            // Validate marketplace URL
            if (!$this->validateMarketplaceURL($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access - Invalid source'
                ], 401);
            }

            $license = $this->licenseService->getCurrentLicense();
            
            return response()->json([
                'success' => true,
                'data' => $license
            ]);

        } catch (\Exception $e) {
            Log::error('[License API] Exception during license status check', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error'
            ], 500);
        }
    }
}