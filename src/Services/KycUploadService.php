<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

/**
 * Decode KYC image payloads, upload to S3, persist pro_documents rows.
 */
final class KycUploadService
{
    private PDO $db;
    private S3StorageService $s3;

    public function __construct(?S3StorageService $s3 = null)
    {
        $this->db = Database::connection();
        $this->s3 = $s3 ?? new S3StorageService();
    }

    /**
     * @return array{url: string, key: string, kind: string, content_type: string}
     */
    public function uploadBase64(
        int $professionalId,
        string $kind,
        string $imageBase64,
        ?string $contentType = null,
        ?string $label = null,
    ): array {
        if (!$this->s3->isConfigured()) {
            throw new \RuntimeException(
                'S3 is not configured. Set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_S3_BUCKET in .env'
            );
        }

        $kind = $this->normalizeKind($kind);
        [$binary, $resolvedType, $ext] = $this->decodeImage($imageBase64, $contentType);

        $uploaded = $this->s3->putKycImage(
            $professionalId,
            $kind,
            $binary,
            $resolvedType,
            $ext,
        );

        $this->upsertDocument(
            $professionalId,
            $kind,
            $label ?? $this->defaultLabel($kind),
            $uploaded['url'],
        );

        return [
            'url' => $uploaded['url'],
            'key' => $uploaded['key'],
            'kind' => $kind,
            'content_type' => $resolvedType,
        ];
    }

    private function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        return match ($kind) {
            'aadhaar', 'aadhar', 'aadhaar_card' => 'aadhaar',
            'selfie', 'face' => 'selfie',
            'cert', 'certificate', 'training' => 'cert',
            'tools', 'shop', 'shop_photo' => 'shop_photo',
            'pan' => 'pan',
            default => 'other',
        };
    }

    private function defaultLabel(string $kind): string
    {
        return match ($kind) {
            'aadhaar' => 'Aadhaar card',
            'selfie' => 'Live selfie',
            'cert' => 'Skill / training certificate',
            'shop_photo' => 'Tools / shop photo',
            'pan' => 'PAN card',
            default => 'KYC document',
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string} binary, contentType, ext
     */
    private function decodeImage(string $raw, ?string $contentType): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('image_base64 is required');
        }

        $type = $contentType;
        if (preg_match('#^data:([^;]+);base64,(.+)$#s', $raw, $m)) {
            $type = $m[1];
            $raw = $m[2];
        }

        $binary = base64_decode($raw, true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('Invalid image_base64');
        }

        // Cap ~6 MB decoded.
        if (strlen($binary) > 6 * 1024 * 1024) {
            throw new \InvalidArgumentException('Image too large (max 6 MB)');
        }

        $type = strtolower(trim((string) ($type ?: 'image/jpeg')));
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        if (!isset($allowed[$type])) {
            // Sniff JPEG/PNG magic if client omitted type.
            if (str_starts_with($binary, "\xFF\xD8\xFF")) {
                $type = 'image/jpeg';
            } elseif (str_starts_with($binary, "\x89PNG")) {
                $type = 'image/png';
            } elseif (str_starts_with($binary, '%PDF')) {
                $type = 'application/pdf';
            } else {
                throw new \InvalidArgumentException('Unsupported file type. Use JPEG, PNG, WEBP, or PDF.');
            }
        }

        return [$binary, $type === 'image/jpg' ? 'image/jpeg' : $type, $allowed[$type] ?? 'jpg'];
    }

    private function upsertDocument(
        int $professionalId,
        string $kind,
        string $label,
        string $fileUrl,
    ): void {
        $this->ensureDocumentsTable();
        $hasFileUrl = $this->hasColumn('file_url');
        $hasThumb = $this->hasColumn('thumbnail_url');

        $existing = $this->db->prepare(
            'SELECT id FROM pro_documents WHERE professional_id = ? AND kind = ? LIMIT 1'
        );
        $existing->execute([$professionalId, $kind]);
        $id = $existing->fetchColumn();

        if ($id) {
            if ($hasFileUrl && $hasThumb) {
                $stmt = $this->db->prepare(
                    'UPDATE pro_documents
                     SET label = ?, status = \'pending\', file_url = ?, thumbnail_url = ?,
                         uploaded_at = NOW(), reviewed_at = NULL, rejected_reason = NULL
                     WHERE id = ?'
                );
                $stmt->execute([$label, $fileUrl, $fileUrl, (int) $id]);
            } elseif ($hasThumb) {
                $stmt = $this->db->prepare(
                    'UPDATE pro_documents
                     SET label = ?, status = \'pending\', thumbnail_url = ?,
                         uploaded_at = NOW(), reviewed_at = NULL, rejected_reason = NULL
                     WHERE id = ?'
                );
                $stmt->execute([$label, $fileUrl, (int) $id]);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE pro_documents
                     SET label = ?, status = \'pending\', uploaded_at = NOW()
                     WHERE id = ?'
                );
                $stmt->execute([$label, (int) $id]);
            }
            return;
        }

        if ($hasFileUrl && $hasThumb) {
            $stmt = $this->db->prepare(
                'INSERT INTO pro_documents
                    (professional_id, kind, label, status, file_url, thumbnail_url, uploaded_at)
                 VALUES (?, ?, ?, \'pending\', ?, ?, NOW())'
            );
            $stmt->execute([$professionalId, $kind, $label, $fileUrl, $fileUrl]);
        } elseif ($hasThumb) {
            $stmt = $this->db->prepare(
                'INSERT INTO pro_documents
                    (professional_id, kind, label, status, thumbnail_url, uploaded_at)
                 VALUES (?, ?, ?, \'pending\', ?, NOW())'
            );
            $stmt->execute([$professionalId, $kind, $label, $fileUrl]);
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO pro_documents
                    (professional_id, kind, label, status, uploaded_at)
                 VALUES (?, ?, ?, \'pending\', NOW())'
            );
            $stmt->execute([$professionalId, $kind, $label]);
        }
    }

    private function ensureDocumentsTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS pro_documents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                professional_id BIGINT UNSIGNED NOT NULL,
                kind ENUM('aadhaar', 'pan', 'selfie', 'shop_photo', 'cert', 'other') NOT NULL,
                label VARCHAR(120) NOT NULL,
                status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                file_url VARCHAR(1024) NULL,
                thumbnail_url VARCHAR(1024) NULL,
                rejected_reason VARCHAR(500) NULL,
                uploaded_at DATETIME NOT NULL,
                reviewed_at DATETIME NULL,
                INDEX idx_pro_doc_pro (professional_id),
                INDEX idx_pro_doc_queue (status, kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (!$this->hasColumn('file_url')) {
            try {
                $this->db->exec(
                    'ALTER TABLE pro_documents ADD COLUMN file_url VARCHAR(1024) NULL AFTER status'
                );
            } catch (\Throwable) {
            }
        }
    }

    private function hasColumn(string $column): bool
    {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM pro_documents LIKE " . $this->db->quote($column));
            return $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            return false;
        }
    }
}
