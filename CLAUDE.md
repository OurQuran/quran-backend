# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Laravel 12 REST API backend for labelling, annotating, and serving Quran verses with full support for the 10 canonical Qiraat (readings). The project tracks word-level and ayah-level differences across readings and exposes them as precompiled HTML for frontend consumption.

The base reading is **Asim/Hafs** (`asim_hafs`). All other readings are mapped relative to it via `mushaf_ayah_to_ayah_map` and `mushaf_word_to_word_map`.

---

## Common Commands

```bash
# Dev server
php artisan serve

# Migrate
php artisan migrate

# Seed (qiraat readings, books)
php artisan db:seed --class=QiraatReadingsSeeder
php artisan db:seed --class=BooksSeeder

# Run tests
php artisan test
php artisan test --filter=SomeTestClass

# Code style
./vendor/bin/pint
```

---

## Architecture

### API Layer

- All routes are JSON REST, served from `routes/api.php`
- Subroutes auto-loaded from `routes/subroutes/*.php` (bookmarks, books, dictionary, qiraats, surahs, tags, users)
- Auth via Laravel Sanctum (`auth:sanctum` middleware)
- All controllers extend `App\Http\Controllers\Controller`, which provides `apiSuccess($data, $message)` and `apiError($message, $statusCode)` helpers
- Standard response shape: `{ success, message, data, error }`

### Data Model

| Table | Purpose |
|---|---|
| `ayahs` | Base Hafs ayah text (canonical source of truth) |
| `words` | Base Hafs word-level tokens |
| `mushaf_ayahs` | Per-reading ayah text (all qiraats including Hafs) |
| `mushaf_words` | Per-reading word tokens |
| `mushaf_ayah_to_ayah_map` | Maps a `mushaf_ayah` → base `ayah` |
| `mushaf_word_to_word_map` | Maps a `mushaf_word` → base `word` |
| `qiraat_readings` | 20 readings (imam/riwaya/name are JSON columns; `code` is stable string key) |
| `qiraat_differences` | Raw difference records |
| `qiraat_diff_ayahs` / `qiraat_diff_words` | Precompiled HTML diff output per reading |

### Qiraat Pipeline

Source data comes from `/home/nightcore/Work/quran-data-kfgqpc` (XML files) and Excel sheets (for some readings). The full per-qiraat pipeline is:

1. **Import** mushaf ayahs (XML or Excel) → `mushaf_ayahs`
2. **Auto-map** ayahs by text → `mushaf_ayah_to_ayah_map`
3. **Repair** and exact-map missed ayahs
4. **Process** mushaf words → `mushaf_words`
5. **Generate word artifacts** (pure_word, word_template)
6. **Auto-map words** → `mushaf_word_to_word_map`
7. **Repair** word maps
8. **Generate ayah artifacts** (pure_text, ayah_template)

**Orchestration commands** (in `routes/console.php`):

```bash
# Full pipeline for one qiraat (accepts id or stable code)
php artisan process:qiraat-mushaf asim_hafs
php artisan process:qiraat-mushaf 7

# Batch pipelines
php artisan process:mushaf-xml          # all XML-sourced readings
php artisan process:mushaf-excel        # all Excel-sourced readings
php artisan process:mushaf-words-map    # word mapping for all non-base readings

# Generate mushaf template (base Hafs)
php artisan generate:mushaf-template

# Precompile diff HTML (run after word mapping)
php artisan qiraat:precompile-diff-html auto
php artisan qiraat:precompile-diff-html 7
```

All pipeline commands accept `--dry-run`.

### Commands Structure

Commands are grouped into subdirectories under `app/Console/Commands/`:

- `Ayahs/` (`App\Console\Commands\Ayahs`) — import:ayahs, process:ayahs, generate:ayah-artifacts, generate:surah-html
- `Words/` (`App\Console\Commands\Words`) — generate:word-artifacts
- `MushafAyahs/` (`App\Console\Commands\MushafAyahs`) — qiraat:import-mushaf-ayahs-xml/excel, process:ayahs-mushaf, qiraat:auto-map-by-text, qiraat:map-mushaf-ayahs, qiraat:repair-and-exact-map, qiraat:verify-ayah-map, generate:ayah-artifacts-mushaf
- `MushafWords/` (`App\Console\Commands\MushafWords`) — qiraat:auto-map-words, qiraat:repair-word-maps, generate:word-artifacts-mushaf
- `Qiraat/` (`App\Console\Commands\Qiraat`) — qiraat:import-differences-excel, qiraat:precompile-diff-html, qiraat:clear-differences
- `Books/` (`App\Console\Commands\Books`) — books:import-auto, books:import-html-folder
- `Tags/` (`App\Console\Commands\Tags`) — tags:import-from-sql

