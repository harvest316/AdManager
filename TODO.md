# AdManager — Maybe / Future TODOs

Items that are worth doing eventually but not prioritised.

---

## GTM Container Auto-Creation via API

**Status:** Maybe — lower priority given server-side preference

**Context:**
Currently `ConversionPlanner::provision()` for GA4 conversions returns manual GTM
setup instructions rather than creating a container automatically. The GTM API
(`tagmanager.googleapis.com`) can create containers, add tags, and publish versions
programmatically.

**Preference:** Server-side event tracking over browser-side tag injection where
possible. Reasons:
- Reduces cookie count and consent friction for the user
- Less dependent on browser ad-blockers / ITP blocking GTM requests
- Lower latency (server fires immediately on event, not after page load + JS parse)
- GA4 Measurement Protocol (already implemented) covers most conversion needs

**When browser GTM is still needed:**
- Enhanced e-commerce (product impression/click tracking requires DOM access)
- Remarketing audiences (need browser cookie `_gads` / `_gcl_*`)
- Third-party tags that have no server-side equivalent

**If this gets built:**
- OAuth2 service account with `tagmanager.edit.containers` scope
- Create container → add GA4 tag → add Google Ads conversion linker tag → publish
- Wire into `ConversionPlanner::provision()` for `platform = 'ga4'`
- See: https://developers.google.com/tag-manager/api/v2/reference

---

## Google Ads API Basic Access (Impression Share / Quality Score)

**Status:** Blocked — waiting on Google approval

Once Basic Access is granted, add to `PerformanceQuery::campaignBreakdown()`:
- `search_impression_share`
- `search_budget_lost_impression_share`
- Keyword Quality Scores (via `adGroupCriterion.qualityInfo.qualityScore`)

---

## AdManager Scheduled Sync (systemd timer)

Once running on the NixOS VPS, add `admanager-sync.timer` to auto-sync all projects
every 6 hours instead of relying on the manual "Sync Now" button in the dashboard.
See `distributed-infra/modules/admanager.nix`.

---

## Browser Pixel fbp/fbc in CAPI Dedup

The `_fbp` and `_fbc` cookies are read server-side in `api.php` and passed to CAPI.
However, cookies are only present if the Meta Pixel has previously fired (setting `_fbp`)
or the user arrived via a Meta ad link (setting `_fbc`). First-visit purchases won't
have either cookie. This is expected behaviour — CAPI will still match on email hash.
No action needed; document for ops awareness.
