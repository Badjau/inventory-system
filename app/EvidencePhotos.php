<?php
declare(strict_types=1);

namespace Inventory;

final class EvidencePhotos
{
    public const MAX_FILES = 5;
    public const MAX_BYTES = 2 * 1024 * 1024;

    public static function directory(): string
    {
        return dirname(__DIR__) . '/storage/evidence';
    }

    public static function inspect(string $path, string $name): array
    {
        $size = filesize($path);
        $image = @getimagesize($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$size || $size > self::MAX_BYTES || !$image || !isset($types[$mime]) || ($image['mime'] ?? '') !== $mime
            || $image[0] * $image[1] > 40000000) {
            throw new ValidationException('Choose JPEG, PNG or WebP photos up to 2 MiB each and 40 megapixels.');
        }
        return ['file' => bin2hex(random_bytes(16)) . '.' . $types[$mime],
            'name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255), 'mime' => $mime];
    }

    public static function receive(mixed $upload): array
    {
        if ($upload === null) return [];
        if (!is_array($upload) || !is_array($upload['error'] ?? null) || count($upload['error']) > self::MAX_FILES) {
            throw new ValidationException('Choose up to five evidence photos.');
        }
        $photos = [];
        try {
            foreach ($upload['error'] as $index => $error) {
                if ($error === UPLOAD_ERR_NO_FILE) continue;
                $path = $upload['tmp_name'][$index] ?? null;
                $name = $upload['name'][$index] ?? null;
                if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_string($name) || !is_uploaded_file($path)) {
                    throw new ValidationException('Photo upload failed. Check PHP upload limits and try again.');
                }
                $photo = self::inspect($path, $name);
                $directory = self::directory();
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create evidence storage.');
                if (!move_uploaded_file($path, $directory . '/' . $photo['file'])) throw new \RuntimeException('Cannot store evidence photo.');
                $photos[] = $photo;
            }
            return $photos;
        } catch (\Throwable $error) {
            self::discard($photos);
            throw $error;
        }
    }

    public static function discard(array $photos): void
    {
        foreach ($photos as $photo) {
            if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $photo['file'] ?? '')) {
                $path = self::directory() . '/' . $photo['file'];
                if (is_file($path)) unlink($path);
            }
        }
    }
}
