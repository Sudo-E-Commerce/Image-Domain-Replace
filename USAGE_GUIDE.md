# 📖 Hướng Dẫn Sử Dụng Simple Storage System

## 🚀 Giới Thiệu

Simple Storage System là hệ thống kiểm tra dung lượng đơn giản, tập trung vào việc:
- ✅ Check dung lượng từ `theme_validate` data
- ✅ Thông báo ngày hết hạn additional storage
- ✅ Cảnh báo khi dung lượng gần đầy hoặc đã đầy
- ✅ Hỗ trợ PHP 7.1+ và Laravel 5.5+

## 📋 Yêu Cầu Hệ Thống

- **PHP**: 7.1 trở lên
- **Laravel**: 5.5 trở lên
- **Function**: `getOption()` helper phải có sẵn
- **Data**: `theme_validate` settings phải được setup

---

## 🔧 Cài Đặt & Setup

### 1. Package đã được tích hợp sẵn
Package được load tự động trong `ImageDomainReplaceServiceProvider`, không cần cài đặt thêm.

### 2. Kiểm tra Helper Functions
```bash
php artisan tinker
>>> function_exists('check_storage_usage')
=> true
>>> function_exists('storage_quick_check')  
=> true
```

### 3. Setup Theme Validate Data
Đảm bảo `theme_validate` data có cấu trúc:
```php
// In database settings table
'theme_validate' => [
    'storage_capacity' => 1024,      // MB - dung lượng cơ bản
    'storage_additional' => [
        'capacity' => 500,           // MB - dung lượng thêm
        'expires_at' => '2024-12-31' // Ngày hết hạn
    ]
]
```

---

## 💻 Sử Dụng Helper Functions

### 1. Check Storage Usage (Chi Tiết)
```php
$status = check_storage_usage();
/*
Returns:
[
    'status' => 'ok|warning|full',
    'is_warning' => false,
    'is_full' => false, 
    'usage_percentage' => 75.5,
    'current_size_bytes' => 786432000,
    'current_size_formatted' => '750 MB',
    'total_capacity_mb' => 1524,
    'available_mb' => 774,
    'messages' => [
        'info' => 'Dung lượng còn lại: 774 MB'
    ],
    'additional_storage' => [
        'enabled' => true,
        'capacity_mb' => 500,
        'expires_at' => '2024-12-31',
        'days_until_expiry' => 45,
        'expiring_soon' => false
    ]
]
*/
```

### 2. Quick Check (Nhanh)
```php
$quick = storage_quick_check();
/*
Returns:
[
    'needs_attention' => true,
    'usage_percentage' => 85.2, 
    'status' => 'warning',
    'messages' => [
        'warning' => 'Dung lượng đã sử dụng 85.2%, nên dọn dẹp'
    ]
]
*/
```

### 3. Boolean Checks
```php
// Kiểm tra warning (>80%)
if (is_storage_warning()) {
    echo "⚠️ Cảnh báo: Dung lượng gần đầy!";
}

// Kiểm tra full (>95%) 
if (is_storage_full()) {
    echo "🚨 Lỗi: Dung lượng đã đầy!";
    // Disable upload features
}

// Check additional storage sắp hết hạn (<30 ngày)
if (has_additional_storage_expiring()) {
    echo "⏰ Additional storage sắp hết hạn!";
}
```

### 4. Get Data Values
```php
// Lấy phần trăm sử dụng
$percentage = get_storage_usage_percentage(); // 75.5

// Lấy tất cả messages
$messages = get_storage_messages();
/*
[
    'info' => 'Dung lượng còn lại: 774 MB',
    'warning' => 'Additional storage hết hạn sau 15 ngày'
]
*/

// Clear cache khi cần
clear_storage_cache();
```

---

## 🌐 Sử Dụng API Endpoints

