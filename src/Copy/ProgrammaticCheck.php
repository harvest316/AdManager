<?php

namespace AdManager\Copy;

use AdManager\Locale;

/**
 * Deterministic rule engine for ad copy validation.
 *
 * Runs 15 rules against copy items before LLM proofreading.
 * Returns issues in the same JSON format as Creative\QualityCheck for dashboard consistency.
 */
class ProgrammaticCheck
{
    private const CTA_VERBS = [
        'try', 'get', 'start', 'learn', 'sign up', 'book', 'buy', 'shop',
        'order', 'create', 'discover', 'explore', 'find', 'join', 'save',
        'download', 'request', 'call', 'contact', 'apply', 'subscribe',
        'browse', 'view', 'see', 'check', 'compare',
    ];

    private const SUPERLATIVES = [
        'best', 'greatest', '#1', 'number one', 'number 1', 'top-rated',
        'highest rated', 'world\'s best', 'industry leading', 'unbeatable',
    ];

    /**
     * Run all checks on a set of copy items for a campaign.
     *
     * @param array   $items       Copy items from ad_copy table
     * @param string  $brandName   Project display name for brand checks
     * @param string  $market      Target market code (AU, US, GB, etc.)
     * @return array  Per-item results: [id => ['issues' => [...], 'auto_fixed' => [...]]]
     */
    public function checkAll(array $items, string $brandName, string $market = 'all'): array
    {
        $results = [];
        $headlines = array_filter($items, fn($i) => $i['copy_type'] === 'headline');
        $descriptions = array_filter($items, fn($i) => $i['copy_type'] === 'description');

        foreach ($items as $item) {
            $issues = [];
            $autoFixed = [];

            // Rule 1: Character limit
            $issues = array_merge($issues, $this->checkCharLimit($item));

            // Rule 2: Empty content
            $issues = array_merge($issues, $this->checkEmpty($item));

            // Rule 6: Standalone readability
            $issues = array_merge($issues, $this->checkStandalone($item));

            // Rule 7: Locale spelling
            $issues = array_merge($issues, $this->checkLocale($item, $market));

            // Rule 8: Unsubstantiated superlatives
            $issues = array_merge($issues, $this->checkSuperlatives($item));

            // Rule 9: Excessive punctuation
            $issues = array_merge($issues, $this->checkPunctuation($item));

            // Rule 10: ALL CAPS
            $issues = array_merge($issues, $this->checkAllCaps($item, $brandName));

            // Rule 11: Phone/price in headline
            $issues = array_merge($issues, $this->checkPhonePrice($item));

            // Rule 14: Leading/trailing whitespace (auto-fix)
            $wsResult = $this->checkWhitespace($item);
            $issues = array_merge($issues, $wsResult['issues']);
            if (!empty($wsResult['fixed'])) {
                $autoFixed[] = $wsResult['fixed'];
            }

            // Rule 15: Special characters
            $issues = array_merge($issues, $this->checkSpecialChars($item));

            $results[$item['id']] = [
                'issues'     => $issues,
                'auto_fixed' => $autoFixed,
            ];
        }

        // Campaign-level checks (rules 3, 4, 5, 12, 13)
        $campaignGroups = [];
        foreach ($items as $item) {
            if ($item['platform'] === 'google' && $item['campaign_name']) {
                $campaignGroups[$item['campaign_name']][] = $item;
            }
        }

        foreach ($campaignGroups as $campaignName => $campaignItems) {
            $campaignHeadlines = array_filter($campaignItems, fn($i) => $i['copy_type'] === 'headline');
            $campaignDescriptions = array_filter($campaignItems, fn($i) => $i['copy_type'] === 'description');

            // Rule 3: RSA count validation
            $countIssues = $this->checkRSACount($campaignHeadlines, $campaignDescriptions, $campaignName);

            // Rule 4: Pin position validation
            $pinIssues = $this->checkPinPositions($campaignHeadlines, $campaignName);

            // Rule 5: Duplicate detection
            $dupIssues = $this->checkDuplicates($campaignHeadlines, $campaignDescriptions);

            // Rule 12: Brand name presence
            $brandIssues = $this->checkBrandPresence($campaignHeadlines, $brandName, $campaignName);

            // Rule 13: CTA presence
            $ctaIssues = $this->checkCTAPresence($campaignHeadlines, $campaignName);

            // Attach campaign-level issues to the first item of each type
            foreach ($countIssues as $issue) {
                $firstItem = !empty($campaignHeadlines) ? reset($campaignHeadlines) : reset($campaignItems);
                $results[$firstItem['id']]['issues'][] = $issue;
            }
            foreach (array_merge($pinIssues, $brandIssues, $ctaIssues) as $issue) {
                if (!empty($campaignHeadlines)) {
                    $firstHL = reset($campaignHeadlines);
                    $results[$firstHL['id']]['issues'][] = $issue;
                }
            }
            foreach ($dupIssues as $id => $issues) {
                foreach ($issues as $issue) {
                    $results[$id]['issues'][] = $issue;
                }
            }
        }

        return $results;
    }

