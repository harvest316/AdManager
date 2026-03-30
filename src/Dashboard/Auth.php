<?php

namespace AdManager\Dashboard;

/**
 * Session-based admin authentication. Single user, bcrypt password in .env.
 */
class Auth
{
    private const SESSION_LIFETIME = 86400 * 7; // 7 days

    public static function require(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (
            !empty($_SESSION['admin_authenticated'])
            && (time() - ($_SESSION['admin_auth_time'] ?? 0)) < self::SESSION_LIFETIME
        ) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
            self::handleLogin();
            return;
        }

        self::renderLoginForm();
        exit;
    }

    /**
     * Check if auth is configured (password hash exists in .env).
     * If not configured, skip auth entirely (dev mode).
     */
    public static function isConfigured(): bool
    {
        return (bool) self::getHash();
    }

    public static function requireIfConfigured(): void
    {
        if (self::isConfigured()) {
            self::require();
        }
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_auth_time']);
        session_destroy();
    }

    private static function handleLogin(): void
    {
        $password = $_POST['password'] ?? '';
        $hash = self::getHash();

        if ($hash && password_verify($password, $hash)) {
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_auth_time'] = time();
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        self::renderLoginForm('Invalid password.');
        exit;
    }

    private static function getHash(): ?string
    {
        $hash = getenv('ADMIN_PASSWORD_HASH') ?: null;
        if ($hash) return $hash;

        $envFile = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envFile)) {
            $env = parse_ini_file($envFile);
            return $env['ADMIN_PASSWORD_HASH'] ?? null;
        }
        return null;
    }

    private static function renderLoginForm(string $error = ''): void
    {
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AdManager — Login</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;background:#0d1117;color:#e6edf3;display:flex;align-items:center;justify-content:center;min-height:100vh}
.login{background:#161b22;border:1px solid #30363d;border-radius:12px;padding:32px;width:90%;max-width:360px}
h1{font-size:20px;margin-bottom:24px;display:flex;align-items:center;gap:10px}
h1 .icon{width:28px;height:28px;background:#58a6ff;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#fff}
.err{background:#3d1117;color:#f85149;border:1px solid #f85149;border-radius:6px;padding:8px 12px;margin-bottom:16px;font-size:13px}
input[type=password]{width:100%;background:#21262d;color:#e6edf3;border:1px solid #30363d;border-radius:6px;padding:10px 12px;font-size:14px;margin-bottom:16px}
input:focus{outline:none;border-color:#58a6ff}
button{width:100%;background:#238636;color:#fff;border:1px solid #2ea043;border-radius:6px;padding:10px;font-size:14px;font-weight:500;cursor:pointer}
button:hover{background:#2ea043}
</style>
</head>
<body>
<div class="login">
<h1><div class="icon">A</div> AdManager</h1>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="action" value="login">
<input type="password" name="password" placeholder="Password" autofocus required>
<button type="submit">Sign in</button>
</form>
</div>
</body></html><?php
    }
}