Laravel auto-discovers all commands recursively — no registration needed.

### Support Classes

- `App\Support\QiraatImportMaps` — central registry of all 20 reading definitions, XML/Excel file mappings, and helpers to resolve reading IDs by code
- `App\Support\BaseAyahArtifactsGenerator` / `BaseWordArtifactsGenerator` — shared generator logic for base (Hafs) artifacts
- `App\Support\MushafAyahArtifactsGenerator` / `MushafWordArtifactsGenerator` — generator logic for mushaf (per-reading) artifacts
- `App\Console\Concerns\InteractsWithCsvReports` — CSV report generation for import/mapping commands
- `App\Console\Concerns\UpsertsMushafAyahs` — shared upsert logic for mushaf ayah import commands

### Helpers

Global helpers live in `app/Helpers.php` (autoloaded). Pipeline utility functions (`runCmd`, `runCmdAndGc`, `resolveQiraatPipelineTarget`) are defined directly in `routes/console.php`. `normalizeArabicForSearch()` strips diacritics/tashkeel from a query so it can be matched against `ayahs.pure_text` (mirrors `BaseAyahArtifactsGenerator::removeArabicDiacriticsFast`).

---

## Search (Exact + Semantic)

Endpoint: `GET /search?q=...&type=exact|semantic` → `QuranController::search()`. Both modes produce a relevance-ordered ayah-id list (capped at `SEARCH_CANDIDATE_CAP`), then hydrate it through the shared `filterAyahs()` pipeline (editions / qiraat / tags), re-sorting the page to preserve ranking.

- **Exact search** runs **entirely in Postgres** — no AI service. The query is diacritic-normalized and matched (ILIKE substring) against `pure_text` (raw `text` as fallback), ranked by `pg_trgm` `similarity()`. Requires the `pg_trgm` extension + GIN trigram indexes (migration `enable_pg_trgm_for_exact_search`).
- **Semantic search** ranks against per-ayah embedding vectors using **pgvector**'s cosine-distance operator (`embedding <=> :vec`). The only AI involvement is embedding the *user's query*: the backend calls the Python service `POST /embed`, gets a 768-dim vector, and runs the pgvector query itself. Requires the `vector` extension + `ayahs.embedding vector(768)` column + HNSW index (migration `add_embedding_to_ayahs_for_semantic_search`).

### Relationship with the `quran-semantic-search` (AI) service

A separate FastAPI/Python project at `/home/nightcore/Projects/quran-semantic-search` owns the embedding **model** (default `Omartificial-Intelligence-Space/GATE-AraBert-v1`, an Arabic-tuned sentence model, 768 dims; overridable via `EMBEDDING_MODEL`/`EMBEDDING_DIM` env). It is a stateless **embedder**, not the search engine:

- `POST /embed { "text": "..." }` → `{ "vector": [...], "dim": 768, "model": "..." }` — called by the backend at query time. (`{ "texts": [...] }` batch form is used for indexing.)
- `backfill_embeddings.py` (in that project) reads ayahs from **this same Postgres DB** and writes the `ayahs.embedding` column. It embeds each ayah's **meaning** — the **tafsir** (`ayah_edition` edition 1 = تفسير الميسر) **+ curated tag names** (`ayah_tags` → `tags.name`) — *not* the raw Arabic text (a sentence model ranks bare classical strings like the muqatta'at "الم" by length, not meaning). Word/letter matching is exact search's job (pg_trgm); semantic is purely meaning. Run it once after the pgvector migration, and again whenever tafsir/tags change or the model changes.
- Legacy `POST /search` (in-memory pickle similarity) still exists but is **not** used by the backend search flow.

Source of truth is this backend's Postgres. The AI service holds no ayah data — it only converts text → vectors. Config: `config/services.php` `ai.url` (env `AI_URL`). If the model/dimension changes, update **both** the `vector(N)` migration here and `EMBEDDING_DIM`/`MODEL_NAME` in the AI service.

---

## Key Conventions

- Qiraat readings are referenced by either their numeric DB id or their stable string `code` (e.g. `asim_hafs`, `nafi_warsh`). Commands accept both interchangeably.
- `imam`, `riwaya`, and `name` columns on `qiraat_readings` are JSON objects with `en`, `ar`, `ku` keys.
- All heavy commands set `memory_limit` to `512M` via `ini_set`.
- PHPSpreadsheet is used for Excel imports (`phpoffice/phpspreadsheet`).
- Model stubs are generated by Reliese (`reliese/laravel`).
