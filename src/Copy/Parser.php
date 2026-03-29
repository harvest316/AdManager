<?php

namespace AdManager\Copy;

/**
 * Extracts structured ad copy from strategy markdown output.
 *
 * Parses the "Ad Copy" section (Section 8) of strategy documents to extract
 * RSA headlines, descriptions, sitelinks, callouts, structured snippets,
 * and Meta primary text variants.
 */
class Parser
{
    /**
     * Parse strategy markdown and return structured copy items.
     *
     * @return array[] Each item has: platform, campaign_name, ad_group_name, copy_type, content, char_limit, pin_position
     */
    public function parse(string $markdown): array
    {
        $items = [];

        $items = array_merge($items, $this->parseGoogleRSAs($markdown));
        $items = array_merge($items, $this->parseSitelinks($markdown));
        $items = array_merge($items, $this->parseCallouts($markdown));
        $items = array_merge($items, $this->parseStructuredSnippets($markdown));
        $items = array_merge($items, $this->parseMetaPrimaryText($markdown));

        return $items;
    }

    /**
     * Parse all Google RSA sections (headlines + descriptions).
     *
     * Format:
     *   ### Google Ads -- RSA for <campaign_name>
     *   **Headlines (15):**
     *   1. `headline text` (PIN to position N)
     *   **Descriptions (4):**
     *   1. `description text`
     */
    private function parseGoogleRSAs(string $markdown): array
    {
        $items = [];

        // Find all RSA campaign sections
        if (!preg_match_all('/###\s+Google Ads\s+--\s+RSA for\s+(.+)/i', $markdown, $campaignMatches, PREG_OFFSET_CAPTURE)) {
            return $items;
        }

        foreach ($campaignMatches[1] as $i => $match) {
            $campaignName = trim($match[0]);
            $sectionStart = $match[1];

            // Find the end of this section (next ### or end of file)
            $nextSection = isset($campaignMatches[0][$i + 1])
                ? $campaignMatches[0][$i + 1][1]
                : $this->findNextH3($markdown, $sectionStart);

            $section = substr($markdown, $sectionStart, $nextSection - $sectionStart);

            // Parse headlines
            $items = array_merge($items, $this->parseHeadlines($section, $campaignName));

            // Parse descriptions
            $items = array_merge($items, $this->parseDescriptions($section, $campaignName));
        }

        return $items;
    }

