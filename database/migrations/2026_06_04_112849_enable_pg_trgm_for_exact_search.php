<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exact-search support (pg_trgm). Runs entirely in Postgres — no AI service
 * (see QuranController::search() with type=exact).
 *
 * Arabic orthography: the uthmani `text` marks every long a with a dagger-alef
 * (U+0670) and uses tatweel (U+0640), neither of which users type. Modern
 * spelling writes a full alef for SOME dagger positions (sabirin, qiyama) but
 * not others (rahman). So each ayah is normalized into TWO forms and a query
 * may match either:
 *
 *   - quran_search_norm_del(text):  dagger-alef DELETED -> matches "alrahman"
 *   - quran_search_norm_keep(text): dagger-alef -> alef  -> matches "alqiyama"/"alsabirin"
 *
 * Both forms also collapse the silent waw-seat alef (waw+U+0670 -> alef, so
 * salwah/zakwah match salah/zakah), unify hamza/alef variants, strip tashkeel +
 * Quranic annotation marks + tatweel, and collapse whitespace — mirroring
 * normalizeArabicForSearch() (app/Helpers.php), which normalizes the user's
 * query the same way. Validated corpus-wide against pure_text.
 *
 * Mark stripping uses translate() over an explicit chr() codepoint list (no
 * regex character ranges) so the source stays ASCII and unambiguous. Codepoints:
 * 1649=alef-wasla 1570=alef-madda 1571=alef-hamza-above 1573=alef-hamza-below
 * 1648=dagger-alef 1600=tatweel 1608=waw 1575=alef.
 *
 * Functional GIN trigram indexes on the identical expressions keep ILIKE /
 * similarity() fast; QuranController::exactSearchIds() calls these same
 * functions so the indexes are used.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $marks = 'chr(1552)||chr(1553)||chr(1554)||chr(1555)||chr(1556)||chr(1557)||chr(1558)||chr(1559)||chr(1560)||chr(1561)||chr(1562)||chr(1611)||chr(1612)||chr(1613)||chr(1614)||chr(1615)||chr(1616)||chr(1617)||chr(1618)||chr(1619)||chr(1620)||chr(1621)||chr(1750)||chr(1751)||chr(1752)||chr(1753)||chr(1754)||chr(1755)||chr(1756)||chr(1757)||chr(1758)||chr(1759)||chr(1760)||chr(1761)||chr(1762)||chr(1763)||chr(1764)||chr(1765)||chr(1766)||chr(1767)||chr(1768)||chr(1769)||chr(1770)||chr(1771)||chr(1772)||chr(1773)||chr(2288)||chr(2289)||chr(2290)||chr(2291)||chr(2292)||chr(2293)||chr(2294)||chr(2295)||chr(2296)||chr(2297)||chr(2298)||chr(2299)||chr(2300)||chr(2301)||chr(2302)||chr(2303)||chr(8204)||chr(8205)||chr(65279)';

        // dagger-alef DELETED form.
        DB::statement(
            "CREATE OR REPLACE FUNCTION quran_search_norm_del(t text) RETURNS text AS \$\$
                SELECT btrim(regexp_replace(
                    translate(
                        translate(replace(t, chr(1608)||chr(1648), chr(1575)),
                            chr(1649)||chr(1570)||chr(1571)||chr(1573)||chr(1648)||chr(1600),
                            chr(1575)||chr(1575)||chr(1575)||chr(1575)),
                        $marks, ''),
                    '\\s+', ' ', 'g'))
            \$\$ LANGUAGE sql IMMUTABLE"
        );

        // dagger-alef -> alef form.
        DB::statement(
            "CREATE OR REPLACE FUNCTION quran_search_norm_keep(t text) RETURNS text AS \$\$
                SELECT btrim(regexp_replace(
                    translate(
                        translate(replace(t, chr(1608)||chr(1648), chr(1575)),
                            chr(1649)||chr(1570)||chr(1571)||chr(1573)||chr(1648)||chr(1600),
                            chr(1575)||chr(1575)||chr(1575)||chr(1575)||chr(1575)),
                        $marks, ''),
                    '\\s+', ' ', 'g'))
            \$\$ LANGUAGE sql IMMUTABLE"
        );

        DB::statement('CREATE INDEX IF NOT EXISTS ayahs_search_norm_del_trgm_idx ON ayahs USING gin (quran_search_norm_del(text) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS ayahs_search_norm_keep_trgm_idx ON ayahs USING gin (quran_search_norm_keep(text) gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ayahs_search_norm_del_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS ayahs_search_norm_keep_trgm_idx');
        DB::statement('DROP FUNCTION IF EXISTS quran_search_norm_del(text)');
        DB::statement('DROP FUNCTION IF EXISTS quran_search_norm_keep(text)');
        // pg_trgm extension intentionally left installed; other features may rely on it.
    }
};
