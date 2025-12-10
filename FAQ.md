# ❓ FAQ & Troubleshooting

## 🔍 Frequently Asked Questions

### Q1: Helper functions không hoạt động?
**A:** Kiểm tra xem package đã được load đúng chưa:
```bash
php artisan tinker
>>> function_exists('check_storage_usage')
>>> function_exists('getOption')
```

Nếu `false`, check:
- ServiceProvider đã đăng ký chưa
- Helper file đã được load chưa
- Có lỗi syntax không

---

### Q2: Làm sao setup theme_validate data?
**A:** Data phải có cấu trúc:
```php
// Trong database settings
[
    'theme_validate' => [
        'storage_capacity' => 1024,      // MB
        'storage_additional' => [
            'capacity' => 500,           // MB  
            'expires_at' => '2024-12-31'
        ]
    ]
]
```

Hoặc setup qua code:
```php
if (function_exists('setOption')) {
    setOption('theme_validate', [
        'storage_capacity' => 1024,
        'storage_additional' => [
            'capacity' => 500,
            'expires_at' => '2024-12-31'
        ]
    ]);
}
```

---

### Q3: Storage luôn báo error?
**A:** Check các điều kiện:
1. **getOption() function exists**: `function_exists('getOption')`
2. **theme_validate data exists**: `getOption('theme_validate', [])`
3. **Storage path readable**: `is_readable(storage_path())`
4. **Disk space function available**: `function_exists('disk_free_space')`

Debug:
```php
$debug = [
    'getOption_exists' => function_exists('getOption'),
    'theme_validate' => getOption('theme_validate', 'NOT_FOUND'),
    'storage_path' => storage_path(),
    'storage_readable' => is_readable(storage_path()),
    'disk_free_space_available' => function_exists('disk_free_space')
];
dd($debug);
```

---

### Q4: Cache không work?
**A:** Check cache driver:
```bash
php artisan config:show cache.default
```

Test cache manually:
```php
cache(['test_key' => 'test_value'], 60);
$retrieved = cache('test_key');
var_dump($retrieved); // Should show 'test_value'
```

---

### Q5: API endpoints trả 404?
**A:** Check routes:
```bash
php artisan route:list | grep storage
```

Nếu không có, check:
- ServiceProvider đăng ký routes chưa
- Routes file có syntax error không
- Web middleware group conflict

---

### Q6: Percentage calculation sai?
**A:** Kiểm tra data:
```php
$status = check_storage_usage();
echo "Current: " . $status['current_size_bytes'] . "\n";
echo "Total: " . ($status['total_capacity_mb'] * 1024 * 1024) . "\n";
echo "Calculated: " . ($status['current_size_bytes'] / ($status['total_capacity_mb'] * 1024 * 1024) * 100) . "%\n";
```

---

### Q7: Additional storage không hiển thị?
**A:** Check data structure:
```php
$theme = getOption('theme_validate', []);
if (!isset($theme['storage_additional'])) {
    echo "Missing storage_additional in theme_validate";
}

if (!isset($theme['storage_additional']['capacity'])) {
    echo "Missing capacity in storage_additional";
}
```

---

### Q8: Performance issue với large storage?
**A:** Optimize:
1. **Tăng cache TTL**: Mặc định 5 phút, có thể tăng lên 15-30 phút
2. **Use background processing**: Chạy trong queue
3. **Limit directory scanning**: Chỉ scan cần thiết

```php
// Custom cache duration
$cacheKey = 'simple_storage_status_extended';
$cached = cache($cacheKey);
if (!$cached) {
    $status = check_storage_usage();
    cache([$cacheKey => $status], 1800); // 30 minutes
}
```

---

## 🛠️ Troubleshooting Guide

### Problem: "Storage system not available"

**Symptoms:**
- Helper functions return error
- API endpoints return 500
- View components show error

**Solutions:**
1. **Check ServiceProvider registration**:
```php
// Check if service is bound
if (app()->bound(\Sudo\ImageDomainReplace\Services\SimpleStorageService::class)) {
    echo "Service is registered";
} else {
    echo "Service NOT registered";
}
```

2. **Check helper file loading**:
```php
if (file_exists(__DIR__.'/helpers/storage.php')) {
    require_once __DIR__.'/helpers/storage.php';
}
```

3. **Manual service instantiation**:
```php
try {
    $service = new \Sudo\ImageDomainReplace\Services\SimpleStorageService();
    $status = $service->getStorageStatus();
    echo "Service works manually";
} catch (\Exception $e) {
    echo "Service error: " . $e->getMessage();
}
```