    /**
     * Parse headlines from an RSA section.
     */
    private function parseHeadlines(string $section, string $campaignName): array
    {
        $items = [];

        // Match numbered headlines with backtick-wrapped text and optional PIN
        if (preg_match_all('/^\d+\.\s+`([^`]+)`(?:\s+\(PIN to position (\d)\))?/m', $section, $matches, PREG_SET_ORDER)) {
            // Only process headlines that appear after "Headlines" marker
            $headlinesPos = stripos($section, '**Headlines');
            $descriptionsPos = stripos($section, '**Descriptions');

            foreach ($matches as $match) {
                $matchPos = strpos($section, $match[0]);

                // Skip if this match is in the descriptions section
                if ($descriptionsPos !== false && $matchPos > $descriptionsPos) {
                    continue;
                }

                // Only include if after Headlines marker
                if ($headlinesPos !== false && $matchPos > $headlinesPos) {
                    $items[] = [
                        'platform'      => 'google',
                        'campaign_name' => $campaignName,
                        'ad_group_name' => null,
                        'copy_type'     => 'headline',
                        'content'       => trim($match[1]),
                        'char_limit'    => 30,
                        'pin_position'  => isset($match[2]) && $match[2] !== '' ? (int) $match[2] : null,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * Parse descriptions from an RSA section.
     */
    private function parseDescriptions(string $section, string $campaignName): array
    {
        $items = [];

        $descriptionsPos = stripos($section, '**Descriptions');
        if ($descriptionsPos === false) {
            return $items;
        }

        $descSection = substr($section, $descriptionsPos);

        // Match numbered descriptions with backtick-wrapped text
        if (preg_match_all('/^\d+\.\s+`([^`]+)`/m', $descSection, $matches)) {
            foreach ($matches[1] as $content) {
                $items[] = [
                    'platform'      => 'google',
                    'campaign_name' => $campaignName,
                    'ad_group_name' => null,
                    'copy_type'     => 'description',
                    'content'       => trim($content),
                    'char_limit'    => 90,
                    'pin_position'  => null,
                ];
            }
        }

        return $items;
    }

    /**
     * Parse sitelink extensions.
     *
     * Format:
     *   **Sitelinks:**
     *   1. Browse the Gallery -- See thousands of AI-generated coloring pages
     */
    private function parseSitelinks(string $markdown): array
    {
        $items = [];

        $sitelinksPos = stripos($markdown, '**Sitelinks');
        if ($sitelinksPos === false) {
            return $items;
        }

        $section = substr($markdown, $sitelinksPos);
        $endPos = $this->findNextBoldSection($section, strlen('**Sitelinks'));
        $section = substr($section, 0, $endPos);

        if (preg_match_all('/^\d+\.\s+(.+?)\s+--\s+(.+)/m', $section, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $items[] = [
                    'platform'      => 'google',
                    'campaign_name' => null,
                    'ad_group_name' => null,
                    'copy_type'     => 'sitelink',
                    'content'       => trim($match[1]) . ' — ' . trim($match[2]),
                    'char_limit'    => 25,
                    'pin_position'  => null,
                ];
            }
        }

        return $items;
    }

    /**
     * Parse callout extensions.
     *
     * Format:
     *   **Callout extensions:**
     *   - AI-Generated in Minutes
     */
    private function parseCallouts(string $markdown): array
    {
        $items = [];

        $calloutsPos = stripos($markdown, '**Callout');
        if ($calloutsPos === false) {
            return $items;
        }

        $section = substr($markdown, $calloutsPos);
        $endPos = $this->findNextBoldSection($section, strlen('**Callout'));
        $section = substr($section, 0, $endPos);

        if (preg_match_all('/^[-*]\s+(.+)/m', $section, $matches)) {
            foreach ($matches[1] as $content) {
                $items[] = [
                    'platform'      => 'google',
                    'campaign_name' => null,
                    'ad_group_name' => null,
                    'copy_type'     => 'callout',
                    'content'       => trim($content),
                    'char_limit'    => 25,
                    'pin_position'  => null,
                ];
            }
        }

        return $items;
    }

    /**
     * Parse structured snippet extensions.
     *
     * Format:
     *   **Structured snippet:**
     *   - Type: Features -> Coloring Books, Color Palettes, ...
     */
    private function parseStructuredSnippets(string $markdown): array
    {
        $items = [];

        $snippetPos = stripos($markdown, '**Structured snippet');
        if ($snippetPos === false) {
            return $items;
        }

        $section = substr($markdown, $snippetPos);
        $endPos = $this->findNextBoldSection($section, strlen('**Structured snippet'));
        $section = substr($section, 0, $endPos);

        if (preg_match_all('/^[-*]\s+(.+)/m', $section, $matches)) {
            foreach ($matches[1] as $content) {
                $items[] = [
                    'platform'      => 'google',
                    'campaign_name' => null,
                    'ad_group_name' => null,
                    'copy_type'     => 'structured_snippet',
                    'content'       => trim($content),
                    'char_limit'    => null,
                    'pin_position'  => null,
                ];
            }
        }

        return $items;
    }

    /**
     * Parse Meta primary text variants.
     *
     * Format:
     *   ### Meta Ads -- Primary Text
     *   **Ad Set 1: Mothers -- Coloring for Kids**
     *   Version A (Direct benefit):
     *   > Copy text here...
     */
    private function parseMetaPrimaryText(string $markdown): array
    {
        $items = [];

        $metaPos = stripos($markdown, '### Meta Ads');
        if ($metaPos === false) {
            return $items;
        }

        // Find the end of the Meta section (next ## or ---)
        $metaSection = substr($markdown, $metaPos);
        if (preg_match('/\n---\n|\n## \d/', $metaSection, $endMatch, PREG_OFFSET_CAPTURE)) {
            $metaSection = substr($metaSection, 0, $endMatch[0][1]);
        }

        // Find ad set sections
        $currentAdSet = 'General';
        if (preg_match_all('/\*\*Ad Set \d+:\s*(.+?)\*\*/', $metaSection, $adSetMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($adSetMatches[1] as $j => $adSetMatch) {
                $adSetName = trim($adSetMatch[0]);
                $adSetStart = $adSetMatch[1];

                // Find end of this ad set section
                $adSetEnd = isset($adSetMatches[0][$j + 1])
                    ? $adSetMatches[0][$j + 1][1]
                    : strlen($metaSection);

                $adSetSection = substr($metaSection, $adSetStart, $adSetEnd - $adSetStart);

                // Extract blockquote copy (lines starting with >)
                if (preg_match_all('/^>\s*(.+)/m', $adSetSection, $copyMatches)) {
                    // Group consecutive blockquote lines into single entries
                    $currentCopy = '';
                    $copies = [];

                    foreach ($copyMatches[1] as $line) {
                        $line = trim($line);
                        if ($currentCopy !== '') {
                            $currentCopy .= ' ' . $line;
                        } else {
                            $currentCopy = $line;
                        }
                    }
                    if ($currentCopy !== '') {
                        $copies[] = $currentCopy;
                    }

                    // Re-parse: split by Version headers to get individual variants
                    $copies = $this->splitByVersionHeaders($adSetSection);

                    foreach ($copies as $copy) {
                        if (trim($copy) === '') continue;
                        $items[] = [
                            'platform'      => 'meta',
                            'campaign_name' => null,
                            'ad_group_name' => $adSetName,
                            'copy_type'     => 'primary_text',
                            'content'       => trim($copy),
                            'char_limit'    => 2000,
                            'pin_position'  => null,
                        ];
                    }
                }
            }
        }

        // Also check for retargeting sections without "Ad Set N:" prefix
        if (preg_match_all('/\*\*Ad Set \d+:\s*Retargeting\*\*|###.*Retargeting/i', $metaSection, $retargetMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($retargetMatches[0] as $match) {
                $retargetStart = $match[1] + strlen($match[0]);
                $retargetSection = substr($metaSection, $retargetStart, 500);

                // Get blockquote text
                $copies = $this->extractBlockquotes($retargetSection);
                foreach ($copies as $copy) {
                    if (trim($copy) === '') continue;
                    $items[] = [
                        'platform'      => 'meta',
                        'campaign_name' => null,
                        'ad_group_name' => 'Retargeting',
                        'copy_type'     => 'primary_text',
                        'content'       => trim($copy),
                        'char_limit'    => 2000,
                        'pin_position'  => null,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * Split ad set section into individual copy variants by "Version" headers.
     */
    private function splitByVersionHeaders(string $section): array
    {
        $copies = [];

        // Split by "Version A/B/C" headers
        $parts = preg_split('/Version [A-Z](?:\s+\([^)]+\))?:\s*\n/i', $section);

        foreach ($parts as $part) {
            $blockquotes = $this->extractBlockquotes($part);
            foreach ($blockquotes as $bq) {
                if (trim($bq) !== '') {
                    $copies[] = $bq;
                }
            }
        }

        return $copies;
    }

    /**
     * Extract blockquote text (lines starting with >) from a section.
     * Consecutive blockquote lines are joined into a single entry.
     */
    private function extractBlockquotes(string $text): array
    {
        $copies = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^>\s*(.+)/', $line, $m)) {
                $current .= ($current !== '' ? ' ' : '') . trim($m[1]);
            } else {
                if ($current !== '') {
                    $copies[] = $current;
                    $current = '';
                }
            }
        }
        if ($current !== '') {
            $copies[] = $current;
        }

        return $copies;
    }

    /**
     * Find the position of the next ### heading after a given offset.
     */
    private function findNextH3(string $markdown, int $afterOffset): int
    {
        $pos = strpos($markdown, "\n### ", $afterOffset + 1);
        if ($pos === false) {
            // Also check for section dividers
            $divPos = strpos($markdown, "\n---\n", $afterOffset + 1);
            return $divPos !== false ? $divPos : strlen($markdown);
        }

        $divPos = strpos($markdown, "\n---\n", $afterOffset + 1);
        if ($divPos !== false && $divPos < $pos) {
            return $divPos;
        }

        return $pos;
    }

    /**
     * Find the next **bold** section marker after an offset within a substring.
     */
    private function findNextBoldSection(string $section, int $afterOffset): int
    {
        // Look for next **Something or ### or --- after the initial offset
        $remaining = substr($section, $afterOffset);
        if (preg_match('/\n(?:\*\*[A-Z]|###|---)/i', $remaining, $match, PREG_OFFSET_CAPTURE)) {
            return $afterOffset + $match[0][1];
        }
        return strlen($section);
    }
}
