<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$localConfig = dirname(__DIR__) . '/config.local.php';
Inventory\Http::start(is_file($localConfig) ? require $localConfig : ['local_only' => true]);
$error = '';
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new Inventory\ValidationException('Method not allowed.', 405);
    }
    if ($method === 'POST') {
        Inventory\Http::verifyToken(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '', $_SESSION['csrf']);
        if (isset($_POST['logout'])) {
            Inventory\Auth::logout();
            header('Location: login.php', true, 303);
            exit;
        }
        $name = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $hr = new Inventory\HrDirectory(Inventory\Database::connect(inventory_config()['database']));
        if (strlen($name) > 254 || strlen($password) > 4096 || !Inventory\Auth::login($name, $password, $hr)) {
            http_response_code(401);
            $error = 'Sign-in failed. Check your details, or wait 15 minutes if you have tried repeatedly.';
        } else {
            header('Location: index.php', true, 303);
            exit;
        }
    } elseif (Inventory\Auth::current()) {
        header('Location: index.php');
        exit;
    }
} catch (Inventory\ValidationException $exception) {
    http_response_code($exception->status);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Inventory login: ' . $exception->getMessage());
    http_response_code(503);
    $error = 'Sign-in is unavailable. Ask the administrator to check the attendance database connection.';
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Inventory</title><link rel="stylesheet" href="assets/base.css"><link rel="stylesheet" href="assets/login.css"></head>
<body><main class="login-card"><h1>Inventory</h1><p>Sign in with your attendance dashboard account.</p>
<?php if ($error): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" action="login.php">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
<label>Username or email<input name="username" autocomplete="username" maxlength="254" required autofocus></label>
<label>Password<input name="password" type="password" autocomplete="current-password" maxlength="4096" required></label>
<button type="submit">Sign in</button>
</form></main></body></html>
