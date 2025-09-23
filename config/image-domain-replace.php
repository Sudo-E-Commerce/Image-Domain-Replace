<?php
// config for Sudo/ImageDomainReplace
return [
    'new_domain' => env('IMAGE_DOMAIN_REPLACE_NEW', 'img.fastmobile.vn'),
    'queue_bucket_check' => false, // Nếu true sẽ dùng queue để check/tạo ảnh bucket
    'fallback_image' => env('IMAGE_DOMAIN_REPLACE_FALLBACK', '/vendor/core/core/base/img/placeholder.png'), // Compatible with existing script.js
    'regex_patterns' => env('IMAGE_REGEX_PATTERNS', ''), // Các pattern regex để nhận diện domain cũ, phân cách nhau bởi dấu phẩy. Ví dụ: resize,cdn,storage,karofi,img
];
