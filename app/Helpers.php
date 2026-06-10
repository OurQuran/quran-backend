<?php

function extractModelAndIdFromNotFoundMessage($message)
{
    // Extract the part after "App\\Models\\"
    $modelStart = strpos($message, 'App\\Models\\');
    if ($modelStart === false) {
        return null;
    }

    // Find the start and end of the model name
    $modelStart += strlen('App\\Models\\');
    $modelEnd = strpos($message, ']', $modelStart);
    if ($modelEnd === false) {
        return null;
    }

    // Extract model name
    $model = substr($message, $modelStart, $modelEnd - $modelStart);

    // Extract the ID, assuming it's the last word in the message
    $parts = explode(' ', $message);
    $id = end($parts);

    return [$model, $id];
}

function convertFromPascalCaseToNormalCase($string)
{
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $string));
}

/**
 * Normalize an Arabic string for exact/lexical search.
 *
 * Mirrors the diacritic-stripping used to build ayahs.pure_text
 * (see App\Support\BaseAyahArtifactsGenerator::removeArabicDiacriticsFast),
 * so a user's query can be matched against pure_text regardless of the
 * tashkeel/hamza form they typed. Used by QuranController exact search.
 *
 * $daggerToAlef toggles the two corpus normalization forms (mirroring the
 * quran_search_norm_del / quran_search_norm_keep SQL functions):
 *   false (del):  dagger-alef (U+0670) is deleted   -> "الرحمـن" => "الرحمن"
 *   true  (keep): dagger-alef becomes a full alef    -> "القيمة"+dagger => "القيامة"
 * Per-word highlighting checks both so it matches the same words exact search does.
 */
function normalizeArabicForSearch(string $text, bool $daggerToAlef = false): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    // Unify alef/hamza forms.
    $text = str_replace(['ٱ', 'آ', 'أ', 'إ'], ['ا', 'ا', 'ا', 'ا'], $text);

    // Keep form: turn the superscript (dagger) alef into a real alef before stripping.
    if ($daggerToAlef) {
        $text = preg_replace('/\x{0670}/u', 'ا', $text);
    }

    // Strip combining marks (tashkeel), Quranic annotation signs, the tatweel
    // elongation, and zero-width chars. Tatweel (U+0640) is cosmetic and kept
    // in ayahs.pure_text (e.g. "الرحمـن"), but users never type it — the exact
    // search SQL strips it from pure_text too so both sides match.
    $pattern = '/['.
        '\x{064B}-\x{0652}'.   // tanwin + harakat + sukun
        '\x{0653}-\x{065F}'.   // maddah, hamza-above/below, other marks
        '\x{0640}'.            // tatweel (kashida)
        '\x{0670}'.            // superscript alef
        '\x{06D6}-\x{06ED}'.   // Quranic annotation signs
        '\x{08F0}-\x{08FF}'.   // Arabic Extended-A marks
        '\x{200C}\x{200D}\x{FEFF}'. // ZWNJ, ZWJ, BOM
        ']/u';

    $pure = preg_replace($pattern, '', $text);
    $pure = preg_replace('/\s+/u', ' ', (string) $pure);

    return trim((string) $pure);
}
