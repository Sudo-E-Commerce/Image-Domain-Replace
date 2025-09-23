<?php

namespace Sudo\ImageDomainReplace\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class ImageDomainReplaceMiddleware
{
    protected $oldDomains;
    protected $regexPatterns;

    protected $newDomain;

    protected $imageSize = [
        30,40,50,60,70,80,90,
        100,110,120,130,140,150,160,170,180,190,
        200,210,215,220,230,240,250,260,270,280,290,
        300,310,320,330,340,350,360,370,380,390,
        400,410,420,430,440,450,460,470,480,490,
        500,510,520,530,540,550,560,570,580,590,
        600,620,640,650,660,680,
        700,720,740,750,760,780,
        800,810,820,840,850,860,880,
        900,960,1000,1020,1050,1080,1100,1150,1200,1250,1300,1350,1400
    ];

    public function __construct()
    {
        // Get old domains from config/env
        $domainsString = config('image-domain-replace.old_domains', env('IMAGE_OLD_DOMAINS', ''));
        $this->oldDomains = array_filter(array_map('trim', explode(',', $domainsString)));
        
        // Get regex patterns from config/env (just the prefixes like: resize, cdn, storage, karofi)
        $patternsString = config('image-domain-replace.regex_patterns', env('IMAGE_REGEX_PATTERNS', ''));
        $this->regexPatterns = array_filter(array_map('trim', explode(',', $patternsString)));
    }

    public function handle($request, Closure $next)
    {
        $this->newDomain = config('image-domain-replace.new_domain', 'your.newdomain.com');
        $response = $next($request);

        // Skip processing for AJAX requests or API routes
        if ($request->ajax() || $request->expectsJson() || $request->is('ajax/*') || $request->is('api/*') || $request->is('image/*')) {
            return $response;
        }

        if (method_exists($response, 'getContent') && $this->isHtmlResponse($response)) {
            $content = $response->getContent();
            
            // Always apply domain replacement
            $content = $this->replaceImageDomains($content);

            // Only add fallback script if NOT admin or scms routes
            if (!$request->is('admin/*') && !$request->is('admin') && !$request->is('scms/*')) {
                $script = $this->getFallbackScript();
                $content = str_replace('</body>', $script . '</body>', $content);
            }

            $response->setContent($content);
        }
        return $response;
    }

    protected function isHtmlResponse($response)
    {
        $contentType = $response->headers->get('Content-Type');
        return $contentType && strpos($contentType, 'text/html') !== false;
    }

    protected function replaceImageDomains($content)
    {
        // Build dynamic pattern from configured domains and patterns
        $allPatterns = [];
        
        // Add old domains (escape them for regex)
        foreach ($this->oldDomains as $domain) {
            $allPatterns[] = preg_quote($domain, '/');
        }
        
        // Add regex patterns for subdomains (like resize., cdn., storage., etc.)
        foreach ($this->regexPatterns as $prefix) {
            $allPatterns[] = '(?:' . preg_quote($prefix, '/') . '\\.|)sudospaces\\.com';
            $allPatterns[] = preg_quote($prefix, '/') . '[^"\'\s]*';
        }
        
        if (empty($allPatterns)) {
            return $content; // No patterns configured, return content unchanged
        }
        $pattern = '/https?:\\/\\/(' . implode('|', $allPatterns) . ')[^"\'\s]*/i';

        return preg_replace_callback($pattern, function ($matches) {
            $url = $matches[0];
            foreach ($this->oldDomains as $oldDomain) {
                if (strpos($url, $oldDomain) !== false) {
                    if (strpos($url, $oldDomain) !== false) {
                        $url = preg_replace('/' . preg_quote($oldDomain, '/') . '/i', $this->newDomain, $url);
                    }
                }
            }
            return $url;
        }, $content);
    }

