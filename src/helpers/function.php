<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
// Thêm check exist thì mới tạo function checkOrCreateInBucket
if (!function_exists('checkOrCreateInBucketIDR')) {
    function checkOrCreateInBucketIDR($url, $newDomain)
    {
        try {
            $awsDomain = config('filesystems.disks.s3.domain') ?? config('filesystems.disks.do.domain', '');
            $upload = getStorageDiskIDR();
            $originalPath = getOriginalImagePathIDR($url);
            
            // check path có .webp thì bỏ để lấy orginPath
            $originalPath = preg_replace('/\.webp$/i', '', $originalPath);
            $isHasWebp = preg_match('/\.webp$/i', $url);

            //check có định dạng size indicator (w200, w300, etc.) to get original path hay không $isHasResize
            $isHasResize = preg_match('/\\/w(\\d+)\\//i', $url);
            
            // Remove .webp extension from original path for processing
            $originalPath = preg_replace('/\.webp$/i', '', $originalPath);
            
            // Setup domain configuration
            if (empty($newDomain) || $newDomain === 'your.newdomain.com') {
                $newDomain = config('image-domain-replace.new_domain', 'storage.sudospaces.com/fastmobile-vn');
            }
            
            // Step 1: Verify original image exists
            if (!$upload->exists($originalPath)) {
                return $url;
            }
            
            // Step 2: Create original WebP version if it doesn't exist
            $originalWebpPath = $originalPath . '.webp';
            if (!$upload->exists($originalWebpPath) && !$isHasResize && $isHasWebp) {
                createWebpImageIDR($originalPath, $originalWebpPath, $upload);
                return rtrim($awsDomain, '/'). '/' . ltrim($originalWebpPath, '/');
            }
            
            // Step 3: Check if resize is needed
            $size = extractImageSizeIDR($url);
            
            // Step 4: Process resize image
            $resizeImagePath = getResizeImagePathIDR($url);
            Log::info('Processing image', [
                'resize_path' => $resizeImagePath,
            ]);
            // Create resized image if it doesn't exist
            if (!$upload->exists($resizeImagePath)) {
                Log::info('Creating resized image from: ' . $originalPath);
                createResizedImageIDR($originalPath, $resizeImagePath, $size, $upload);
            }
            
            // Step 5: Create resized WebP version if needed
            if ($isHasWebp) {
                $resizeWebpPath = $resizeImagePath . '.webp';
                if (!$upload->exists($resizeWebpPath)) {
                    createWebpImageIDR($resizeImagePath, $resizeWebpPath, $upload);
                }
                
                if ($upload->exists($resizeWebpPath)) {
                    $finalUrl =  rtrim($awsDomain, '/'). '/' . ltrim($resizeWebpPath, '/');
                    return $finalUrl;
                } else {
                    Log::warning('Failed to create resized WebP, falling back to resized image');
                }
            }
            
            // Step 6: Return resized image URL
            $finalUrl = rtrim($awsDomain, '/'). '/' . ltrim($resizeImagePath, '/');
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
}
if (!function_exists('getStorageDiskIDR')) {
    function getStorageDiskIDR()
    {
        $disk = config('app.storage_type') ?? config('SudoMedia.storage_type', 'do');
        if ($disk == 'digitalocean') {
            $disk = 'do';
        }
        return Storage::disk($disk);
    }
}

if (!function_exists('getOriginalImagePathIDR')) {
    function getOriginalImagePathIDR($url)
    {
        // Remove bucket name from path if present
        $awsDomain = config('filesystems.disks.s3.domain') ?? config('filesystems.disks.do.domain', '');
       
        $path = str_replace($awsDomain, '', $url);

        // Remove leading slash if present
        $path = ltrim($path, '/');
        
        // Remove size indicator (w200, w300, etc.) to get original path
        $originalPath = preg_replace('/\/w\d+\//', '/', $path);

        return $originalPath;
    }
}

if (!function_exists('getResizeImagePathIDR')) {
    function getResizeImagePathIDR($url)
    {
        // Remove bucket name from path if present
        $awsDomain = config('filesystems.disks.s3.domain') ?? config('filesystems.disks.do.domain', '');
        $path = str_replace($awsDomain, '', $url);
        
        // Remove leading slash if present
        $path = ltrim($path, '/');
        // Remove .webp extension but keep the w{number} structure
        $path = preg_replace('/\.webp$/i', '', $path);
        
        return $path;
    }
}


if (!function_exists('getOriginalImageUrlIDR')) {
    function getOriginalImageUrlIDR($url)
    {
        return preg_replace('/\\/w\\d+/', '', $url);
    }
}

if (!function_exists('extractImageSizeIDR')) {
    function extractImageSizeIDR($url)
    {
        preg_match('/w(\\d+)/i', $url, $matches);
        return isset($matches[1]) ? (int)$matches[1] : null;
    }
}

if (!function_exists('createResizedImageIDR')) {
    function createResizedImageIDR($originalUrl, $resizeLink, $size, $upload)
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
            $awsDomain = config('filesystems.disks.s3.domain') ?? config('filesystems.disks.do.domain', '');
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

            $quality = 100;
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
}


if (!function_exists('checkOrCreateWebpIDR')) {
    function checkOrCreateWebpIDR($url, $newDomain)
    {
        try {
            $upload = getStorageDiskIDR();
            $originalPath = getOriginalImagePathIDR($url);
            // check path có .webp thì bỏ để lấy orginPath
            $originalPath = preg_replace('/\.webp$/i', '', $originalPath);

            // Check if original image exists
            if (!$upload->exists($originalPath)) {
                Log::warning('Original image does not exist in bucket for WebP: ' . $originalPath);
                return $url;
            }
            $webpLink = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', getResizeImagePathIDR($url));
            Log::info('WebP path: ' . $webpLink);

            // Check if WebP image already exists
            if ($upload->exists($webpLink)) {
                Log::info('WebP image already exists: ' . $webpLink);
                // Return the new domain URL even if image exists
                $newUrl = $newDomain . '/' . $webpLink;
                Log::info('Returning existing WebP image URL: ' . $newUrl);
                return $newUrl;
            }

            $originalUrl = getOriginalImageUrlIDR($url, $newDomain);
            Log::info('Original URL for WebP processing: ' . $originalUrl);

            // Create WebP image
            createWebpImageIDR($originalUrl, $webpLink, $upload);

            // Verify the WebP image was created successfully
            if ($upload->exists($webpLink)) {
                $newUrl = $newDomain . '/' . $webpLink;
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
}

if (!function_exists('createWebpImageIDR')) {
    function createWebpImageIDR($originalUrl, $webpLink, $upload)
    {
        try {
            // Log the attempt
            Log::info('Creating WebP image', [
                'original_url' => $originalUrl,
                'webp_link' => $webpLink
            ]);

            $awsDomain = config('filesystems.disks.s3.domain') ?? config('filesystems.disks.do.domain', '');
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