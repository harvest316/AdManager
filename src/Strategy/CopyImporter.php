<?php

namespace AdManager\Strategy;

use AdManager\DB;
use AdManager\Locale;

/**
 * Imports ad copy from a strategy into the ad_copy table,
 * auto-generating locale variants for each target market.
 *
 * Flow: Strategy markdown → parse headlines/descriptions/primary text →
 *       insert EN copy → localise spelling per market → optionally translate
 *       to non-English languages via OpenRouter.
 */
class CopyImporter
{
    private const GOOGLE_MARKETS = [
        'US' => 'en', 'GB' => 'en', 'AU' => 'en', 'CA' => 'en',
        'NZ' => 'en', 'IE' => 'en', 'ZA' => 'en', 'IN' => 'en',
    ];

    private const TRANSLATE_LANGUAGES = [
        'es' => ['name' => 'Spanish',    'market' => 'ES'],
        'fr' => ['name' => 'French',     'market' => 'FR'],
        'de' => ['name' => 'German',     'market' => 'DE'],
        'pt' => ['name' => 'Portuguese', 'market' => 'BR'],
        'ja' => ['name' => 'Japanese',   'market' => 'JP'],
        'ko' => ['name' => 'Korean',     'market' => 'KR'],
    ];

    /**
     * Import ad copy items into the database with locale variants.
     *
     * @param int    $projectId     Project to import into
     * @param array  $copyItems     Array of [
     *   'campaign_name' => string,
     *   'ad_group_name' => string,
     *   'platform'      => 'google'|'meta',
     *   'copy_type'     => 'headline'|'description'|'primary_text'|'sitelink_text'|'callout',
     *   'content'       => string (US English),
     *   'pin_position'  => int|null,
     * ]
     * @param array  $markets       Target markets (default: US + GB)
     * @param array  $languages     Languages to translate into (default: none — pass language codes)
     * @param bool   $clearExisting Remove existing copy for this project first
     * @return int   Number of copy items created
     */
    public function import(
        int $projectId,
        array $copyItems,
        array $markets = ['US', 'GB'],
        array $languages = [],
        bool $clearExisting = false
    ): int {
        $db = DB::get();

        if ($clearExisting) {
            $db->prepare('DELETE FROM ad_copy WHERE project_id = ?')->execute([$projectId]);
        }

        $stmt = $db->prepare(
            'INSERT INTO ad_copy (project_id, campaign_name, ad_group_name, platform, copy_type, content, pin_position, status, target_market, language)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $count = 0;

        foreach ($copyItems as $item) {
            $usContent = $item['content'];

            // Insert for each English-speaking market with appropriate spelling
            foreach ($markets as $market) {
                $market = strtoupper($market);
                $lang = self::GOOGLE_MARKETS[$market] ?? 'en';
                if ($lang !== 'en') continue; // only English markets in this loop

                $localised = Locale::localise($usContent, $market);

                $stmt->execute([
                    $projectId,
                    $item['campaign_name'],
                    $item['ad_group_name'],
                    $item['platform'],
                    $item['copy_type'],
                    $localised,
                    $item['pin_position'] ?? null,
                    'draft',
                    $market,
                    'en',
                ]);
                $count++;
            }

            // Also insert a market-neutral version for copy that has no spelling differences
            if (Locale::localise($usContent, 'GB') === $usContent) {
                $stmt->execute([
                    $projectId,
                    $item['campaign_name'],
                    $item['ad_group_name'],
                    $item['platform'],
                    $item['copy_type'],
                    $usContent,
                    $item['pin_position'] ?? null,
                    'draft',
                    'all',
                    'en',
                ]);
                $count++;
            }
        }

        // Translate into requested languages
        if (!empty($languages)) {
            $count += $this->translateCopy($projectId, $copyItems, $languages);
        }

        return $count;
    }

    /**
     * Translate copy items into target languages via OpenRouter.
     */
    private function translateCopy(int $projectId, array $copyItems, array $languages): int
    {
        $apiKey = $_ENV['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: '';
        if (!$apiKey) {
            echo "  OPENROUTER_API_KEY not set — skipping translations\n";
            return 0;
        }

        $db = DB::get();
        $stmt = $db->prepare(
            'INSERT INTO ad_copy (project_id, campaign_name, ad_group_name, platform, copy_type, content, pin_position, status, target_market, language)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $count = 0;

        foreach ($languages as $langCode) {
            if (!isset(self::TRANSLATE_LANGUAGES[$langCode])) continue;
            $langInfo = self::TRANSLATE_LANGUAGES[$langCode];
            echo "  Translating to {$langInfo['name']}...\n";

            foreach ($copyItems as $item) {
                $translated = $this->translate($apiKey, $item['content'], $langInfo['name'], $item['copy_type']);
                if (!$translated) continue;

                $stmt->execute([
                    $projectId,
                    $item['campaign_name'],
                    $item['ad_group_name'],
                    $item['platform'],
                    $item['copy_type'],
                    $translated,
                    $item['pin_position'] ?? null,
                    'draft',
                    $langInfo['market'],
                    $langCode,
                ]);
                $count++;
                usleep(200_000); // rate limit
            }
        }

        return $count;
    }

    private function translate(string $apiKey, string $text, string $langName, string $copyType): string
    {
        $constraints = match ($copyType) {
            'headline' => 'The translation MUST be 30 characters or fewer.',
            'description' => 'The translation MUST be 90 characters or fewer.',
            'callout' => 'The translation MUST be 25 characters or fewer.',
            'sitelink_text' => 'The translation MUST be 25 characters or fewer.',
            default => 'Keep the translation concise and punchy.',
        };

        $payload = [
            'model' => 'google/gemini-2.0-flash-001',
            'messages' => [[
                'role' => 'user',
                'content' => "Translate this ad copy to {$langName}. Keep brand names untranslated. Same tone — casual, direct. {$constraints} Return ONLY the translated text.\n\n{$text}",
            ]],
            'temperature' => 0.3,
            'max_tokens' => 200,
        ];

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($body, true);
        $result = trim($data['choices'][0]['message']['content'] ?? '');
        return trim($result, '"\'');
    }
}
