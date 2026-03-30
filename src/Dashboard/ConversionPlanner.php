<?php

namespace AdManager\Dashboard;

use AdManager\DB;

/**
 * Plans and provisions conversion actions for a project.
 *
 * Analyses the project's website URL, strategy docs, and campaign types
 * to determine which conversion events should exist. Then creates them
 * on Google Ads (and plans them for Meta/GA4 manual setup).
 */
class ConversionPlanner
{
    /**
     * Standard conversion event templates by business type.
     * Each template maps to Google Ads categories + Meta standard events.
     */
    private const TEMPLATES = [
        'ecommerce' => [
            ['name' => 'Purchase',       'event' => 'purchase',        'category' => 'PURCHASE',       'primary' => true,  'trigger' => 'url_match',  'meta_event' => 'Purchase'],
            ['name' => 'Add to Cart',    'event' => 'add_to_cart',     'category' => 'ADD_TO_CART',    'primary' => false, 'trigger' => 'custom_event', 'meta_event' => 'AddToCart'],
            ['name' => 'Begin Checkout', 'event' => 'begin_checkout',  'category' => 'BEGIN_CHECKOUT', 'primary' => false, 'trigger' => 'custom_event', 'meta_event' => 'InitiateCheckout'],
            ['name' => 'View Product',   'event' => 'view_item',       'category' => 'PAGE_VIEW',      'primary' => false, 'trigger' => 'url_match',  'meta_event' => 'ViewContent'],
        ],
        'lead_gen' => [
            ['name' => 'Form Submit',    'event' => 'generate_lead',   'category' => 'LEAD',           'primary' => true,  'trigger' => 'url_match',  'meta_event' => 'Lead'],
            ['name' => 'Contact',        'event' => 'contact',         'category' => 'CONTACT',        'primary' => false, 'trigger' => 'click',      'meta_event' => 'Contact'],
            ['name' => 'View Pricing',   'event' => 'view_pricing',    'category' => 'PAGE_VIEW',      'primary' => false, 'trigger' => 'url_match',  'meta_event' => 'ViewContent'],
        ],
        'saas' => [
            ['name' => 'Sign Up',        'event' => 'sign_up',         'category' => 'SIGNUP',         'primary' => true,  'trigger' => 'url_match',  'meta_event' => 'CompleteRegistration'],
            ['name' => 'Purchase',       'event' => 'purchase',        'category' => 'PURCHASE',       'primary' => true,  'trigger' => 'url_match',  'meta_event' => 'Purchase'],
            ['name' => 'Start Trial',    'event' => 'begin_checkout',  'category' => 'BEGIN_CHECKOUT', 'primary' => false, 'trigger' => 'url_match',  'meta_event' => 'StartTrial'],
            ['name' => 'View Pricing',   'event' => 'view_pricing',    'category' => 'PAGE_VIEW',      'primary' => false, 'trigger' => 'url_match',  'meta_event' => 'ViewContent'],
        ],
        'service' => [
            ['name' => 'Order Placed',   'event' => 'purchase',        'category' => 'PURCHASE',       'primary' => true,  'trigger' => 'url_match',  'meta_event' => 'Purchase'],
            ['name' => 'Quote Request',  'event' => 'generate_lead',   'category' => 'LEAD',           'primary' => true,  'trigger' => 'url_match',  'meta_event' => 'Lead'],
            ['name' => 'Contact',        'event' => 'contact',         'category' => 'CONTACT',        'primary' => false, 'trigger' => 'click',      'meta_event' => 'Contact'],
            ['name' => 'Phone Call',     'event' => 'phone_call',      'category' => 'CONTACT',        'primary' => false, 'trigger' => 'click',      'meta_event' => 'Contact'],
        ],
    ];

