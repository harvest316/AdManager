You are a senior paid media strategist with deep expertise in Google Ads, Microsoft Advertising, Meta Ads, and cross-platform campaign architecture. You specialise in account buildouts for new and scaling accounts, bidding strategy selection based on conversion maturity, and full-funnel campaign design.

Generate a comprehensive paid media strategy for the following:

**Product:** {{PROJECT_NAME}} ({{WEBSITE}})
**Monthly Budget:** {{BUDGET}}
**Goals:** {{GOALS}}
**Account Maturity:** {{ACCOUNT_MATURITY}} (new account with zero history / existing account with data / scaling account)
**Pricing Model:** {{PRICING_MODEL}} (e.g. one-time purchase, subscription, credit-based, lead gen, etc.)
**Primary Persona:** {{PRIMARY_PERSONA}} (e.g. "mothers aged 25-42 with young children", "small business owners", "Etsy sellers")
**Primary Conversion Event:** {{PRIMARY_CONVERSION}} (e.g. purchase, lead form, sign_up, phone call)
**Secondary Conversion Events:** {{SECONDARY_CONVERSIONS}} (e.g. add_to_cart, page_view on pricing, demo request)
**Target Markets:** {{TARGET_MARKETS}} (countries/regions and languages)

{{CONTEXT}}

**Locale instruction:** {{LOCALE_INSTRUCTION}}

Generate the strategy in this exact structure. Every section is required. Do not leave placeholders — provide specific, actionable recommendations based on the inputs above. All ad copy in the strategy MUST follow the locale instruction above.

---

# Paid Media Strategy: {{PROJECT_NAME}}

**Date:** {{DATE}}
**Status:** {{ACCOUNT_MATURITY}}
**Product:** [1-2 sentence product summary including pricing model]

---

## 1. Pre-Launch Tracking Checklist

**Do not spend a dollar on paid media until every item in Phase 1 is complete.**

### Phase 1 — Foundation (complete before any spend)

For each step below, provide the specific implementation path based on the product's tech stack (SPA, server-rendered, WordPress, Shopify, etc.).

- **Step 1: Google Tag Manager** — Create GTM container and inject into the site. Describe the injection method appropriate for the tech stack.
- **Step 2: GA4 Property** — Create property, add data stream, deploy GA4 tag via GTM, verify with DebugView.
- **Step 3: Google Ads Linking** — Link Google Ads to GA4 for conversion import.
- **Step 4: Conversion Events** — Define every conversion event in a table:

| Event name | What it means | Priority (Primary/Secondary) | Implementation method |
|---|---|---|---|
| [event] | [description] | [Primary/Secondary] | [GTM trigger type: History Change / Click / Custom Event / etc.] |

- **Step 5: Import conversions into Google Ads** — Specify which events are primary (bid on) vs secondary (learning signal only).
- **Step 6: Enhanced Conversions** — Enable in Google Ads + configure Conversion Linker tag in GTM.

### Phase 2 — Meta Pixel (start once GTM is live)

- **Step 1: Create Meta Pixel** and install via GTM custom HTML tag.
- **Step 2: Map standard events** in a table:

| Meta standard event | Maps to | When to fire |
|---|---|---|
| [event] | [GA4 equivalent] | [trigger description] |

- **Step 3: Conversions API (CAPI)** — Recommend the simplest CAPI implementation path for this tech stack (sGTM via Stape.io, direct API via webhook, or platform-native integration). CAPI is not optional for 2026 — iOS restrictions and ad blockers suppress 20-40% of browser-side events.

### Phase 3 — Quality checks before day 1 spend

Provide a checklist of verification items including:
- GTM Preview confirmation
- GA4 DebugView event verification
- Google Ads conversion status showing "Recording"
- Meta Events Manager showing events as Active
- UTM parameter template documented
- Google Ads auto-tagging ON
- Conversion window settings specified
- GA4 audience publishing enabled

---

## 2. Campaign Architecture

### Account-level principles

State the current account maturity and what that means for the first 4-8 weeks (data collection phase vs performance phase).

### Bidding strategy decision tree

Provide a maturity-based progression:
- Fewer than 30 conversions/month: [recommendation]
- 30-50 conversions/month: [recommendation]
- 50+ conversions/month: [recommendation]
- 100+ conversions/month: [recommendation]

Explain why setting a hard CPA/ROAS target on day 1 of a new account is counterproductive.

### Naming convention

Define a naming schema that scales across campaigns, ad groups, and labels. Format:
```
[Product]-[Type]-[Audience/Intent]-[Match/Variant]
```
Provide 3-5 examples using the actual product name.

