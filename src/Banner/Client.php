<?php

namespace AdManager\Banner;

/**
 * Banner network client — DB-only, no HTTP.
 *
 * Banner campaigns are managed purely through the local AdManager DB.
 * This client is a minimal singleton that provides the network name and
 * serves as the injection point for tests.
 *
 * Singleton: Client::get() returns the shared instance.
 */
class Client
{
    private static ?self $instance = null;

    private string $networkName;

    private function __construct()
    {
        $this->networkName = $_ENV['BANNER_NETWORK_NAME']
            ?? getenv('BANNER_NETWORK_NAME')
            ?: 'banner';
    }

    public static function get(): self
    {
        if (self::$instance) return self::$instance;
        self::$instance = new self();
        return self::$instance;
    }

    /**
     * Reset singleton (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function networkName(): string
    {
        return $this->networkName;
    }
}
