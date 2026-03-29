<?php

namespace AdManager;

/**
 * Locale-aware spelling and terminology for ad copy.
 *
 * Maps US English spelling to locale variants. Use localise() to convert
 * US-authored copy to the target market's conventions.
 */
class Locale
{
    /**
     * US -> GB spelling map. Applied case-sensitively in order.
     * Space-suffixed entries prevent partial matches (e.g. "color " not "Colorado").
     */
    private const US_TO_GB = [
        // -ize / -ise
        'customize'    => 'customise',
        'Customize'    => 'Customise',
        'customizable' => 'customisable',
        'personalize'  => 'personalise',
        'Personalize'  => 'Personalise',
        'personalized' => 'personalised',
        'Personalized' => 'Personalised',
        'analyze'      => 'analyse',
        'Analyze'      => 'Analyse',
        'optimize'     => 'optimise',
        'Optimize'     => 'Optimise',
        'recognize'    => 'recognise',
        'Recognize'    => 'Recognise',
        'organize'     => 'organise',
        'Organize'     => 'Organise',
        'organization' => 'organisation',
        'Organization' => 'Organisation',
        'memorize'     => 'memorise',
        'summarize'    => 'summarise',
        'apologize'    => 'apologise',
        'maximize'     => 'maximise',
        'minimize'     => 'minimise',
        'digitize'     => 'digitise',
        'utilize'      => 'utilise',
        'prioritize'   => 'prioritise',
        'Prioritize'   => 'Prioritise',
        'specialize'   => 'specialise',
        'specialized'  => 'specialised',

        // -or / -our
        'coloring'  => 'colouring',
        'Coloring'  => 'Colouring',
        'colors'    => 'colours',
        'Colors'    => 'Colours',
        'color '    => 'colour ',
        'Color '    => 'Colour ',
        'favorite'  => 'favourite',
        'Favorite'  => 'Favourite',
        'favorites' => 'favourites',
        'flavor'    => 'flavour',
        'honor'     => 'honour',
        'humor'     => 'humour',
        'labor'     => 'labour',
        'neighbor'  => 'neighbour',

        // -er / -re
        'center'  => 'centre',
        'Center'  => 'Centre',
        'fiber'   => 'fibre',
        'liter'   => 'litre',
        'meter'   => 'metre',
        'theater' => 'theatre',

        // -ense / -ence
        'defense' => 'defence',
        'offense' => 'offence',
        'license' => 'licence',

        // -log / -logue
        'catalog'  => 'catalogue',
        'Catalog'  => 'Catalogue',
        'dialog'   => 'dialogue',
        'analog'   => 'analogue',

        // doubled consonants
        'traveling'  => 'travelling',
        'traveled'   => 'travelled',
        'traveler'   => 'traveller',
        'modeling'   => 'modelling',
        'modeled'    => 'modelled',
        'canceled'   => 'cancelled',
        'canceling'  => 'cancelling',
        'enrollment' => 'enrolment',
        'fulfill'    => 'fulfil',

        // other
        'gray'    => 'grey',
        'Gray'    => 'Grey',
        'jewelry' => 'jewellery',
        'aging'   => 'ageing',
        'check '  => 'cheque ',  // financial context — space to avoid "checklist"
        'plow'    => 'plough',
        'tire'    => 'tyre',

        // cultural terms
        'mom '  => 'mum ',
        'Mom '  => 'Mum ',
        'moms'  => 'mums',
        'Moms'  => 'Mums',
        'mommy' => 'mummy',
        'Mommy' => 'Mummy',

        // currency context handled separately (not a spelling issue)
    ];

    /**
     * GB markets (use British spelling).
     */
    private const GB_MARKETS = ['GB', 'UK', 'AU', 'NZ', 'IE', 'ZA', 'IN'];

    /**
     * US markets (use American spelling).
     */
    private const US_MARKETS = ['US', 'CA'];

    /**
     * Convert US-authored copy to the target market's spelling conventions.
     *
     * @param string $text      US English text
     * @param string $market    Target market code (US, GB, AU, etc.)
     * @return string           Localised text
     */
    public static function localise(string $text, string $market): string
    {
        $market = strtoupper($market);

        if (in_array($market, self::GB_MARKETS, true)) {
            return self::usToGb($text);
        }

        // US markets and unknown markets: return as-is (US is the authoring default)
        return $text;
    }

    /**
     * Apply US -> GB spelling conversions.
     */
    public static function usToGb(string $text): string
    {
        foreach (self::US_TO_GB as $us => $gb) {
            $text = str_replace($us, $gb, $text);
        }
        return $text;
    }

    /**
     * Apply GB -> US spelling conversions (reverse).
     */
    public static function gbToUs(string $text): string
    {
        $gbToUs = array_flip(self::US_TO_GB);
        foreach ($gbToUs as $gb => $us) {
            $text = str_replace($gb, $us, $text);
        }
        return $text;
    }

    /**
     * Determine if a market uses British or American spelling.
     */
    public static function spellingVariant(string $market): string
    {
        $market = strtoupper($market);
        if (in_array($market, self::GB_MARKETS, true)) return 'gb';
        return 'us';
    }

    /**
     * Get the prompt instruction for locale-aware copy generation.
     * Used by the strategy generator to tell Claude which spelling to use.
     */
    public static function promptInstruction(string $market): string
    {
        $variant = self::spellingVariant($market);
        if ($variant === 'gb') {
            return 'Use British English spelling throughout (colour, personalise, favourite, mum, centre, grey, etc.). Never use American spellings.';
        }
        return 'Use American English spelling throughout (color, personalize, favorite, mom, center, gray, etc.). Never use British spellings.';
    }
}
