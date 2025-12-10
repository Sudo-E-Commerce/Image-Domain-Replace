<?php

namespace Sudo\ImageDomainReplace\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Aws\S3\S3Client;
use Exception;

class CheckStorageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:check-cdn
                            {--force : Force check ngay lập tức}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check dung lượng CDN/S3 và lưu vào database';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting CDN storage check...');
        
        try {
            // Tính dung lượng hiện tại
            $storageSize = $this->getCurrentStorageSize();
            
            if ($storageSize === false) {
                $this->error('Failed to calculate storage size');
                return 1;
            }
            
            // Format dung lượng
            $formattedSize = $this->formatBytes($storageSize);
            
            $this->info("Current CDN Storage: {$formattedSize} ({$storageSize} bytes)");
            
            // Lưu vào database
            $this->saveToDatabase($storageSize, $formattedSize);
            
            $this->info('✓ Storage check completed successfully!');
            
            return 0;
            
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('CheckStorageCommand failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
    
    /**
     * Get current storage size
     * 
     * @return int|false Size in bytes or false on error
     */
    private function getCurrentStorageSize()
    {
        try {
            $driver = config('app.storage_type') ?? config('SudoMedia.storage_type', 'do');
            
            if ($driver == 'digitalocean') {
                $driver = 'do';
            }

            $this->info("Storage driver: {$driver}");

            if ($driver === 'local') {
                return $this->getLocalStorageSize();
            } else {
                return $this->getCloudStorageSize();
            }
            
        } catch (Exception $e) {
            Log::error('Cannot calculate storage size', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get local storage size
     * 
     * @return int
     */
    private function getLocalStorageSize()
    {
        $basePath = public_path();
        
        $this->info("Calculating local storage at: {$basePath}");
        
        // Try shell command first (faster)
        if (function_exists('shell_exec') && PHP_OS_FAMILY !== 'Windows') {
            $command = "du -sb " . escapeshellarg($basePath) . " 2>/dev/null | cut -f1";
            $output = shell_exec($command);
            
            if ($output !== null && is_numeric(trim($output))) {
                return (int) trim($output);
            }
        }
        
        $this->warn('Shell command not available, using PHP calculation (slower)');
        return $this->calculateDirectorySize($basePath);
    }
    
    /**
     * Calculate directory size
     * 
     * @param string $directory
     * @param int $depth
     * @return int
     */
    private function calculateDirectorySize($directory, $depth = 0)
    {
        if ($depth > 5 || !is_dir($directory)) {
            return 0;
        }
        
        $size = 0;
        $excludeDirs = ['vendor', 'node_modules', '.git', 'storage/logs'];
        
        try {
            $iterator = new \DirectoryIterator($directory);
            
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot()) {
                    continue;
                }
                
                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $fileInfo->getPathname());
                
                $skip = false;
                foreach ($excludeDirs as $excludeDir) {
                    if (strpos($relativePath, $excludeDir) === 0) {
                        $skip = true;
                        break;
                    }
                }
                
                if ($skip) {
                    continue;
                }
                
                if ($fileInfo->isFile()) {
                    $size += $fileInfo->getSize();
                } elseif ($fileInfo->isDir()) {
                    $size += $this->calculateDirectorySize($fileInfo->getPathname(), $depth + 1);
                }
            }
        } catch (Exception $e) {
            Log::warning('Error calculating directory size', [
                'directory' => $directory,
                'error' => $e->getMessage()
            ]);
        }
        
        return $size;
    }
    
    /**
     * Get cloud storage size (S3/DigitalOcean Spaces)
     * 
     * @return int
     */
    private function getCloudStorageSize()
    {
        try {
            $config = config("image-domain-replace.license.storage");
            
            if (!$config || !isset($config['key']) || !isset($config['secret'])) {
                $this->error('CDN configuration not found');
                return 0;
            }
            
            $this->info("Connecting to CDN: {$config['endpoint']}");
            $this->info("Bucket: {$config['bucket']}");
            
            $s3Client = new S3Client([
                'region'  => $config['region'],
                'endpoint'  => $config['endpoint'],
                'version' => 'latest',
                'credentials' => [
                    'key'    => $config['key'],
                    'secret' => $config['secret'],
                ],
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false)
            ]);

            $bucket = $config['bucket'];
            $size = 0;
            $objectCount = 0;

            $params = [
                'Bucket' => $bucket,
                'MaxKeys' => 1000,
            ];

            $bar = $this->output->createProgressBar();
            $bar->start();

            do {
                $result = $s3Client->listObjectsV2($params);

                if (!empty($result['Contents'])) {
                    foreach ($result['Contents'] as $obj) {
                        $size += $obj['Size'];
                        $objectCount++;
                        $bar->advance();
                    }
                }

                if ($result['IsTruncated']) {
                    $params['ContinuationToken'] = $result['NextContinuationToken'];
                } else {
                    break;
                }

            } while (true);

            $bar->finish();
            $this->info("Total objects: {$objectCount}");

            return $size;
            
        } catch (Exception $e) {
            $this->error('Failed to get cloud storage size: ' . $e->getMessage());
            Log::error('Cloud storage size check failed', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Save storage data to database
     * 
     * @param int $sizeBytes
     * @param string $sizeFormatted
     * @return void
     */
    private function saveToDatabase($sizeBytes, $sizeFormatted)
    {
        $data = [
            'size_bytes' => $sizeBytes,
            'size_formatted' => $sizeFormatted,
            'checked_at' => date('Y-m-d H:i:s'),
            'storage_driver' => config('app.storage_type') ?? config('SudoMedia.storage_type', 'do')
        ];
        
        $encodedValue = base64_encode(json_encode($data));
        
        // Thử lưu vào bảng settings trước
        if (Schema::hasTable('settings')) {
            $exists = DB::table('settings')->where('key', 'storage_cdn')->exists();
            
            if ($exists) {
                DB::table('settings')
                    ->where('key', 'storage_cdn')
                    ->update([
                        'value' => $encodedValue,
                        'updated_at' => now()
                    ]);
                $this->info('✓ Updated storage_cdn in settings table');
            } else {
                DB::table('settings')->insert([
                    'key' => 'storage_cdn',
                    'value' => $encodedValue,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $this->info('✓ Inserted storage_cdn into settings table');
            }
            
            return;
        }
        
        // Fallback: lưu vào bảng options
        if (Schema::hasTable('options')) {
            $exists = DB::table('options')->where('name', 'storage_cdn')->exists();
            
            if ($exists) {
                DB::table('options')
                    ->where('name', 'storage_cdn')
                    ->update([
                        'value' => $encodedValue
                    ]);
                $this->info('✓ Updated storage_cdn in options table');
            } else {
                DB::table('options')->insert([
                    'name' => 'storage_cdn',
                    'value' => $encodedValue
                ]);
                $this->info('✓ Inserted storage_cdn into options table');
            }
            
            return;
        }
        
        $this->warn('No suitable database table found (settings or options)');
    }
    
    /**
     * Format bytes to human readable
     * 
     * @param int $bytes
     * @return string
     */
    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        if ($bytes == 0) {
            return '0 B';
        }
        
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
