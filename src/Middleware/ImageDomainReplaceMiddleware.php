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
                array_walk_recursive($arrayContent, function (&$item, $key) use ($request) {
                    if (is_string($item)) {
                        $item = $this->replaceImageDomains($item, $request);
                    }
                });
                $response->setContent(json_encode($arrayContent));
            }
        }

        if (method_exists($response, 'getContent') && $this->isHtmlResponse($response)) {
            $content = $response->getContent();
            
            // Always apply domain replacement
            $content = $this->replaceImageDomains($content, $request);

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

    protected function replaceImageDomains($content, $request)
    {
        // Build dynamic pattern from configured domains and patterns
        $allPatterns = [];
        
        // Add old domains (escape them for regex)
        foreach ($this->oldDomains as $domain) {
            $allPatterns[] = preg_quote($domain, '/');
        }
        
        // Add regex patterns for subdomains (like resize., cdn., storage., etc.)
        foreach ($this->regexPatterns as $prefix) {
            if (strtolower($prefix) === 'sudospaces.com') {
                continue;
            }
            $allPatterns[] = '(?:' . preg_quote($prefix, '/') . '\\.|)sudospaces\\.com';
            $allPatterns[] = preg_quote($prefix, '/') . '[^"\'\s]*';
        }
        
        if (empty($allPatterns)) {
            return $content; // No patterns configured, return content unchanged
        }
        $pattern = '/https?:\\/\\/(' . implode('|', $allPatterns) . ')[^"\'\s]*/i';

        return preg_replace_callback($pattern, function ($matches) use ($request) {
            $url = $matches[0];
            foreach ($this->oldDomains as $oldDomain) {
                if (strpos($url, $oldDomain) !== false) {
                    if (strpos($url, $oldDomain) !== false) {
                        $url = str_replace($oldDomain, $this->newDomain, $url);
                        if($request->ajax()){
                            checkOrCreateInBucketIDR($url, $this->newDomain);
                        }
                    }
                }
            }
            return $url;
        }, $content);
    }

    public function getFallbackScript()
    {
        $scriptPath = '/vendor/image-domain-replace/js/script.js?v=' . time();
        
        return '
        <!-- Image Domain Replace Package -->
        <script src="' . $scriptPath . '"></script>';
    }
}