---

### Problem: "getOption function not found"

**Symptoms:**
- Call to undefined function getOption()
- Storage check always returns error

**Solutions:**
1. **Check if function exists globally**:
```bash
grep -r "function getOption" app/ vendor/ packages/
```

2. **Define fallback function**:
```php
if (!function_exists('getOption')) {
    function getOption($key, $default = null) {
        // Fallback to config
        return config("settings.{$key}", $default);
    }
}
```

3. **Use Laravel native methods**:
```php
// Instead of getOption(), use:
$themeValidate = config('app.theme_validate', []);
// or
$themeValidate = \DB::table('settings')->where('key', 'theme_validate')->value('value');
```

---

### Problem: Incorrect storage calculation

**Symptoms:**
- Percentage shows wrong values
- Size formatting incorrect
- Warning/Full thresholds wrong

**Debug Steps:**
1. **Check raw values**:
```php
$service = app(\Sudo\ImageDomainReplace\Services\SimpleStorageService::class);

// Debug current size calculation
echo "Storage path: " . storage_path() . "\n";
echo "Directory exists: " . (is_dir(storage_path()) ? 'yes' : 'no') . "\n";

// Check theme_validate data
$theme = getOption('theme_validate', []);
echo "Theme validate data: " . json_encode($theme, JSON_PRETTY_PRINT) . "\n";

// Manual size calculation
$size = $service->getCurrentStorageSize();
echo "Current size: {$size} bytes\n";
```

2. **Verify directory calculation**:
```php
function getDirectorySize($path) {
    $size = 0;
    foreach (glob(rtrim($path, '/').'/*', GLOB_NOSORT) as $each) {
        $size += is_file($each) ? filesize($each) : getDirectorySize($each);
    }
    return $size;
}

$manualSize = getDirectorySize(storage_path());
echo "Manual calculation: {$manualSize} bytes\n";
```

3. **Check thresholds**:
```php
$status = check_storage_usage();
echo "Usage: {$status['usage_percentage']}%\n";
echo "Warning threshold: 80%\n";
echo "Full threshold: 95%\n";
echo "Is warning: " . ($status['usage_percentage'] > 80 ? 'yes' : 'no') . "\n";
echo "Is full: " . ($status['usage_percentage'] > 95 ? 'yes' : 'no') . "\n";
```

---

### Problem: Cache not working

**Symptoms:**
- Same calculation time every request
- Storage data never cached
- Performance issues

**Solutions:**
1. **Test cache system**:
```php
// Test basic caching
$testKey = 'storage_cache_test_' . time();
cache([$testKey => 'test_value'], 300);
$retrieved = cache($testKey);

if ($retrieved === 'test_value') {
    echo "Cache working\n";
    cache()->forget($testKey);
} else {
    echo "Cache NOT working\n";
}
```

2. **Check cache driver**:
```php
echo "Cache driver: " . config('cache.default') . "\n";
echo "Cache store: " . get_class(cache()->getStore()) . "\n";
```

3. **Force cache clear**:
```bash
php artisan cache:clear
php artisan config:clear
```

4. **Use specific cache store**:
```php
// Use file cache specifically
$cacheKey = 'simple_storage_status';
$fileCache = cache()->store('file');
$fileCache->put($cacheKey, $status, 300);
```

---

### Problem: Routes not loading

