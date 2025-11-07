# Hướng dẫn tách License Management thành Package riêng

## Tóm tắt
Hướng dẫn này sẽ giúp bạn tách phần quản lý license (bao gồm API `updateLicense` và logic validation) từ codebase hiện tại thành một package độc lập.

## Cấu trúc Package mới

### 1. Tạo cấu trúc thư mục
```
packages/license-management/
├── composer.json
├── src/
│   ├── LicenseManagementServiceProvider.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── LicenseController.php
│   ├── Services/
│   │   └── LicenseValidationService.php
│   ├── Middleware/
│   │   └── LicenseValidationMiddleware.php
│   ├── Facades/
│   │   └── LicenseManager.php
│   └── Events/
│       └── LicenseUpdatedEvent.php
├── config/
│   └── license.php
├── routes/
│   └── license.php
└── README.md
```

### 2. Tạo composer.json cho package mới
```json
{
    "name": "sudo/license-management",
    "description": "License Management Package for Sudo WebsiteAI",
    "type": "library",
    "require": {
        "php": "^8.0",
        "illuminate/support": "^9.0|^10.0|^11.0"
    },
    "autoload": {
        "psr-4": {
            "Sudo\\LicenseManagement\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Sudo\\LicenseManagement\\LicenseManagementServiceProvider"
            ],
            "aliases": {
                "LicenseManager": "Sudo\\LicenseManagement\\Facades\\LicenseManager"
            }
        }
    }
}
```

## Các file cần tạo

### 1. LicenseManagementServiceProvider.php
```php
<?php

namespace Sudo\LicenseManagement;

use Illuminate\Support\ServiceProvider;
use Sudo\LicenseManagement\Services\LicenseValidationService;

class LicenseManagementServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/license.php', 'license');
        
        $this->app->singleton('license-manager', function ($app) {
            return new LicenseValidationService();
        });
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/license.php');
        
        $this->publishes([
            __DIR__ . '/../config/license.php' => config_path('license.php'),
        ], 'license-config');

        // Di chuyển logic booted từ PluginManagementServiceProvider
        $this->app->booted(function(){
            if(function_exists('dGhlbWVWYWxpZGF0ZQ') && \Schema::hasTable('settings')) {
                dGhlbWVWYWxpZGF0ZQ();
            }
        });
    }
}
```

### 2. LicenseController.php
```php
<?php

namespace Sudo\LicenseManagement\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sudo\LicenseManagement\Services\LicenseValidationService;
use Sudo\LicenseManagement\Events\LicenseUpdatedEvent;

class LicenseController extends Controller
{
    protected $licenseService;

    public function __construct(LicenseValidationService $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    public function updateLicense(Request $request)
    {
        try {
            \Log::info('License update API called');
            
            $result = $this->licenseService->updateLicense($request->all());
            
            event(new LicenseUpdatedEvent($result));
            
            return response()->json(['error' => false]);
        } catch (Exception $e) {
            return response()->json(['error' => true, 'message' => $e->getMessage()]);
        }
    }
}
```

### 3. LicenseValidationService.php
```php
<?php

namespace Sudo\LicenseManagement\Services;

use Illuminate\Support\Facades\Artisan;
use Sudo\Base\Models\Setting;
use Sudo\Base\Supports\SettingStore;
use Sudo\PluginManagement\Events\ClearCacheEvent;

class LicenseValidationService
{
    public function updateLicense(array $data)
    {
        $settingName = 'theme_validate';
        
        // Xử lý dữ liệu
        $unset = ['_token', 'redirect', 'setLanguage'];
        foreach ($unset as $value) {
            unset($data[$value]);
        }
        
        $data = removeScriptArray($data);
        $data = base64_encode(json_encode($data));
        
        // Cập nhật hoặc tạo setting
        if (Setting::where('key', $settingName)->exists()) {
            Setting::where('key', $settingName)->update([
                'value' => $data
            ]);
        } else {
            Setting::insert([
                'key' => $settingName,
                'locale' => '',
                'value' => $data
            ]);
        }
        
        // Cập nhật setting store
        $settingStore = app(SettingStore::class);
        $settingStore->set('media_size_calculator', null);
        $settingStore->save();
        
        // Clear cache
        event(new ClearCacheEvent());
        Artisan::call('sudo:clear');
        
        return $data;
    }

    public function validateLicense()
    {
        try {
            $data = getOption('theme_validate', 'all', false);
            
            if (isset($data['domain']) && $data['domain'] != eval(base64_decode('cmV0dXJuIGdldEhvc3RGcm9tQ29uZmlnKCk7'))) {
                $data = \Sudo\Base\Facades\BaseHelper::Z2V0SW5mb21hdGlvbkxpY2Vuc2U();
                openNoticeEXp();
                exit();
            }
            
            if (empty($data)) {
                $data = \Sudo\Base\Facades\BaseHelper::Z2V0SW5mb21hdGlvbkxpY2Vuc2U();
            }
            
            $endTime = $data['end_time'] ?? '';
            if (!empty($endTime)) {
                $endTime = date('Y-m-d', strtotime($endTime));
            }
            
            if (!empty($endTime) && $endTime < date('Y-m-d')) {
                $data = \Sudo\Base\Facades\BaseHelper::Z2V0SW5mb21hdGlvbkxpY2Vuc2U();
                openNoticeEXp();
                exit();
            }
            
            return $data;
        } catch (\Exception $e) {
            \Log::error('License validation failed: ' . $e->getMessage());
            return false;
        }
    }
}
```

