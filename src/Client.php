<?php

namespace AdManager;

use Google\Ads\GoogleAds\Lib\V18\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V18\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2\GoogleAdsOAuth2Credential;
use Google\Auth\Credentials\UserRefreshCredentials;
use Dotenv\Dotenv;

class Client
{
    private static ?GoogleAdsClient $instance = null;
    private static array $env = [];

    public static function boot(): void
    {
        if (self::$env) return;
        $dotenv = Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->load();
        $dotenv->required([
            'GOOGLE_ADS_CLIENT_ID',
            'GOOGLE_ADS_CLIENT_SECRET',
            'GOOGLE_ADS_DEVELOPER_TOKEN',
            'GOOGLE_ADS_REFRESH_TOKEN',
            'GOOGLE_ADS_CUSTOMER_ID',
        ]);
        self::$env = $_ENV;
    }

    public static function get(): GoogleAdsClient
    {
        if (self::$instance) return self::$instance;
        self::boot();

        $credentials = new UserRefreshCredentials(
            'https://www.googleapis.com/auth/adwords',
            [
                'client_id'     => self::$env['GOOGLE_ADS_CLIENT_ID'],
                'client_secret' => self::$env['GOOGLE_ADS_CLIENT_SECRET'],
                'refresh_token' => self::$env['GOOGLE_ADS_REFRESH_TOKEN'],
            ]
        );

        self::$instance = (new GoogleAdsClientBuilder())
            ->withDeveloperToken(self::$env['GOOGLE_ADS_DEVELOPER_TOKEN'])
            ->withOAuth2Credential($credentials)
            ->build();

        return self::$instance;
    }

    public static function customerId(): string
    {
        self::boot();
        // Strip dashes — API expects plain digits
        return preg_replace('/[^0-9]/', '', self::$env['GOOGLE_ADS_CUSTOMER_ID']);
    }

    public static function env(string $key): string
    {
        self::boot();
        return self::$env[$key] ?? '';
    }
}
