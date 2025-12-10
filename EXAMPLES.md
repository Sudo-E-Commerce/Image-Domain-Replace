# 🔧 Simple Storage Examples

Các ví dụ thực tế sử dụng Simple Storage System.

## 📋 Table of Contents
- [Basic Examples](#basic-examples)
- [Admin Integration](#admin-integration) 
- [Upload Management](#upload-management)
- [Monitoring & Alerts](#monitoring--alerts)
- [API Usage](#api-usage)
- [Custom Views](#custom-views)

---

## 🎯 Basic Examples

### 1. Check Storage trong Controller
```php
<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        // Basic storage check
        $storageStatus = [];
        
        if (function_exists('storage_quick_check')) {
            $quick = storage_quick_check();
            
            if ($quick['needs_attention']) {
                $storageStatus = [
                    'show_alert' => true,
                    'type' => $quick['status'], // 'warning' or 'full'
                    'percentage' => $quick['usage_percentage'],
                    'messages' => $quick['messages']
                ];
            }
        }
        
        return view('admin.dashboard', compact('storageStatus'));
    }
}
```

### 2. Storage Widget Component
```php
<?php 
// app/View/Components/StorageWidget.php

namespace App\View\Components;

use Illuminate\View\Component;

class StorageWidget extends Component
{
    public $status;
    public $showDetails;
    
    public function __construct($showDetails = false)
    {
        $this->showDetails = $showDetails;
        $this->status = $this->getStorageStatus();
    }
    
    protected function getStorageStatus()
    {
        if (!function_exists('check_storage_usage')) {
            return ['error' => true, 'message' => 'Storage system not available'];
        }
        
        return check_storage_usage();
    }
    
    public function render()
    {
        return view('components.storage-widget');
    }
}
```

### 3. Blade Component Template
```blade
{{-- resources/views/components/storage-widget.blade.php --}}
<div class="storage-widget card">
    <div class="card-header">
        <h6><i class="fas fa-hdd"></i> Storage Usage</h6>
    </div>
    <div class="card-body">
        @if(isset($status['error']))
            <div class="alert alert-danger">{{ $status['message'] }}</div>
        @else
            {{-- Progress Bar --}}
            <div class="progress mb-3">
                <div class="progress-bar @if($status['usage_percentage'] >= 95) bg-danger @elseif($status['usage_percentage'] >= 80) bg-warning @else bg-success @endif"
                     style="width: {{ $status['usage_percentage'] }}%">
                    {{ number_format($status['usage_percentage'], 1) }}%
                </div>
            </div>
            
            {{-- Usage Info --}}
            <div class="row text-center">
                <div class="col">
                    <small class="text-muted">Used</small><br>
                    <strong>{{ $status['current_size_formatted'] ?? '0 B' }}</strong>
                </div>
                <div class="col">
                    <small class="text-muted">Available</small><br>
                    <strong>{{ $status['available_mb'] ?? 0 }} MB</strong>
                </div>
            </div>
            
            @if($showDetails && !empty($status['messages']))
                <hr>
                @foreach($status['messages'] as $type => $message)
                    <small class="text-{{ $type === 'error' ? 'danger' : ($type === 'warning' ? 'warning' : 'info') }}">
                        {{ $message }}
                    </small><br>
                @endforeach
            @endif
        @endif
    </div>
</div>
```

---

## 👨‍💼 Admin Integration

### 1. Admin Dashboard Alert
```blade
{{-- resources/views/admin/layouts/app.blade.php --}}
<div class="container-fluid">
    {{-- Storage Alert --}}
    @if(function_exists('storage_quick_check'))
        @php $storage = storage_quick_check(); @endphp
        @if($storage['needs_attention'])
            <div class="alert alert-{{ $storage['status'] === 'full' ? 'danger' : 'warning' }} alert-dismissible fade show">
                <strong>
                    @if($storage['status'] === 'full')
                        <i class="fas fa-exclamation-triangle"></i> Storage Full!
                    @else  
                        <i class="fas fa-exclamation-circle"></i> Storage Warning!
                    @endif
                </strong>
                
                <p class="mb-2">
                    Current usage: {{ number_format($storage['usage_percentage'], 1) }}%
                </p>
                
                @if(!empty($storage['messages']))
                    <ul class="mb-2">
                        @foreach($storage['messages'] as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
                
                <div class="btn-group">
                    <a href="/admin/storage" class="btn btn-sm btn-outline-dark">
                        <i class="fas fa-chart-bar"></i> View Details
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-dark" onclick="clearStorageCache()">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
                
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        @endif
    @endif
    
    {{-- Main content --}}
    @yield('content')
</div>

<script>
function clearStorageCache() {
    fetch('/storage-check/clear-cache', { method: 'POST' })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
}
</script>
```

### 2. Admin Storage Management Page
```php
<?php
// app/Http/Controllers/Admin/StorageController.php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Controller;

class StorageController extends Controller
{
    public function index()
    {
        $data = [];
        
        if (function_exists('check_storage_usage')) {
            $data['storage'] = check_storage_usage();
            $data['storage']['last_updated'] = now();
        } else {
            $data['error'] = 'Storage monitoring not available';
        }
        
        return view('admin.storage.index', $data);
    }
    
    public function clearCache()
    {
        if (function_exists('clear_storage_cache')) {
            $result = clear_storage_cache();
            
            return response()->json([
                'success' => $result,
                'message' => $result ? 'Cache cleared successfully' : 'Failed to clear cache'
            ]);
        }
        
        return response()->json(['success' => false, 'message' => 'Function not available'], 500);
    }
}
```

### 3. Admin Routes
```php
<?php
// routes/admin.php

Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'admin']], function () {
    
    // Storage management
    Route::get('/storage', 'Admin\StorageController@index')->name('admin.storage.index');
    Route::post('/storage/clear-cache', 'Admin\StorageController@clearCache')->name('admin.storage.clear-cache');
    
});
```

---

## 📤 Upload Management

### 1. Upload Validation Middleware
```php
<?php
// app/Http/Middleware/CheckStorageSpace.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckStorageSpace
{
    public function handle(Request $request, Closure $next)
    {
        // Check if upload request
        if ($request->hasFile('file') || $request->hasFile('image') || $request->hasFile('upload')) {
            
            // Check if storage is full
            if (function_exists('is_storage_full') && is_storage_full()) {
                return response()->json([
                    'error' => 'Storage đã đầy. Không thể upload thêm file.',
                    'storage_full' => true
                ], 413); // HTTP 413 Payload Too Large
            }
            
            // Warning if storage is high
            if (function_exists('is_storage_warning') && is_storage_warning()) {
                // Log warning but allow upload
                \Log::warning('Upload while storage warning', [
                    'usage' => function_exists('get_storage_usage_percentage') ? get_storage_usage_percentage() : 'unknown',
                    'file' => $request->file('file') ? $request->file('file')->getClientOriginalName() : 'unknown'
                ]);
            }
        }
        
        return $next($request);
    }
}
```

### 2. Upload Controller with Storage Check
```php
<?php
// app/Http/Controllers/UploadController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Controller;

class UploadController extends Controller
{
    public function upload(Request $request)
    {
        // Pre-upload storage check
        $storageCheck = $this->checkStorageBeforeUpload();
        if ($storageCheck !== true) {
            return $storageCheck; // Return error response
        }
        
        // Validate file
        $request->validate([
            'file' => 'required|file|max:10240' // 10MB max
        ]);
        
        try {
            $file = $request->file('file');
            $path = $file->store('uploads', 'public');
            
            // Clear storage cache after upload
            if (function_exists('clear_storage_cache')) {
                clear_storage_cache();
            }
            
            return response()->json([
                'success' => true,
                'path' => $path,
                'message' => 'File uploaded successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    protected function checkStorageBeforeUpload()
    {
        if (!function_exists('is_storage_full')) {
            return true; // Allow if function not available
        }
        
        if (is_storage_full()) {
            return response()->json([
                'error' => 'Storage đã đầy. Vui lòng dọn dẹp trước khi upload.',
                'storage_full' => true
            ], 413);
        }
        
        return true;
    }
    
    public function getStorageInfo()
    {
        if (!function_exists('check_storage_usage')) {
            return response()->json(['error' => 'Storage info not available'], 500);
        }
        
        return response()->json(check_storage_usage());
    }
}
```

### 3. Frontend Upload with Storage Check
```javascript
// Upload form with storage validation
class StorageAwareUpload {
    constructor() {
        this.checkStorage();
        this.setupUploadForm();
    }
    
    async checkStorage() {
        try {
            const response = await fetch('/storage-check/quick');
            const data = await response.json();
            
            if (data.needs_attention) {
                this.showStorageWarning(data);
                
                if (data.status === 'full') {
                    this.disableUpload();
                }
            }
        } catch (error) {
            console.error('Storage check failed:', error);
        }
    }
    
    showStorageWarning(data) {
        const alertHtml = `
            <div class="alert alert-${data.status === 'full' ? 'danger' : 'warning'}" id="storageAlert">
                <strong>Storage ${data.status === 'full' ? 'Full' : 'Warning'}!</strong>
                Usage: ${data.usage_percentage.toFixed(1)}%
                ${data.messages.map(msg => `<br>${msg}`).join('')}
                ${data.status === 'full' ? '<br><strong>Upload disabled!</strong>' : ''}
            </div>
        `;
        
        document.getElementById('uploadContainer').insertAdjacentHTML('afterbegin', alertHtml);
    }
    
    disableUpload() {
        const uploadBtn = document.getElementById('uploadButton');
        const fileInput = document.getElementById('fileInput');
        
        if (uploadBtn) uploadBtn.disabled = true;
        if (fileInput) fileInput.disabled = true;
    }
    
    setupUploadForm() {
        const form = document.getElementById('uploadForm');
        if (!form) return;
        
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Check storage before upload
            const storageOk = await this.checkStorageBeforeUpload();
            if (!storageOk) return;
            
            // Proceed with upload
            this.doUpload(new FormData(form));
        });
    }
    
    async checkStorageBeforeUpload() {
        try {
            const response = await fetch('/storage-check/quick');
            const data = await response.json();
            
            if (data.status === 'full') {
                alert('Storage đã đầy! Không thể upload.');
                return false;
            }
            
            return true;
        } catch (error) {
            console.error('Pre-upload storage check failed:', error);
            return true; // Allow upload if check fails
        }
    }
    
    async doUpload(formData) {
        try {
            this.showUploading();
            
            const response = await fetch('/upload', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showSuccess(result.message);
                // Refresh storage info
                setTimeout(() => this.checkStorage(), 2000);
            } else {
                this.showError(result.message);
            }
            
        } catch (error) {
            this.showError('Upload error: ' + error.message);
        } finally {
            this.hideUploading();
        }
    }
    
    showUploading() {
        const btn = document.getElementById('uploadButton');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            btn.disabled = true;
        }
    }
    
    hideUploading() {
        const btn = document.getElementById('uploadButton');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-upload"></i> Upload';
            btn.disabled = false;
        }
    }
    
    showSuccess(message) {
        this.showMessage(message, 'success');
    }
    
    showError(message) {
        this.showMessage(message, 'danger');
    }
    
    showMessage(message, type) {
        const alertHtml = `
            <div class="alert alert-${type} alert-dismissible fade show">
                ${message}
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        `;
        
        document.getElementById('messages').innerHTML = alertHtml;
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', () => {
    new StorageAwareUpload();
});
```

---

## 📊 Monitoring & Alerts

### 1. Storage Monitoring Command
```php
<?php
// app/Console/Commands/MonitorStorage.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class MonitorStorage extends Command
{
    protected $signature = 'storage:monitor {--send-email}';
    protected $description = 'Monitor storage usage and send alerts';
    
    public function handle()
    {
        if (!function_exists('check_storage_usage')) {
            $this->error('Storage monitoring functions not available');
            return 1;
        }
        
        $status = check_storage_usage();
        
        // Log current status
        Log::info('Storage monitoring', [
            'usage_percentage' => $status['usage_percentage'],
            'status' => $status['status'],
            'available_mb' => $status['available_mb']
        ]);
        
        // Check critical conditions
        if ($status['is_full']) {
            $this->error('🚨 CRITICAL: Storage is full (' . $status['usage_percentage'] . '%)');
            
            if ($this->option('send-email')) {
                $this->sendStorageAlert($status, 'full');
            }
            
            return 2; // Critical exit code
        }
        
        elseif ($status['is_warning']) {
            $this->warn('⚠️ WARNING: Storage usage high (' . $status['usage_percentage'] . '%)');
            
            if ($this->option('send-email')) {
                $this->sendStorageAlert($status, 'warning');
            }
        }
        
        // Check additional storage expiry
        if (isset($status['additional_storage']['expiring_soon']) && $status['additional_storage']['expiring_soon']) {
            $days = $status['additional_storage']['days_until_expiry'];
            $this->warn("⏰ Additional storage expires in {$days} days");
        }
        
        $this->info('✅ Storage monitoring completed');
        return 0;
    }
    
    protected function sendStorageAlert($status, $type)
    {
        $adminEmail = config('app.admin_email', 'admin@example.com');
        
        try {
            Mail::send('emails.storage-alert', compact('status', 'type'), function($message) use ($adminEmail, $type) {
                $message->to($adminEmail)
                       ->subject('Storage Alert: ' . ucfirst($type));
            });
            
            $this->info("📧 Alert email sent to {$adminEmail}");
            
        } catch (\Exception $e) {
            $this->error('Failed to send email: ' . $e->getMessage());
        }
    }
}
```

### 2. Email Template
```blade
{{-- resources/views/emails/storage-alert.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <title>Storage Alert</title>
    <style>
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .alert { padding: 15px; border-radius: 4px; margin: 10px 0; }
        .alert-warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .progress { height: 20px; background: #f8f9fa; border-radius: 4px; overflow: hidden; }
        .progress-bar { height: 100%; color: white; text-align: center; line-height: 20px; }
        .bg-warning { background-color: #ffc107; }
        .bg-danger { background-color: #dc3545; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        th { background-color: #f8f9fa; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Storage Alert - {{ ucfirst($type) }}</h2>
        
        <div class="alert alert-{{ $type === 'full' ? 'danger' : 'warning' }}">
            @if($type === 'full')
                <strong>🚨 CRITICAL: Storage is full!</strong>
            @else
                <strong>⚠️ WARNING: Storage usage is high!</strong>
            @endif
        </div>
        
        {{-- Progress Bar --}}
        <div class="progress">
            <div class="progress-bar bg-{{ $type === 'full' ? 'danger' : 'warning' }}" 
                 style="width: {{ $status['usage_percentage'] }}%">
                {{ number_format($status['usage_percentage'], 1) }}%
            </div>
        </div>
        
        {{-- Details Table --}}
        <table>
            <tr>
                <th>Metric</th>
                <th>Value</th>
            </tr>
            <tr>
                <td>Usage Percentage</td>
                <td>{{ number_format($status['usage_percentage'], 1) }}%</td>
            </tr>
            <tr>
                <td>Current Usage</td>
                <td>{{ $status['current_size_formatted'] }}</td>
            </tr>
            <tr>
                <td>Available Space</td>
                <td>{{ $status['available_mb'] }} MB</td>
            </tr>
            <tr>
                <td>Total Capacity</td>
                <td>{{ $status['total_capacity_mb'] }} MB</td>
            </tr>
            <tr>
                <td>Status</td>
                <td><strong>{{ strtoupper($status['status']) }}</strong></td>
            </tr>
        </table>
        
        {{-- Messages --}}
        @if(!empty($status['messages']))
            <h3>System Messages:</h3>
            <ul>
                @foreach($status['messages'] as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
        
        {{-- Additional Storage Info --}}
        @if(isset($status['additional_storage']) && $status['additional_storage']['enabled'])
            <h3>Additional Storage:</h3>
            <table>
                <tr>
                    <td>Additional Capacity</td>
                    <td>{{ $status['additional_storage']['capacity_mb'] }} MB</td>
                </tr>
                <tr>
                    <td>Expires On</td>
                    <td>{{ $status['additional_storage']['expires_at'] }}</td>
                </tr>
                <tr>
                    <td>Days Until Expiry</td>
                    <td>{{ $status['additional_storage']['days_until_expiry'] }} days</td>
                </tr>
            </table>
        @endif
        
        {{-- Action Items --}}
        <h3>Recommended Actions:</h3>
        <ul>
            @if($type === 'full')
                <li><strong>Immediate:</strong> Clean up unused files</li>
                <li><strong>Immediate:</strong> Disable file uploads temporarily</li>
                <li><strong>Plan:</strong> Upgrade storage plan</li>
            @else
                <li>Review and clean up large files</li>
                <li>Consider upgrading storage plan soon</li>
                <li>Monitor usage more frequently</li>
            @endif
        </ul>
        
        <p><small>This alert was generated automatically at {{ now() }}.</small></p>
    </div>
</body>
</html>
```

### 3. Cron Job Setup
```bash
# Add to crontab
# Check storage every hour
0 * * * * cd /path/to/app && php artisan storage:monitor --send-email

# Check storage every 15 minutes (without email)
*/15 * * * * cd /path/to/app && php artisan storage:monitor
```

---

## 🌐 API Usage

### 1. API Routes
```php
<?php
// routes/api.php

Route::group(['prefix' => 'api/storage', 'middleware' => ['auth:api']], function () {
    
    // Get storage status
    Route::get('/status', function() {
        if (!function_exists('check_storage_usage')) {
            return response()->json(['error' => 'Storage API not available'], 500);
        }
        
        return response()->json([
            'success' => true,
            'data' => check_storage_usage()
        ]);
    });
    
    // Quick check
    Route::get('/quick', function() {
        if (!function_exists('storage_quick_check')) {
            return response()->json(['error' => 'Storage API not available'], 500);
        }
        
        return response()->json([
            'success' => true,
            'data' => storage_quick_check()
        ]);
    });
    
    // Clear cache
    Route::post('/clear-cache', function() {
        if (!function_exists('clear_storage_cache')) {
            return response()->json(['error' => 'Storage API not available'], 500);
        }
        
        $result = clear_storage_cache();
        
        return response()->json([
            'success' => $result,
            'message' => $result ? 'Cache cleared successfully' : 'Failed to clear cache'
        ]);
    });
    
});
```

### 2. JavaScript API Client
```javascript
// Storage API Client
class StorageAPI {
    constructor(baseUrl = '/storage-check') {
        this.baseUrl = baseUrl;
    }
    
    async getStatus() {
        try {
            const response = await fetch(`${this.baseUrl}/status`);
            return await response.json();
        } catch (error) {
            console.error('Storage API error:', error);
            throw error;
        }
    }
    
    async quickCheck() {
        try {
            const response = await fetch(`${this.baseUrl}/quick`);
            return await response.json();
        } catch (error) {
            console.error('Storage quick check error:', error);
            throw error;
        }
    }
    
    async clearCache() {
        try {
            const response = await fetch(`${this.baseUrl}/clear-cache`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });
            return await response.json();
        } catch (error) {
            console.error('Clear cache error:', error);
            throw error;
        }
    }
    
    // Auto-monitoring
    startMonitoring(interval = 300000) { // 5 minutes default
        this.monitoringInterval = setInterval(async () => {
            try {
                const status = await this.quickCheck();
                this.handleStorageUpdate(status);
            } catch (error) {
                console.error('Storage monitoring error:', error);
            }
        }, interval);
    }
    
    stopMonitoring() {
        if (this.monitoringInterval) {
            clearInterval(this.monitoringInterval);
            this.monitoringInterval = null;
        }
    }
    
    handleStorageUpdate(status) {
        // Dispatch custom event
        const event = new CustomEvent('storageUpdate', {
            detail: status
        });
        document.dispatchEvent(event);
        
        // Built-in handlers
        if (status.needs_attention) {
            this.showNotification(status);
        }
    }
    
    showNotification(status) {
        if ('Notification' in window && Notification.permission === 'granted') {
            const title = status.status === 'full' ? 'Storage Full!' : 'Storage Warning';
            const body = `Usage: ${status.usage_percentage.toFixed(1)}%`;
            
            new Notification(title, {
                body: body,
                icon: '/favicon.ico'
            });
        }
    }
}

// Usage
const storageAPI = new StorageAPI();

// Manual checks
storageAPI.getStatus().then(status => console.log(status));
storageAPI.quickCheck().then(status => console.log(status));

// Auto monitoring
storageAPI.startMonitoring(300000); // Every 5 minutes

// Listen for storage updates
document.addEventListener('storageUpdate', (event) => {
    const status = event.detail;
    console.log('Storage updated:', status);
    
    // Update UI elements
    updateStorageUI(status);
});
```

---

## 🎨 Custom Views

### 1. Advanced Storage Dashboard
```blade
{{-- resources/views/admin/storage/dashboard.blade.php --}}
@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-header">
                <h1>Storage Management</h1>
                <div class="page-options">
                    <button class="btn btn-primary" onclick="refreshStorage()">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                    <button class="btn btn-warning" onclick="clearCache()">
                        <i class="fas fa-trash"></i> Clear Cache
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    @if(isset($storage))
        <div class="row">
            {{-- Usage Overview --}}
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4>Storage Usage Overview</h4>
                    </div>
                    <div class="card-body">
                        {{-- Main Progress Bar --}}
                        <div class="progress mb-4" style="height: 30px;">
                            <div class="progress-bar 
                                @if($storage['usage_percentage'] >= 95) bg-danger
                                @elseif($storage['usage_percentage'] >= 80) bg-warning
                                @else bg-success
                                @endif" 
                                style="width: {{ $storage['usage_percentage'] }}%">
                                {{ number_format($storage['usage_percentage'], 1) }}%
                            </div>
                        </div>
                        
                        {{-- Usage Stats --}}
                        <div class="row text-center">
                            <div class="col-md-3">
                                <div class="metric">
                                    <div class="metric-value">{{ $storage['current_size_formatted'] }}</div>
                                    <div class="metric-label">Used Space</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="metric">
                                    <div class="metric-value">{{ $storage['available_mb'] }} MB</div>
                                    <div class="metric-label">Available</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="metric">
                                    <div class="metric-value">{{ $storage['total_capacity_mb'] }} MB</div>
                                    <div class="metric-label">Total Capacity</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="metric">
                                    <div class="metric-value">
                                        <span class="badge badge-{{ $storage['status'] === 'ok' ? 'success' : ($storage['status'] === 'warning' ? 'warning' : 'danger') }}">
                                            {{ strtoupper($storage['status']) }}
                                        </span>
                                    </div>
                                    <div class="metric-label">Status</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            {{-- Additional Storage Info --}}
            <div class="col-md-4">
                @if(isset($storage['additional_storage']) && $storage['additional_storage']['enabled'])
                    <div class="card">
                        <div class="card-header">
                            <h5>Additional Storage</h5>
                        </div>
                        <div class="card-body">
                            <div class="metric">
                                <div class="metric-value">{{ $storage['additional_storage']['capacity_mb'] }} MB</div>
                                <div class="metric-label">Additional Capacity</div>
                            </div>
                            
                            <div class="metric mt-3">
                                <div class="metric-value">{{ $storage['additional_storage']['expires_at'] }}</div>
                                <div class="metric-label">Expires On</div>
                            </div>
                            
                            <div class="metric mt-3">
                                <div class="metric-value {{ $storage['additional_storage']['expiring_soon'] ? 'text-danger' : 'text-success' }}">
                                    {{ $storage['additional_storage']['days_until_expiry'] }} days
                                </div>
                                <div class="metric-label">Until Expiry</div>
                            </div>
                            
                            @if($storage['additional_storage']['expiring_soon'])
                                <div class="alert alert-warning mt-3">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Additional storage expires soon!
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="card">
                        <div class="card-header">
                            <h5>Upgrade Storage</h5>
                        </div>
                        <div class="card-body text-center">
                            <i class="fas fa-plus-circle fa-3x text-muted mb-3"></i>
                            <p>Need more storage space?</p>
                            <button class="btn btn-primary">
                                <i class="fas fa-arrow-up"></i> Upgrade Plan
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        
        {{-- Messages & Alerts --}}
        @if(!empty($storage['messages']))
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5>System Messages</h5>
                        </div>
                        <div class="card-body">
                            @foreach($storage['messages'] as $type => $message)
                                <div class="alert alert-{{ $type === 'error' ? 'danger' : ($type === 'warning' ? 'warning' : 'info') }}">
                                    {{ $message }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif
        
    @else
        <div class="row">
            <div class="col-12">
                <div class="alert alert-danger">
                    <h4>Error</h4>
                    <p>{{ $error ?? 'Storage information could not be retrieved.' }}</p>
                </div>
            </div>
        </div>
    @endif
</div>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #dee2e6;
}

.metric {
    text-align: center;
    padding: 1rem 0;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 600;
    color: #495057;
}

.metric-label {
    font-size: 0.875rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 0.25rem;
}

.progress {
    background-color: #f8f9fa;
    border-radius: 0.375rem;
}

.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.alert {
    border-radius: 0.375rem;
}
</style>

<script>
function refreshStorage() {
    const btn = event.target;
    const originalHtml = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spin fa-spinner"></i> Refreshing...';
    btn.disabled = true;
    
    setTimeout(() => {
        location.reload();
    }, 1000);
}

function clearCache() {
    const btn = event.target;
    const originalHtml = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spin fa-spinner"></i> Clearing...';
    btn.disabled = true;
    
    fetch('/storage-check/clear-cache', { method: 'POST' })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
}

// Auto refresh every 2 minutes
setInterval(() => {
    fetch('/storage-check/quick')
        .then(response => response.json())
        .then(data => {
            // Update progress bar if needed
            if (data.usage_percentage !== undefined) {
                const progressBar = document.querySelector('.progress-bar');
                if (progressBar) {
                    progressBar.style.width = data.usage_percentage + '%';
                    progressBar.textContent = data.usage_percentage.toFixed(1) + '%';
                }
            }
        })
        .catch(error => {
            console.log('Auto refresh error:', error);
        });
}, 120000); // 2 minutes
</script>
@endsection
```

---

Các examples này cung cấp đầy đủ pattern sử dụng Simple Storage System trong các scenario thực tế! 🚀