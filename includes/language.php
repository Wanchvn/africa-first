<?php
/**
 * Qarota — language detection and helpers.
 */

/**
 * Detect a post's language from its text content.
 *
 * Lightweight heuristic — good enough for ranking, not for translation.
 * Returns a 2-letter code ('en', 'tw', 'fr', 'dg') or null if unknown.
 *
 * @param string $text    The post body
 * @param string|null $userDefault  The author's preferred language, used as fallback
 */
function detect_post_language(string $text, ?string $userDefault = null): ?string
{
    $text = trim($text);
    if ($text === '') {
        return $userDefault ?: null;
    }

    // Normalize: lowercase, strip punctuation
    $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($text));
    $clean = preg_replace('/\s+/', ' ', trim($clean));
    if ($clean === '') {
        return $userDefault ?: null;
    }

    // High-signal common words per language
    $markers = [
        'en' => ['the', 'and', 'you', 'for', 'with', 'this', 'that', 'are', 'is', 'have', 'was', 'will', 'not', 'but', 'what', 'when', 'how', 'who', 'yes', 'no'],
        'tw' => ['na', 'me', 'wo', 'ne', 'mu', 'ma', 'ye', 'no', 'bi', 'de', 'ka', 'kɔ', 'nso', 'nnipa', 'akwaaba', 'medaase', 'ɛyɛ'],
        'dg' => ['ka', 'ni', 'n', 'ya', 'di', 'do', 'yɛ', 'bɛ', 'salim', 'laa', 'sɔɣi', 'kpamba', 'ninsala'],
        'fr' => ['le', 'la', 'les', 'des', 'une', 'un', 'est', 'et', 'que', 'pour', 'dans', 'avec', 'pas', 'vous', 'nous', 'sur', 'mais', 'tout', 'bien', 'comment'],
    ];

    $tokens = explode(' ', $clean);
    $scores = array_fill_keys(array_keys($markers), 0);

    foreach ($tokens as $tok) {
        foreach ($markers as $lang => $words) {
            if (in_array($tok, $words, true)) {
                $scores[$lang]++;
            }
        }
    }

    arsort($scores);
    $top_lang  = array_key_first($scores);
    $top_score = $scores[$top_lang];

    // Require at least 2 marker hits to make a claim.
    if ($top_score >= 2) {
        return $top_lang;
    }

    return $userDefault ?: null;
}

/**
 * Parse a user's languages field into an array of 2-letter codes.
 */
function parse_user_languages(?string $languages): array
{
    if (empty($languages)) return [];

    $map = [
        'english'    => 'en',
        'en'         => 'en',
        'twi'        => 'tw',
        'akan'       => 'tw',
        'tw'         => 'tw',
        'dagbani'    => 'dg',
        'dg'         => 'dg',
        'french'     => 'fr',
        'français'   => 'fr',
        'francais'   => 'fr',
        'fr'         => 'fr',
    ];

    $tokens = preg_split('/\s*,\s*/', mb_strtolower(trim($languages)));
    $codes = [];
    foreach ($tokens as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        if (isset($map[$tok])) {
            $codes[] = $map[$tok];
        }
    }

    return array_values(array_unique($codes));
}

/**
 * Human label for a 2-letter language code.
 */
function language_label(string $code): string
{
    return match ($code) {
        'en' => 'English',
        'tw' => 'Twi',
        'dg' => 'Dagbani',
        'fr' => 'French',
        default => strtoupper($code),
    };
}