    public function checkOrCreateInBucket($url)
    {
        try {
            $upload = $this->getStorageDisk();
            $originalPath = $this->getOriginalImagePath($url);

            // Check if original image exists
            if (!$upload->exists($originalPath)) {
                Log::warning('Original image does not exist in bucket: ' . $originalPath);
                return $url;
            }

            $size = $this->extractImageSize($url);
            Log::info('Extracted size: ' . ($size ?: 'none'));
            
            if (!$size || !in_array($size, $this->imageSize)) {
                Log::info('Size not valid or not in allowed sizes list');
                return $url;
            }

            $resizeLink = $this->getResizeImagePath($url);
            Log::info('Resize path: ' . $resizeLink);

            // Check if resized image already exists
            if ($upload->exists($resizeLink)) {
                Log::info('Resized image already exists: ' . $resizeLink);
                // Return the new domain URL even if image exists
                $newUrl = $this->newDomain . '/' . $resizeLink;
                Log::info('Returning existing image URL: ' . $newUrl);
                return $newUrl;
            }

            $originalUrl = $this->getOriginalImageUrl($url);
            Log::info('Original URL for processing: ' . $originalUrl);

            // Create resized image
            $this->createResizedImage($originalUrl, $resizeLink, $size, $upload);

            // Verify the resized image was created successfully
            if ($upload->exists($resizeLink)) {
                $newUrl = $this->newDomain . '/' . $resizeLink;
                Log::info('Successfully created and verified resized image, returning: ' . $newUrl);
                return $newUrl;
            } else {
                Log::error('Resized image was not created successfully: ' . $resizeLink);
                return $url;
            }

        } catch (\Exception $e) {
            Log::error('Error in checkOrCreateInBucket for image: ' . $url);
            Log::error('Error message: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return $url;
        }
    }

    protected function getStorageDisk()
    {
        $disk = config('app.storage_type');
        if ($disk == 'digitalocean') {
            $disk = 'do';
        }
        return Storage::disk($disk);
    }

    protected function getOriginalImagePath($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        
        // Remove bucket name from path if present
        $bucketName = env('DO_BUCKET', '');
        if ($bucketName) {
            $path = str_replace('/' . $bucketName . '/', '', $path);
        }
        
        // Remove leading slash if present
        $path = ltrim($path, '/');
        
        // Remove size indicator (w200, w300, etc.) to get original path
        $originalPath = preg_replace('/\/w\d+\//', '/', $path);
        
        // If path starts with year (like 2025/09/...), ensure it's properly formatted
        if (!preg_match('/^\d{4}\//', $originalPath)) {
            // Remove any leading slash again after regex replacement
            $originalPath = ltrim($originalPath, '/');
        }
        
        Log::info('Original path conversion', [
            'input_url' => $url,
            'parsed_path' => parse_url($url, PHP_URL_PATH),
            'after_bucket_removal' => $path,
            'final_original_path' => $originalPath
        ]);
        
        return $originalPath;
    }

    protected function getResizeImagePath($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        
        // Remove bucket name from path if present
        $bucketName = env('DO_BUCKET', '');
        if ($bucketName) {
            $path = str_replace('/' . $bucketName . '/', '', $path);
        }
        
        // Remove leading slash if present
        $path = ltrim($path, '/');
        
        Log::info('Resize path conversion', [
            'input_url' => $url,
            'parsed_path' => parse_url($url, PHP_URL_PATH),
            'final_resize_path' => $path
        ]);
        
        return $path;
    }

    protected function getOriginalImageUrl($url)
    {
        return preg_replace('/\\/w\\d+/', '', $url);
    }

    protected function extractImageSize($url)
    {
        preg_match('/w(\\d+)/i', $url, $matches);
        return isset($matches[1]) ? (int)$matches[1] : null;
    }

    protected function createResizedImage($originalUrl, $resizeLink, $size, $upload)
    {
        try {
            // Log the attempt
            Log::info('Creating resized image', [
                'original_url' => $originalUrl,
                'resize_link' => $resizeLink,
                'size' => $size
            ]);

            // Check if resized image already exists
            if ($upload->exists($resizeLink)) {
                Log::info('Resized image already exists: ' . $resizeLink);
                return;
            }

            // Get image content with error handling
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'Mozilla/5.0 (compatible; ImageProcessor/1.0)',
                    'follow_location' => true
                ]
            ]);

            $imageContent = file_get_contents($originalUrl, false, $context);
            
            if ($imageContent === false) {
                Log::error('Failed to fetch image content from: ' . $originalUrl);
                return;
            }

            // Create and resize image
            $image = Image::make($imageContent);
            
            // Get original dimensions for logging
            $originalWidth = $image->width();
            $originalHeight = $image->height();
            
            Log::info('Original image dimensions', [
                'width' => $originalWidth,
                'height' => $originalHeight,
                'target_size' => $size
            ]);

            // Only resize if image is larger than target size
            if ($originalWidth > $size) {
                $image->widen($size, function ($constraint) {
                    $constraint->upsize();
                });
            }

            // Determine file extension and quality
            $fileExtension = pathinfo(parse_url($resizeLink, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (!$fileExtension) {
                $fileExtension = 'jpg';
            }

            $quality = 90;
            if (strtolower($fileExtension) === 'png') {
                $quality = 9; // PNG compression level (0-9)
            }

            // Create image stream
            $imageResize = $image->stream($fileExtension, $quality);

            // Upload to storage
            $result = $upload->put($resizeLink, $imageResize->__toString(), 'public');

            if ($result) {
                Log::info('Successfully created resized image: ' . $resizeLink);
            } else {
                Log::error('Failed to upload resized image: ' . $resizeLink);
            }

        } catch (\Exception $e) {
            Log::error('Error in createResizedImage', [
                'original_url' => $originalUrl,
                'resize_link' => $resizeLink,
                'size' => $size,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function getFallbackScript()
    {
        $scriptPath = '/vendor/image-domain-replace/js/script.js';
        
        return '
        <!-- Image Domain Replace Package -->
        <script src="' . $scriptPath . '"></script>';
    }
}
