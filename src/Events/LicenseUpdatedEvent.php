<?php

namespace Sudo\ImageDomainReplace\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event được trigger khi license được cập nhật
 */
class LicenseUpdatedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * Dữ liệu license đã được update (encoded)
     *
     * @var string
     */
    public $licenseData;

    /**
     * Thời gian update
     *
     * @var \Carbon\Carbon
     */
    public $updatedAt;

    /**
     * Raw data trước khi encode
     *
     * @var array
     */
    public $rawData;

    /**
     * Tạo một instance mới của event
     *
     * @param string $licenseData - Dữ liệu đã encode
     * @param array $rawData - Dữ liệu gốc trước khi encode
     */
    public function __construct($licenseData, $rawData = [])
    {
        $this->licenseData = $licenseData;
        $this->rawData = $rawData;
        $this->updatedAt = now();
    }

    /**
     * Lấy thông tin license đã decode
     *
     * @return array
     */
    public function getDecodedLicenseData()
    {
        try {
            return json_decode(base64_decode($this->licenseData), true) ?: [];
        } catch (\Exception $e) {
            \Log::error('Failed to decode license data in event', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Kiểm tra xem license có hợp lệ không
     *
     * @return bool
     */
    public function isValidLicense()
    {
        $data = $this->getDecodedLicenseData();
        
        if (empty($data)) {
            return false;
        }

        // Kiểm tra các trường bắt buộc
        $requiredFields = ['domain'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lấy domain từ license data
     *
     * @return string|null
     */
    public function getDomain()
    {
        $data = $this->getDecodedLicenseData();
        return $data['domain'] ?? null;
    }

    /**
     * Lấy thời hạn license
     *
     * @return string|null
     */
    public function getEndTime()
    {
        $data = $this->getDecodedLicenseData();
        return $data['end_time'] ?? null;
    }

    /**
     * Lấy license key
     *
     * @return string|null
     */
    public function getLicenseKey()
    {
        $data = $this->getDecodedLicenseData();
        return $data['license_key'] ?? null;
    }

    /**
     * Lấy toàn bộ raw data
     *
     * @return array
     */
    public function getRawData()
    {
        return $this->rawData;
    }

    /**
     * Lấy thông tin tóm tắt cho logging
     *
     * @return array
     */
    public function getSummary()
    {
        $decoded = $this->getDecodedLicenseData();
        
        return [
            'domain' => $decoded['domain'] ?? null,
            'end_time' => $decoded['end_time'] ?? null,
            'license_key' => isset($decoded['license_key']) ? substr($decoded['license_key'], 0, 8) . '...' : null,
            'data_size' => strlen($this->licenseData),
            'fields_count' => count($decoded),
            'updated_at' => $this->updatedAt->toISOString(),
            'is_valid' => $this->isValidLicense()
        ];
    }
}