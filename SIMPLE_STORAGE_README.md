# Simple Storage Monitoring

Hệ thống kiểm tra dung lượng đơn giản, chỉ tập trung vào việc check dung lượng từ `theme_validate` data và thông báo hết hạn.

## Tính năng chính

- ✅ Check dung lượng sử dụng so với license trong `theme_validate`
- ✅ Kiểm tra ngày hết hạn additional storage
- ✅ Thông báo khi dung lượng gần đầy (>80%) hoặc đã đầy (>95%)
- ✅ Hỗ trợ PHP 7.1+
- ✅ Tương thích Laravel 5.5+
- ✅ Cache kết quả để tối ưu performance

## Cài đặt

Service đã được đăng ký tự động trong `ImageDomainReplaceServiceProvider`, không cần config thêm.

## Sử dụng

### Helper Functions

```php
// Check storage usage
$status = check_storage_usage();

// Quick check nếu cần chú ý
$quick = storage_quick_check();

// Kiểm tra warning
if (is_storage_warning()) {
    echo "Storage đang warning!";
}

// Kiểm tra full
if (is_storage_full()) {
    echo "Storage đã đầy!";
}

// Lấy phần trăm sử dụng
$percentage = get_storage_usage_percentage();

// Lấy messages
$messages = get_storage_messages();

// Check additional storage sắp hết hạn
if (has_additional_storage_expiring()) {
    echo "Additional storage sắp hết hạn!";
}

// Clear cache
clear_storage_cache();
```

### API Endpoints

```
GET /storage-check/status       - Lấy thông tin đầy đủ
GET /storage-check/quick        - Quick check
POST /storage-check/clear-cache - Clear cache
GET /storage-check/test-view    - Test view component
```

### View Component

Sử dụng view component để hiển thị thông báo:

```blade
@include('license::storage-notification')
```

## Data Structure

### Theme Validate Data
```php
[
    'storage_capacity' => 1024,      // MB - dung lượng cơ bản
    'storage_additional' => [
        'capacity' => 500,           // MB - dung lượng thêm
        'expires_at' => '2024-12-31' // Ngày hết hạn
    ]
]
```

### Storage Status Response
```php
[
    'status' => 'ok|warning|full',
    'is_warning' => false,
    'is_full' => false,
    'usage_percentage' => 75.5,
    'current_size_bytes' => 786432000,
    'current_size_formatted' => '750 MB',
    'total_capacity_mb' => 1024,
    'available_mb' => 274,
    'messages' => [
        'info' => 'Dung lượng còn lại: 274 MB'
    ],
    'additional_storage' => [
        'enabled' => true,
        'capacity_mb' => 500,
        'expires_at' => '2024-12-31',
        'days_until_expiry' => 30,
        'expiring_soon' => false
    ]
]
```

### Quick Check Response
```php
[
    'needs_attention' => true,
    'usage_percentage' => 85.2,
    'status' => 'warning',
    'messages' => [
        'warning' => 'Dung lượng đã sử dụng 85.2%, nên dọn dẹp'
    ]
]
```

## Cache

- Cache key: `simple_storage_status`
- TTL: 300 seconds (5 phút)
- Auto refresh khi clear cache

## Thresholds

- **Warning**: >80% dung lượng
- **Full**: >95% dung lượng  
- **Expiring Soon**: <30 ngày đến hết hạn additional storage

## PHP Compatibility

- PHP 7.1+: ✅ Full support
- Laravel 5.5+: ✅ Full support
- Backward compatible với existing helpers

## Files

```
src/
├── Services/SimpleStorageService.php
├── helpers/storage.php  
├── routes/storage-simple.php
├── View/storage-notification.blade.php
└── ImageDomainReplaceServiceProvider.php
```

## Testing

```bash
# Test API
curl http://domain.com/storage-check/status
curl http://domain.com/storage-check/quick

# Test view
http://domain.com/storage-check/test-view

# Test helpers trong tinker
php artisan tinker
>>> check_storage_usage()
>>> storage_quick_check()
```

## Integration

Để tích hợp vào existing admin panel:

```php
// Trong controller hoặc middleware
if (is_storage_warning()) {
    session()->flash('storage_warning', 'Dung lượng gần đầy!');
}

// Trong blade template
@if(storage_quick_check()['needs_attention'])
    @include('license::storage-notification')
@endif
```

Đơn giản, hiệu quả, tập trung vào yêu cầu cốt lõi! 🚀