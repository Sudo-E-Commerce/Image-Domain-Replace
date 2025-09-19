<?php

namespace Sudo\ImageDomainReplace\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Sudo\ImageDomainReplace\Middleware\ImageDomainReplaceMiddleware;

class ImageController extends Controller
{
    /**
     * Get fallback image URL - compatible with existing script.js
     * This method processes the image and returns fallback URL format
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFallbackImageUrl(Request $request)
    {
        // Set JSON response headers explicitly
        $headers = [
            'Content-Type' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest'
        ];

        try {
            $imageUrl = $request->input('imageUrl'); // Note: script.js uses 'imageUrl' not 'image_url'
            
            Log::info('Fallback image request received', [
                'imageUrl' => $imageUrl,
                'request_method' => $request->method(),
                'content_type' => $request->header('Content-Type'),
                'ajax' => $request->ajax(),
                'expects_json' => $request->expectsJson()
            ]);
            
            if (!$imageUrl) {
                return response()->json([
                    'success' => false,
                    'fallbackImageUrl' => config('image-domain-replace.fallback_image', '/vendor/core/core/base/img/placeholder.png'),
                    'message' => 'Image URL is required'
                ], 400, $headers);
            }

            Log::info('Processing image URL via fallback endpoint: ' . $imageUrl);

            // Use the middleware's image checking logic
            $middleware = new ImageDomainReplaceMiddleware();
            $processedUrl = $middleware->checkOrCreateInBucket($imageUrl);

            // Check if processing was successful
            $reflection = new \ReflectionClass($middleware);
            
            $getStorageDisk = $reflection->getMethod('getStorageDisk');
            $getStorageDisk->setAccessible(true);
            $disk = $getStorageDisk->invoke($middleware);
            
            $getResizeImagePath = $reflection->getMethod('getResizeImagePath');
            $getResizeImagePath->setAccessible(true);
            $resizePath = $getResizeImagePath->invoke($middleware, $imageUrl);

            $fileExists = $disk->exists($resizePath);

            $fallbackImageUrl = null;
            
            if ($fileExists) {
                $fallbackImageUrl = $imageUrl;
            } else {
                // If processing failed, return default fallback
                $fallbackImageUrl = config('image-domain-replace.fallback_image', '/vendor/core/core/base/img/placeholder.png');
            }

            Log::info('Fallback endpoint result', [
                'original_url' => $imageUrl,
                'fallback_url' => $fallbackImageUrl,
                'file_exists' => $fileExists,
                'success' => $fileExists
            ]);

            return response()->json([
                'success' => true,
                'fallbackImageUrl' => $fallbackImageUrl,
                'message' => $fileExists ? 'Image processed successfully' : 'Using default fallback image'
            ], 200, $headers);

        } catch (\Exception $e) {
            Log::error('Fallback image URL error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'fallbackImageUrl' => config('image-domain-replace.fallback_image', '/vendor/core/core/base/img/placeholder.png'),
                'message' => 'Error processing image, using default fallback',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500, $headers);
        }
    }
}