<?php
declare(strict_types=1);

namespace Inventory;

final class Http
{
    public static function allowsLocal(string $address): bool
    {
        return in_array($address, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
    }

    public static function start(array $config): void
    {
        if (($config['local_only'] ?? true) && !self::allowsLocal($_SERVER['REMOTE_ADDR'] ?? '')) {
            self::json(['error' => 'This initial version is available only on this PC.'], 403);
        }
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('inventory_session');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function verifyToken(string $provided, string $expected): void
    {
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            throw new ValidationException('Session expired. Reload the page and try again.', 403);
        }
    }

    public static function input(): array
    {
        self::verifyToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '', $_SESSION['csrf'] ?? '');
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            throw new ValidationException('Send JSON data.', 415);
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if (strlen($raw) > 65536) {
            throw new ValidationException('Request too large.', 413);
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException('Invalid JSON.');
        }
        if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
            throw new ValidationException('Send a JSON object.');
        }
        return $data;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
