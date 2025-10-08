<?php

namespace Sudo\ImageDomainReplace\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
class ImageController extends Controller
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
            //log $imageUrl for debugging

            if (!$imageUrl) {
                $awsDomain = config('filesystems.disks.s3.domain') ?? config('filesystems.disks.do.domain', '');
                $imageUrl = getOriginalImageUrlIDR($request->input('imageUrl', ''));
                return response()->json([
                    'success' => false,
                    'fallbackImageUrl' => rtrim($awsDomain, '/'). '/' . ltrim($imageUrl, '/'),
                    'message' => 'Image URL is required'
                ], 400, $headers);
            }

            $processedUrl = checkOrCreateInBucketIDR($imageUrl, $this->oldDomains);
            $upload = getStorageDiskIDR();
            $awsDomain = config('filesystems.disks.s3.domain') ?? config('filesystems.disks.do.domain', '');
            $imageUrl = str_replace($awsDomain, '', $processedUrl);
 
            $fileExists = $upload->exists($imageUrl);

            $fallbackImageUrl = null;
            
            if ($fileExists) {
                $fallbackImageUrl = $processedUrl;
            } else {
                $fallbackImageUrl = config('image-domain-replace.fallback_image', '/vendor/core/core/base/img/placeholder.png');
            }

            return response()->json([
                'success' => true,
                'request' => $imageUrl,
                'fallbackImageUrl' => $fallbackImageUrl,
                'message' => $fileExists ? 'Image processed successfully' : 'Using default fallback image'
            ], 200, $headers);

        } catch (\Exception $e) {
            Log::error('Fallback image URL error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            $awsDomain = config('filesystems.disks.s3.domain') ?? config('filesystems.disks.do.domain', '');
            $imageUrl = getOriginalImageUrlIDR($request->input('imageUrl', ''));
            return response()->json([
                'success' => false,
                'fallbackImageUrl' => rtrim($awsDomain, '/'). '/' . ltrim($imageUrl, '/'),
                'message' => 'Error processing image, using default fallback',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500, $headers);
        }
    }
}