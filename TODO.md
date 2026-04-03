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

## TrustPilot + Google Business Profile Ratings in Ad Campaigns

**Status:** Future — research needed

Consider integrating TrustPilot ratings and Google Business Profile (GBP) reviews into ad campaigns:
- **Google Ads:** Seller ratings extension (requires TrustPilot or Google Customer Reviews integration with 100+ reviews). Auto-shows star ratings below RSA ads.
- **Meta Ads:** Social proof in ad copy — pull review count + average rating via TrustPilot API, inject into primary text ("Rated 4.8/5 by 200+ businesses").
- **RSA headlines:** Dynamic headline using review stats ("Trusted by 200+ Businesses" with actual count from API).
- **GBP API:** Pull Google review count + rating for client businesses (2Step prospects). Use in ad copy targeting.

**APIs:**
- TrustPilot Business API: https://developers.trustpilot.com/
- Google My Business API (deprecated → Google Business Profile API): review retrieval

**Implementation:**
- `src/Social/TrustPilot.php` — fetch review summary (count, avg rating)
- `src/Social/GoogleBusinessProfile.php` — fetch GBP reviews
- Wire into strategy generator: "include social proof stats in ad copy"
- Wire into ad copy templates: `{{REVIEW_COUNT}}`, `{{AVG_RATING}}` placeholders

---

## Mantis Ad Network (Cannabis-Specific Programmatic)

**Status:** Future — only if cannabis advertising exceeds $250/month

Mantis is the largest cannabis-specific programmatic ad network (50K+ sites/apps). Uses OpenRTB exchange protocol (not REST API). $500 deposit + 100% service fee effectively doubles cost.

At $200-600/month budget, Mantis is impractical — ExoClick's contextual targeting with cannabis keywords in legal geos is more cost-effective. Revisit if cannabis becomes a primary vertical with dedicated budget.

- Mantis: https://www.mantisadnetwork.com/
- OpenRTB docs: https://github.com/mantisadnetwork

---

## Browser Pixel fbp/fbc in CAPI Dedup

The `_fbp` and `_fbc` cookies are read server-side in `api.php` and passed to CAPI.
However, cookies are only present if the Meta Pixel has previously fired (setting `_fbp`)
or the user arrived via a Meta ad link (setting `_fbc`). First-visit purchases won't
have either cookie. This is expected behaviour — CAPI will still match on email hash.
No action needed; document for ops awareness.
