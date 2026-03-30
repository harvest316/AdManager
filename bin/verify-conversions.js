#!/usr/bin/env node
/**
 * Playwright-based conversion action verifier.
 *
 * Visits a website and checks for:
 * - Google Analytics 4 (gtag/GA4 config + event tags)
 * - Meta Pixel (fbq init + standard events)
 * - Google Tag Manager container
 * - Google Ads conversion linker
 * - Conversion event triggers on specific URLs
 *
 * Usage:
 *   node bin/verify-conversions.js --url https://example.com
 *   node bin/verify-conversions.js --url https://example.com --trigger-url /order-confirmation
 *   node bin/verify-conversions.js --project-id 1  (reads from DB)
 *
 * Output: JSON to stdout for PHP consumption.
 */

const { chromium } = require('playwright');

async function main() {
    const args = parseArgs(process.argv.slice(2));
    const url = args.url;
    const triggerUrl = args['trigger-url'];
    const projectId = args['project-id'];

    if (!url && !projectId) {
        console.error('Usage: node bin/verify-conversions.js --url <url> [--trigger-url /path]');
        process.exit(1);
    }

    // If project-id given, we'd read from DB — but for now just require URL
    const targetUrl = url;

    const results = {
        url: targetUrl,
        timestamp: new Date().toISOString(),
        ga4: { found: false, measurement_ids: [], events: [] },
        meta_pixel: { found: false, pixel_ids: [], events: [] },
        gtm: { found: false, container_ids: [] },
        google_ads: { found: false, conversion_ids: [] },
        conversion_linker: false,
        page_events: [],
        errors: [],
    };

    let browser;
    try {
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        });

        const page = await context.newPage();

        // Capture all network requests to detect tracking pixels
        const networkRequests = [];
        page.on('request', req => {
            const reqUrl = req.url();
            networkRequests.push(reqUrl);
        });

        // Capture console for dataLayer pushes
        const dataLayerEvents = [];
        page.on('console', msg => {
            const text = msg.text();
            if (text.includes('dataLayer') || text.includes('gtag')) {
                dataLayerEvents.push(text);
            }
        });

        // Navigate to main page
        await page.goto(targetUrl, { waitUntil: 'networkidle', timeout: 30000 });
        await page.waitForTimeout(3000); // Wait for async tags to fire

        // Check for GA4
        const ga4Scripts = networkRequests.filter(u =>
            u.includes('google-analytics.com/g/collect') ||
            u.includes('analytics.google.com/g/collect') ||
            u.includes('googletagmanager.com/gtag/js')
        );
        if (ga4Scripts.length > 0) {
            results.ga4.found = true;
            // Extract measurement IDs from gtag.js URLs
            for (const u of ga4Scripts) {
                const match = u.match(/[?&]id=(G-[A-Z0-9]+)/);
                if (match && !results.ga4.measurement_ids.includes(match[1])) {
                    results.ga4.measurement_ids.push(match[1]);
                }
            }
            // Extract events from collect requests
            for (const u of ga4Scripts) {
                const evMatch = u.match(/[?&]en=([^&]+)/);
                if (evMatch && !results.ga4.events.includes(evMatch[1])) {
                    results.ga4.events.push(decodeURIComponent(evMatch[1]));
                }
            }
        }

        // Check for gtag in page source
        const pageGtag = await page.evaluate(() => {
            const scripts = Array.from(document.querySelectorAll('script'));
            const ids = [];
            for (const s of scripts) {
                const src = s.src || '';
                const text = s.textContent || '';
                // gtag config
                const configMatches = text.matchAll(/gtag\s*\(\s*['"]config['"]\s*,\s*['"]([^'"]+)['"]/g);
                for (const m of configMatches) ids.push(m[1]);
                // gtag.js src
                const srcMatch = src.match(/gtag\/js\?id=([^&]+)/);
                if (srcMatch) ids.push(srcMatch[1]);
            }
            return [...new Set(ids)];
        });
        for (const id of pageGtag) {
            if (id.startsWith('G-') && !results.ga4.measurement_ids.includes(id)) {
                results.ga4.measurement_ids.push(id);
                results.ga4.found = true;
            }
            if (id.startsWith('AW-')) {
                results.google_ads.found = true;
                if (!results.google_ads.conversion_ids.includes(id)) {
                    results.google_ads.conversion_ids.push(id);
                }
            }
        }

        // Check for Meta Pixel
        const metaPixelRequests = networkRequests.filter(u =>
            u.includes('facebook.com/tr') || u.includes('facebook.net/en_US/fbevents.js')
        );
        if (metaPixelRequests.length > 0) {
            results.meta_pixel.found = true;
            for (const u of metaPixelRequests) {
                const idMatch = u.match(/[?&]id=(\d+)/);
                if (idMatch && !results.meta_pixel.pixel_ids.includes(idMatch[1])) {
                    results.meta_pixel.pixel_ids.push(idMatch[1]);
                }
                const evMatch = u.match(/[?&]ev=([^&]+)/);
                if (evMatch && !results.meta_pixel.events.includes(evMatch[1])) {
                    results.meta_pixel.events.push(decodeURIComponent(evMatch[1]));
                }
            }
        }

        // Check page source for fbq
        const pageFbq = await page.evaluate(() => {
            const scripts = Array.from(document.querySelectorAll('script'));
            const pixelIds = [];
            const events = [];
            for (const s of scripts) {
                const text = s.textContent || '';
                const initMatches = text.matchAll(/fbq\s*\(\s*['"]init['"]\s*,\s*['"](\d+)['"]/g);
                for (const m of initMatches) pixelIds.push(m[1]);
                const trackMatches = text.matchAll(/fbq\s*\(\s*['"]track['"]\s*,\s*['"]([^'"]+)['"]/g);
                for (const m of trackMatches) events.push(m[1]);
            }
            return { pixelIds: [...new Set(pixelIds)], events: [...new Set(events)] };
        });
        for (const id of pageFbq.pixelIds) {
            if (!results.meta_pixel.pixel_ids.includes(id)) results.meta_pixel.pixel_ids.push(id);
            results.meta_pixel.found = true;
        }
        for (const ev of pageFbq.events) {
            if (!results.meta_pixel.events.includes(ev)) results.meta_pixel.events.push(ev);
        }

        // Check for GTM
        const gtmRequests = networkRequests.filter(u => u.includes('googletagmanager.com/gtm.js'));
        if (gtmRequests.length > 0) {
            results.gtm.found = true;
            for (const u of gtmRequests) {
                const match = u.match(/[?&]id=(GTM-[A-Z0-9]+)/);
                if (match && !results.gtm.container_ids.includes(match[1])) {
                    results.gtm.container_ids.push(match[1]);
                }
            }
        }

        // Check for conversion linker
        results.conversion_linker = networkRequests.some(u =>
            u.includes('googleadservices.com/pagead/conversion') ||
            u.includes('google.com/pagead/1p-conversion')
        );

        // If trigger URL specified, navigate there and check for conversion events
        if (triggerUrl) {
            const fullTriggerUrl = new URL(triggerUrl, targetUrl).href;
            results.trigger_check = { url: fullTriggerUrl, events_found: [] };

            const preCount = networkRequests.length;
            try {
                await page.goto(fullTriggerUrl, { waitUntil: 'networkidle', timeout: 15000 });
                await page.waitForTimeout(2000);

                const newRequests = networkRequests.slice(preCount);
                for (const u of newRequests) {
                    if (u.includes('google-analytics.com/g/collect') || u.includes('analytics.google.com/g/collect')) {
                        const evMatch = u.match(/[?&]en=([^&]+)/);
                        if (evMatch) results.trigger_check.events_found.push({ type: 'ga4', event: decodeURIComponent(evMatch[1]) });
                    }
                    if (u.includes('facebook.com/tr')) {
                        const evMatch = u.match(/[?&]ev=([^&]+)/);
                        if (evMatch) results.trigger_check.events_found.push({ type: 'meta', event: decodeURIComponent(evMatch[1]) });
                    }
                    if (u.includes('googleadservices.com/pagead/conversion')) {
                        results.trigger_check.events_found.push({ type: 'google_ads', event: 'conversion' });
                    }
                }
            } catch (e) {
                results.trigger_check.error = e.message;
            }
        }

    } catch (e) {
        results.errors.push(e.message);
    } finally {
        if (browser) await browser.close();
    }

    // Output JSON
    console.log(JSON.stringify(results, null, 2));
}

function parseArgs(argv) {
    const args = {};
    for (let i = 0; i < argv.length; i++) {
        if (argv[i].startsWith('--')) {
            const key = argv[i].slice(2);
            const val = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true;
            args[key] = val;
        }
    }
    return args;
}

main().catch(e => {
    console.error(JSON.stringify({ error: e.message }));
    process.exit(1);
});