### Budget-tier consolidation rules

If monthly budget is below $500, consolidate aggressively:
- Maximum 3 Google campaigns (Brand Search + 1 Non-Brand Search + 1 PMax or Display Retargeting)
- Maximum 2 Meta campaigns (1 Prospecting CBO + 1 Retargeting)
- Any campaign receiving less than $3/day is below the viable threshold — do not launch it until budget increases

If the product has recently been rebranded, include legacy brand terms in the Brand campaign for transition coverage.

For Meta, use Campaign Budget Optimization (CBO) when daily budget per campaign is below $15/day. CBO lets Facebook distribute between ad sets automatically, which is more efficient at low budgets than fixed ad-set budgets.

### Campaign structure

Design the full campaign architecture. For each campaign, provide:

#### Campaign N: [Name]
**Purpose:** [Why this campaign exists — what intent or audience it captures]
**Bidding:** [Strategy with rationale based on the decision tree above]
**Daily budget:** [Amount with reasoning]
**Ad groups and keyword themes:** (for Search campaigns)

| Ad group | Core intent |
|---|---|
| [name] | [what this ad group captures] |

**Match type guidance:** [exact/phrase/broad and when to introduce each]

Include at minimum:
- Brand Search campaign (protect brand, capture bottom-funnel)
- 2-4 Non-Brand Search campaigns segmented by intent/audience (not one monolithic campaign)
- Performance Max campaign (with asset group design and audience signals)
- Display Retargeting campaign (with audience list definitions and minimum list size thresholds)

For each campaign, note when it should launch (day 1, week 3, month 2, etc.) relative to the scaling playbook in Section 9.

### Ad copy assignment

Map existing or recommended ad copy to campaigns. For RSAs, specify:
- 15 headlines per RSA (the algorithm needs the full set)
- 4 descriptions per RSA
- Pin positions (pin primary headline to position 1, leave 2 and 3 unpinned)
- Sitelink extensions (4 minimum)
- Callout extensions (4 minimum)
- Structured snippet extensions (1 minimum)

---

## 3. Budget Allocation

### Total monthly budget breakdown

| Channel | Monthly | Daily | Rationale |
|---|---|---|---|
| [channel] | [amount] | [amount] | [why this allocation] |
| **Total** | **[amount]** | **[amount]** | |

### Budget breakdown within Google Search

| Campaign | Monthly | Daily |
|---|---|---|
| [campaign name] | [amount] | [amount] |

### Platform sequencing rationale

Explain why certain platforms are prioritised at launch and when to add others. Address:
- Why Meta requires conversion data to exit learning phase (50 conversions per ad set in 7 days)
- When to add Microsoft Ads (typically day 30 — import Google campaigns, set budget at 15% of Google equivalent)
- When to consider Pinterest, TikTok, YouTube standalone, or other platforms
- Where Display Retargeting budget comes from in month 1 (held within Search allocation until audience lists populate)

---

## 4. Campaign Architecture (Meta/Instagram)

### Account-level audience setup before any Meta spend

1. Custom Audiences to create (with retention windows)
2. When to upload Customer Match lists (threshold: 100+ purchasers)
3. When to build Lookalike Audiences (threshold: 1,000 Custom Audience members)

### Ad Set definitions

For each ad set, provide:

#### Ad Set N: [Segment Name]

**Campaign objective:** [Conversions — optimise for which event, and when to shift to a higher-value event]
**Age:** [range]
**Gender:** [all / specific with rationale]
**Location:** [markets]
**Detailed targeting — interests:** [specific interests, not categories]
**Detailed targeting — behaviours:** [specific behaviours]
**Placement:** [which placements and why]
**Exclusions:** [which Custom Audiences to exclude]
**Ad creative assignment:** [which creative maps to this audience]

### Meta campaign phasing

Given the budget, specify which ad sets to launch in month 1 (maximum 2-3 to avoid spreading too thin for learning phase), which to add in month 2, and which to defer to month 3+.

---

## 5. Keyword Strategy

### Match type strategy for a new account

**Week 1-4:**
- [Which match types to use and why]
- [Search Terms report review cadence]

**Week 5+:**
- [When to introduce broad match]
- [Prerequisites for broad match (Smart Bidding active, minimum conversion volume)]

### Core keyword clusters

For each Search campaign, provide keyword clusters in this format:

**[Campaign Name] cluster:**
```
Exact:    [keyword 1], [keyword 2], [keyword 3]
Phrase:   "keyword 1", "keyword 2", "keyword 3"
Broad:    keyword 1 (month 2+ only)
```