    // Rule 1: Character limit
    private function checkCharLimit(array $item): array
    {
        if (!$item['char_limit']) return [];
        $len = mb_strlen($item['content']);
        if ($len > $item['char_limit']) {
            return [[
                'category'    => 'char_limit',
                'severity'    => 'fail',
                'description' => "Content is {$len} chars, exceeds {$item['char_limit']} char limit",
            ]];
        }
        return [];
    }

    // Rule 2: Empty content
    private function checkEmpty(array $item): array
    {
        if (trim($item['content']) === '') {
            return [[
                'category'    => 'empty_content',
                'severity'    => 'fail',
                'description' => 'Content is empty or whitespace-only',
            ]];
        }
        return [];
    }

    // Rule 3: RSA count
    private function checkRSACount(array $headlines, array $descriptions, string $campaignName): array
    {
        $issues = [];
        $hlCount = count($headlines);
        $descCount = count($descriptions);

        if ($hlCount < 15) {
            $issues[] = [
                'category'    => 'rsa_count',
                'severity'    => 'fail',
                'description' => "Campaign '{$campaignName}' has {$hlCount} headlines, needs 15",
            ];
        } elseif ($hlCount > 15) {
            $issues[] = [
                'category'    => 'rsa_count',
                'severity'    => 'warning',
                'description' => "Campaign '{$campaignName}' has {$hlCount} headlines, Google allows max 15",
            ];
        }

        if ($descCount < 4) {
            $issues[] = [
                'category'    => 'rsa_count',
                'severity'    => 'fail',
                'description' => "Campaign '{$campaignName}' has {$descCount} descriptions, needs 4",
            ];
        }

        return $issues;
    }

    // Rule 4: Pin position validation
    private function checkPinPositions(array $headlines, string $campaignName): array
    {
        $issues = [];
        $pinned = array_filter($headlines, fn($h) => $h['pin_position'] !== null);
        $pin1 = array_filter($headlines, fn($h) => (int)$h['pin_position'] === 1);

        if (empty($pin1)) {
            $issues[] = [
                'category'    => 'pin_position',
                'severity'    => 'warning',
                'description' => "Campaign '{$campaignName}': no headline pinned to position 1 (recommended for brand/primary benefit)",
            ];
        }

        if (count($pinned) > 3) {
            $issues[] = [
                'category'    => 'pin_position',
                'severity'    => 'warning',
                'description' => "Campaign '{$campaignName}': " . count($pinned) . " pinned headlines (over-pinning reduces RSA testing effectiveness, max 3 recommended)",
            ];
        }

        return $issues;
    }

    // Rule 5: Duplicate detection
    private function checkDuplicates(array $headlines, array $descriptions): array
    {
        $issues = [];
        $allItems = array_merge(array_values($headlines), array_values($descriptions));

        for ($i = 0; $i < count($allItems); $i++) {
            for ($j = $i + 1; $j < count($allItems); $j++) {
                if ($allItems[$i]['copy_type'] !== $allItems[$j]['copy_type']) continue;

                $a = strtolower(trim($allItems[$i]['content']));
                $b = strtolower(trim($allItems[$j]['content']));

                if ($a === $b) {
                    $issues[$allItems[$j]['id']][] = [
                        'category'    => 'duplicate',
                        'severity'    => 'fail',
                        'description' => "Exact duplicate of: \"{$allItems[$i]['content']}\"",
                    ];
                } elseif (levenshtein($a, $b) < 3 && strlen($a) > 5) {
                    $issues[$allItems[$j]['id']][] = [
                        'category'    => 'duplicate',
                        'severity'    => 'warning',
                        'description' => "Near-duplicate of: \"{$allItems[$i]['content']}\"",
                    ];
                }
            }
        }

        return $issues;
    }

