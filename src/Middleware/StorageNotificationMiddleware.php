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
            $alertClass = $isFull ? 'danger' : 'warning';
            $borderColor = $isFull ? '#dc3545' : '#ffc107';
            $icon = $isFull ? 'exclamation-triangle' : 'warning';
            $title = $isFull ? 'Dung lượng website đã đầy' : 'Cảnh báo dung lượng website';
            $usagePercentage = number_format($storageStatus['usage_percentage'], 1);
            
            $html = '<div class="alert alert-' . $alertClass . ' storage-alert" style="margin: 15px; border-left: 4px solid ' . $borderColor . '; position: relative; z-index: 9999;">';
            $html .= '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
            $html .= '<span aria-hidden="true">&times;</span>';
            $html .= '</button>';
            $html .= '<h4 style="margin-top: 0;">';
            $html .= '<i class="fa fa-' . $icon . '"></i> ';
            $html .= htmlspecialchars($title);
            $html .= '</h4>';
            
            // Messages
            if (!empty($storageStatus['messages'])) {
                $html .= '<ul style="margin-bottom: 10px;">';
                foreach ($storageStatus['messages'] as $type => $message) {
                    $html .= '<li>' . htmlspecialchars($message) . '</li>';
                }
                $html .= '</ul>';
            }
            
            // Progress bar
            $html .= '<div class="progress" style="height: 20px; margin-bottom: 5px;">';
            $html .= '<div class="progress-bar progress-bar-' . $alertClass . ' progress-bar-striped" ';
            $html .= 'role="progressbar" ';
            $html .= 'style="width: ' . min($storageStatus['usage_percentage'], 100) . '%;" ';
            $html .= 'aria-valuenow="' . $storageStatus['usage_percentage'] . '" ';
            $html .= 'aria-valuemin="0" ';
            $html .= 'aria-valuemax="100">';
            $html .= $usagePercentage . '%';
            $html .= '</div>';
            $html .= '</div>';
            
            // Summary
            $html .= '<small class="text-muted">';
            $html .= 'Đã sử dụng ' . $usagePercentage . '% dung lượng';
            if (!empty($storageStatus['current_size_formatted']) && !empty($storageStatus['total_storage_formatted'])) {
                $html .= ' (' . htmlspecialchars($storageStatus['current_size_formatted']) . ' / ' . htmlspecialchars($storageStatus['total_storage_formatted']) . ')';
            }
            $html .= '</small>';
            
            $html .= '</div>';
            
            return $html;
            
        } catch (\Exception $e) {
            \Log::error('Storage notification error: ' . $e->getMessage());
            return null;
        }
    }
}
