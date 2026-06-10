<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Semantic-search support (pgvector).
 *
 * PREREQUISITE: the pgvector extension must be installed on the Postgres
 * server BEFORE running this migration, otherwise "CREATE EXTENSION vector"
 * fails. On this machine (Arch/CachyOS, Postgres 18, pg_config present):
 *
 *     git clone --branch v0.8.0 https://github.com/pgvector/pgvector.git
 *     cd pgvector && make && sudo make install
 *
 * The embedding column stores the sentence-embedding vector for each ayah,
 * produced by the Python AI service (default model:
 * Omartificial-Intelligence-Space/GATE-AraBert-v1 => 768 dims).
 * If you switch models (EMBEDDING_MODEL in the AI service), change
 * EMBEDDING_DIM below to match the new model's dimension.
 *
 * The column is populated by the AI service's backfill script
 * (quran-semantic-search/backfill_embeddings.py); at query time the backend
 * embeds the user's query via POST /embed and ranks rows with the `<=>`
 * cosine-distance operator. See QuranController::search() (type=semantic).
 */
return new class extends Migration
{
    private const EMBEDDING_DIM = 768;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        DB::statement('ALTER TABLE ayahs ADD COLUMN IF NOT EXISTS embedding vector('.self::EMBEDDING_DIM.')');

        // HNSW = approximate nearest neighbour, cosine distance. Handles
        // incremental inserts, so creating it before backfill is fine at this scale.
        DB::statement('CREATE INDEX IF NOT EXISTS ayahs_embedding_hnsw_idx ON ayahs USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ayahs_embedding_hnsw_idx');
        DB::statement('ALTER TABLE ayahs DROP COLUMN IF EXISTS embedding');
        // Extension intentionally left installed.
    }
};
