<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * qiraat_differences.explanation was a Kurdish text. It becomes a jsonb object with the project's usual
 * language keys, e.g. {"ku": "...", "ar": "..."}. Existing text moves to the "ku" key; Arabic is added by
 * `qiraat:import-explanations-ar`; a missing language simply has no key.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE qiraat_differences
            ALTER COLUMN explanation TYPE jsonb
            USING CASE
                WHEN explanation IS NULL OR btrim(explanation) = '' THEN NULL
                ELSE jsonb_build_object('ku', explanation)
            END
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE qiraat_differences
            ALTER COLUMN explanation TYPE text
            USING explanation->>'ku'
        ");
    }
};
