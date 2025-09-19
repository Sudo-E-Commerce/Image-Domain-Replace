<?php
// config for Sudo/ImageDomainReplace
return [
    'new_domain' => env('IMAGE_DOMAIN_REPLACE_NEW', 'img.fastmobile.vn'),
    'queue_bucket_check' => false, // Nếu true sẽ dùng queue để check/tạo ảnh bucket
    'fallback_image' => env('IMAGE_DOMAIN_REPLACE_FALLBACK', '/vendor/core/core/base/img/placeholder.png'), // Compatible with existing script.js
];
