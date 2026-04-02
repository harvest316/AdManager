# CLAUDE.md — AdManager

Multi-project ad platform managing Google Ads + Meta (Facebook/Instagram) campaigns with AI-powered creative generation, strategy planning, and optimisation.

## Quick Start

```bash
php bin/db-init.php                                    # Create SQLite DB
php bin/project.php create "myproject" --url "https://example.com" --display "My Project"
php bin/project.php budget myproject google 6.70       # $6.70/day Google
php bin/project.php budget myproject meta 5.00         # $5.00/day Meta
php bin/project.php goals myproject --cpa 30 --platform google
```

## Architecture

```
src/Google/         — Google Ads API (Search, Display, Video, PMax, DemandGen)
src/Meta/           — Meta Marketing API (Campaigns, Ad Sets, Ads, Assets)
src/Creative/       — Image gen (OpenRouter), video gen (Kling), ffmpeg overlays
src/Strategy/       — Claude CLI strategy generation + storage
src/Optimise/       — Split tests, keyword mining, budget allocation, fatigue detection
src/DB.php          — SQLite singleton (db/admanager.db)
review/             — HTML review dashboard (php bin/review-server.php)
prompts/            — Prompt templates for Claude CLI
```

## Workflow

1. **Generate strategy:** `php bin/strategy.php generate --project X --platform google --type search`
2. **Generate creative:** `php bin/generate-creative.php image "prompt" --mode draft`
3. **Review creative:** `php bin/review-server.php` → approve/reject/feedback at localhost:8080
4. **Create campaign:** `php bin/create-ad.php --project X --platform google --strategy 1`
   - Uploads in PAUSED state — never auto-enables
5. **Enable:** `php bin/manage.php enable <campaign_id>` (Google) or `php bin/meta-campaign.php enable <id>` (Meta)
6. **Sync performance:** `php bin/sync-performance.php --project X --days 7`
7. **Optimise:** `php bin/optimise.php full --project X`

## Key Principles

- **Never auto-enable** — all campaigns start PAUSED, human reviews before enabling
- **Multi-project** — manages multiple products (Audit&Fix, 2Step, etc.)
- **Budget per project+platform** — daily budget set at project+platform level
- **Claude CLI for LLM** — uses `claude -p "..."` not OpenRouter for text generation
- **Split tests** — A/B testing with statistical significance (z-test)

## Available Agents

For complex tasks, use these agency agents (see mmo-platform docs/agency-agents-reference.md):
- **PPC Campaign Strategist** — campaign structure, budget allocation, bidding
- **Ad Creative Strategist** — ad copy, RSA optimisation, creative testing
- **Paid Social Strategist** — Meta campaign design, audience targeting
- **Search Query Analyst** — keyword mining, negative keyword architecture
- **Tracking & Measurement Specialist** — conversion tracking, attribution

## Database

SQLite at `db/admanager.db`. Schema in `db/schema.sql`. Key tables: projects, budgets, campaigns, ad_groups, keywords, assets, ads, strategies, split_tests, goals, performance.

## Secrets

All credentials in `.env` (gitignored). Never commit secrets. See `.env.example` for required vars.
