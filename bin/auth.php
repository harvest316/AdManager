#!/usr/bin/env php
<?php
/**
 * One-time OAuth2 flow to generate a refresh token.
 * Run this once, then paste the refresh token into .env.
 *
 * Usage: php bin/auth.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$clientId     = $_ENV['GOOGLE_ADS_CLIENT_ID'];
$clientSecret = $_ENV['GOOGLE_ADS_CLIENT_SECRET'];
$scope        = 'https://www.googleapis.com/auth/adwords';
$redirectUri  = 'urn:ietf:wg:oauth:2.0:oob';

// Step 1: print auth URL
$authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
    'client_id'             => $clientId,
    'redirect_uri'          => $redirectUri,
    'response_type'         => 'code',
    'scope'                 => $scope,
    'access_type'           => 'offline',
    'prompt'                => 'consent',
]);

echo "\n";
echo "=== Google Ads OAuth2 Setup ===\n\n";
echo "1. Open this URL in your browser:\n\n";
echo "   {$authUrl}\n\n";
echo "2. Sign in with the Google account that has access to your Ads account.\n";
echo "3. Approve the permissions.\n";
echo "4. Copy the authorisation code shown and paste it below.\n\n";
echo "Authorisation code: ";

$code = trim(fgets(STDIN));

// Step 2: exchange code for tokens
$response = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]),
    ],
]));

$tokens = json_decode($response, true);

if (empty($tokens['refresh_token'])) {
    echo "\nError: " . ($tokens['error_description'] ?? $response) . "\n";
    exit(1);
}

echo "\n=== Success ===\n\n";
echo "Refresh token: {$tokens['refresh_token']}\n\n";
echo "Add this to your .env file:\n";
echo "GOOGLE_ADS_REFRESH_TOKEN={$tokens['refresh_token']}\n\n";