### 4. LicenseManager Facade
```php
<?php

namespace Sudo\LicenseManagement\Facades;

use Illuminate\Support\Facades\Facade;

class LicenseManager extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'license-manager';
    }
}
```

### 5. LicenseUpdatedEvent.php
```php
<?php

namespace Sudo\LicenseManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LicenseUpdatedEvent
{
    use Dispatchable, SerializesModels;

    public $licenseData;

    public function __construct($licenseData)
    {
        $this->licenseData = $licenseData;
    }
}
```

### 6. config/license.php
```php
<?php

return [
    'setting_key' => 'theme_validate',
    'validation_enabled' => env('LICENSE_VALIDATION_ENABLED', true),
    'auto_clear_cache' => env('LICENSE_AUTO_CLEAR_CACHE', true),
];
```

### 7. routes/license.php
```php
<?php

use Illuminate\Support\Facades\Route;
use Sudo\LicenseManagement\Http\Controllers\LicenseController;

Route::middleware(['web', 'auth:admin'])->prefix('admin')->group(function () {
    Route::post('/license/update', [LicenseController::class, 'updateLicense'])
        ->name('admin.license.update');
});
```

## Các bước thực hiện

### Bước 1: Tạo package mới
1. Tạo thư mục `packages/license-management/`
2. Tạo tất cả các file theo cấu trúc trên

### Bước 2: Cập nhật composer.json chính
Thêm package mới vào `composer.json` của dự án:
```json
{
    "autoload": {
        "psr-4": {
            "Sudo\\LicenseManagement\\": "packages/license-management/src/"
        }
    }
}
```

### Bước 3: Đăng ký service provider
Thêm vào `config/app.php`:
```php
'providers' => [
    // ...
    Sudo\LicenseManagement\LicenseManagementServiceProvider::class,
],
```

### Bước 4: Di chuyển code hiện tại

#### 4.1 Xóa code từ SystemManagerment.php
- Xóa method `updateLicense` từ file này
- Route sẽ được chuyển sang package mới

#### 4.2 Cập nhật PluginManagementServiceProvider.php
- Xóa phần code booted trong file này:
```php
// Xóa code này
$this->app->booted(function(){
    if(function_exists('dGhlbWVWYWxpZGF0ZQ') && \Schema::hasTable('settings')) {
        dGhlbWVWYWxpZGF0ZQ();
    }
});
```

#### 4.3 Cập nhật function dGhlbWVWYWxpZGF0ZQ trong functions.php
Thay thế bằng:
```php
if (!function_exists('dGhlbWVWYWxpZGF0ZQ')) {
    function dGhlbWVWYWxpZGF0ZQ()
    {
        return app('license-manager')->validateLicense();
    }
}
```

### Bước 5: Cập nhật routes
Xóa route cũ và sử dụng route mới từ package.

### Bước 6: Test và deploy
1. Chạy `composer dump-autoload`
2. Test các chức năng license
3. Kiểm tra validation middleware
4. Deploy package

## Lợi ích của việc tách package

1. **Tách biệt concerns**: License management được tách riêng
2. **Dễ maintain**: Code dễ bảo trì và cập nhật
3. **Reusable**: Có thể sử dụng lại trong các project khác
4. **Clean architecture**: Giảm coupling giữa các module
5. **Testing**: Dễ dàng test riêng biệt

## Lưu ý quan trọng

1. Đảm bảo tất cả dependencies được khai báo đúng
2. Test kỹ lưỡng trước khi deploy
3. Backup database trước khi thay đổi
4. Cập nhật documentation cho team
5. Kiểm tra performance sau khi tách package