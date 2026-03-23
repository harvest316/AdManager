# AdManager

Google Ads API management scripts for Audit&Fix campaigns.

## Setup

1. Copy `.env.example` to `.env` and fill in credentials
2. `composer install`
3. `php auth.php` — run once to get refresh token

## Scripts

- `auth.php` — OAuth2 flow, generates refresh token
- `add-negatives.php` — bulk add negative keywords from CSV
- `sync-campaign.php` — sync keywords/ads from docs/google-ads/

## Credentials needed

- Google Cloud OAuth2 client ID + secret (Desktop app type)
- Google Ads developer token
- Google Ads customer ID (your account number, no dashes)