    /**
     * Analyse a project and recommend conversion actions.
     *
     * Uses strategy docs, campaign types, and URL patterns to determine
     * which conversion events make sense.
     *
     * @return array Recommended actions (not yet saved)
     */
    public function plan(int $projectId): array
    {
        $db = DB::get();

        // Load project context
        $proj = $db->prepare('SELECT * FROM projects WHERE id = ?');
        $proj->execute([$projectId]);
        $project = $proj->fetch();
        if (!$project) return [];

        // Load strategies for business type inference
        $strats = $db->prepare('SELECT full_strategy, campaign_type FROM strategies WHERE project_id = ? ORDER BY created_at DESC LIMIT 3');
        $strats->execute([$projectId]);
        $strategies = $strats->fetchAll();

        // Load campaigns to understand what platforms are in use
        $camps = $db->prepare('SELECT DISTINCT platform, type FROM campaigns WHERE project_id = ?');
        $camps->execute([$projectId]);
        $campaignTypes = $camps->fetchAll();

        // Load existing conversion actions to avoid duplicates
        $existing = $db->prepare('SELECT event_name, platform FROM conversion_actions WHERE project_id = ?');
        $existing->execute([$projectId]);
        $existingKeys = [];
        foreach ($existing->fetchAll() as $e) {
            $existingKeys[] = $e['event_name'] . ':' . $e['platform'];
        }

        // Determine business type
        $businessType = $this->inferBusinessType($project, $strategies);

        // Get the template
        $template = self::TEMPLATES[$businessType] ?? self::TEMPLATES['service'];

        // Determine which platforms need actions
        $platforms = ['ga4']; // GA4 always needed
        foreach ($campaignTypes as $ct) {
            if (!in_array($ct['platform'], $platforms)) {
                $platforms[] = $ct['platform'];
            }
        }

        // Build recommendations
        $recommendations = [];
        foreach ($template as $action) {
            foreach ($platforms as $platform) {
                $key = $action['event'] . ':' . $platform;
                if (in_array($key, $existingKeys)) continue;

                $recommendations[] = [
                    'project_id'    => $projectId,
                    'name'          => $action['name'],
                    'event_name'    => $platform === 'meta' ? ($action['meta_event'] ?? $action['event']) : $action['event'],
                    'platform'      => $platform,
                    'category'      => $action['category'],
                    'is_primary'    => $action['primary'] ? 1 : 0,
                    'trigger_type'  => $action['trigger'],
                    'trigger_value' => $this->inferTriggerValue($action, $project),
                    'default_value' => 0,
                    'status'        => 'planned',
                ];
            }
        }

        return ['business_type' => $businessType, 'actions' => $recommendations];
    }

    /**
     * Save planned actions to the database.
     *
     * @return int[] Inserted IDs
     */
    public function savePlan(int $projectId, array $actions): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'INSERT INTO conversion_actions (project_id, name, event_name, platform, category, is_primary, trigger_type, trigger_value, default_value, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $ids = [];
        foreach ($actions as $a) {
            $stmt->execute([
                $projectId,
                $a['name'],
                $a['event_name'],
                $a['platform'],
                $a['category'],
                $a['is_primary'],
                $a['trigger_type'] ?? null,
                $a['trigger_value'] ?? null,
                $a['default_value'] ?? 0,
                'planned',
            ]);
            $ids[] = (int) $db->lastInsertId();
        }

        Changelog::log($projectId, 'strategy', 'planned',
            count($ids) . ' conversion actions planned',
            ['action_ids' => $ids, 'count' => count($ids)]);

