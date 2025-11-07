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
        // Debug log to check if middleware is called
        Log::info('LicenseValidationMiddleware called', ['path' => $request->path()]);

        // Check if middleware is enabled
        if (!config('image-domain-replace.license.middleware.enabled', true)) {
            Log::info('License middleware disabled');
            return $next($request);
        }
        
        // Skip validation for excluded routes
        if ($this->shouldSkipValidation($request)) {
            Log::info('Skipping validation for route', ['path' => $request->path()]);
            return $next($request);
        }

        // Validate license
        $validation = $this->validateLicenseWithExpiry($request);
        Log::info('License validation result', $validation);
        
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
            if (!$licenseData) {
                return [
                    'valid' => false,
                    'reason' => 'no_license',
                    'details' => [
                        'message' => 'No license found',
                        'current' => $this->getCurrentDomain($request)
                    ]
                ];
            }

            // Check domain validation if strict mode enabled
                $domainValid = $this->validateDomain($request, $licenseData);
                if (!$domainValid['valid']) {
                    return $domainValid;
                }
            // Check expiry
            if (isset($licenseData['end_time']) && !empty($licenseData['end_time'])) {
                $expiryCheck = $this->checkExpiry($licenseData['end_time']);
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
            
            return [
                'valid' => false,
                'reason' => 'validation_error',
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
     */
    private function checkExpiry(string $end_time): array
    {
        try {
            $issueTimestamp = strtotime($end_time);
            if ($issueTimestamp === false) {
                return [
                    'valid' => false,
                    'reason' => 'invalid_issue_date',
                    'details' => ['end_time' => $end_time]
                ];
            }
            $expiryTimestamp = $issueTimestamp;
            if (time() > $expiryTimestamp) {
                return [
                    'valid' => false,
                    'reason' => 'license_expired',
                    'details' => [
                        'end_time' => $end_time,
                        'expiryDate' => date('Y-m-d H:i:s', $expiryTimestamp),
                        'currentDate' => date('Y-m-d H:i:s')
                    ]
                ];
            }

            return [
                'valid' => true,
                'details' => [
                    'end_time' => $end_time,
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
        echo eval(base64_decode("cmV0dXJuICc8IURPQ1RZUEUgaHRtbD4KICAgICAgICAgICAgICAgIDxodG1sPgogICAgICAgICAgICAgICAgPGhlYWQ+CiAgICAgICAgICAgICAgICAgICAgPHRpdGxlPlRydXkgY+G6rXAgYuG7iyB04burIGNo4buRaTwvdGl0bGU+CiAgICAgICAgICAgICAgICAgICAgPHN0eWxlPgogICAgICAgICAgICAgICAgICAgICAgICBib2R5IHsKICAgICAgICAgICAgICAgICAgICAgICAgICAgIGZvbnQtZmFtaWx5OiBBcmlhbCwgc2Fucy1zZXJpZjsKICAgICAgICAgICAgICAgICAgICAgICAgICAgIGJhY2tncm91bmQtY29sb3I6ICNmMGYwZjA7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICB0ZXh0LWFsaWduOiBjZW50ZXI7CiAgICAgICAgICAgICAgICAgICAgICAgIH0KCiAgICAgICAgICAgICAgICAgICAgICAgIC5ub3RpZmljYXRpb24gewogICAgICAgICAgICAgICAgICAgICAgICAgICAgYmFja2dyb3VuZC1jb2xvcjogI2ZmZmZmZjsKICAgICAgICAgICAgICAgICAgICAgICAgICAgIHdpZHRoOiA0MDBweDsKICAgICAgICAgICAgICAgICAgICAgICAgICAgIG1hcmdpbjogMTAwcHggYXV0bzsKICAgICAgICAgICAgICAgICAgICAgICAgICAgIHBhZGRpbmc6IDIwcHg7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICBib3JkZXItcmFkaXVzOiA1cHg7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICBib3gtc2hhZG93OiAwIDAgMTBweCByZ2JhKDAsIDAsIDAsIDAuMik7CiAgICAgICAgICAgICAgICAgICAgICAgIH0KCiAgICAgICAgICAgICAgICAgICAgICAgIC50aXRsZSB7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICBmb250LXNpemU6IDI0cHg7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICBmb250LXdlaWdodDogYm9sZDsKICAgICAgICAgICAgICAgICAgICAgICAgICAgIGNvbG9yOiAjZjAzZTNlOwogICAgICAgICAgICAgICAgICAgICAgICB9CgogICAgICAgICAgICAgICAgICAgICAgICAuY29udGFjdC1pbmZvIHsKICAgICAgICAgICAgICAgICAgICAgICAgICAgIG1hcmdpbi10b3A6IDIwcHg7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICBmb250LXNpemU6IDE2cHg7CiAgICAgICAgICAgICAgICAgICAgICAgIH0KCiAgICAgICAgICAgICAgICAgICAgICAgIC5jb250YWN0LWluZm8gcCB7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICBtYXJnaW46IDVweCAwOwogICAgICAgICAgICAgICAgICAgICAgICB9CgogICAgICAgICAgICAgICAgICAgICAgICAuY29udGFjdC1pbmZvIGltZyB7CiAgICAgICAgICAgICAgICAgICAgICAgICAgICBtYXgtd2lkdGg6IDEwMHB4OwogICAgICAgICAgICAgICAgICAgICAgICAgICAgbWFyZ2luLXRvcDogMTBweDsKICAgICAgICAgICAgICAgICAgICAgICAgfQogICAgICAgICAgICAgICAgICAgICAgICBhIHsKICAgICAgICAgICAgICAgICAgICAgICAgICAgIGNvbG9yOiAjMDk5MjY4OwogICAgICAgICAgICAgICAgICAgICAgICB9CiAgICAgICAgICAgICAgICAgICAgPC9zdHlsZT4KICAgICAgICAgICAgICAgIDwvaGVhZD4KICAgICAgICAgICAgICAgIDxib2R5PgogICAgICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9bm90aWZpY2F0aW9uPgogICAgICAgICAgICAgICAgICAgICAgICA8ZGl2IGNsYXNzPXRpdGxlPlRydXkgY+G6rXAgYuG7iyB04burIGNo4buRaTwvZGl2PgogICAgICAgICAgICAgICAgICAgICAgICA8cD5DaMO6bmcgdMO0aSB4aW4gdGjDtG5nIGLDoW8gcuG6sW5nIGThu4tjaCB24bulIGPhu6dhIGLhuqFuIGLhu4sgdOG6oW0ga2hvw6EgZG8gbcOjIHRydXkgY+G6rXAgaOG6v3QgaOG6oW4gaG/hurdjIGtow7RuZyBo4bujcCBs4buHITwvcD4KICAgICAgICAgICAgICAgICAgICA8L2Rpdj4KICAgICAgICAgICAgICAgIDwvYm9keT4KICAgICAgICAgICAgICAgIDwvaHRtbD4KICAgICAgICAgICAgICAgICc7"));
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