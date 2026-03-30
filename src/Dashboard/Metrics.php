<?php

namespace AdManager\Dashboard;

/**
 * Shared metric computation — the single place where cost_micros becomes dollars.
 * All dashboard endpoints use this; callers never see cost_micros.
 */
class Metrics
{
    /**
     * Convert raw performance aggregates into decision-ready metrics.
     */
    public static function compute(array $raw): array
    {
        $costMicros = (int) ($raw['cost_micros'] ?? 0);
        $impressions = (int) ($raw['impressions'] ?? 0);
        $clicks = (int) ($raw['clicks'] ?? 0);
        $conversions = (float) ($raw['conversions'] ?? 0);
        $conversionValue = (float) ($raw['conversion_value'] ?? 0);
        $cost = $costMicros / 1_000_000;

        return [
            'impressions'     => $impressions,
            'clicks'          => $clicks,
            'cost'            => round($cost, 2),
            'ctr'             => $impressions >= 50 ? round(($clicks / $impressions) * 100, 2) : null,
            'conversions'     => round($conversions, 2),
            'conversion_rate' => $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : null,
            'cpa'             => $conversions > 0 ? round($cost / $conversions, 2) : null,
            'roas'            => $cost > 0 ? round($conversionValue / $cost, 2) : null,
            'conversion_value' => round($conversionValue, 2),
        ];
    }

    /**
     * Format a dollar amount for display.
     */
    public static function money(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    /**
     * Format a percentage for display.
     */
    public static function pct(?float $value): string
    {
        if ($value === null) return '—';
        return number_format($value, 1) . '%';
    }

    /**
     * Format a multiplier (ROAS) for display.
     */
    public static function roas(?float $value): string
    {
        if ($value === null) return '—';
        return number_format($value, 1) . 'x';
    }

    /**
     * Compute delta between current and prior period.
     * Returns ['value' => float|null, 'direction' => 'up'|'down'|'flat'|null].
     */
    public static function delta(?float $current, ?float $prior): array
    {
        if ($current === null || $prior === null || $prior == 0) {
            return ['value' => null, 'direction' => null];
        }
        $pct = (($current - $prior) / abs($prior)) * 100;
        $direction = abs($pct) < 1 ? 'flat' : ($pct > 0 ? 'up' : 'down');
        return ['value' => round($pct, 1), 'direction' => $direction];
    }
}
