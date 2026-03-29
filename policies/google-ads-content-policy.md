# Google Ads Content Policy Reference

**Last verified:** 2026-03-29
**Source:** support.google.com/adspolicy/answer/6008942, /6021546

This is a curated reference for LLM-powered ad copy and creative QA checks.
Not exhaustive — covers rules most likely to cause disapproval or account suspension.

---

## Prohibited Content

**RULE:** No counterfeit goods — ads must not promote products that mimic another brand's trademark, logo, or design to deceive.
**Consequence:** Account suspension
**Applies to:** all

**RULE:** No dangerous products or services — ads must not promote explosives, firearms (in most regions), recreational drugs, tobacco products, or drug paraphernalia.
**Consequence:** Ad disapproval; repeated = account suspension
**Applies to:** all

**RULE:** No enabling dishonest behaviour — ads must not promote hacking services, fake documents, academic cheating services, or click-fraud software.
**Consequence:** Account suspension
**Applies to:** all

**RULE:** No inappropriate content — ads must not promote content that is shocking, exploitative, promotes cruelty to animals, or bullies individuals.
**Consequence:** Ad disapproval
**Applies to:** all

## Prohibited Practices

**RULE:** No misrepresentation — ads must not make misleading claims about products, services, or business identity. No hiding material information. No "too good to be true" claims.
**Consequence:** Account suspension (misrepresentation is the #1 cause of permanent bans)
**Applies to:** all

**RULE:** No abusing the ad network — ads must not contain malware, cloaking (showing different content to Google vs users), or interfere with ad delivery systems.
**Consequence:** Account suspension
**Applies to:** all

**RULE:** No data collection without consent — landing pages collecting personal information must have a privacy policy and use SSL (HTTPS).
**Consequence:** Ad disapproval
**Applies to:** landing page

## Restricted Content

**RULE:** Alcohol ads must comply with local laws and not target minors. Must not portray excessive consumption or imply health benefits.
**Consequence:** Ad disapproval in restricted regions
**Applies to:** copy, image, video

**RULE:** Gambling ads require local licensing and certification. Online gambling restricted to approved operators in approved regions.
**Consequence:** Ad disapproval; unlicensed = account suspension
**Applies to:** all

**RULE:** Healthcare and pharmaceutical ads must comply with local regulations. Prescription drug ads restricted to licensed pharmacies in approved countries. No unapproved health claims.
**Consequence:** Ad disapproval
**Applies to:** copy

**RULE:** Financial services ads must include required disclosures (APR, fees, terms). No misleading profit claims. Cryptocurrency exchanges restricted to certified advertisers.
**Consequence:** Ad disapproval
**Applies to:** copy, landing page

**RULE:** Trademarks — ads using another brand's trademark in ad text may be restricted. Trademark owners can file complaints. Resellers/informational sites may qualify for exceptions.
**Consequence:** Ad disapproval
**Applies to:** copy

## Editorial Standards

**RULE:** No excessive or gimmicky capitalisation — ALL CAPS words are not allowed (except for common abbreviations like "USA", "AI", "SEO" and brand names that are naturally capitalised).
**Consequence:** Ad disapproval
**Applies to:** copy

**RULE:** No exclamation marks in headlines — Google Search ads reject exclamation marks in headline assets.
**Consequence:** Ad disapproval
**Applies to:** copy (headlines)

**RULE:** No repeated punctuation — "!!!", "???", "..." used for emphasis are not allowed.
**Consequence:** Ad disapproval
**Applies to:** copy

**RULE:** No gimmicky use of numbers, symbols, or superscripts — "F-R-E-E", "@home", "w1n" are not allowed.
**Consequence:** Ad disapproval
**Applies to:** copy

**RULE:** No unnecessary spacing or symbols — extra spaces ("B i g   S a l e"), bullet characters in headlines, or Unicode symbols used for decoration.
**Consequence:** Ad disapproval
**Applies to:** copy

**RULE:** Headlines max 30 characters, descriptions max 90 characters (including spaces). RSAs accept up to 15 headlines and 4 descriptions.
**Consequence:** API rejection
**Applies to:** copy

**RULE:** No unsubstantiated superlative claims — "best", "number one", "#1" require third-party verification on the landing page.
**Consequence:** Ad disapproval
**Applies to:** copy

**RULE:** Ad text must be grammatically correct and clearly written. Misspellings and random capitalisation trigger disapproval.
**Consequence:** Ad disapproval
**Applies to:** copy

## Visual Rules

**RULE:** No misleading interactive elements — images must not contain fake buttons, fake cursors, fake form fields, or fake notification badges that trick users into clicking.
**Consequence:** Ad disapproval
**Applies to:** image

**RULE:** No shocking or violent imagery — images must not contain graphic violence, accidents, surgical procedures, or content designed to shock.
**Consequence:** Ad disapproval
**Applies to:** image, video

**RULE:** Text-heavy images perform poorly in Display and PMax — Google recommends text covering no more than 20% of the image area. Not a hard reject, but severely penalised in auction.
**Consequence:** Reduced delivery (soft penalty)
**Applies to:** image

**RULE:** No before/after images without proper context — health, weight loss, and cosmetic procedure before/after comparisons require disclaimers and must not make unrealistic promises.
**Consequence:** Ad disapproval
**Applies to:** image

**RULE:** No strobing, flashing, or excessively animated elements in display ads.
**Consequence:** Ad disapproval
**Applies to:** image (animated), video

## Destination Requirements

**RULE:** Landing page must be functional — no broken pages, error messages, under construction, or parked domains.
**Consequence:** Ad disapproval
**Applies to:** landing page

**RULE:** Landing page must match ad content — the product or offer described in the ad must be clearly available on the landing page.
**Consequence:** Ad disapproval
**Applies to:** landing page

**RULE:** Landing page must not contain malware, unwanted software, or auto-downloads.
**Consequence:** Account suspension
**Applies to:** landing page

**RULE:** Landing page must use HTTPS if collecting personal information.
**Consequence:** Ad disapproval
**Applies to:** landing page

## Technical Requirements

### RSA Character Limits

| Field | Max Chars | Count |
|-------|-----------|-------|
| Headline | 30 | Min 3, max 15 |
| Description | 90 | Min 2, max 4 |
| Display URL path | 15 per path | Max 2 paths |

### Performance Max Text Assets

| Field | Max Chars | Count |
|-------|-----------|-------|
| Headline | 30 | Min 3, max 15 |
| Long headline | 90 | Min 1, max 5 |
| Description | 90 | Min 3, max 5 |
| Business name | 25 | 1 only |

Note: Double-width characters (Korean, Japanese, Chinese) count as 2.

### Image Assets

| Spec | Requirement |
|------|------------|
| Formats | JPEG, PNG |
| Max file size | 5 MB (PMax), 150 KB (Display) |
| Landscape (1.91:1) | Recommended 1200x628 px |
| Square (1:1) | Recommended 1200x1200 px |
| Portrait (4:5) | Recommended 960x1200 px |
| Text overlay | Max 20% of image area |
| Safe zone | Center 80% of frame |

### Video Assets

| Spec | Requirement |
|------|------------|
| Min duration | 10 seconds |
| Quality | HD minimum |
| Horizontal (16:9) | 1920x1080 px |
| Vertical (9:16) | 1080x1920 px |
| Square (1:1) | 1080x1080 px |

## Enforcement Escalation

1. **First violation:** Warning email. Ad disapproved.
2. **First strike:** Account hold for 3 days.
3. **Second strike:** Account hold for 7 days.
4. **Third strike (within 90 days):** Account suspension.

**Immediate suspension (no warning):** Malware, cloaking, counterfeit goods, phishing, coordinated deceptive practices.
