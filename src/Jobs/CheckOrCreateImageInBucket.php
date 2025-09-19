<?php

namespace Sudo\ImageDomainReplace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckOrCreateImageInBucket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $url;

    public function __construct($url)
    {
        $this->url = $url;
    }

    public function handle()
    {
        // TODO: Kiểm tra tồn tại và tạo ảnh trên bucket nếu cần
        // Ví dụ: dùng SDK của S3 hoặc DigitalOcean Spaces
    }
}