**Symptoms:**
- 404 on /storage-check/* endpoints
- Routes not in route:list

**Solutions:**
1. **Check route registration**:
```bash
php artisan route:list | grep -i storage
```

2. **Check ServiceProvider**:
```php
// In ImageDomainReplaceServiceProvider::boot()
$this->loadRoutesFrom(__DIR__ . '/routes/storage-simple.php');
```

3. **Check route file syntax**:
```php
// Test route file syntax
php -l packages/ImageDomainReplace/src/routes/storage-simple.php
```

4. **Manual route loading**:
```php
// In routes/web.php or routes/api.php
include_once base_path('packages/ImageDomainReplace/src/routes/storage-simple.php');
```

---

### Problem: View component not rendering

**Symptoms:**
- @include('license::storage-notification') shows error
- View not found error

**Solutions:**
1. **Check view path registration**:
```php
// In ServiceProvider::register()
$this->loadViewsFrom(__DIR__.'/View', 'license');
```

2. **Check file exists**:
```php
$viewPath = base_path('packages/ImageDomainReplace/src/View/storage-notification.blade.php');
echo "View exists: " . (file_exists($viewPath) ? 'yes' : 'no') . "\n";
```

3. **Use full path**:
```blade
{{-- Instead of @include('license::storage-notification') --}}
@include('path.to.view.storage-notification')
```

4. **Debug view loading**:
```php
$viewFactory = app('view');
if ($viewFactory->exists('license::storage-notification')) {
    echo "View found\n";
} else {
    echo "View NOT found\n";
    
    // Check registered paths
    $paths = $viewFactory->getFinder()->getPaths();
    echo "View paths: " . json_encode($paths) . "\n";
}
```

---

### Problem: Permission errors

**Symptoms:**
- Unable to calculate directory size
- Cannot write cache
- File access errors

**Solutions:**
1. **Check storage directory permissions**:
```bash
ls -la storage/
chmod -R 755 storage/
chown -R www-data:www-data storage/
```

2. **Check cache directory**:
```bash
ls -la storage/framework/cache/
chmod -R 755 storage/framework/cache/
```

3. **Test file operations**:
```php
$testFile = storage_path('app/test.txt');
if (file_put_contents($testFile, 'test')) {
    echo "Can write to storage\n";
    unlink($testFile);
} else {
    echo "Cannot write to storage\n";
}
```

---

### Problem: Memory or timeout issues

**Symptoms:**
- Script timeout on large directories
- Memory exhausted errors
- Slow response times

**Solutions:**
1. **Increase limits**:
```php
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);
```

2. **Use background processing**:
```php
// Queue storage calculation
dispatch(new CalculateStorageJob());
```

3. **Optimize directory scanning**:
```php
// Use iterator instead of recursive function
function getDirectorySizeOptimized($directory) {
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    
    return $size;
}
```

4. **Sample-based calculation**:
```php
// Calculate subset and extrapolate
function estimateStorageSize($directory, $sampleRatio = 0.1) {
    $files = glob($directory . '/*');
    $sampleSize = max(1, intval(count($files) * $sampleRatio));
    $sampleFiles = array_slice($files, 0, $sampleSize);
    
    $sampleTotal = 0;
    foreach ($sampleFiles as $file) {
        if (is_file($file)) {
            $sampleTotal += filesize($file);
        }
    }
    
    return intval($sampleTotal / $sampleRatio);
}
```

---

## 🔧 Advanced Debugging

### Enable Debug Mode
```php
// Add to config/app.php or .env
'debug' => true,
'log_level' => 'debug',

// Enable storage debugging
'IMAGE_DOMAIN_REPLACE_DEBUG' => true,
```

### Debug Service
```php
// Create debug endpoint
Route::get('/debug/storage', function() {
    $debug = [];
    
    // Check function availability
    $debug['functions'] = [
        'check_storage_usage' => function_exists('check_storage_usage'),
        'getOption' => function_exists('getOption'),
        'storage_quick_check' => function_exists('storage_quick_check'),
    ];
    
    // Check service binding
    $debug['services'] = [
        'SimpleStorageService' => app()->bound(\Sudo\ImageDomainReplace\Services\SimpleStorageService::class),
    ];
    
    // Check file paths
    $debug['paths'] = [
        'storage_path' => storage_path(),
        'storage_exists' => is_dir(storage_path()),
        'storage_readable' => is_readable(storage_path()),
        'storage_writable' => is_writable(storage_path()),
    ];
    
    // Check theme_validate
    try {
        $debug['theme_validate'] = function_exists('getOption') ? getOption('theme_validate', 'FUNCTION_EXISTS_BUT_NO_DATA') : 'FUNCTION_NOT_EXISTS';
    } catch (\Exception $e) {
        $debug['theme_validate'] = 'ERROR: ' . $e->getMessage();
    }
    
    // Check cache
    $testKey = 'debug_cache_test';
    cache([$testKey => time()], 60);
    $debug['cache'] = [
        'driver' => config('cache.default'),
        'test_write' => cache($testKey) !== null,
    ];
    cache()->forget($testKey);
    
    return response()->json($debug, 200, [], JSON_PRETTY_PRINT);
});
```

### Performance Profiling
```php
// Add performance tracking
function profileStorageCheck() {
    $start = microtime(true);
    $memory_start = memory_get_usage(true);
    
    $result = check_storage_usage();
    
    $end = microtime(true);
    $memory_end = memory_get_usage(true);
    
    return [
        'result' => $result,
        'performance' => [
            'time_ms' => round(($end - $start) * 1000, 2),
            'memory_mb' => round(($memory_end - $memory_start) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]
    ];
}
```

Với guide này, bạn có thể giải quyết hầu hết các vấn đề gặp phải với Simple Storage System! 🔧