#!/usr/bin/env php
<?php
/**
 * Meta (Facebook) OAuth token management.
 *
 * Usage:
 *   php bin/meta-auth.php setup      # Interactive: enter app creds, get login URL, exchange code
 *   php bin/meta-auth.php exchange    # Exchange short-lived token for long-lived token
 *   php bin/meta-auth.php refresh     # Refresh an expiring long-lived token
 *   php bin/meta-auth.php status      # Check current token validity and expiry
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

// ── Colour helpers ──────────────────────────────────────────────────────────

function c(string $color, string $text): string
{
    $codes = [
        'green'   => "\033[32m",
        'red'     => "\033[31m",
        'yellow'  => "\033[33m",
        'cyan'    => "\033[36m",
        'bold'    => "\033[1m",
        'dim'     => "\033[2m",
        'reset'   => "\033[0m",
    ];
    return ($codes[$color] ?? '') . $text . "\033[0m";
}

function heading(string $title): void
{
    echo "\n" . c('bold', c('cyan', "=== {$title} ===")) . "\n\n";
}

function success(string $msg): void
{
    echo c('green', "  [OK] ") . $msg . "\n";
}

function warn(string $msg): void
{
    echo c('yellow', "  [!] ") . $msg . "\n";
}

function err(string $msg): void
{
    echo c('red', "  [ERROR] ") . $msg . "\n";
}

function info(string $msg): void
{
    echo c('dim', "  ") . $msg . "\n";
}

function prompt(string $label, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo c('bold', "  {$label}{$suffix}: ");
    $input = trim(fgets(STDIN));
    return $input !== '' ? $input : $default;
}

// ── HTTP helper ─────────────────────────────────────────────────────────────

function graphGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        err("curl failed: {$curlErr}");
        exit(1);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        err("Non-JSON response (HTTP {$httpCode}): {$body}");
        exit(1);
    }

    if (isset($decoded['error'])) {
        $msg  = $decoded['error']['message'] ?? json_encode($decoded['error']);
        $code = $decoded['error']['code'] ?? 0;
        err("Graph API error ({$code}): {$msg}");
        exit(1);
    }

    return $decoded;
}

// ── Env loading ─────────────────────────────────────────────────────────────

function loadEnv(): void
{
    $envPath = dirname(__DIR__);
    if (file_exists($envPath . '/.env')) {
        $dotenv = Dotenv::createImmutable($envPath);
        $dotenv->load();
    }
}

function envGet(string $key): string
{
    return $_ENV[$key] ?? getenv($key) ?: '';
}

function requireEnvCredentials(): array
{
    $appId     = envGet('META_APP_ID');
    $appSecret = envGet('META_APP_SECRET');

    if ($appId === '' || $appSecret === '') {
        err("META_APP_ID and META_APP_SECRET must be set in .env");
        info("Run 'php bin/meta-auth.php setup' to configure them interactively.");
        exit(1);
    }

    return [$appId, $appSecret];
}

function requireEnvToken(): string
{
    $token = envGet('META_ACCESS_TOKEN');
    if ($token === '') {
        err("META_ACCESS_TOKEN is not set in .env");
        info("Run 'php bin/meta-auth.php setup' or 'php bin/meta-auth.php exchange' first.");
        exit(1);
    }
    return $token;
}

// ── API version ─────────────────────────────────────────────────────────────

function apiVersion(): string
{
    return envGet('META_API_VERSION') ?: 'v20.0';
}

// ── Exchange short-lived token for long-lived ───────────────────────────────

function exchangeForLongLived(string $appId, string $appSecret, string $shortToken): string
{
    $v   = apiVersion();
    $url = "https://graph.facebook.com/{$v}/oauth/access_token?" . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $appId,
        'client_secret'     => $appSecret,
        'fb_exchange_token' => $shortToken,
    ]);

    $data = graphGet($url);

    if (empty($data['access_token'])) {
        err("Token exchange failed. Response: " . json_encode($data));
        exit(1);
    }

    return $data['access_token'];
}

// ── Show token identity + ad accounts ───────────────────────────────────────

function showTokenIdentity(string $token): void
{
    $v = apiVersion();

    // Who am I?
    $me = graphGet("https://graph.facebook.com/{$v}/me?access_token={$token}");
    success("Authenticated as: " . c('bold', $me['name'] ?? 'Unknown') . " (ID: {$me['id']})");

    // List ad accounts
    $accounts = graphGet("https://graph.facebook.com/{$v}/me/adaccounts?" . http_build_query([
        'access_token' => $token,
        'fields'       => 'account_id,name,account_status',
    ]));

    if (!empty($accounts['data'])) {
        echo "\n";
        info("Ad accounts:");
        foreach ($accounts['data'] as $acct) {
            $status = match ($acct['account_status'] ?? 0) {
                1       => c('green', 'ACTIVE'),
                2       => c('red', 'DISABLED'),
                3       => c('yellow', 'UNSETTLED'),
                7       => c('yellow', 'PENDING_REVIEW'),
                9       => c('yellow', 'IN_GRACE_PERIOD'),
                100     => c('yellow', 'PENDING_CLOSURE'),
                101     => c('red', 'CLOSED'),
                201     => c('yellow', 'ANY_ACTIVE'),
                202     => c('yellow', 'ANY_CLOSED'),
                default => c('dim', "status={$acct['account_status']}"),
            };
            $name = $acct['name'] ?? '(unnamed)';
            info("  act_{$acct['account_id']}  {$name}  {$status}");
        }
    } else {
        warn("No ad accounts found for this user.");
    }
}

// ── Commands ────────────────────────────────────────────────────────────────

function cmdSetup(): void
{
    heading('Meta OAuth Setup');

    // 1. Get app credentials
    $appId = envGet('META_APP_ID');
    $appSecret = envGet('META_APP_SECRET');

    if ($appId !== '' && $appSecret !== '') {
        info("Using META_APP_ID and META_APP_SECRET from .env");
        info("App ID: {$appId}");
        echo "\n";
    } else {
        info("Enter your Meta App credentials (from developers.facebook.com):\n");
        $appId     = prompt('META_APP_ID');
        $appSecret = prompt('META_APP_SECRET');

        if ($appId === '' || $appSecret === '') {
            err("Both APP_ID and APP_SECRET are required.");
            exit(1);
        }

        echo "\n";
        warn("Add these to your .env file:");
        echo "  META_APP_ID={$appId}\n";
        echo "  META_APP_SECRET={$appSecret}\n\n";
    }

    // 2. Print the OAuth login URL
    $v = apiVersion();
    $scopes = 'ads_management,ads_read,pages_read_engagement';
    $redirectUri = 'https://localhost';

    $loginUrl = "https://www.facebook.com/{$v}/dialog/oauth?" . http_build_query([
        'client_id'     => $appId,
        'redirect_uri'  => $redirectUri,
        'scope'         => $scopes,
        'response_type' => 'code',
    ]);

    info("Step 1: Open this URL in your browser:\n");
    echo "  " . c('cyan', $loginUrl) . "\n\n";

    info("Step 2: Authorise the app and approve all permissions.");
    info("Step 3: You will be redirected to https://localhost/?code=XXXXXX");
    info("        (The page won't load -- that's expected. Copy the 'code' from the URL.)\n");

    // 3. Get the code
    $code = prompt('Paste the code parameter');

    if ($code === '') {
        err("No code provided.");
        exit(1);
    }

    // URL-decode if the user pasted it encoded
    $code = urldecode($code);
    // Strip trailing #_=_ that Facebook sometimes appends
    $code = rtrim($code, '#_=');

    // 4. Exchange code for short-lived token
    echo "\n";
    info("Exchanging authorisation code for access token...");

    $url = "https://graph.facebook.com/{$v}/oauth/access_token?" . http_build_query([
        'client_id'     => $appId,
        'redirect_uri'  => $redirectUri,
        'client_secret' => $appSecret,
        'code'          => $code,
    ]);

    $data = graphGet($url);

    if (empty($data['access_token'])) {
        err("Failed to get access token. Response: " . json_encode($data));
        exit(1);
    }

    $shortToken = $data['access_token'];
    success("Got short-lived token.");

    // 5. Exchange for long-lived token
    info("Exchanging for long-lived token...");
    $longToken = exchangeForLongLived($appId, $appSecret, $shortToken);
    success("Got long-lived token (valid ~60 days).\n");

    // 6. Verify identity and list ad accounts
    showTokenIdentity($longToken);

    // 7. Print final instructions
    echo "\n";
    heading('Add to .env');
    echo "  META_ACCESS_TOKEN={$longToken}\n\n";
    info("Token expires in ~60 days. Run 'php bin/meta-auth.php status' to check.");
    info("Run 'php bin/meta-auth.php refresh' before it expires to get a new one.\n");
}

function cmdExchange(string $shortToken = ''): void
{
    heading('Meta Token Exchange');

    [$appId, $appSecret] = requireEnvCredentials();

    if ($shortToken === '') {
        info("Paste a short-lived access token (from Graph API Explorer or OAuth flow):\n");
        $shortToken = prompt('Short-lived token');

        if ($shortToken === '') {
            err("No token provided.");
            exit(1);
        }
    }

    echo "\n";
    info("Exchanging for long-lived token...");
    $longToken = exchangeForLongLived($appId, $appSecret, $shortToken);
    success("Got long-lived token (valid ~60 days).\n");

    showTokenIdentity($longToken);

    echo "\n";
    heading('Add to .env');
    echo "  META_ACCESS_TOKEN={$longToken}\n\n";
}

function cmdRefresh(): void
{
    heading('Meta Token Refresh');

    [$appId, $appSecret] = requireEnvCredentials();
    $currentToken = requireEnvToken();

    info("Refreshing current long-lived token...\n");

    $longToken = exchangeForLongLived($appId, $appSecret, $currentToken);

    if ($longToken === $currentToken) {
        warn("Token unchanged. Facebook may return the same token if it is still fresh.");
        info("Tokens can only be refreshed once they are at least 24 hours old.");
    } else {
        success("Got new long-lived token (valid ~60 days).\n");
    }

    showTokenIdentity($longToken);

    echo "\n";
    heading('Update .env');
    echo "  META_ACCESS_TOKEN={$longToken}\n\n";
    info("Replace the existing META_ACCESS_TOKEN value in your .env file.\n");
}

function cmdStatus(): void
{
    heading('Meta Token Status');

    $token = requireEnvToken();
    $v = apiVersion();

    // debug_token endpoint
    $url = "https://graph.facebook.com/{$v}/debug_token?" . http_build_query([
        'input_token'  => $token,
        'access_token' => $token,
    ]);

    $data = graphGet($url);
    $info = $data['data'] ?? [];

    if (empty($info)) {
        err("Unexpected response from debug_token endpoint.");
        exit(1);
    }

    // Validity
    $isValid = $info['is_valid'] ?? false;
    if ($isValid) {
        success("Token is " . c('bold', c('green', 'VALID')));
    } else {
        err("Token is " . c('bold', c('red', 'INVALID')));
        if (isset($info['error']['message'])) {
            info("Reason: {$info['error']['message']}");
        }
    }

    // App
    if (isset($info['app_id'])) {
        $appName = $info['application'] ?? 'Unknown';
        info("App: {$appName} (ID: {$info['app_id']})");
    }

    // User
    if (isset($info['user_id'])) {
        info("User ID: {$info['user_id']}");
    }

    // Type
    if (isset($info['type'])) {
        info("Type: {$info['type']}");
    }

    // Scopes
    if (!empty($info['scopes'])) {
        info("Scopes: " . implode(', ', $info['scopes']));
    }

    // Expiry
    if (isset($info['expires_at'])) {
        if ($info['expires_at'] === 0) {
            info("Expires: " . c('green', 'Never (non-expiring token)'));
        } else {
            $expiresAt   = $info['expires_at'];
            $expiresDate = date('Y-m-d H:i:s T', $expiresAt);
            $daysLeft    = max(0, (int) round(($expiresAt - time()) / 86400));

            if ($daysLeft <= 0) {
                info("Expires: " . c('red', "EXPIRED on {$expiresDate}"));
            } elseif ($daysLeft <= 7) {
                info("Expires: " . c('yellow', "{$expiresDate} ({$daysLeft} days left -- RENEW SOON)"));
            } else {
                info("Expires: " . c('green', "{$expiresDate} ({$daysLeft} days left)"));
            }
        }
    }

    // Issued at
    if (isset($info['issued_at']) && $info['issued_at'] > 0) {
        info("Issued:  " . date('Y-m-d H:i:s T', $info['issued_at']));
    }

    // Data access expiry
    if (isset($info['data_access_expires_at']) && $info['data_access_expires_at'] > 0) {
        $dataExpiry = date('Y-m-d H:i:s T', $info['data_access_expires_at']);
        info("Data access expires: {$dataExpiry}");
    }

    echo "\n";
}

// ── Main ────────────────────────────────────────────────────────────────────

loadEnv();

$command = $argv[1] ?? '';

switch ($command) {
    case 'setup':
        cmdSetup();
        break;

    case 'exchange':
        $tokenArg = $argv[2] ?? '';
        cmdExchange($tokenArg);
        break;

    case 'refresh':
        cmdRefresh();
        break;

    case 'status':
        cmdStatus();
        break;

    default:
        echo "\n";
        echo c('bold', "Meta OAuth Token Manager") . "\n\n";
        echo "Usage:\n";
        echo "  php bin/meta-auth.php " . c('cyan', 'setup') . "          Interactive: enter app creds, authorise, get long-lived token\n";
        echo "  php bin/meta-auth.php " . c('cyan', 'exchange') . "       Exchange a short-lived token for a long-lived token\n";
        echo "  php bin/meta-auth.php " . c('cyan', 'exchange') . " " . c('dim', '<token>') . "  Exchange with token passed as argument\n";
        echo "  php bin/meta-auth.php " . c('cyan', 'refresh') . "        Refresh an expiring long-lived token from .env\n";
        echo "  php bin/meta-auth.php " . c('cyan', 'status') . "         Check current token validity, scopes, and expiry\n";
        echo "\n";
        echo c('dim', "  Credentials (META_APP_ID, META_APP_SECRET) and token (META_ACCESS_TOKEN)") . "\n";
        echo c('dim', "  are read from .env in the project root.") . "\n\n";
        exit(1);
}