### 1. Get Full Status
```bash
curl http://domain.com/storage-check/status
```
```json
{
    "status": "warning",
    "is_warning": true,
    "is_full": false,
    "usage_percentage": 85.2,
    "current_size_formatted": "870 MB", 
    "total_capacity_mb": 1024,
    "messages": {
        "warning": "Dung lượng đã sử dụng 85.2%, nên dọn dẹp"
    }
}
```

### 2. Quick Check API
```bash
curl http://domain.com/storage-check/quick
```
```json
{
    "needs_attention": true,
    "usage_percentage": 85.2,
    "status": "warning",
    "messages": ["Cần dọn dẹp dung lượng"]
}
```

### 3. Clear Cache API
```bash
curl -X POST http://domain.com/storage-check/clear-cache
```
```json
{
    "success": true,
    "message": "Cache cleared successfully"
}
```

### 4. Test View Component
```
GET http://domain.com/storage-check/test-view
```

---

## 🎨 Sử Dụng View Component

### 1. Include Component
```blade
{{-- Trong admin dashboard --}}
@include('license::storage-notification')
```

### 2. Conditional Display
```blade
{{-- Chỉ hiện khi cần attention --}}
@if(storage_quick_check()['needs_attention'])
    @include('license::storage-notification')
@endif
```

### 3. Custom Implementation
```blade
@php
    $storage = check_storage_usage();
@endphp

@if($storage['is_warning'] || $storage['is_full'])
<div class="alert alert-{{ $storage['is_full'] ? 'danger' : 'warning' }}">
    <strong>Storage {{ $storage['is_full'] ? 'Full' : 'Warning' }}!</strong>
    
    {{-- Progress bar --}}
    <div class="progress mt-2">
        <div class="progress-bar bg-{{ $storage['is_full'] ? 'danger' : 'warning' }}" 
             style="width: {{ $storage['usage_percentage'] }}%">
            {{ number_format($storage['usage_percentage'], 1) }}%
        </div>
    </div>
    
    {{-- Messages --}}
    @if(!empty($storage['messages']))
        <ul class="mt-2 mb-0">
            @foreach($storage['messages'] as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif
</div>
@endif
```

---

## ⚙️ Integration Examples

### 1. Admin Dashboard Alert
```php
// AdminController.php
public function dashboard()
{
    $data = [];
    
    // Check storage status
    if (is_storage_warning()) {
        $data['storage_alert'] = [
            'type' => is_storage_full() ? 'danger' : 'warning',
            'message' => 'Storage ' . (is_storage_full() ? 'đã đầy' : 'gần đầy'),
            'percentage' => get_storage_usage_percentage()
        ];
    }
    
    return view('admin.dashboard', $data);
}
```

### 2. Upload Middleware
```php
// CheckStorageMiddleware.php
public function handle($request, Closure $next)
{
    if (is_storage_full()) {
        return response()->json([
            'error' => 'Storage đã đầy, không thể upload!'
        ], 413); // Payload Too Large
    }
    
    if (is_storage_warning()) {
        // Log warning
        Log::warning('Storage warning during upload', [
            'percentage' => get_storage_usage_percentage()
        ]);
    }
    
    return $next($request);
}
```

### 3. Cron Job Monitoring
```php
// CheckStorageCommand.php  
public function handle()
{
    $status = check_storage_usage();
    
    if ($status['is_full']) {
        // Send email alert
        Mail::to(config('admin.email'))->send(new StorageFullAlert($status));
        $this->error('🚨 STORAGE FULL!');
    }
    
    elseif ($status['is_warning']) {
        $this->warn('⚠️ Storage warning: ' . $status['usage_percentage'] . '%');
    }
    
    // Check expiring additional storage
    if ($status['additional_storage']['expiring_soon']) {
        $days = $status['additional_storage']['days_until_expiry'];
        $this->warn("⏰ Additional storage expires in {$days} days");
    }
    
    $this->info('✅ Storage check completed');
}
```