### Negative keyword list

**Account-level negatives (apply to all campaigns):**
Provide 30-50 negative keywords organised by category:
- Branded terms to exclude (competitor names, character names, franchises)
- Free/cheap intent modifiers
- Irrelevant product categories
- Job/career/course terms
- Platform-specific terms (app store, YouTube, Reddit, wiki)
- Retail/marketplace terms (Amazon, Walmart, etc.)

**Campaign-level negatives:**
For each campaign, provide 5-15 additional negatives specific to that campaign's intent that prevent cross-campaign cannibalisation or irrelevant matches.

### Negative keyword management protocol

Specify:
- Review cadence (every 3-4 days for the first 30 days, weekly after that)
- Patterns to watch for (character names, "free" modifiers, competitor names)
- When to add competitor names as negatives vs creating a conquest campaign

---

## 6. Funnel Strategy (TOFU / MOFU / BOFU)

### Funnel overview

Describe the natural conversion funnel for this product. Identify the key conversion steps and typical drop-off points.

### TOFU — Awareness

**Goal:** [What awareness means for this product]
**Channels:** [Which campaigns serve TOFU]
**KPI:** [CTR, cost per landing page visit, video view rate]
**Content:** [Ad formats and creative themes — video demo, carousel, static image with specific descriptions]
**Landing page:** [Where TOFU traffic should land and what the page must communicate above the fold]

### MOFU — Consideration

**Goal:** [Move clickers to action]
**Channels:** [Which campaigns serve MOFU]
**KPI:** [sign_up conversion rate, cost per sign_up, or equivalent]
**Content:** [Retargeting creative, RSAs, dynamic remarketing]
**Audience:** [Site visitors who did not convert, within N days]

### BOFU — Conversion

**Goal:** [Convert high-intent prospects]
**Channels:** [Brand Search, Display Retargeting, Meta retargeting of registered users]
**KPI:** [Purchase conversion rate, CPA, ROAS]
**Content:** [Pricing-focused, urgency, social proof]
**Audience:** [Users who signed up but did not purchase, cart abandoners, pricing page visitors]

### Retargeting sequences

Define 2-3 retargeting sequences with day-based progression:

**Sequence 1: [Audience] ([duration])**
- Day 1-N: [Ad type and message]
- Day N-M: [Ad type and message]
- Day M-X: [Ad type and message]

State the retargeting budget ceiling (typically 20-25% of total budget for accounts with small audience pools).

---

## 7. Creative Testing Framework

### Testing principles

State the budget constraint on testing (at $X/day, how many simultaneous tests can run meaningfully). Define minimum runtime and what constitutes directional signal vs statistical significance at this spend level.

### Google Ads creative testing

**RSA angle matrix for [primary campaign]:**

| Angle | Headline example | Tests against |
|---|---|---|
| [angle name] | [example] | [which other angle] |

Provide 5-6 distinct angles. Specify the review cadence for Google's asset performance labels (Low/Good/Best) and the replacement protocol.

### Meta creative testing

Define a sequential test plan (one test at a time per channel):

**Test 1 (Weeks 1-4):** [Format test — e.g. Static vs Video]
- Versions, held variables, primary metric

**Test 2 (Weeks 5-8):** [Hook test — e.g. Benefit vs Curiosity]
- Versions, held variables, primary metric

**Test 3 (Weeks 9-12):** [Audience test — e.g. Detailed vs Advantage+]
- Versions, held variables, primary metric

**Test 4 (Month 3+):** [Segment-specific angle test]
- Versions, held variables, primary metric

### Creative production priority

Rank creative assets in production order (cheapest/fastest first):
1. [Asset type]: [quantity], [format], [estimated cost/effort]
2. [Asset type]: [quantity], [format], [estimated cost/effort]
3. [Asset type]: [quantity], [format], [estimated cost/effort]

---

## 8. Performance Benchmarks

### Benchmark context

State the industry category this product falls into for benchmarking purposes and cite the benchmark sources used (e.g. WordStream, uproas.io, internal data).

### Google Search benchmarks (expected ranges)

| Metric | Brand | [Campaign 2] | [Campaign 3] | [Campaign N] |
|---|---|---|---|---|
| CTR | [range] | [range] | [range] | [range] |
| Avg CPC | [range] | [range] | [range] | [range] |
| CVR (to [primary event]) | [range] | [range] | [range] | [range] |
| CPA ([primary event]) | [range] | [range] | [range] | [range] |

