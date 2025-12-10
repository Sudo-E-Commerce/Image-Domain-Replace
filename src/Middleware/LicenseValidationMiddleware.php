<?php

namespace Sudo\ImageDomainReplace\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Sudo\ImageDomainReplace\Services\LicenseValidationService;

/**
 * License Validation Middleware
 * Blocks website access based on license validation and expiration
 */
class LicenseValidationMiddleware
{
    protected $licenseService;

    public function __construct(LicenseValidationService $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    public function handle(Request $request, Closure $next)
    {
        // Check if middleware is enabled
        if (!config('image-domain-replace.license.middleware.enabled', true)) {
            return $next($request);
        }
        
        // Skip validation for excluded routes
        if ($this->shouldSkipValidation($request)) {
            return $next($request);
        }

        // Validate license
        $validation = $this->validateLicenseWithExpiry($request);
        
        if (!$validation['valid']) {
            return $this->blockAccess($request, $validation['reason'], $validation['details']);
        }

        return $next($request);
    }

    /**
     * Check if validation should be skipped for this request
     */
    private function shouldSkipValidation(Request $request): bool
    {
        $excludeRoutes = config('image-domain-replace.license.middleware.exclude_routes', [
            'api/license/*',
            'admin/license/*',
            '_debugbar/*',
            'test-provider',
        ]);

        $currentPath = $request->path();

        foreach ($excludeRoutes as $pattern) {
            if ($this->matchesPattern($currentPath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if path matches pattern (supports wildcards)
     */
    private function matchesPattern(string $path, string $pattern): bool
    {
        // Escape special regex characters except for *
        $pattern = preg_quote($pattern, '/');
        // Replace escaped * with .* for wildcard matching
        $pattern = str_replace('\*', '.*', $pattern);
        $result = preg_match('/^' . $pattern . '$/', $path);
        return $result === 1;
    }

    /**
     * Validate license with expiry check
     */
    private function validateLicenseWithExpiry(Request $request): array
    {
        try {
            // Get license data
            $licenseData = $this->licenseService->getLicenseData();
            // Nếu chưa có license data trong DB, cho phép truy cập bình thường
            // Đây là trạng thái ban đầu khi chưa được activate license
            if (!$licenseData || empty($licenseData)) {
                return [
                    'valid' => true,
                    'reason' => 'no_license_data',
                    'details' => [
                        'message' => 'No license data found - initial state',
                        'current' => $this->getCurrentDomain($request)
                    ]
                ];
            }

            // Check domain validation if strict mode enabled
            $domainValid = $this->validateDomain($request, $licenseData);
            if (!$domainValid['valid']) {
                return $domainValid;
            }
            
            // Check expiry - chỉ sử dụng end_time
            $expiryTime = $licenseData['end_time'] ?? null;
            if (!empty($expiryTime)) {
                $expiryCheck = $this->checkExpiry($expiryTime);
                if (isset($expiryCheck['valid']) && $expiryCheck['valid'] == false) {
                    return $expiryCheck;
                }
            }
            
            return [
                'valid' => true,
                'reason' => 'valid',
                'details' => [
                    'license' => $licenseData,
                    'domain' => $this->getCurrentDomain($request)
                ]
            ];

        } catch (\Exception $e) {
            Log::error('License validation error: ' . $e->getMessage());
            
            // Nếu có lỗi validation, cho phép truy cập để tránh crash website
            Log::warning('License validation failed, allowing access to prevent website crash');
            return [
                'valid' => true,
                'reason' => 'validation_error_allow',
                'details' => [
                    'error' => $e->getMessage(),
                    'current' => $this->getCurrentDomain($request)
                ]
            ];
        }
    }

    /**
     * Get current domain from request
     */
    private function getCurrentDomain(Request $request): string
    {
        return env('APP_URL') ? parse_url(env('APP_URL'), PHP_URL_HOST) : $request->getHost();
    }

    /**
     * Validate domain against license
     */
    private function validateDomain(Request $request, array $licenseData): array
    {
        $currentDomain = $this->getCurrentDomain($request);
        $allowedHost = $licenseData['domain'] ?? null;

        // If license has specific domain, check it
        if (isset($licenseData['domain']) && !empty($licenseData['domain'])) {
            if ($currentDomain !== $licenseData['domain']) {
                return [
                    'valid' => false,
                    'reason' => 'domain_mismatch',
                    'details' => [
                        'allowed' => $licenseData['domain'],
                        'current' => $currentDomain
                    ]
                ];
            }
        }

        // Check against config allowed host
        if ($allowedHost && $currentDomain !== $allowedHost) {
            return [
                'valid' => false,
                'reason' => 'host_not_allowed',
                'details' => [
                    'allowed' => $allowedHost,
                    'current' => $currentDomain
                ]
            ];
        }

        return ['valid' => true];
    }

    /**
     * Check license expiry
     * Chỉ sử dụng end_time
     */
    private function checkExpiry(string $expiryTime): array
    {
        try {
            $issueTimestamp = strtotime($expiryTime);
            if ($issueTimestamp === false) {
                return [
                    'valid' => false,
                    'reason' => 'invalid_issue_date',
                    'details' => ['end_time' => $expiryTime]
                ];
            }
            $expiryTimestamp = $issueTimestamp;
            if (time() > $expiryTimestamp) {
                return [
                    'valid' => false,
                    'reason' => 'license_expired',
                    'details' => [
                        'end_time' => $expiryTime,
                        'expiryDate' => date('Y-m-d H:i:s', $expiryTimestamp),
                        'currentDate' => date('Y-m-d H:i:s')
                    ]
                ];
            }

            return [
                'valid' => true,
                'details' => [
                    'end_time' => $expiryTime,
                    'expiryDate' => date('Y-m-d H:i:s', $expiryTimestamp),
                    'daysRemaining' => floor(($expiryTimestamp - time()) / (24 * 60 * 60))
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Error checking license expiry: ' . $e->getMessage());
            return [
                'valid' => false,
                'reason' => 'expiry_check_error',
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Block access with appropriate response
     */
    private function blockAccess(Request $request, string $reason, array $details = [])
    {
        // Use custom block function for web requests
        $blockHtml = $this->openNoticeEXp();
        return response($blockHtml, 403);
    }

    /**
     * Function to show license expiry notice
     */
    function openNoticeEXp()
    {
        return '<!doctype html>
            <html lang="vi">
            <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>Hosting đã hết hạn</title>
            <script src="https://cdn.tailwindcss.com"></script>
            </head>
            <body class="min-h-screen bg-gray-50 flex items-center justify-center p-6 dark:bg-gray-900">
            <main class="w-full max-w-2xl mx-auto">
                <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl shadow-lg p-8 sm:p-12">
                <div class="flex items-start gap-6">
                    <!-- Icon -->
                    <div class="flex-shrink-0">
                    <div class="w-16 h-16 rounded-full bg-red-50 dark:bg-red-900/30 flex items-center justify-center ring-1 ring-red-100 dark:ring-red-800">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01" />
                        </svg>
                    </div>
                    </div>

                    <!-- Content -->
                    <div class="flex-1">
                    <h1 class="text-2xl sm:text-3xl font-semibold text-gray-900 dark:text-gray-100">Hosting của bạn đã hết hạn</h1>
                    <p class="mt-3 text-gray-600 dark:text-gray-300 leading-relaxed">Trang web hiện không thể truy cập do hosting đã hết hạn. Để phục hồi dịch vụ, vui lòng liên hệ ngay nhà cung cấp hosting để gia hạn hoặc kiểm tra thông tin thanh toán.</p>

                    <div class="mt-6 flex flex-col sm:flex-row sm:items-center gap-4 justify-evenly">
                        <a href="https://sudo.vn" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center px-5 py-3 rounded-lg bg-red-600 hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-red-500 text-white font-medium shadow-sm">
                        Đi tới sudo.vn
                        <svg xmlns="http://www.w3.org/2000/svg" class="ml-3 w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 3h7v7m0 0L10 21l-7-7 11-11z" />
                        </svg>
                        </a>

                        <button type="button" onclick="location.reload()" class="inline-flex items-center justify-center px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium hover:bg-gray-50">
                        Thử tải lại
                        </button>
                    </div>

                    <div class="mt-6 text-sm text-gray-500 dark:text-gray-400 text-center">Nếu bạn không phải quản trị viên của trang này, vui lòng thông báo cho người quản trị hoặc bộ phận IT.
                    </div>
                    </div>
                </div>

                <!-- Footer help -->
                <footer class="mt-8 border-t border-gray-100 dark:border-gray-700 pt-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 text-center">Cần hỗ trợ thêm? Gửi email tới <a href="mailto:support@sudo.vn" class="underline">support@sudo.vn</a> hoặc gọi hotline nhà cung cấp.</p>
                </footer>
                </section>
            </main>
            </body>
        </html>';
    }

    /**
     * Get user-friendly block message
     */
    private function getBlockMessage(string $reason): string
    {
        $messages = [
            'no_license' => 'No valid license found. Please contact support.',
            'domain_mismatch' => 'License is not valid for this domain.',
            'host_not_allowed' => 'Access from this host is not permitted.',
            'license_expired' => 'License has expired. Please renew your license.',
            'invalid_issue_date' => 'License has invalid issue date.',
            'validation_error' => 'License validation failed. Please try again.',
            'expiry_check_error' => 'Unable to validate license expiry.'
        ];

        return $messages[$reason] ?? 'Access denied due to license validation failure.';
    }
}