        return $ids;
    }

    /**
     * Provision a planned action on its platform.
     * Currently supports Google Ads. Meta and GA4 return setup instructions.
     */
    public function provision(int $actionId): array
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT ca.*, p.display_name, p.name as project_name FROM conversion_actions ca JOIN projects p ON p.id = ca.project_id WHERE ca.id = ?');
        $stmt->execute([$actionId]);
        $action = $stmt->fetch();

        if (!$action) return ['ok' => false, 'error' => 'Action not found'];
        if ($action['status'] !== 'planned') return ['ok' => false, 'error' => 'Action already provisioned'];

        $result = match ($action['platform']) {
            'google' => $this->provisionGoogle($action),
            'meta'   => $this->provisionMeta($action),
            'ga4'    => $this->provisionGA4($action),
            default  => ['ok' => false, 'error' => "Unknown platform: {$action['platform']}"],
        };

        // Update status
        $newStatus = $result['ok'] ? 'created' : 'failed';
        $db->prepare("UPDATE conversion_actions SET status = ?, external_id = ?, verification_note = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$newStatus, $result['external_id'] ?? null, $result['note'] ?? null, $actionId]);

        if ($result['ok']) {
            Changelog::log((int) $action['project_id'], 'strategy', 'created',
                "Conversion action '{$action['name']}' created on {$action['platform']}",
                ['action_id' => $actionId, 'external_id' => $result['external_id'] ?? null],
                'strategy', $actionId);
        }

        return $result;
    }

    /**
     * List all conversion actions for a project.
     */
    public static function listForProject(int $projectId): array
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM conversion_actions WHERE project_id = ? ORDER BY is_primary DESC, platform, name');
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    // ── Private helpers ─────────────────────────────────────────

    private function inferBusinessType(array $project, array $strategies): string
    {
        $signals = strtolower(
            ($project['description'] ?? '') . ' ' .
            ($project['website_url'] ?? '') . ' ' .
            ($project['display_name'] ?? '')
        );

        // Check strategy text for stronger signals
        foreach ($strategies as $s) {
            $signals .= ' ' . strtolower($s['full_strategy'] ?? '');
        }

        if (preg_match('/\b(shop|store|product|cart|checkout|merchant)\b/', $signals)) return 'ecommerce';
        if (preg_match('/\b(saas|software|trial|subscription|sign.?up|app)\b/', $signals)) return 'saas';
        if (preg_match('/\b(lead|form|quote|consultation|booking)\b/', $signals)) return 'lead_gen';
        return 'service';
    }

    private function inferTriggerValue(array $action, array $project): ?string
    {
        $url = $project['website_url'] ?? '';
        if (!$url) return null;

        return match ($action['event']) {
            'purchase'       => '/order-confirmation',
            'generate_lead'  => '/thank-you',
            'sign_up'        => '/welcome',
            'begin_checkout' => '/checkout',
            'add_to_cart'    => null, // JS event, not URL
            'view_item'      => null,
            'view_pricing'   => '/pricing',
            'contact'        => null, // Click event
            'phone_call'     => null, // Click event
            default          => null,
        };
    }

    private function provisionGoogle(array $action): array
    {
        try {
            $tracker = new \AdManager\Google\ConversionTracking();

            // Check if already exists
            $existing = $tracker->getConversionActionByName($action['name']);
            if ($existing) {
                return [
                    'ok' => true,
                    'external_id' => $existing['resource_name'],
                    'note' => 'Already exists in Google Ads',
                ];
            }

            $type = $action['trigger_type'] === 'click' ? 'CLICK_TO_CALL' : 'WEBPAGE';
            $resourceName = $tracker->createConversionAction(
                $action['name'],
                $type,
                $action['category'],
                (float) $action['default_value'],
                (bool) $action['is_primary']
            );

            return [
                'ok' => true,
                'external_id' => $resourceName,
                'note' => 'Created in Google Ads',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'note' => 'Google Ads API error: ' . $e->getMessage()];
        }
    }

    private function provisionMeta(array $action): array
    {
        // Meta conversion events can't be created via API — they're configured
        // via Events Manager or fire automatically when the pixel detects them.
        // We return setup instructions instead.
        $eventName = $action['event_name'];
        $instructions = "In Meta Events Manager:\n";
        $instructions .= "1. Ensure Pixel is installed on the site\n";
        $instructions .= "2. Standard event '{$eventName}' will fire automatically if GTM is configured\n";
        $instructions .= "3. For CAPI: add server-side event in your backend for '{$eventName}'\n";
        $instructions .= "4. Set event priority in Aggregated Event Measurement if iOS traffic is significant";

        return [
            'ok' => true,
            'external_id' => "meta_standard:{$eventName}",
            'note' => $instructions,
        ];
    }

    private function provisionGA4(array $action): array
    {
        // GA4 events are configured via GTM or measurement protocol.
        // We return the GTM configuration needed.
        $eventName = $action['event_name'];
        $trigger = $action['trigger_type'];
        $value = $action['trigger_value'];

        $instructions = "In GTM:\n";
        if ($trigger === 'url_match' && $value) {
            $instructions .= "1. Create trigger: Page View where Page Path contains '{$value}'\n";
        } elseif ($trigger === 'click') {
            $instructions .= "1. Create trigger: Click - All Elements with appropriate CSS selector\n";
        } else {
            $instructions .= "1. Create trigger: Custom Event matching '{$eventName}'\n";
        }
        $instructions .= "2. Create tag: GA4 Event with event name '{$eventName}'\n";
        $instructions .= "3. In GA4 Admin > Events, mark '{$eventName}' as conversion\n";
        $instructions .= "4. Import into Google Ads: Tools > Conversions > Import > GA4";

        return [
            'ok' => true,
            'external_id' => "ga4_event:{$eventName}",
            'note' => $instructions,
        ];
    }
}