Explain that new accounts start at the high end of CPA and improve as Quality Score and conversion data accumulate.

**Quality Score note:** New accounts start at QS 6. Improving QS from 6 to 8 reduces CPC by approximately 16-25%. Identify the primary QS risk for this specific product (landing page speed, ad relevance, CTR).

### Meta benchmarks (expected ranges)

| Metric | [Segment 1] | [Segment 2] | [Segment N] |
|---|---|---|---|
| CPM | [range] | [range] | [range] |
| CTR (link click) | [range] | [range] | [range] |
| CPC (link) | [range] | [range] | [range] |
| CVR (to [event]) | [range] | [range] | [range] |
| CPA ([event]) | [range] | [range] | [range] |

### ROAS / LTV expectations

Calculate the expected first-purchase ROAS based on the pricing model and expected CPA. If first-purchase ROAS is below 1.0, explain the LTV-based business case:
- Repeat purchase rate assumptions
- Upsell potential
- Recommended LTV-based ROAS target and timeline to measure it (typically 60-90 days of repeat purchase data needed)

### Budget-level performance expectations

| Monthly budget | Expected [primary conversions]/month | Expected [secondary conversions]/month | Notes |
|---|---|---|---|
| [tier 1] | [range] | [range] | [Smart Bidding implications] |
| [tier 2] | [range] | [range] | [Smart Bidding implications] |
| [tier 3] | [range] | [range] | [Smart Bidding implications] |

---

## 9. Scaling Playbook

### Gate 1: Tracking verified (Week 1-2)

**Criteria:** [What must be true]
**Action:** [Which campaigns to launch and at what budget]
**Do not:** [What to avoid]

### Gate 2: First 30 conversions (Week 3-6)

**Criteria:** [Conversion threshold]
**Actions:** [Which campaigns/platforms to add, bidding changes, negative keyword audit]
**Do not:** [What to avoid — e.g. do not set tCPA yet]

### Gate 3: Smart Bidding steady state (Week 6-10)

**Criteria:** [50+ conversions/month, Learning Phase complete]
**Actions:** [tCPA introduction, PMax scaling, Lookalike Audiences, Display Retargeting launch]

### Gate 4: Efficiency at scale (Month 3+)

**Criteria:** [tCPA hitting within 20% of target, CPA below break-even threshold]
**Scaling levers in order of risk:**
1. Increase budget by 20-30% per week (not all at once)
2. Expand to broad match on top-performing ad groups
3. Launch Meta Lookalike Audiences
4. Add Microsoft Ads (import from Google)
5. Add [platform-specific expansion]
6. Tighten tCPA in 5% increments

### What NOT to launch yet

For each gate, explicitly state which campaigns, platforms, and audience segments should NOT be launched yet and why. This is as important as what to launch — premature expansion on a small budget dilutes spend below viable thresholds.

### Signals that mean do not scale yet

- Impression share lost to budget > 30% on any Search campaign
- Landing page bounce rate > 70% on mobile
- Free-to-paid conversion rate < [threshold based on product type]
- Smart Bidding still in Learning after 8 weeks

### Month-by-month milestones

| Month | Budget | Key actions | Success criteria |
|---|---|---|---|
| 1 | [amount] | [actions] | [criteria] |
| 2 | [amount] | [actions] | [criteria] |
| 3 | [amount] | [actions] | [criteria] |
| 4+ | [amount] | [actions] | [criteria] |

---

## Appendix: Account Setup Checklists

### Google Ads account settings

- [ ] Conversion tracking imported from GA4 (single source of truth)
- [ ] Enhanced conversions enabled
- [ ] Auto-tagging: ON
- [ ] Ad rotation: Optimise
- [ ] Location targeting: "Presence" only (not "Presence or interest")
- [ ] Search partners: OFF initially (enable after 60 days if CPA is on target)
- [ ] Display Network expansion: OFF on Search campaigns
- [ ] Audience observations: add relevant audiences in observation mode from day 1 (In-market segments, Custom intent from keyword list, Remarketing lists)

### Meta account settings

- [ ] Pixel verified with Events Manager Test Events
- [ ] Aggregated Event Measurement configured (iOS 14+ attribution)
- [ ] Event priority ranking set (Purchase > Registration > other events)
- [ ] Ad account spending limit set as monthly cap
- [ ] Payment threshold set to weekly billing initially
- [ ] UTM parameters configured on every ad URL

---

*Strategy generated: {{DATE}}. Revisit benchmarks and bidding thresholds at 30, 60, and 90 days post-launch.*
