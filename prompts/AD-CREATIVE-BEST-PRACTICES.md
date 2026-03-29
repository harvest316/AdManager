# Ad Creative Best Practices Reference
## Meta (Facebook/Instagram) + Google Ads (YouTube, Display, PMax, Demand Gen)
### For AI-Generated Creative Pipelines (OpenRouter/Gemini + Kling + ffmpeg)

**Last updated:** 2026-03-28
**Scope:** Video ads, image ads, production pipeline, and Colormora-specific guidance

---

## Table of Contents

1. [Video Ads — Optimal Lengths by Placement](#1-video-ads--optimal-lengths-by-placement)
2. [Video Ads — Audio Requirements and Strategy](#2-video-ads--audio-requirements-and-strategy)
3. [Video Ads — Text Overlays and Safe Zones](#3-video-ads--text-overlays-and-safe-zones)
4. [Video Ads — Creative Structure](#4-video-ads--creative-structure)
5. [Image Ads — Dimensions and Aspect Ratios](#5-image-ads--dimensions-and-aspect-ratios)
6. [Image Ads — Text on Images](#6-image-ads--text-on-images)
7. [Image Ads — Style and Visual Direction](#7-image-ads--style-and-visual-direction)
8. [Production Pipeline — Minimum Viable Creative Sets](#8-production-pipeline--minimum-viable-creative-sets)
9. [Production Pipeline — Split Testing Variants](#9-production-pipeline--split-testing-variants)
10. [Production Pipeline — Priority Order](#10-production-pipeline--priority-order)
11. [Colormora — Creative Strategy](#11-colormora--creative-strategy)

---

## 1. Video Ads — Optimal Lengths by Placement

### Meta (Facebook/Instagram)

| Placement | Recommended Length | Hard Limit | Notes |
|-----------|-------------------|------------|-------|
| Feed (Facebook) | 15–30s | 240 min (technical) | Most users scroll past in 1–3s. Front-load value. 15s is the performance sweet spot for conversion campaigns. |
| Feed (Instagram) | 15s | 60s for most accounts | Instagram feed viewers are faster-moving than Facebook. 15s is the ceiling in practice. |
| Stories | 9–15s | 60s (but auto-cuts at 15s per card) | Stories over 15s split into multiple cards. Design for 15s as one complete unit. Vertical 9:16 only. |
| Reels | 7–15s | 90s | The algorithm favours videos that are watched to completion. 7–15s maximises completion rate. For complex creative, 30s is viable but completion rate drops ~40%. |
| In-Stream (pre/mid-roll) | 6–15s | No limit | These are unskippable or skippable pre-roll inside Facebook Watch / in-stream. 6s for awareness, 15s for conversion. |
| Audience Network | 15–30s | 120s | Lower quality placements. Match Feed specs and exclude if brand-safety is a concern. |

**Meta rule of thumb:** Design for 15s. If a concept cannot convey the full message in 15s, it is a scripting problem, not a duration problem.

### YouTube (Google)

| Placement | Recommended Length | Skip Rules | Notes |
|-----------|-------------------|------------|-------|
| In-stream Skippable | 15–30s (strong hooks); up to 3 min for remarketing | Skip available after 5s | The first 5s is the only guaranteed impression. Design for two audiences: the 5s viewer (hook only) and the 30s viewer (full story). For cold traffic: 15–30s. For warm/remarketing audiences: 60–90s story-format works. |
| Non-skippable In-stream | 15–20s (15s preferred) | Cannot skip | No escape hatch. Must be tight. 20s is the Google-published maximum for this format. |
| Bumper Ads | 6s | Cannot skip | Six seconds. No exceptions. Designed for frequency and brand recall, not conversion. Use as a companion to longer in-stream campaigns. Must work without sound (very common on mobile). |
| YouTube Shorts | 15–30s | Not applicable (organic-style feed) | Shorter performs better. Vertical 9:16. The first 1–2 frames must stop the scroll — same hook discipline as Reels. Paid Shorts ads appear between organic Shorts swipes. |
| YouTube Discovery (now Demand Gen) | Drive clicks to full video or landing page | N/A | These are thumbnail + headline ads in YouTube search results and homepage. The creative is a thumbnail image + title text, not an in-stream video. |

**YouTube rule of thumb:** The first 5 seconds determine whether you earn the next 10. Write the hook before anything else.

### Google Demand Gen

Demand Gen (formerly Discovery) serves on YouTube feed, Gmail Promotions, and Discover feed. Creative options are:
- **Single image** (treated as an image ad, not a video)
- **Video** — 15–60s, but 15–30s is optimal for Discover/YouTube feed placements where autoplay is muted

Demand Gen does NOT have in-stream video placement. Video here is card-style autoplay in a feed context, which means sound-off rules apply. Design video for Demand Gen identically to Meta Feed video.

### Google Performance Max (PMax)

PMax video asset requirements and platform placement:

| Asset type | Minimum | Recommended | Placement |
|-----------|---------|-------------|-----------|
| Landscape video (16:9) | 1 | 3–5 | YouTube in-stream, Display expandable |
| Portrait video (9:16) | 1 | 2–3 | YouTube Shorts, Discovery |
| Square video (1:1) | Optional | 1–2 | Display, Discover feed |

**Video lengths for PMax:**
- At least one video under 30s (Google auto-generates shorter cuts from long assets — but auto-generated cuts are poor quality; always supply your own at multiple lengths)
- At least one video 6–15s (used as bumper equivalent in PMax)
- PMax can run your video on YouTube in-stream — the same 5-second hook discipline applies

---

## 2. Video Ads — Audio Requirements and Strategy

### The Sound-Off Reality

| Platform | % watched without sound (industry estimates, 2024–2026) |
|----------|----------------------------------------------------------|
| Meta Feed (mobile) | 70–85% |
| Meta Stories | 40–60% (users often tap to unmute; immersive format) |
| Meta Reels | 50–65% |
| YouTube (desktop) | 15–25% (desktop users expect sound-on) |
| YouTube (mobile) | 40–55% |
| YouTube Shorts | 50–65% |
| Google Display | N/A (static image; video display is mostly muted autoplay) |
| Google Demand Gen | 65–75% (feed context, mobile) |

**Conclusion:** Design every ad to work completely without sound. Then layer audio to reward viewers who have it on.

### Sound-Off Compliance Checklist

- Captions or on-screen text convey the full message
- Visual story is self-contained (viewer understands what the product is and what to do)
- Key benefit is visible as text, not just spoken
- CTA is visible on screen (not just in voiceover)

### Should You Generate Voiceover?

**Yes — but as enhancement, not as information delivery.** If a viewer only gets the message by hearing the voiceover, the ad is broken.

Reasons to include voiceover:
- Increases engagement time and completion rates for sound-on viewers (estimated +15–25% watch time)
- Creates emotional resonance that text alone cannot (tone, pacing, warmth)
- Helps YouTube in-stream performance where more viewers expect audio
- Improves accessibility (plus required for some regulated verticals)

Reasons voiceover sometimes hurts:
- Generic AI voiceover (robotic, flat) actively signals "this is an ad" to Meta algorithm and users — triggers scroll
- Mismatched tone (too formal for a casual product) reduces trust

**Recommendation for an automated pipeline:**
- Generate voiceover via ElevenLabs for all videos
- Use a conversational, warm voice (not broadcast presenter style) — the "mum talking to camera" cadence outperforms corporate narration in consumer products
- Treat voiceover as confirmation of what the screen shows, not as new information
- Keep sentences short — 6–9 words per sentence in the VO script

### AI Voiceover Tool Comparison

| Tool | Strengths | Weaknesses | Best for |
|------|-----------|------------|----------|
| ElevenLabs | Most natural prosody; cloning available; good emotional range | Cost scales with volume; 11-char min | Primary recommendation for any consumer product |
| PlayHT | Large voice library; strong narration voices | Less natural on conversational styles | Long-form explainer voiceovers |
| Murf | Good UI; team collaboration; natural enough | Mid-tier naturalness vs ElevenLabs | Quick batch production when ElevenLabs budget is tight |
| Google Text-to-Speech (Neural2/Chirp) | Free at scale; API-native | Still detectable as synthetic on careful listen | Utility scripts, B-roll narration, not hero creative |
| OpenAI TTS | Good quality; API-simple; natural cadence | Limited voice variety; no prosody control | Fast pipeline integration where variety is less important |

**Pipeline recommendation:** ElevenLabs as primary. Cache generated audio — do not regenerate the same VO script twice. At 100K credits/month (current quota), this is ample for creative generation volume.

### Music

- Background music increases emotional engagement and watch time
- Meta autoplay ads with recognisable music (licensed or trending audio) get boosted organic distribution in some placements — this does not apply to paid ads but the engagement signal matters
- Use royalty-free music (Epidemic Sound, Artlist, or YouTube Audio Library for Google placements)
- Music volume should sit 20–30% below voiceover in the mix — it should be felt, not heard
- For ffmpeg, use `-filter_complex amix` or `volume` filter to duck music under VO

---

## 3. Video Ads — Text Overlays and Safe Zones

### Caption/Subtitle Strategy

**Always add captions to video ads.** This is not optional for Meta. For Google video it is strongly recommended.

On Meta specifically: Meta's own internal data shows captioned video ads increase view time by an average of 12% and improve conversion rate in sound-off environments. The gap between captioned and uncaptioned is widest on mobile feed.

**Implementation options for ffmpeg pipeline:**
- Option A: Burn captions in at render time using `subtitles` filter or `drawtext` with timed sequences — hard-coded, no viewer control, most reliable
- Option B: Supply `.srt` file as a separate upload — YouTube accepts SRT directly; Meta can use it for accessibility but it is separate from the burned-in caption
- Recommendation: Burn captions in for Meta and Display. Supply SRT separately for YouTube (keeps captions optionally dismissible, which is the platform expectation)

**Caption formatting:**
- Font: bold sans-serif (e.g. Montserrat Bold, Poppins Bold, or Impact for high visibility)
- Size: approximately 5–7% of frame height (for 1080x1920, this is ~54–75px; for 1920x1080, ~54–75px)
- Position: lower third, with at minimum 80px clearance from the bottom edge (safe zone for UI chrome)
- Background: semi-transparent pill/rectangle behind text (black at ~65% opacity) — dramatically improves legibility over variable backgrounds
- Maximum 2 lines per caption segment; maximum ~32 characters per line
- Sync timing: 0.1s delay after spoken word before displaying is less visually jarring than perfectly locked sync

**ffmpeg drawtext caption pattern:**
```
drawtext=fontfile=/path/to/font.ttf:text='<caption text>':fontcolor=white:fontsize=60:x=(w-text_w)/2:y=h-120:box=1:boxcolor=black@0.6:boxborderw=8
```
For multi-cue captions, use the `subtitles` filter with an SRT file — it handles timing automatically and is far more maintainable than inline drawtext for anything over 3 cues.

### Headline/Benefit Overlays

These are distinct from captions. A headline overlay is a designed persistent text element (e.g. "Create a coloring book in 60 seconds"), not a caption of spoken dialogue.

**When to use headline overlays:**
- First 3s hook (where voiceover may not have started) — use large overlay to communicate the hook without audio
- When showing a product feature on screen that needs labelling
- End card — product name + CTA

**Where NOT to use headline overlays:**
- Over the subject's face (if using UGC or talent creative)
- In the bottom 15% of a 9:16 video (covered by Instagram/TikTok/Reels UI)
- In the top 8% of a 9:16 video (covered by profile name UI)

### CTA Text Placement

The call-to-action text should appear in the **final 3–5 seconds** of every video (the "end card"). It should also appear at the **first-opportunity hook** if the hook is a direct-response approach.

CTA positioning rules:
- Vertical (9:16): center horizontally; place in the middle zone (30%–70% of vertical height) — this avoids all platform UI chrome
- Landscape (16:9): lower third is conventional; avoid bottom 10% for YouTube (overlaid by ad UI components like the CTA button and companion banner)
- The CTA button overlay that platforms auto-add (YouTube's yellow CTA button; Meta's "Learn More" button) sits below or over your creative. Do not design your in-video CTA in the same position as the platform's auto-generated button — it creates visual noise

### Platform-Specific Safe Zones

#### Meta (9:16 Stories and Reels)
| Zone | Pixels (1080x1920) | Restriction |
|------|--------------------|-------------|
| Top | 0–250px from top | Profile picture, username, ad label — keep clear |
| Bottom | 1670–1920px from top | Interaction icons (like, comment, share), "Sponsored" label — keep clear |
| Left/Right | 0–65px from each edge | Tap zones, edge bleeds — keep text content inward |
| Safe content zone | 250px to 1670px vertically, 65px to 1015px horizontally | All text, faces, key visuals must be inside this zone |

#### YouTube (16:9 In-stream)
| Zone | Pixels (1920x1080) | Restriction |
|------|--------------------|-------------|
| Bottom | 980–1080px from top | Ad unit UI (skip button, CTA overlay, companion ad label) |
| Top-left | 0–250px from top, 0–200px from left | YouTube logo and ad badge on some placements |
| Recommended text zone | 50px to 980px vertically | Keep all burned-in text within this range |

#### YouTube Shorts (9:16)
Same safe zones as Meta Reels — the layouts are nearly identical. Use the Meta 9:16 safe zone spec.

### Meta's 20% Text Rule — Current Status

**The 20% text rule on images was officially retired by Meta in 2021.** Ads are no longer rejected for exceeding the grid-based 20% threshold. However, this does not mean text-heavy images perform equally well.

What has replaced the hard rule:
- Meta's delivery algorithm uses relevance and quality signals. High text coverage on an image can lower the system's engagement score (particularly if the text replaces visual storytelling that could drive higher click-through)
- The practical guideline is: use text on images to label, not to explain. If you need a paragraph of text to explain the product, use a video or a carousel instead
- For video text overlays, there is no text percentage rule — this was always image-specific

---

## 4. Video Ads — Creative Structure

### The Hook (0–3 seconds)

The hook is the most important creative decision in any video ad. Every other second of the video is earned by the hook.

**The hook must do one of the following:**
1. State an unexpected or specific benefit: "You can make a personalised coloring book for your kids in 60 seconds"
2. Pose a relatable problem: "Can't find a coloring book your kid will actually sit with?"
3. Show a striking visual outcome before anything is explained (pattern interrupt)
4. Start with social proof: "50,000 mums have already made one for their kids"

**What kills hooks:**
- Logo first (nobody cares about your logo in the first 3 seconds)
- Ambient B-roll with no context (looks like stock footage, gets scrolled)
- Slow fade-in or any transition that delays content
- Starting with music alone and no visual anchor

**Technical hook requirements:**
- Movement in the first frame — the algorithm on both Meta and YouTube rewards videos that trigger a visual hold reflex. A static first frame in a scroll environment looks like an image and is processed as low-priority by the eye
- For Kling-generated video: ensure motion starts at frame 1, not after a 1–2s establishing shot. Either set the motion prompt to start with action or trim the first frames in ffmpeg

### Standard Creative Frameworks

#### Problem → Agitation → Solution (PAS) — Best for cold traffic
```
0–3s:   Problem shown or stated (hook)
3–8s:   Why this problem is painful / relatable (agitation)
8–18s:  Product introduced as the solution (demo or outcome)
18–25s: Proof (result, testimonial, stat)
25–30s: CTA
```

#### Demo-First — Best for products where the output is visual
```
0–3s:   Show the finished product / output (hook is the outcome)
3–10s:  Show the creation process (how easy it is)
10–18s: Show multiple examples / outputs (breadth of what's possible)
18–25s: State the offer (free to start, no credit card, etc.)
25–30s: CTA
```

#### UGC/Testimonial — Best for social proof and trust building
```
0–3s:   Person on camera states result or opinion directly ("I was not expecting it to be this good")
3–12s:  Story — what they were trying to do, what they tried before
12–22s: Specific outcome / product shown
22–28s: Endorsement + CTA mention
28–30s: CTA overlay
```

### Polished vs UGC Style

| Style | When it wins | When it loses |
|-------|-------------|---------------|
| Polished (brand visual, designed overlays, colour-graded) | Brand awareness, YouTube pre-roll (higher-production expectation), premium product positioning | On Reels/TikTok-style placements — looks like an ad, gets scrolled faster |
| UGC (phone-shot feel, natural lighting, person-to-camera) | Meta Feed, Reels, Stories — blends with organic content; higher trust signals; lower "ad detected" reflex | YouTube pre-roll where sudden quality drop signals low production value; PMax display where context is editorial |
| AI-generated hybrid (Kling video + text overlay + VO) | Scalable; no talent costs; fast iteration | Faces look uncanny; product interaction looks artificial; risk of appearing inauthentic if people notice |

**For AI video pipelines using Kling:** The uncanny valley problem with AI-generated people is real and measurable. Prefer:
- Product-focused shots (the coloring book pages, the interface, the output) rather than AI-generated people
- Flat-lay or top-down shots of physical coloring pages — these look completely natural when AI-generated
- Motion graphics and text animations instead of synthetic people
- Real footage of children coloring, licensed from stock (Pond5, Storyblocks), edited together with AI-generated product shots

### End Card Requirements

Every video needs a designed final frame (last 2–5s) that contains:
- Product name or logo
- Core CTA text (e.g. "Start free — no credit card")
- URL or "Search colorcraft-ai.com"
- If YouTube: this is where YouTube's end screen elements go — design the end card with 390x110px blank zones in the bottom-right and bottom-left for the subscribe button and video recommendation cards

---

## 5. Image Ads — Dimensions and Aspect Ratios

### Meta

| Placement | Recommended dimensions | Aspect ratio | Min resolution | Notes |
|-----------|----------------------|--------------|----------------|-------|
| Feed (square) | 1080x1080 | 1:1 | 600x600 | Most versatile single-image size; works across Facebook and Instagram feed |
| Feed (landscape) | 1200x628 | 1.91:1 | 600x314 | Link ads / traffic campaigns; Facebook favours this for desktop feed |
| Feed (portrait) | 1080x1350 | 4:5 | 600x750 | Takes up more vertical feed space; higher CTR than 1:1 in many tests |
| Stories | 1080x1920 | 9:16 | 600x1067 | Full screen; safe zone caution required |
| Reels thumbnail | 1080x1920 | 9:16 | Same as Stories | Must have visual impact as a still — used as the Reels ad thumbnail |
| Carousel card | 1080x1080 | 1:1 | 600x600 | Per-card; all cards must be same aspect ratio |
| Carousel card (Stories) | 1080x1920 | 9:16 | 600x1067 | Vertical carousel for Stories placement |
| Collection hero | 1200x628 or 1080x1080 | 1.91:1 or 1:1 | — | Top image in a collection ad; rest auto-pulled from product catalogue |

**File specs:**
- Format: JPG or PNG (PNG preferred for images with text — sharper rendering)
- Max file size: 30MB (practical target: under 1MB for fast loading, under 5MB for complex AI-generated images)
- Colour space: sRGB (Meta does not correctly display AdobeRGB or P3 — colours shift)

### Google Display Network

| Ad size | Dimensions | Notes |
|---------|-----------|-------|
| Medium Rectangle | 300x250 | Single highest-volume placement; if you only make one display size, make this one |
| Large Rectangle | 336x280 | Close second; interchangeable with 300x250 in most contexts |
| Leaderboard | 728x90 | Desktop top-of-page; thin format — headline must be very short |
| Half Page | 300x600 | High visibility; premium inventory; often the best performer after 300x250 |
| Large Mobile Banner | 320x100 | Mobile web; better engagement than 320x50 |
| Mobile Banner | 320x50 | Very thin; minimal creative real estate — logo + CTA only |
| Billboard | 970x250 | Premium desktop placements; less common inventory |
| Square | 250x250 | Lower inventory; lower priority |

**For Responsive Display Ads (RDA) — the Google-preferred format:**
Upload assets in the responsive format and Google assembles the ad. Required assets:
- Landscape image: 1200x628 (minimum), 1.91:1
- Square image: 1200x1200 (minimum), 1:1
- Logo: 1200x1200 square, 1200x300 landscape (PNG with transparency preferred)
- Short headline: 30 chars
- Long headline: 90 chars
- Description: 90 chars

### Performance Max (PMax) Image Assets

| Asset | Dimensions | Aspect ratio | Required |
|-------|-----------|--------------|---------|
| Landscape image | 1200x628 min; 1200x628 preferred | 1.91:1 | Yes |
| Square image | 1200x1200 min | 1:1 | Yes |
| Portrait image | 960x1200 min; 1200x1500 preferred | 4:5 | Recommended |
| Logo (square) | 128x128 min; 1200x1200 preferred | 1:1 | Yes |
| Logo (landscape) | 512x128 min; 1200x300 preferred | 4:1 | Recommended |

Maximum 20 images per asset group. Minimum 2–3 images is required to launch but provides almost no variation. Target 8–12 images across different themes and visual styles per asset group.

### Demand Gen Image Assets

Same specs as PMax. Demand Gen additionally supports:
- Portrait (9:16) for Discover and Gmail placements
- Carousel format (individual card images at 1:1 or 1.91:1)

---

## 6. Image Ads — Text on Images

### How Much Text Is Too Much

The old Meta 20% grid rule is gone but the principle survives: **text on an image should label or highlight, not explain.** The most common error is treating an image ad as a poster and writing 30+ words on it.

**Guidelines by text volume:**

| Text amount | Effect | Use case |
|-------------|--------|----------|
| Zero text (pure image) | Highest visual impact; lowest clarity; relies on caption/headline for message | Brand awareness, visually self-explanatory products |
| 1–5 words | High impact; clear focal point; works at small sizes | Best practice for most conversion ads |
| 6–15 words | Moderate — starts competing with the visual; works if text is designed well | Product benefit callouts, pricing statements |
| 16–30 words | Low — image becomes busy; text becomes unreadable at ad sizes below 300px | Avoid in most placements |
| 30+ words | Fails at most placements; renders unreadable on mobile; signals "low production quality" | Never |

### Headline and CTA Overlay Positioning

**Rule:** Text goes where the eye lands first OR where the eye exits. Avoid placing text in the middle of the main subject.

Common positions:
- Top third: good for hook statements and product names — eye enters here
- Bottom third: good for CTA and price — eye exits here; this is the last thing seen before clicking
- Left-aligned at 10% from left edge: natural reading entry point in Western markets
- Centre-aligned with visual padding: works for product-centric images where the subject is in the middle

**Contrast requirements:**
- Text must have at least 4.5:1 contrast ratio against its background (WCAG AA standard — also the practical readability threshold for small ad formats)
- When background is variable (lifestyle photography): always use a text shadow, a pill background, or a gradient overlay — never place bare white or black text directly on a photographic background and hope for the best
- For AI-generated images: generate the image with intentional negative space where the text will go, rather than trying to overlay text on busy areas after the fact

### Do Image Text Overlays Perform Better or Worse?

This is context-dependent, but the research consensus as of 2026:

- For **search intent audiences** (Google Display retargeting, PMax): minimal-text images with strong CTAs in the headline/description fields outperform text-heavy images — the ad platform headline is doing the job
- For **cold traffic interruption** (Meta Feed cold audience): images with a single bold benefit statement ("Make a coloring book in 60 seconds") often outperform clean images because the visual must tell the story without relying on the user to read the caption
- For **UGC-style content**: text overlays that mimic organic social (bold subtitle-style text in the centre of a vertical video frame) perform well because they match the format vocabulary of the platform
- For **product-demo images** (showing the UI or output): no text overlay needed on the product screenshot — the product speaks for itself; add text in the surrounding design

---

## 7. Image Ads — Style and Visual Direction

### Lifestyle vs Product vs UGC

| Style | Performance context | AI pipeline viability |
|-------|--------------------|-----------------------|
| Lifestyle (people using product in natural setting) | Highest engagement for consumer products — people see themselves in the ad | Moderate — AI-generated people suffer uncanny valley; licensed stock is more reliable |
| Product shot (clean product against minimal background) | High CTR for intent-based audiences; works well in Shopping and Display | High — AI image generation excels at product renders and flat-lay compositions |
| UGC-style (phone-quality feel, imperfect, authentic) | Top performer for cold Meta audiences; trust signal | Low for pure AI — AI cannot fake authenticity convincingly; use real UGC or create hybrid |
| Screenshot / UI mockup | High for SaaS and digital tools — shows exactly what the product does | Very high — screenshot compositing is reliable and precise |
| Illustrated / graphic design | Brand awareness; high shareability; platform depends | High — AI image generation excels at illustration styles |

**For Colormora (digital tool + physical output):** The most effective image types are:
1. Before/after split: the prompt text → the generated coloring page
2. Flat-lay lifestyle: a printed coloring page on a table, with colouring pencils or crayons in frame
3. Output gallery: grid of 4–6 generated coloring pages showing variety
4. Physical book mockup: the coloring pages rendered inside a printed book mockup

All four of these are achievable with AI generation (Gemini/DALL-E for the coloring page outputs, then composited with stock flat-lay backgrounds or 3D book mockups).

### Stock Photo vs AI-Generated

This question is more nuanced than it appears:

**AI-generated wins:**
- Speed (generate 20 variants in the time it takes to license 3 stock photos)
- Customisation (exact product-specific composition; no irrelevant stock-photo context)
- Cost at scale (per-image cost approaches zero at volume)
- Consistency (same visual language across all assets)

**Stock wins:**
- Human authenticity — actual human faces in actual situations; no uncanny valley
- Speed when you need a very specific real-world scenario
- Safety — licensed photos have clear IP status

**Practical recommendation:** Use AI generation for:
- Product shots, UI screenshots, coloring page outputs, illustrated backgrounds, abstract compositions
- Any image where the subject is not a human face

Use licensed stock for:
- Images showing people (parent with child, teacher in classroom)
- UGC-style testimonial-feel images
- Situations requiring genuine emotional authenticity

### Colour and Contrast for Feed Visibility

Feed environments are noisy. The single most actionable thing you can do for an image ad is ensure it has a distinct visual signature versus the organic content around it.

**Tactics:**
- Avoid beige, grey, and muted earth tones as the primary background — these are the default tones of most organic photos and cause ads to blend in
- High-saturation backgrounds (bright yellow, cobalt blue, coral) stop the scroll in feed contexts — test against more neutral treatments; results vary by audience
- White backgrounds work extremely well for product shots on Google Display (clean, high-contrast, professional) but can disappear in a Meta feed
- The most important contrast is between the main subject and the background — a coloring page on a white background on a white platform background is invisible; a coloring page on a deep teal or navy background is immediately visible

---

## 8. Production Pipeline — Minimum Viable Creative Sets

### Meta Ads — Minimum to Launch

| Asset type | Count | Placements |
|-----------|-------|------------|
| Square image (1080x1080) | 3 variants | Feed |
| Portrait image (1080x1350) | 2 variants | Feed |
| Vertical video 9:16, 15s | 2 variants | Stories, Reels |
| Square video 1:1, 15s | 1 variant | Feed video |

Total: 3 images + 3 videos = minimum viable launch. This supports one campaign with meaningful creative rotation.

**To enable Advantage+ creative (Meta's RSA equivalent for creative):** Upload all the above; Meta will mix and match across placements automatically. This is the recommended approach — do not over-constrain placements manually.

### Google Ads — Minimum to Launch

#### Search (RSA)
- 15 headlines, 4 descriptions per RSA (see COPY.md for RSA strategy)
- Minimum 2 RSAs per ad group to enable ad rotation testing
- At least 1 RSA rated "Good" or "Excellent" by Google's ad strength tool before launching

#### Display (Responsive Display Ads)
- 5 landscape images (1.91:1)
- 5 square images (1:1)
- 1 logo (square)
- 5 headlines (30 chars each)
- 1 long headline (90 chars)
- 5 descriptions (90 chars each)

#### Performance Max (per asset group)
- 3–5 images (mix of landscape, square, portrait)
- 1–3 videos (at minimum one; two or more is strongly recommended — Google auto-generates video from images if none supplied, and auto-generated video is terrible)
- 5 headlines (30 chars)
- 5 long headlines (90 chars)
- 5 descriptions (90 chars)
- 1 business name
- 1 final URL
- Audience signal (1st-party list or interest-based)

**Critical note on PMax video:** If you do not supply videos, Google will auto-generate them from your images using basic Ken Burns-style animation and auto-selected music. These auto-generated videos perform significantly worse than even a basic human-produced video. Always supply at least one video per asset group before launching PMax.

#### YouTube Video Campaign
- Minimum: 1 hero in-stream video (15–30s) + 1 bumper (6s)
- Recommended: 2–3 in-stream video variants for creative testing from day 1

### Demand Gen — Minimum to Launch
- Same image specs as PMax (landscape + square)
- 1 video if running video Discovery placements
- At least 3 headline variants and 3 description variants

---

## 9. Production Pipeline — Split Testing Variants

### How Many Variants Per Test

**The testing principle:** Every new ad group should launch with at least 2 ad variants. Running a single creative with no alternative is not testable and shows algorithm bias toward any single approach.

| Platform | Minimum variants | Recommended variants | Notes |
|----------|-----------------|---------------------|-------|
| Meta (per ad set) | 2 | 3–4 | Meta's Advantage+ will rotate automatically; more than 6 per ad set dilutes data |
| Google RSA (per ad group) | 2 RSAs | 3 RSAs | Google rotates and reports asset-level signals, not full-ad signals |
| YouTube in-stream | 2 | 3 | One should be your hook-variant test (different first 5s) |
| PMax (per asset group) | Implicit — upload 5+ assets per slot | 10–15 total assets | Asset-level performance reporting shows winning headlines, images, videos |
| Google Display (RDA) | 5 images per RDA | 10 images per RDA | More assets = more combinations = more signal |

### What to Test First

Priority order for hypothesis value:

1. **Creative angle (the biggest lever):** Problem-focused vs outcome-focused vs social-proof-focused. Different angles speak to different psychological states. Test this before anything else.
2. **Hook (first 3s for video; headline for static):** A/B the same body with two different hooks. This isolates the single highest-impact creative decision.
3. **Format:** Video vs image vs carousel. These have meaningfully different CPCs, completion patterns, and conversion rates. Know which format wins for your specific offer before scaling.
4. **Offer framing:** "Free to start" vs "Try free — no credit card" vs "Generate your first coloring book free" — identical offer, different conversion rates.
5. **Visual style:** Polished vs UGC-feel. Lower priority than angle and hook, but important for Meta Feed.
6. **CTA copy:** "Start free" vs "Try it free" vs "Create now" — low effect size but easy to test.

### Statistical Significance and Sample Sizes

Do not declare winners on insufficient data. The minimum thresholds:

| Metric | Minimum sample for significance | Notes |
|--------|--------------------------------|-------|
| CTR winner | 1,000 impressions per variant | Low bar — CTR is a simple proportion |
| Conversion rate winner | 30–50 conversions per variant | The 95% confidence interval is wide below 30 conversions |
| CPA winner | 50+ conversions per variant; ideally 100+ | CPA is a ratio of two variables; needs more data |
| ROAS winner | 50–100 purchases per variant | Revenue variance adds noise |

**Practical reality for small budgets:** At $10–$20/day on Meta or Google, reaching 30 conversions per variant will take 2–4 weeks. Do not pause tests early because one variant looks ahead at 10 conversions — the current leader at small n is often not the leader at 50 conversions.

### Creative Fatigue Monitoring

Signs a creative is fatiguing (applicable to both Meta and Google video):

| Signal | Threshold to investigate | Action |
|--------|-------------------------|--------|
| CTR declining over 7-day rolling window | >20% decline from peak CTR | Refresh hook or swap creative |
| Frequency (Meta) | >3.5 average frequency in a 7-day window | Reduce budget or expand audience, add new creatives |
| CPM increasing without explanation | >30% increase over 2-week period | Audience saturation; new creative and/or lookalike expansion |
| View-through rate (YouTube) declining | >25% drop from first-2-weeks baseline | Hook no longer working; test new hooks before full creative rebuild |

---

## 10. Production Pipeline — Priority Order

This sequence maximises learning speed and minimises wasted production time.

### Phase 1 — Foundational Assets (Week 1)

Build these first because they feed all platforms and generate the initial data.

1. **3 square images (1080x1080)** — AI-generated, 3 different creative angles. These deploy to Meta Feed, Google Display, and PMax immediately.
2. **1 landscape image (1200x628)** — For Google RDA, Demand Gen, and PMax landscape requirement. Adapt from the winning square image.
3. **1 portrait image (1080x1350 or 1080x1920)** — Meta Feed portrait (more screen real estate than square).
4. **RSA copy** — 15 headlines + 4 descriptions per ad group (no image needed; copy-only). Launch Search campaigns while visual assets are in production.

### Phase 2 — Video Assets (Week 1–2)

5. **1 x 15s video (9:16 vertical)** — Hook + demo + CTA. This single video unlocks Meta Stories, Reels, YouTube Shorts, and PMax. Highest leverage single asset.
6. **1 x 15–30s video (16:9 landscape)** — For YouTube in-stream. Adapt from the vertical video if the composition allows, or produce separately with a horizontal-optimised composition.
7. **1 x 6s bumper (16:9)** — Extract the strongest 6s from the landscape video. Bumpers run as frequency-builders on YouTube alongside in-stream campaigns.

### Phase 3 — Expansion (Week 2–4)

8. **2 additional image variants** — Testing different visual styles (lifestyle vs product vs UI screenshot).
9. **Second video variant** — Different hook or creative angle. Now you have a genuine A/B test on video.
10. **Carousel ad (Meta)** — 5 cards showing different coloring book styles or use cases. Carousels often win for products with multiple outputs because each card can address a different buyer motivation.

### Phase 4 — Creative Refresh (Week 4+)

Run the fatigue monitoring signals from Section 9. Rebuild whichever assets are declining. By this point you have enough conversion data to brief more targeted creative (the ads know which angle converts).

---

## 11. Colormora — Creative Strategy

### Primary Audience Profile

**Core buyer: Mum, 28–45, with children aged 3–12.** Psychographic notes:
- Values activities that are screen-free but the child will actually engage with
- Frequently frustrated by generic coloring books that the child ignores after 5 minutes
- Willing to pay a small premium for something personalised ("their unicorn, not A unicorn")
- Discovery happens on Instagram, Pinterest, and Google ("personalised coloring book for kids")
- Time-poor: any friction in the creation process is a drop-off risk
- Buys for gifting occasions as much as for everyday use (birthdays, Christmas, stocking fillers)

**Secondary buyer: Etsy seller / KDP creator.** Very different psychology:
- Calculating ROI (time to generate vs revenue per book)
- Wants bulk output and commercial rights
- Discovery on YouTube ("how to make AI coloring book to sell") and Google ("AI coloring book generator for Etsy")
- This audience should receive completely different creative — benefit-led messaging about volume and commercial use, not the mum-and-kid emotional angle

Separate these two audiences into separate ad sets/campaigns. Do not combine them.

### Creative Angles — Ranked by Projected Performance

These are ordered by estimated resonance with the primary (mum) audience, drawing from the paid media strategy and market research for Colormora.

**Angle 1: Personalisation ("theirs, not generic")**
Hook: "She ignored every coloring book — until I made one with her favourite things"
Body: Shows the process of entering a child's name, favourite character, interests → the AI generates pages featuring those things → child excitedly coloring
Why it works: Emotional specificity. The parent immediately maps this to their own child. The product benefit (personalisation) is shown, not just stated.

**Angle 2: Speed and ease ("60 seconds")**
Hook: "I made my son a 20-page coloring book in 60 seconds"
Body: Screen recording or demo-style walkthrough — type a prompt, pages appear, download, print
Why it works: Overcomes the implicit objection "this sounds complicated." Speed is a strong hook in the parenting space where time is scarce.

**Angle 3: Gift angle ("the gift they actually wanted")**
Hook: "The most thoughtful gift I've given in years cost me $3"
Body: Finished printed coloring book as a birthday gift; child's reaction; show how it was made
Why it works: Lowers the "is this worth paying for" objection by reframing cost against a gift purchase context (people are already spending money on gifts; $3 in credits is trivial framing).

**Angle 4: Creator/business angle (for Etsy secondary audience)**
Hook: "I'm making $2,000/month selling AI coloring books on Etsy"
Body: Shows the workflow — bulk generation, KDP export, Etsy listing, sales screenshot
Why it works: Pure ROI; the social proof stat does the work. Designed for secondary audience only.

**Angle 5: Problem-focused ("nothing holds their attention")**
Hook: "My 6-year-old's attention span is 4 minutes. This coloring book is the only thing that lasts longer."
Body: Generic coloring books discarded → Colormora → child engaged for 30+ minutes
Why it works: Relatable frustration. Strong problem statement drives interest from parents who have experienced this exact scenario.

### UGC vs Polished — Recommendation

**Primary recommendation: UGC-style for Meta cold traffic.**

The mum audience is extremely well-practiced at detecting traditional advertising in their feed. Polished product ads signal "this is a brand trying to sell me something." UGC-style content ("here's what I made for my kid last week") signals peer recommendation.

This is a challenge for an AI-generated video pipeline because genuine UGC is difficult to fake convincingly. Options:

1. **Hybrid approach (recommended):** Record one genuine testimonial video from a real user (even a beta tester or friend). Use this as the "hero" UGC creative. Supplement with AI-generated demo/product shots for scale. The genuine UGC creative will outperform AI-generated UGC on Meta cold traffic.

2. **Demo-first AI creative:** Avoid AI-generated faces altogether. Use screen recording of the product UI, flat-lay photography of printed pages, animated product walkthroughs. This style reads as "look at what this product does" rather than trying to fake authenticity. It performs well when the product output is visually compelling — and coloring pages are visually compelling.

3. **AI character video (Kling):** Use Kling-generated video for product demonstrations (the app generating pages, pages printing, etc.) rather than for emotional scenes involving children or parents. AI-generated children are a specific trust risk — the uncanny valley effect is strongest with children because parents are hyperattuned to the look and feel of children's faces and body language.

**For YouTube in-stream:** Polished demo-first creative performs well because YouTube pre-roll viewers expect a higher production standard than Meta feed. A clean screen recording with good VO and text overlays is entirely appropriate.

**For Google Display and PMax:** Polished product images with minimal text. The placement context (news sites, apps, YouTube pre-roll) favours more restrained, professional creative than the social feed environment.

### Coloring-Page Creative Execution

The product output (the generated coloring pages) is genuinely beautiful and visually distinctive. This is a significant creative advantage.

**Image ad strategy:** Use the product output AS the creative. A grid of 4 high-quality AI-generated coloring pages on a clean background is immediately eye-catching in a feed, signals clearly what the product does, and invites the viewer to imagine their own variant.

**Prompt guidance for generating showcase coloring pages (for use in ads):**
- Request thick, clean outlines (coloring book illustration style requires strong contrast between outline and fill area)
- Avoid photorealistic rendering styles — coloring books are specifically the abstracted/outlined version of subjects
- Subject matter for mum/child audience: unicorns, dinosaurs, fairies, space themes, animals, princess characters (generic enough to not infringe character IP)
- Subject matter for the ad itself: choose 4–6 pages that show variety — mix of detailed (for older children) and simple (for toddlers) — this demonstrates the product's range
- High contrast black-and-white output (no grey shading) to show how the pages would look before coloring

### Specific Messaging Do's and Don'ts

**Do:**
- Lead with the emotional outcome (the child's excitement, the personalised detail) before the product mechanics
- Show actual generated outputs — the pages are the product; show them
- Use specific numbers: "60 seconds", "20 pages", "$3 pack" — specificity converts better than vague claims
- Reference print fulfillment as a feature for the gift angle — "delivered to your door" is a high-value proof point
- Mention "no design skills needed" — removes the competency objection

**Do not:**
- Use "AI" as a primary headline benefit for the mum audience — the mum audience is not buying AI technology, they are buying a personalised coloring book. AI is the how, not the why.
- Lead with pricing — the free tier and low entry cost should be a secondary call to action ("start free") not the hook, because it signals "cheap" rather than "valuable"
- Show AI generation "loading" states or error screens in creative — it exists but including it signals unreliability
- Use stock photography of smiling families that looks like a life insurance ad — the category is playful; the creative should feel playful

### Minimum Creative Set for Colormora Launch

| Asset | Description | Priority |
|-------|-------------|----------|
| Image A: Gallery grid | 4-panel grid of generated coloring pages, clean white background, logo + "Start free" | P1 |
| Image B: Angle 1 (personalisation) | Flat-lay of printed personalised coloring book with child's name visible | P1 |
| Image C: Angle 2 (speed/ease) | Split before/after: prompt text → finished coloring page, "60 seconds" overlaid | P1 |
| Video A: 15s demo (9:16) | Screen recording walkthrough: type prompt → pages generate → download → child colors page. VO: "I made my daughter a whole coloring book in 60 seconds." End card: colorcraft-ai.com + "Start free" | P1 |
| Video B: 6s bumper (16:9) | Single most striking coloring page generating on screen. Large text: "Custom coloring books in 60 seconds." colorcraft-ai.com | P1 |
| Image D: Gift angle | Physical coloring book mockup with "For [child's name]" on cover, gift ribbon, birthday context | P2 |
| Video C: 30s YouTube in-stream (16:9) | Full PAS structure: hook (child ignoring generic book) → agitate → demo → outcome (engagement) → CTA | P2 |
| Carousel: 5 pages shown | Each card shows a different generated coloring page style (fantasy, animals, space, vehicles, characters). Final card: "Generate yours free" | P2 |

This set covers Meta cold traffic (Images A/B/C + Video A), YouTube bumper (Video B), YouTube in-stream for Phase 2 (Video C), PMax asset requirements (all images + Video A/B), and Demand Gen (images + Video A).

---

## Appendix: ffmpeg Command Reference for Ad Creative Pipeline

### Generate vertical video with burned captions and VO

```bash
# Composite: Kling video + voiceover audio + music (ducked) + burned captions
ffmpeg -i video.mp4 -i voiceover.mp3 -i music.mp3 \
  -filter_complex "
    [2:a]volume=0.25[music_ducked];
    [1:a][music_ducked]amix=inputs=2:duration=first[audio_mix];
    [0:v]subtitles=captions.srt:force_style='FontName=Poppins Bold,FontSize=58,PrimaryColour=&HFFFFFF,BorderStyle=3,BackColour=&H99000000,Outline=0,Shadow=0,Alignment=2,MarginV=100'[v_captioned]
  " \
  -map "[v_captioned]" -map "[audio_mix]" \
  -c:v libx264 -crf 18 -preset slow \
  -c:a aac -b:a 192k \
  output_ad.mp4
```

### Resize and pad image to multiple aspect ratios from a single source

```bash
# Square 1:1 from landscape source
ffmpeg -i source.jpg -vf "scale=1080:1080:force_original_aspect_ratio=decrease,pad=1080:1080:(ow-iw)/2:(oh-ih)/2:color=#FFFFFF" square_1080.jpg

# Portrait 4:5 from landscape source
ffmpeg -i source.jpg -vf "scale=1080:1350:force_original_aspect_ratio=decrease,pad=1080:1350:(ow-iw)/2:(oh-ih)/2:color=#FFFFFF" portrait_1080x1350.jpg

# Vertical 9:16
ffmpeg -i source.jpg -vf "scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:color=#FFFFFF" vertical_1080x1920.jpg
```

### Trim first N seconds (for bumper extraction from longer video)

```bash
# Extract first 6 seconds as bumper
ffmpeg -i full_video.mp4 -t 6 -c:v copy -c:a copy bumper_6s.mp4
```
