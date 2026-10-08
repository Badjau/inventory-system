<?php
declare(strict_types=1);
namespace Inventory;

final class Auth
{
    public static function user(?array $account, array $session, ?int $now = null): ?array
    {
        $now ??= time();
        if (!$account || !($account['is_admin'] || $account['is_it_admin'])
            || (int) $account['id'] !== ($session['user_id'] ?? null)
            || ($session['last_seen'] ?? 0) < $now - 1800 || ($session['signed_in'] ?? 0) < $now - 28800
            || !hash_equals(hash('sha256', $account['password']), $session['credential'] ?? '')) return null;
        return ['id' => (int) $account['id'], 'username' => $account['username'] ?: $account['email'],
            'name' => $account['name'], 'role' => 'editor'];
    }

    public static function current(): ?array
    {
        if (!isset($_SESSION['user_id']) || !is_int($_SESSION['user_id'])) return null;
        $hr = new HrDirectory(Database::connect(\inventory_config()['database']));
        $user = self::user($hr->loginUserById($_SESSION['user_id']), $_SESSION);
        if ($user) $_SESSION['last_seen'] = time();
        return $user;
    }

    public static function authorize(?array $user, bool $write): void
    {
        if (!$user) throw new ValidationException('Sign in to inventory.', 401);
        if ($write && $user['role'] !== 'editor') throw new ValidationException('This account has read-only access.', 403);
    }

    public static function login(string $name, string $password, HrDirectory $hr, ?string $directory = null): bool
    {
        $account = $hr->loginUser($name);
        $file = fopen(($directory ?? dirname(__DIR__) . '/storage') . '/login-attempts.local.json', 'c+');
        if (!$file || !flock($file, LOCK_EX)) throw new \RuntimeException('Cannot open login throttle.');
        try {
            $now = time();
            $attempts = json_decode(stream_get_contents($file) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            $attempts = array_filter($attempts, static fn (array $item): bool => $item['until'] > $now);
            $keys = ['user:' . ($account ? (string) $account['id'] : hash('sha256', mb_strtolower($name))),
                'ip:' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')];
            $blocked = false;
            foreach ($keys as $key) if (($attempts[$key]['count'] ?? 0) >= 10) $blocked = true;
            $hash = $account['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
            $valid = password_verify($password, $hash) && $account
                && ($account['is_admin'] || $account['is_it_admin']) && !$blocked;
            if (!$valid && !$blocked) foreach ($keys as $key) {
                $attempts[$key] = ['count' => ($attempts[$key]['count'] ?? 0) + 1, 'until' => $attempts[$key]['until'] ?? $now + 900];
            }
            if ($valid) unset($attempts[$keys[0]]);
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($attempts, JSON_THROW_ON_ERROR));
            fflush($file);
        } finally { flock($file, LOCK_UN); fclose($file); }
        if (!$valid) return false;
        session_regenerate_id(true);
        $_SESSION = ['user_id' => (int) $account['id'], 'credential' => hash('sha256', $account['password']),
            'signed_in' => $now, 'last_seen' => $now, 'csrf' => bin2hex(random_bytes(32))];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
