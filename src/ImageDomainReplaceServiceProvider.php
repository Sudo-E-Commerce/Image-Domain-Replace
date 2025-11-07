<?php

namespace Sudo\ImageDomainReplace;

use Illuminate\Support\ServiceProvider;
use Sudo\ImageDomainReplace\Services\LicenseValidationService;

class ImageDomainReplaceServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Ghi đè cấu hình để thêm 'use_path_style_endpoint' cho S3 và DO
        config()->set('filesystems.disks.s3.use_path_style_endpoint', true);
        config()->set('filesystems.disks.do.use_path_style_endpoint', true);
        
        // Đăng ký License Manager service
        $this->app->singleton('image-domain-replace.license-manager', function ($app) {
            return new LicenseValidationService();
        });
        
        // Merge license config
        $this->mergeConfigFrom(__DIR__ . '/config/license.php', 'image-domain-replace-license');
    }

    public function boot()
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/routes/license.php');
        
        // Đăng ký middleware khi boot
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('web', \Sudo\ImageDomainReplace\Middleware\ImageDomainReplaceMiddleware::class);

        // Publish config
        $this->publishes([
            __DIR__.'/../config/image-domain-replace.php' => config_path('image-domain-replace.php'),
        ], 'config');

        // Publish public assets (JS files)
        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/image-domain-replace'),
        ], 'public');

        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../config/image-domain-replace.php', 'image-domain-replace'
        );

        // Khai báo helpers/function.php vào hệ thống, chỉ function.php
        if (file_exists($file = __DIR__.'/helpers/function.php')) {
            require_once $file;
        }
        
        // Load license helpers
        if (file_exists($file = __DIR__.'/helpers/license.php')) {
            require_once $file;
        }
        
        // License validation boot logic
        $this->bootLicenseValidation();
        
        // Register custom throttle middleware for license APIs
        $this->registerLicenseThrottleMiddleware();
    }
    
    /**
     * Boot license validation logic
     */
     protected function bootLicenseValidation()
     {
         $this->app->booted(function () {
             try {
                 if (config('image-domain-replace-license.validation_enabled', true) && 
                     \Illuminate\Support\Facades\Schema::hasTable('settings')) {
                     // Thực hiện validation license nếu cần
                     if (function_exists('dGhlbWVWYWxpZGF0ZQ')) {
                         dGhlbWVWYWxpZGF0ZQ();
                     }
                 }
             } catch (\Exception $e) {
                 // Silent fail để không crash app khi database chưa setup
                 \Illuminate\Support\Facades\Log::debug('License validation boot failed', [
                     'error' => $e->getMessage()
                 ]);
             }
         });
     }

     /**
      * Register license throttle middleware
      */
     protected function registerLicenseThrottleMiddleware()
     {
         $router = $this->app['router'];
         
         // Đăng ký throttle middleware cho license APIs
         $router->aliasMiddleware('throttle:license-update', 
             \Illuminate\Routing\Middleware\ThrottleRequests::class . ':10,60');
         $router->aliasMiddleware('throttle:license-info', 
             \Illuminate\Routing\Middleware\ThrottleRequests::class . ':60,60');
         $router->aliasMiddleware('throttle:license-validate', 
             \Illuminate\Routing\Middleware\ThrottleRequests::class . ':30,60');
     }
}
