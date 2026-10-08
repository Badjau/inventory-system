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
                if (is_file($path . '.thumb96.jpg')) unlink($path . '.thumb96.jpg');
            }
        }
    }

    public static function thumbnail(string $path): string
    {
        $cached = $path . '.thumb96.jpg';
        if (is_file($cached)) return $cached;
        if (!extension_loaded('gd')) throw new ValidationException('Enable PHP GD to display device thumbnails.', 503);
        $source = @imagecreatefromstring(file_get_contents($path));
        if (!$source) throw new ValidationException('Photo cannot be decoded.', 422);
        $thumbnail = imagecreatetruecolor(96, 96);
        $temporary = null;
        try {
            imagefill($thumbnail, 0, 0, imagecolorallocate($thumbnail, 255, 255, 255));
            $width = imagesx($source);
            $height = imagesy($source);
            $side = min($width, $height);
            imagecopyresampled($thumbnail, $source, 0, 0, (int) (($width - $side) / 2), (int) (($height - $side) / 2), 96, 96, $side, $side);
            $temporary = tempnam(dirname($path), 'thumb-');
            if ($temporary === false || !imagejpeg($thumbnail, $temporary, 72)) throw new \RuntimeException('Cannot create thumbnail.');
            if (!@rename($temporary, $cached) && !is_file($cached)) throw new \RuntimeException('Cannot cache thumbnail.');
            return $cached;
        } finally {
            imagedestroy($source);
            imagedestroy($thumbnail);
            if (is_string($temporary) && is_file($temporary)) unlink($temporary);
        }
    }
}