### 4. AJAX Auto-refresh
```javascript
// Auto check every 5 minutes
setInterval(function() {
    fetch('/storage-check/quick')
        .then(response => response.json())
        .then(data => {
            if (data.needs_attention) {
                // Show notification
                showStorageAlert(data);
            } else {
                // Hide notification
                hideStorageAlert();
            }
        });
}, 300000); // 5 minutes
```

---

## 🛡️ Error Handling

### 1. Safe Function Usage
```php
// Always check function exists
if (function_exists('check_storage_usage')) {
    $status = check_storage_usage();
    
    if ($status['status'] === 'error') {
        Log::error('Storage check failed', $status);
        return false;
    }
} else {
    Log::warning('Storage helpers not loaded');
    return false;
}
```

### 2. Try-catch Pattern
```php
try {
    $storage = check_storage_usage();
    
    if ($storage['is_warning']) {
        // Handle warning
    }
    
} catch (Exception $e) {
    Log::error('Storage check exception: ' . $e->getMessage());
    
    // Fallback behavior
    $storage = [
        'status' => 'error',
        'usage_percentage' => 0,
        'messages' => ['Không thể kiểm tra dung lượng']
    ];
}
```

---

## 📊 Monitoring & Thresholds

### Default Thresholds
```php
// Warning level
$warning_threshold = 80; // >80% = warning

// Full level  
$full_threshold = 95;    // >95% = full

// Expiry warning
$expiry_days = 30;       // <30 days = expiring soon
```

### Cache Settings
```php
// Cache duration
$cache_ttl = 300; // 5 minutes

// Cache key
$cache_key = 'simple_storage_status';
```

---

## 🧪 Testing & Debug

### 1. Test Commands
```bash
# Test trong tinker
php artisan tinker
>>> check_storage_usage()
>>> storage_quick_check() 
>>> is_storage_warning()
>>> get_storage_usage_percentage()

# Test APIs
curl http://domain.com/storage-check/status
curl http://domain.com/storage-check/quick
```

### 2. Debug Logs
```php
// Enable debug logs
Log::info('Storage check result:', check_storage_usage());

// Check theme_validate data
Log::info('Theme validate:', getOption('theme_validate', []));

// Check current directory size
Log::info('Directory size:', [
    'path' => storage_path(),
    'size_mb' => disk_free_space(storage_path()) / 1024 / 1024
]);
```

### 3. Manual Testing
```php
// Test with fake data
function test_storage_scenarios() {
    // Scenario 1: Normal usage (50%)
    // Scenario 2: Warning (85%) 
    // Scenario 3: Full (98%)
    // Scenario 4: Expiring additional storage
}
```

---

## 🚨 Troubleshooting

### 1. Helpers không load
```bash
# Check service provider
php artisan route:list | grep storage-check

# Clear cache
php artisan config:clear
php artisan route:clear
```

### 2. getOption() không tìm thấy
```php
// Check if function exists
if (!function_exists('getOption')) {
    Log::error('getOption helper not found');
    return ['status' => 'error', 'message' => 'Missing getOption helper'];
}
```

### 3. Theme validate data empty
```php
$theme_validate = getOption('theme_validate', []);
if (empty($theme_validate)) {
    Log::warning('theme_validate data is empty');
    // Setup default data
}
```

---

## 📈 Performance Tips

1. **Cache Usage**: Kết quả được cache 5 phút
2. **Lightweight**: Chỉ check cần thiết, không scan toàn bộ files  
3. **Background Tasks**: Dùng queue cho heavy operations
4. **Rate Limiting**: API có built-in throttling

---

## 🔒 Security Notes

1. **API Access**: Chỉ admin mới được access storage APIs
2. **Sensitive Data**: Không expose absolute paths
3. **Error Messages**: Không leak system information

---

Hệ thống đơn giản, hiệu quả và đáp ứng đầy đủ yêu cầu check dung lượng theo theme_validate! 🎯