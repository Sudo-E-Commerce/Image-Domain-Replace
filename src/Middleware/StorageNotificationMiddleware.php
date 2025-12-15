<?php

namespace Sudo\ImageDomainReplace\Middleware;

use Closure;
use Illuminate\Http\Request;

class StorageNotificationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Kiểm tra xem có bật storage monitoring không
        if (!env('STORAGE_MONITORING_ENABLED', false)) {
            return $next($request);
        }
        
        // Auto sync storage nếu chưa chạy hôm nay (chỉ cho admin routes)
        if ($request->is('admin*')) {
            $this->autoSyncStorageIfNeeded();
        }
        
        // Load helper nếu chưa có
        $helperPath = __DIR__ . '/../helpers/storage.php';
        if (file_exists($helperPath) && !function_exists('storage_quick_check')) {
            require_once $helperPath;
        }
        
        // Kiểm tra storage trước khi xử lý upload
        if (function_exists('storage_quick_check')) {
            try {
                $storageStatus = storage_quick_check();
                
                // Block upload nếu storage đã full
                if (!empty($storageStatus['status']) && $storageStatus['status'] === 'full') {
                    // Kiểm tra nếu request có file upload
                    if ($request->hasFile('file') || 
                        $request->hasFile('image') || 
                        $request->hasFile('images') ||
                        $request->hasFile('upload') ||
                        count($request->allFiles()) > 0) {
                        
                        // Trả về lỗi cho upload request
                        if ($request->ajax() || $request->wantsJson()) {
                            return response()->json([
                                'success' => false,
                                'error' => 'Dung lượng lưu trữ đã đầy. Vui lòng liên hệ quản trị viên để nâng cấp.',
                                'message' => 'Dung lượng lưu trữ đã đầy. Không thể upload file.'
                            ], 507); // 507 Insufficient Storage
                        }
                        
                        // Redirect với thông báo lỗi cho normal request
                        return redirect()->back()->withErrors([
                            'storage_full' => 'Dung lượng lưu trữ đã đầy. Không thể upload file. Vui lòng liên hệ quản trị viên.'
                        ])->withInput();
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Storage check in middleware failed: ' . $e->getMessage());
            }
        }
        
        $response = $next($request);
        
        // Chỉ inject vào HTML response
        if (!$this->isHtmlResponse($response)) {
            return $response;
        }
        
        // Chỉ inject vào admin routes
        if (!$request->is('admin*')) {
            return $response;
        }
        
        // Lấy nội dung HTML
        $content = $response->getContent();
        
        // Tạo storage notification HTML
        $notification = $this->getStorageNotificationHtml();
        
        // Chèn notification ngay sau thẻ <body>
        if ($notification && strpos($content, '<body') !== false) {
            $content = preg_replace(
                '/(<body[^>]*>)/i',
                '$1' . PHP_EOL . $notification,
                $content,
                1
            );
            
            $response->setContent($content);
        }
        
        return $response;
    }
    
    /**
     * Tự động sync storage nếu chưa chạy hôm nay
     * Chạy 1 lần/ngày khi admin vào lần đầu
     * 
     * @return void
     */
    protected function autoSyncStorageIfNeeded()
    {
        try {
            $today = date('Y-m-d');
            $cacheKey = 'storage_cdn_last_sync_date';
            $lastSyncDate = \Cache::get($cacheKey);
            
            // Nếu đã sync hôm nay rồi thì bỏ qua
            if ($lastSyncDate === $today) {
                return;
            }
            
            // Chạy sync storage từ S3/Cloud
            $this->syncStorageFromCloud();
            
            // Lưu cache để không chạy lại trong ngày
            \Cache::put($cacheKey, $today, 86400); // Cache 24h
            
            \Log::info('Storage auto-synced successfully', ['date' => $today]);
            
        } catch (\Exception $e) {
            \Log::error('Auto sync storage failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync storage từ cloud và lưu vào DB
     * 
     * @return void
     */
    protected function syncStorageFromCloud()
    {
        try {
            // Lấy service
            $service = app(\Sudo\ImageDomainReplace\Services\SimpleStorageService::class);
            
            // Tính dung lượng thực tế từ cloud
            $currentSize = $service->getCurrentStorageSize();
            // Lưu vào DB
            $this->saveStorageCdnToDB($currentSize);
            
            // Clear cache của quick check để reload dữ liệu mới
            \Cache::forget('storage_quick_check');
            
        } catch (\Exception $e) {
            \Log::error('Sync storage from cloud failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Lưu storage_cdn vào database
     * 
     * @param int $size Size in bytes
     * @return void
     */
    protected function saveStorageCdnToDB($size)
    {
        try {
            $data = json_encode([
                'size' => $size,
                'size_formatted' => $this->formatBytes($size),
                'updated_at' => date('Y-m-d H:i:s'),
                'source' => 'auto_sync'
            ]);
            
            // Encode base64 trước khi lưu vào DB
            $encodedData = base64_encode($data);
            
            // Kiểm tra bảng settings trước
            if (\Schema::hasTable('settings')) {
                $exists = \DB::table('settings')->where('key', 'storage_cdn')->exists();
                
                if ($exists) {
                    \DB::table('settings')
                        ->where('key', 'storage_cdn')
                        ->update(['value' => $encodedData]);
                } else {
                    // Kiểm tra xem bảng settings có cột created_at/updated_at không
                    $hasTimestamps = \Schema::hasColumn('settings', 'created_at');
                    
                    $insertData = [
                        'key' => 'storage_cdn',
                        'value' => $encodedData
                    ];
                    
                    if ($hasTimestamps) {
                        $insertData['created_at'] = date('Y-m-d H:i:s');
                        $insertData['updated_at'] = date('Y-m-d H:i:s');
                    }
                    
                    \DB::table('settings')->insert($insertData);
                }
            } 
            // Fallback sang bảng options
            elseif (\Schema::hasTable('options')) {
                $exists = \DB::table('options')->where('name', 'storage_cdn')->exists();
                
                if ($exists) {
                    \DB::table('options')
                        ->where('name', 'storage_cdn')
                        ->update(['value' => $encodedData]);
                } else {
                    \DB::table('options')->insert([
                        'name' => 'storage_cdn',
                        'value' => $encodedData
                    ]);
                }
            }
            
            \Log::info('Storage CDN saved to DB', ['size' => $size]);
            
        } catch (\Exception $e) {
            \Log::error('Save storage_cdn to DB failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Format bytes to human readable
     * 
     * @param int $bytes
     * @return string
     */
    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        if ($bytes == 0) {
            return '0 B';
        }
        
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Kiểm tra xem response có phải HTML không
     *
     * @param  mixed  $response
     * @return bool
     */
    protected function isHtmlResponse($response)
    {
        if (!method_exists($response, 'getContent')) {
            return false;
        }
        
        $contentType = $response->headers->get('Content-Type');
        
        return $contentType && strpos($contentType, 'text/html') !== false;
    }
    
    /**
     * Tạo HTML cho storage notification
     *
     * @return string|null
     */
    protected function getStorageNotificationHtml()
    {
        // Load helper nếu chưa có
        $helperPath = __DIR__ . '/../helpers/storage.php';
        if (file_exists($helperPath) && !function_exists('storage_quick_check')) {
            require_once $helperPath;
        }
        
        if (!function_exists('storage_quick_check')) {
            return null;
        }
        
        try {
            $storageStatus = storage_quick_check();
            
            // Chỉ hiển thị khi cần chú ý
            if (!$storageStatus['needs_attention']) {
                return null;
            }
            
            $status = $storageStatus['status'];
            $isFull = $status === 'full';
            $bgColor = $isFull ? '#f8d7da' : '#fff3cd';
            $textColor = $isFull ? '#721c24' : '#856404';
            $borderColor = $isFull ? '#dc3545' : '#ffc107';
            $icon = $isFull ? '⚠' : '⚠';
            $title = $isFull ? 'Dung lượng website đã đầy' : 'Cảnh báo dung lượng website';
            
            // Inline CSS hoàn chỉnh, không phụ thuộc Bootstrap
            $alertStyle = 'position: relative; z-index: 9999; padding: 15px 50px 15px 15px; ' .
                         'background-color: ' . $bgColor . '; color: ' . $textColor . '; ' .
                         'border: 1px solid ' . $borderColor . '; border-left: 4px solid ' . $borderColor . '; ' .
                         'border-radius: 4px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; ' .
                         'font-size: 14px; line-height: 1.5;';
            
            $closeStyle = 'position: absolute; top: 10px; right: 15px; background: none; border: none; ' .
                         'font-size: 24px; font-weight: bold; line-height: 1; color: ' . $textColor . '; ' .
                         'opacity: 0.5; cursor: pointer; padding: 0; width: 24px; height: 24px; transition: opacity 0.2s;';
            
            $closeHoverScript = '<script>document.addEventListener("DOMContentLoaded", function() {
                var closeBtn = document.querySelector(".storage-alert button");
                if (closeBtn) {
                    closeBtn.addEventListener("mouseenter", function() { this.style.opacity = "0.8"; });
                    closeBtn.addEventListener("mouseleave", function() { this.style.opacity = "0.5"; });
                }
            });</script>';
            
            $titleStyle = 'margin: 0 0 10px 0; padding: 0; font-size: 16px; font-weight: bold; color: ' . $textColor . ';';
            
            $ulStyle = 'margin: 0 0 10px 0; padding-left: 20px; list-style-type: disc;';
            
            $liStyle = 'margin: 5px 0; color: ' . $textColor . ';';
            
            $html = '<div class="storage-alert" style="' . $alertStyle . '">';
            $html .= '<button type="button" onclick="this.parentElement.style.display=\'none\'" style="' . $closeStyle . '" aria-label="Close">';
            $html .= '<span aria-hidden="true">&times;</span>';
            $html .= '</button>';
            $html .= '<h4 style="' . $titleStyle . '">';
            $html .= '<span style="font-size: 18px; margin-right: 8px;">' . $icon . '</span>';
            $html .= htmlspecialchars($title);
            $html .= '</h4>';
            
            // Messages
            if (!empty($storageStatus['messages'])) {
                $html .= '<ul style="' . $ulStyle . '">';
                foreach ($storageStatus['messages'] as $type => $message) {
                    $html .= '<li style="' . $liStyle . '">' . htmlspecialchars($message) . '</li>';
                }
                $html .= '</ul>';
            }
            
            $html .= '</div>';
            $html .= $closeHoverScript;
            
            return $html;
            
        } catch (\Exception $e) {
            \Log::error('Storage notification error: ' . $e->getMessage());
            return null;
        }
    }
}
