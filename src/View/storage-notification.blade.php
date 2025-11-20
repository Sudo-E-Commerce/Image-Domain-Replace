{{-- Storage Notification Component --}}
@if(function_exists('storage_quick_check'))
    @php
        $storageStatus = storage_quick_check();
    @endphp
    
    @if($storageStatus['needs_attention'])
        <div class="alert alert-{{ $storageStatus['status'] === 'full' ? 'danger' : 'warning' }} storage-alert" role="alert">
            <div class="row">
                <div class="col-auto">
                    <i class="fas fa-{{ $storageStatus['status'] === 'full' ? 'exclamation-triangle' : 'info-circle' }}"></i>
                </div>
                <div class="col">
                    <h6 class="alert-heading mb-1">
                        @if($storageStatus['status'] === 'full')
                            Dung lượng website đã đầy
                        @else
                            Cảnh báo dung lượng website
                        @endif
                    </h6>
                    
                    @if(!empty($storageStatus['messages']))
                        <ul class="mb-2 small">
                            @foreach($storageStatus['messages'] as $type => $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                    
                    <div class="progress mb-2" style="height: 8px;">
                        <div class="progress-bar 
                            @if($storageStatus['usage_percentage'] >= 95) bg-danger
                            @elseif($storageStatus['usage_percentage'] >= 80) bg-warning
                            @else bg-info
                            @endif" 
                            role="progressbar" 
                            style="width: {{ $storageStatus['usage_percentage'] }}%"
                            aria-valuenow="{{ $storageStatus['usage_percentage'] }}" 
                            aria-valuemin="0" 
                            aria-valuemax="100">
                        </div>
                    </div>
                    
                    <small class="text-muted">
                        Đã sử dụng {{ number_format($storageStatus['usage_percentage'], 1) }}% dung lượng
                    </small>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
        </div>
    @endif
@endif

<style>
.storage-alert {
    border-left: 4px solid;
    margin-bottom: 1rem;
}

.alert-warning.storage-alert {
    border-left-color: #ffc107;
    background-color: #fff3cd;
    border-color: #ffecb5;
}

.alert-danger.storage-alert {
    border-left-color: #dc3545;
    background-color: #f8d7da;
    border-color: #f5c6cb;
}

.storage-alert .progress {
    background-color: rgba(0,0,0,.1);
}

.storage-alert .btn-close {
    opacity: 0.7;
}

.storage-alert .btn-close:hover {
    opacity: 1;
}
</style>