    // Rule 6: Standalone readability
    private function checkStandalone(array $item): array
    {
        if (!in_array($item['copy_type'], ['headline', 'description'])) return [];

        $content = $item['content'];
        $issues = [];

        // Headlines/descriptions that start with conjunctions won't make sense standalone
        if (preg_match('/^(and|but|also|or|so|yet|nor)\b/i', $content)) {
            $issues[] = [
                'category'    => 'standalone',
                'severity'    => 'warning',
                'description' => "Starts with '{$content[0]}...' — may not make sense as standalone RSA element",
            ];
        }

        // Headlines ending with comma suggest incomplete thought
        if ($item['copy_type'] === 'headline' && str_ends_with(rtrim($content), ',')) {
            $issues[] = [
                'category'    => 'standalone',
                'severity'    => 'warning',
                'description' => 'Ends with comma — suggests incomplete thought in RSA rotation',
            ];
        }

        return $issues;
    }

    // Rule 7: Locale spelling
    private function checkLocale(array $item, string $market): array
    {
        if ($market === 'all') return [];

        $variant = Locale::spellingVariant($market);
        $content = $item['content'];

        if ($variant === 'gb') {
            $localised = Locale::usToGb($content);
            if ($localised !== $content) {
                return [[
                    'category'    => 'locale',
                    'severity'    => 'warning',
                    'description' => "US spelling detected in {$market} market copy. Should be: \"{$localised}\"",
                ]];
            }
        } elseif ($variant === 'us') {
            $americanised = Locale::gbToUs($content);
            if ($americanised !== $content) {
                return [[
                    'category'    => 'locale',
                    'severity'    => 'warning',
                    'description' => "British spelling detected in {$market} market copy. Should be: \"{$americanised}\"",
                ]];
            }
        }

        return [];
    }

    // Rule 8: Unsubstantiated superlatives
    private function checkSuperlatives(array $item): array
    {
        $lower = strtolower($item['content']);
        foreach (self::SUPERLATIVES as $sup) {
            if (str_contains($lower, $sup)) {
                // Allow if qualified with evidence
                if (preg_match('/(?:rated|ranked|voted|awarded|certified|according to|per )/i', $item['content'])) {
                    continue;
                }
                return [[
                    'category'    => 'superlative',
                    'severity'    => 'warning',
                    'description' => "Unsubstantiated superlative \"{$sup}\" — Google may disapprove. Add qualifier or remove.",
                ]];
            }
        }
        return [];
    }

    // Rule 9: Excessive punctuation
    private function checkPunctuation(array $item): array
    {
        $issues = [];
        $content = $item['content'];

        // Google rejects exclamation marks in headlines
        if ($item['copy_type'] === 'headline' && str_contains($content, '!')) {
            $issues[] = [
                'category'    => 'punctuation',
                'severity'    => 'fail',
                'description' => 'Exclamation mark in headline — Google Ads will reject',
            ];
        }

        // Multiple exclamation marks in descriptions
        if ($item['copy_type'] === 'description' && substr_count($content, '!') > 1) {
            $issues[] = [
                'category'    => 'punctuation',
                'severity'    => 'warning',
                'description' => 'Multiple exclamation marks — appears spammy, Google may flag',
            ];
        }

        // Repeated punctuation (??, !!, ...)
        if (preg_match('/[?!.]{3,}/', $content)) {
            $issues[] = [
                'category'    => 'punctuation',
                'severity'    => 'warning',
                'description' => 'Repeated punctuation marks — gimmicky, likely to be flagged',
            ];
        }

        return $issues;
    }

