<?php

/**
 * Parses and validates uploaded log files, including ZIP archive expansion.
 */
class UploadParser
{
    public const DEFAULT_MAX_FILES = 200;
    public const DEFAULT_MAX_TOTAL_BYTES = 12 * 1024 * 1024;

    /**
     * Validate a file name for path traversal attacks.
     *
     * Rejects empty names, NUL bytes, absolute paths and any path segment
     * that is ".", ".." or empty. Directory separators are normalized to "/".
     *
     * @param string $name
     * @return bool
     */
    public static function validateFileName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }

        $name = str_replace('\\', '/', $name);

        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse the raw files array from a request into normalized entries.
     *
     * Input format:  [['name' => string, 'content' => string], ...]
     * Output format: [['name' => string, 'data' => string], ...]
     *
     * ZIP archives are expanded into their entries. Limits (max files and
     * total size) are enforced across the whole expanded upload.
     *
     * @param array|null $files
     * @return array|ApiError
     */
    public static function parseFiles(?array $files): array|ApiError
    {
        if ($files === null || $files === []) {
            return [];
        }

        $storageConfig = Config::Get('storage');
        $uploadConfig = $storageConfig['uploadFiles'] ?? [];
        $maxFiles = $uploadConfig['maxFiles'] ?? self::DEFAULT_MAX_FILES;
        $maxTotalBytes = $uploadConfig['maxTotalBytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES;

        $result = [];
        $totalBytes = 0;

        foreach ($files as $file) {
            if (!is_array($file)) {
                return new ApiError(400, "Each file entry must be an object.");
            }

            $name = $file['name'] ?? '';
            $content = $file['content'] ?? $file['data'] ?? '';

            if (!is_string($name) || !is_string($content)) {
                return new ApiError(400, "File name and content must be strings.");
            }

            if (self::isZipName($name)) {
                $remainingSlots = $maxFiles - count($result);
                $remainingBudget = $maxTotalBytes - $totalBytes;
                $expanded = self::expandZip($name, $content, $remainingBudget, $remainingSlots);
                if ($expanded instanceof ApiError) {
                    return $expanded;
                }
                foreach ($expanded as $entry) {
                    $result[] = $entry;
                    $totalBytes += strlen($entry['data']);
                }
            } else {
                if (!self::validateFileName($name)) {
                    return new ApiError(400, "Invalid file name: " . htmlspecialchars($name));
                }
                $result[] = ['name' => $name, 'data' => $content];
                $totalBytes += strlen($content);
            }

            if (count($result) > $maxFiles) {
                return new ApiError(413, "Too many files in upload. Maximum is {$maxFiles}.");
            }
            if ($totalBytes > $maxTotalBytes) {
                return new ApiError(413, "Upload exceeds maximum total size of {$maxTotalBytes} bytes.");
            }
        }

        return $result;
    }

    private static function isZipName(string $name): bool
    {
        return str_ends_with(strtolower($name), '.zip');
    }

    /**
     * Expand a ZIP archive provided as a raw byte string into file entries.
     *
     * @param string $zipName Original file name of the archive
     * @param string $zipData Raw ZIP bytes
     * @param int $remainingBudget Remaining total size budget in bytes
     * @param int $remainingSlots Remaining file count budget
     * @return array|ApiError
     */
    private static function expandZip(string $zipName, string $zipData, int $remainingBudget, int $remainingSlots): array|ApiError
    {
        $tmpDir = CORE_PATH . '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }

        $tmpFile = $tmpDir . '/upload_' . bin2hex(random_bytes(8)) . '.zip';
        if (@file_put_contents($tmpFile, $zipData) === false) {
            return new ApiError(500, "Failed to process upload archive.");
        }

        $zip = new ZipArchive();
        $result = [];

        try {
            $openResult = $zip->open($tmpFile);
            if ($openResult !== true) {
                @unlink($tmpFile);
                return new ApiError(400, "Invalid ZIP archive: " . htmlspecialchars($zipName));
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if ($entryName === false) {
                    continue;
                }
                if (str_ends_with($entryName, '/')) {
                    continue;
                }
                if (!self::validateFileName($entryName)) {
                    return new ApiError(400, "Invalid file name in archive: " . htmlspecialchars($entryName));
                }

                $entryContent = $zip->getFromIndex($i);
                if ($entryContent === false) {
                    continue;
                }

                if (strlen($entryContent) > $remainingBudget) {
                    return new ApiError(413, "Expanded upload exceeds maximum total size.");
                }
                if ($remainingSlots <= 0) {
                    return new ApiError(413, "Too many files in upload. Maximum is " . self::DEFAULT_MAX_FILES . ".");
                }

                $result[] = ['name' => $entryName, 'data' => $entryContent];
                $remainingBudget -= strlen($entryContent);
                $remainingSlots--;
            }

            $zip->close();
            @unlink($tmpFile);
        } catch (\Throwable $e) {
            @unlink($tmpFile);
            throw $e;
        }

        return $result;
    }
}