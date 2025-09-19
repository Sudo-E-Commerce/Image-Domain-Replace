# Image Domain Replace Laravel Package

## Cài đặt

1. Thêm vào composer.json của project:

```json
"repositories": [
    {
        "type": "path",
        "url": "./packages/ImageDomainReplace"
    }
],
```

2. Cài đặt package:

```bash
composer require sudo/image-domain-replace:dev-main
```

3. Publish assets (JavaScript files):

```bash
php artisan vendor:publish --provider="Sudo\\ImageDomainReplace\\ImageDomainReplaceServiceProvider" --tag=public
```

4. Publish config (tùy chọn):

```bash
php artisan vendor:publish --provider="Sudo\\ImageDomainReplace\\ImageDomainReplaceServiceProvider" --tag=config
```

5. Cấu hình domain mới trong `.env` hoặc `config/image-domain-replace.php`:

```
IMAGE_DOMAIN_REPLACE_NEW=https://storage.sudospaces.com
IMAGE_DOMAIN_REPLACE_FALLBACK=/assets/img/default_image.png
```

## Cấu hình

File JavaScript sẽ được publish tại: `/vendor/image-domain-replace/js/script.js`

## Chức năng

Package tự động:
- Tự động replace domain ảnh cũ về domain mới trong response HTML
- Thêm fallback JavaScript cho ảnh lỗi với retry logic thông minh  
- Tự động kiểm tra/tạo ảnh resize khi cần thiết với API endpoint `/image/check-and-upload`
- Xử lý cache busting và retry để tăng độ tin cậy
- Hỗ trợ dynamic images (SPA applications) với MutationObserver
- Tương thích mọi phiên bản Laravel/PHP >= 7.2

## Sử dụng nâng cao

Có thể khởi tạo thủ công JavaScript với config tùy chỉnh:

```javascript
const imageHandler = new ImageDomainReplace({
    fallbackImageUrl: '/custom/placeholder.jpg',
    checkImageUrl: '/image/check-and-upload',
    maxRetries: 5,
    retryDelay: 2000,
    debug: true
});
```

## Tuỳ chỉnh
- Sửa `config/image-domain-replace.php` để thay đổi domain mới hoặc tắt queue

## Đóng góp
PR hoặc issue tại repo này.


Lưu ý: Tất cả repo sử dụng se mbf cần thêm             'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false)
ở filesystem.php và 
AWS_USE_PATH_STYLE_ENDPOINT=true
ở .env