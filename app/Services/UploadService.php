<?php

declare(strict_types=1);

namespace App\Services;

use finfo;
use RuntimeException;
use ZipArchive;

/**
 * Attachment uploads (FR-5): whitelisted types checked by content (finfo), size limit
 * from settings, stored outside the web root under storage/uploads/{Y}/{m}/ with random names.
 */
class UploadService
{
    /** Allowed extensions and the MIME types finfo may report for them. */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    /** The file inside a DOCX/XLSX that proves it really is one. */
    private const OFFICE_MARKERS = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml'];

    /** MIME type stored and served for each extension (finfo results for Office files vary). */
    private const CANONICAL_MIME = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public const MAX_FILES = 5;

    /**
     * Turn a $_FILES entry for a multiple input (name="attachments[]") into a list of
     * single-file arrays, skipping empty slots.
     *
     * @param array<string, mixed>|null $entry
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public static function normalise(?array $entry): array
    {
        if ($entry === null || !isset($entry['name'])) {
            return [];
        }

        $files = [];
        foreach ((array) $entry['name'] as $i => $name) {
            $error = (int) ((array) $entry['error'])[$i];
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => (string) $name,
                'type' => (string) (((array) $entry['type'])[$i] ?? ''),
                'tmp_name' => (string) (((array) $entry['tmp_name'])[$i] ?? ''),
                'error' => $error,
                'size' => (int) (((array) $entry['size'])[$i] ?? 0),
            ];
        }

        return $files;
    }

    /**
     * Check a batch of files. Returns user-facing error messages (empty when all are fine).
     *
     * @param list<array{name: string, tmp_name: string, error: int, size: int}> $files
     * @return list<string>
     */
    public function validate(array $files, int $maxMegabytes): array
    {
        if (count($files) > self::MAX_FILES) {
            return ['You can upload at most ' . self::MAX_FILES . ' files at a time.'];
        }

        $errors = [];
        $maxBytes = $maxMegabytes * 1024 * 1024;
        foreach ($files as $file) {
            $name = self::safeName($file['name']);
            $error = $this->check($file, $maxBytes, $maxMegabytes);
            if ($error !== null) {
                $errors[] = "\"$name\": $error";
            }
        }

        return $errors;
    }

    /**
     * Move a validated upload into storage. Returns the attachment columns.
     *
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @return array{original_name: string, stored_path: string, mime_type: string, size_bytes: int}
     */
    public function store(array $file): array
    {
        $ext = self::extension($file['name']);
        $relativeDir = now()->format('Y/m');
        $dir = storage_path('uploads/' . $relativeDir);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create upload directory $dir");
        }

        $relative = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!$this->moveFile($file['tmp_name'], storage_path('uploads/' . $relative))) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        return [
            'original_name' => self::safeName($file['name']),
            'stored_path' => $relative,
            'mime_type' => self::CANONICAL_MIME[$ext],
            'size_bytes' => (int) $file['size'],
        ];
    }

    /**
     * Remove a stored file (used to clean up when the database transaction fails).
     */
    public function delete(string $storedPath): void
    {
        $path = self::absolutePath($storedPath);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Absolute path of a stored file, or null if the stored path is not one we generated.
     */
    public static function absolutePath(string $storedPath): ?string
    {
        if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.(pdf|jpe?g|png|docx|xlsx)$#', $storedPath) !== 1) {
            return null;
        }

        return storage_path('uploads/' . $storedPath);
    }

    /**
     * Original file name made safe to store and show: no path, no control characters.
     */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return mb_substr($name === '' ? 'file' : $name, -255);
    }

    /**
     * Whether a temp file is a genuine HTTP upload. Overridable for tests.
     */
    protected function isUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    /**
     * Move a temp upload into place. Overridable for tests.
     */
    protected function moveFile(string $from, string $to): bool
    {
        return move_uploaded_file($from, $to);
    }

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     */
    private function check(array $file, int $maxBytes, int $maxMegabytes): ?string
    {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return "is larger than the server allows.";
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !$this->isUploadedFile($file['tmp_name'])) {
            return 'did not upload completely. Please try again.';
        }

        $ext = self::extension($file['name']);
        if (!isset(self::ALLOWED[$ext])) {
            return 'is not an allowed type. Allowed: PDF, JPG, PNG, DOCX, XLSX.';
        }

        $size = filesize($file['tmp_name']) ?: 0;
        if ($size === 0) {
            return 'is empty.';
        }
        if ($size > $maxBytes) {
            return "is larger than {$maxMegabytes} MB.";
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED[$ext], true)) {
            return 'does not match its file type (the content is not a real ' . strtoupper($ext) . ' file).';
        }
        if (isset(self::OFFICE_MARKERS[$ext]) && !$this->isOfficeFile($file['tmp_name'], self::OFFICE_MARKERS[$ext])) {
            return 'is not a valid ' . strtoupper($ext) . ' document.';
        }

        return null;
    }

    private function isOfficeFile(string $path, string $marker): bool
    {
        if (!class_exists(ZipArchive::class)) {
            // Without ext-zip the finfo check above is the best available.
            return true;
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $found = $zip->locateName($marker) !== false;
        $zip->close();

        return $found;
    }

    private static function extension(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}
