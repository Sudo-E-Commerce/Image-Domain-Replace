<?php

namespace Sudo\ImageDomainReplace;

use Illuminate\Support\ServiceProvider;

class ImageDomainReplaceServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Ghi đè cấu hình để thêm 'use_path_style_endpoint' cho S3 và DO
        config()->set('filesystems.disks.s3.use_path_style_endpoint', true);
        config()->set('filesystems.disks.do.use_path_style_endpoint', true);
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        
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
    }
}