    // Rule 10: ALL CAPS
    private function checkAllCaps(array $item, string $brandName): array
    {
        // Split into words and check each
        $words = preg_split('/\s+/', $item['content']);
        $brandWords = preg_split('/\s+/', strtoupper($brandName));
        // Common acronyms that are acceptable
        $allowedAcronyms = ['AI', 'SEO', 'CTA', 'PDF', 'DIY', 'USA', 'UK', 'AU', 'NZ', 'FAQ', 'ROI', 'API', 'VR', 'AR'];

        foreach ($words as $word) {
            $clean = preg_replace('/[^A-Z]/', '', $word);
            if (strlen($clean) >= 2 && $word === strtoupper($word) && !ctype_digit($word)) {
                // Check if it's a known acronym or brand name
                $upper = strtoupper(preg_replace('/[^A-Za-z]/', '', $word));
                if (in_array($upper, $allowedAcronyms)) continue;
                if (in_array($upper, $brandWords)) continue;
                if (strlen($clean) <= 4) continue; // Short caps likely acronyms

                return [[
                    'category'    => 'capitalisation',
                    'severity'    => 'fail',
                    'description' => "ALL CAPS word \"{$word}\" — Google rejects excessive capitalisation",
                ]];
            }
        }
        return [];
    }

    // Rule 11: Phone/price in headline
    private function checkPhonePrice(array $item): array
    {
        if ($item['copy_type'] !== 'headline') return [];

        $issues = [];

        // Phone numbers
        if (preg_match('/\d{3}[-.\s]?\d{3,4}[-.\s]?\d{3,4}/', $item['content'])) {
            $issues[] = [
                'category'    => 'phone_in_headline',
                'severity'    => 'warning',
                'description' => 'Phone number in headline — use call extensions instead',
            ];
        }

        return $issues;
    }

    // Rule 12: Brand name presence
    private function checkBrandPresence(array $headlines, string $brandName, string $campaignName): array
    {
        if (empty($brandName)) return [];

        $brandLower = strtolower($brandName);
        $brandCount = 0;
        foreach ($headlines as $h) {
            if (str_contains(strtolower($h['content']), $brandLower)) {
                $brandCount++;
            }
        }

        if ($brandCount < 2) {
            return [[
                'category'    => 'brand_presence',
                'severity'    => 'warning',
                'description' => "Campaign '{$campaignName}': only {$brandCount} headline(s) contain brand name '{$brandName}' (recommend >= 2)",
            ]];
        }
        return [];
    }

    // Rule 13: CTA presence
    private function checkCTAPresence(array $headlines, string $campaignName): array
    {
        $hasCTA = false;
        foreach ($headlines as $h) {
            $lower = strtolower($h['content']);
            foreach (self::CTA_VERBS as $verb) {
                if (str_contains($lower, $verb)) {
                    $hasCTA = true;
                    break 2;
                }
            }
        }

        if (!$hasCTA) {
            return [[
                'category'    => 'cta_presence',
                'severity'    => 'warning',
                'description' => "Campaign '{$campaignName}': no headline contains a call-to-action verb",
            ]];
        }
        return [];
    }

    // Rule 14: Whitespace
    private function checkWhitespace(array $item): array
    {
        $content = $item['content'];
        $trimmed = trim($content);

        if ($content !== $trimmed) {
            return [
                'issues' => [[
                    'category'    => 'whitespace',
                    'severity'    => 'warning',
                    'description' => 'Leading/trailing whitespace detected (auto-fixed)',
                ]],
                'fixed' => $trimmed,
            ];
        }

        return ['issues' => [], 'fixed' => null];
    }

    // Rule 15: Special characters
    private function checkSpecialChars(array $item): array
    {
        $content = $item['content'];
        $issues = [];

        // Emoji detection
        if (preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $content)) {
            $issues[] = [
                'category'    => 'special_chars',
                'severity'    => 'warning',
                'description' => 'Emoji detected — may be rejected by some ad platforms',
            ];
        }

        // Trademark symbols
        if (preg_match('/[™®©]/', $content)) {
            $issues[] = [
                'category'    => 'special_chars',
                'severity'    => 'warning',
                'description' => 'Trademark/copyright symbol detected — some platforms reject these',
            ];
        }

        return $issues;
    }

    /**
     * Determine overall QA status from issues.
     */
    public static function overallStatus(array $issues): string
    {
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'fail') return 'fail';
        }
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'warning') return 'warning';
        }
        return 'pass';
    }
}
