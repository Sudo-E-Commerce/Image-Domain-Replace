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
        if ($request->is('api/*') || $request->is('image/*')) {
            return $response;
        }

        //check response is json 
        if ($request->ajax()) {
            $content = $response->getContent();
            $arrayContent = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($arrayContent)) {
                array_walk_recursive($arrayContent, function (&$item, $key) {
                    if (is_string($item)) {
                        $item = $this->replaceImageDomains($item);
                        // Check and create in bucket if needed
                        $item = $this->checkOrCreateInBucket($item);
                    }
                });
                $response->setContent(json_encode($arrayContent));
            }
        }

        if (method_exists($response, 'getContent') && $this->isHtmlResponse($response)) {
            $content = $response->getContent();
            
            // Always apply domain replacement
            $content = $this->replaceImageDomains($content);

            // Only add fallback script if NOT admin or scms routes
            if (!$request->is('admin/*') && !$request->is('admin') && !$request->is('scms/*')) {
                $script = $this->getFallbackScript();
                
                // Tìm vị trí CUỐI CÙNG của </body>
                $lastBodyPos = strripos($content, '</body>');
                
                if ($lastBodyPos !== false) {
                    // Chèn vào vị trí cuối cùng
                    $content = substr_replace($content, $script, $lastBodyPos, 0);
                }
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
            $awsDomain = config('filesystems.disks.s3.domain', '');
            $upload = $this->getStorageDisk();
            $originalPath = $this->getOriginalImagePath($url);
            
            // check path có .webp thì bỏ để lấy orginPath
            $originalPath = preg_replace('/\.webp$/i', '', $originalPath);
            $isHasWebp = preg_match('/\.webp$/i', $url);

            //check có định dạng size indicator (w200, w300, etc.) to get original path hay không $isHasResize
            $isHasResize = preg_match('/\\/w(\\d+)\\//i', $url);
            
            // Remove .webp extension from original path for processing
            $originalPath = preg_replace('/\.webp$/i', '', $originalPath);
            
            // Setup domain configuration
            if (empty($this->newDomain) || $this->newDomain === 'your.newdomain.com') {
                $this->newDomain = config('image-domain-replace.new_domain', 'storage.sudospaces.com/fastmobile-vn');
            }
            
            // Step 1: Verify original image exists
            if (!$upload->exists($originalPath)) {
                return $url;
            }
            
            // Step 2: Create original WebP version if it doesn't exist
            $originalWebpPath = $originalPath . '.webp';
            if (!$upload->exists($originalWebpPath) && !$isHasResize && $isHasWebp) {
                $this->createWebpImage($originalPath, $originalWebpPath, $upload);
                return $awsDomain . ltrim($originalWebpPath, '/');
            }
            
            // Step 3: Check if resize is needed
            $size = $this->extractImageSize($url);
            
            // Step 4: Process resize image
            $resizeImagePath = $this->getResizeImagePath($url);
            Log::info('Processing image', [
                'resize_path' => $resizeImagePath,
            ]);
            // Create resized image if it doesn't exist
            if (!$upload->exists($resizeImagePath)) {
                Log::info('Creating resized image from: ' . $originalPath);
                $this->createResizedImage($originalPath, $resizeImagePath, $size, $upload);
            }
            
            // Step 5: Create resized WebP version if needed
            if ($isHasWebp) {
                $resizeWebpPath = $resizeImagePath . '.webp';
                if (!$upload->exists($resizeWebpPath)) {
                    $this->createWebpImage($resizeImagePath, $resizeWebpPath, $upload);
                }
                
                if ($upload->exists($resizeWebpPath)) {
                    $finalUrl =  $awsDomain . ltrim($resizeWebpPath, '/');
                    return $finalUrl;
                } else {
                    Log::warning('Failed to create resized WebP, falling back to resized image');
                }
            }
            
            // Step 6: Return resized image URL
            $finalUrl = $awsDomain . ltrim($resizeImagePath, '/');
            return $finalUrl;
            
        } catch (\Exception $e) {
            Log::error('Error in checkOrCreateInBucket', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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
        // Remove bucket name from path if present
        $awsDomain = config('filesystems.disks.s3.domain', '');

        $path = str_replace($awsDomain, '', $url);

        // Remove leading slash if present
        $path = ltrim($path, '/');
        
        // Remove size indicator (w200, w300, etc.) to get original path
        $originalPath = preg_replace('/\/w\d+\//', '/', $path);

        return $originalPath;
    }

    protected function getResizeImagePath($url)
    {
        // Remove bucket name from path if present
        $awsDomain = config('filesystems.disks.s3.domain', '');
        $path = str_replace($awsDomain, '', $url);
        
        // Remove leading slash if present
        $path = ltrim($path, '/');
        // Remove .webp extension but keep the w{number} structure
        $path = preg_replace('/\.webp$/i', '', $path);
        
        // Log::info('Resize path conversion', [
        //     'input_url' => $url,
        //     'parsed_path' => parse_url($url, PHP_URL_PATH),
        //     'final_resize_path' => $path
        // ]);
        
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
            $awsDomain = config('filesystems.disks.s3.domain', '');
            $originalUrl = str_replace($awsDomain, '', $originalUrl);
            $originalUrl = ltrim($originalUrl, '/');
            $originalUrl = $awsDomain . '/' . $originalUrl;

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

    public function checkOrCreateWebp($url)
    {
        try {
            $upload = $this->getStorageDisk();
            $originalPath = $this->getOriginalImagePath($url);
            // check path có .webp thì bỏ để lấy orginPath
            $originalPath = preg_replace('/\.webp$/i', '', $originalPath);

            // Check if original image exists
            if (!$upload->exists($originalPath)) {
                Log::warning('Original image does not exist in bucket for WebP: ' . $originalPath);
                return $url;
            }
            $webpLink = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $this->getResizeImagePath($url));
            Log::info('WebP path: ' . $webpLink);

            // Check if WebP image already exists
            if ($upload->exists($webpLink)) {
                Log::info('WebP image already exists: ' . $webpLink);
                // Return the new domain URL even if image exists
                $newUrl = $this->newDomain . '/' . $webpLink;
                Log::info('Returning existing WebP image URL: ' . $newUrl);
                return $newUrl;
            }

            $originalUrl = $this->getOriginalImageUrl($url);
            Log::info('Original URL for WebP processing: ' . $originalUrl);

            // Create WebP image
            $this->createWebpImage($originalUrl, $webpLink, $upload);

            // Verify the WebP image was created successfully
            if ($upload->exists($webpLink)) {
                $newUrl = $this->newDomain . '/' . $webpLink;
                Log::info('Successfully created and verified WebP image, returning: ' . $newUrl);
                return $newUrl;
            } else {
                Log::error('WebP image was not created successfully: ' . $webpLink);
                return $url;
            }

        } catch (\Exception $e) {
            Log::error('Error in checkOrCreateWebP for image: ' . $url);
            Log::error('Error message: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return $url;
        }
    }

    public function createWebpImage($originalUrl, $webpLink, $upload)
    {
        try {
            // Log the attempt
            Log::info('Creating WebP image', [
                'original_url' => $originalUrl,
                'webp_link' => $webpLink
            ]);

            $awsDomain = config('filesystems.disks.s3.domain', '');
            $originalUrl = str_replace($awsDomain, '', $originalUrl);
            $originalUrl = ltrim($originalUrl, '/');
            $originalUrl = $awsDomain . '/' . $originalUrl;
            
            $imageContent = file_get_contents($originalUrl, false);
            
            if ($imageContent === false) {
                Log::error('Failed to fetch image content from: ' . $originalUrl);
                return;
            }

            // Create and convert image to WebP
            $image = Image::make($imageContent);

            // Determine quality for WebP
            $quality = 100;

            // Create WebP image stream
            $imageWebp = $image->encode('webp', $quality);
            // Upload to storage
            $result = $upload->put($webpLink, $imageWebp->__toString(), 'public');

            if ($result) {
                Log::info('Successfully created WebP image: ' . $webpLink);
            } else {
                Log::error('Failed to upload WebP image: ' . $webpLink);
            }

        } catch (\Exception $e) {
            Log::error('Error in createWebpImage', [
                'original_url' => $originalUrl,
                'webp_link' => $webpLink,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
