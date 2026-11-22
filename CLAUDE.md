# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Laravel 12 REST API backend for labelling, annotating, and serving Quran verses with full support for the 10 canonical Qiraat (readings). The project tracks word-level and ayah-level differences across readings and exposes them as precompiled HTML for frontend consumption.

The base reading is **Asim/Hafs** (`asim_hafs`). All other readings are mapped relative to it via `mushaf_ayah_to_ayah_map` and `mushaf_word_to_word_map`.

---

## The Quran Ecosystem (four sibling projects)

This repo is one of four projects that together make up the product. Work often spans them, so know where each piece lives:

| Project | Path | Role |
|---|---|---|
| **Backend** (this repo) | `/home/nightcore/Projects/quran-backend` | Laravel 12 API + Postgres. Source of truth for ayahs, qiraat, tags, books. |
| **Frontend** | `/home/nightcore/Projects/quran-frontend` | Next.js app that consumes this API. See "Frontend" below. |
| **Data-Cleaning** | `/home/nightcore/Projects/Data-Cleaning` | Python scripts that clean the source `.docx`/PDF/Excel material so it can be imported here. See "Data-Cleaning" below. |
| **Semantic search (AI)** | `/home/nightcore/Projects/quran-semantic-search` | FastAPI embedder used by semantic search. See "Search" below. |

Data flow: raw Word docs / Excel sheets → **Data-Cleaning** → cleaned `.xlsx` / `.html` → **Backend** import commands → Postgres → JSON API → **Frontend**.

Cross-project couplings to keep in mind when changing things:
- Frontend base URL is `NEXT_PUBLIC_BASE_API` (in the frontend's `.env`) and it expects the `{ success, message, data, error }` response envelope. Changing that shape or any route breaks the frontend.
- `qiraat:audit-text` loads fonts from the sibling frontend (`../quran-frontend/public/fonts/quran/quran_font_4.ttf` + the Noto Naskh fallback) to check glyph coverage — keep those paths valid.
- Tajweed HTML emitted by the backend uses `tajweed-*` CSS classes (e.g. `tajweed-hamza-wasl`, `tajweed-madda-normal`); the frontend styles them in `src/index.css` and describes them in `src/helpers/tajweed.ts` (single-letter codes `h l n p o m q t g i f c w a u d b s`). Renaming a class on one side requires the other.
- Backend and frontend both use three languages: `en`, `ar`, `ku` (Kurdish Sorani). Multilingual JSON columns / responses use those exact keys.

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
- Subroutes auto-loaded from `routes/subroutes/*.php` (bookmarks, books, dictionary, qiraats, roles, surahs, tags, users)
- Auth via Laravel Sanctum (`auth:sanctum` middleware). Roles/permissions use `spatie/laravel-permission`; `routes/subroutes/roles.php` is gated by `permission:users.manage,api` (`RolesController`)
- Public (unauthenticated) content endpoints include `/privacy-policy`, `/terms-and-conditions`, `/data-and-compliance` (`LegalPageController`, multilingual)
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

- `Ayahs/` (`App\Console\Commands\Ayahs`) — import:ayahs, process:ayahs, generate:ayah-artifacts, generate:surah-html, import:tajweed-edition (tajweed HTML edition from per-surah JSON), plus a quran.com QCF tajweed importer
- `Words/` (`App\Console\Commands\Words`) — generate:word-artifacts
- `MushafAyahs/` (`App\Console\Commands\MushafAyahs`) — qiraat:import-mushaf-ayahs-xml/excel, process:ayahs-mushaf, qiraat:auto-map-by-text, qiraat:map-mushaf-ayahs, qiraat:repair-and-exact-map, qiraat:verify-ayah-map, generate:ayah-artifacts-mushaf
- `MushafWords/` (`App\Console\Commands\MushafWords`) — qiraat:auto-map-words, qiraat:repair-word-maps, generate:word-artifacts-mushaf
- `Qiraat/` (`App\Console\Commands\Qiraat`) — qiraat:import-differences-excel, qiraat:precompile-diff-html, qiraat:clear-differences, qiraat:audit-text (Unicode / glyph-coverage / suspicious-pattern audit of DB rows and source files; writes a CSV report)
- `Books/` (`App\Console\Commands\Books`) — books:import-html-folder (the only book importer; see "Books" below)
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

## Books (qiraat reading books)

The `books` / `book_sections` / `book_section_refs` tables hold the long-form Word-document books (one per reading/riwaya), served read-only by `BookController` (`GET /books`, `/books/{id}`, `/books/{id}/sections`, `/books/{id}/sections/{order_no}`, `/books/{id}/download` for the PDF).

`body_text` is plain text, so the importer keeps structure with two conventions the viewer relies on: a **table row is one line with cells separated by ` │ `** (`ImportHtmlBooksFromFolder::CELL_SEP`, mirrored by `TABLE_CELL_SEP` in the frontend's `SectionContent.tsx`, which rebuilds consecutive such lines into a real `<table>`), and a newline inside HTML text is just whitespace (pandoc hard-wraps at ~72 cols — keeping those newlines cut every paragraph into fragments). Heading text is whitespace-collapsed. Changing either convention means re-importing every book.

They are loaded **only** by `php artisan books:import-html-folder [--dir=] [--language=ku|ar] [--split-level=1] [--force] [--dry-run]`, which reads `*.html` from `--dir` (default `<project_root>/html_files/`) (`01.html`, `02.html`, … each with a sibling `NN_images/` dir). The `<title>` becomes the book name, content is split on `<h1>` (configurable) into sections, stored as plain text with footnote refs (with offsets) and base64-inlined images. Each book has a `language` (`ku` = the Kurdish editions, the default for all pre-existing rows; `ar` = the Arabic "الدرر الحسان" editions). A book is identified by (`name`, `language`), so an Arabic and a Kurdish book share the same canonical Arabic `name` (resolved from filename/`<title>` keywords by `QiraatImportMaps::booksByFilenameKeyword()`); `GET /books?language=ar|ku` filters, and every book payload carries `language`. Without the filter `/books` returns both languages — the frontend's `BooksClient` currently calls it unfiltered. Arabic books have no PDF/author rows. That HTML is produced by the Data-Cleaning project (below) — if book content looks wrong, fix it there and re-import with `--force`, not in the DB.

---

## Frontend (`/home/nightcore/Projects/quran-frontend`)

Next.js App Router + React + TypeScript + Tailwind + shadcn/Radix UI. (Its `README.md` is the stale Vite template — ignore it.) No test runner is configured.

```bash
npm run dev      # dev server
npm run build    # production build
npm run lint     # next lint
```

- **API base**: `NEXT_PUBLIC_BASE_API` in `.env` (gitignored; only `NEXT_PUBLIC_*` reaches the browser). Single axios instance in `src/api/axiosInstance.ts` attaches `Authorization: Bearer <token>` from localStorage and types the `{ success, message, data, error }` envelope (`IApiResponse`, plus `IPaginationResponse` / `IMeta` for paging).
- **i18n routing**: every page lives under `src/app/[locale]/…` with locales `en | ar | ku` (`src/i18n-config.ts`, default `en`; translations in `src/translation/{en,ar,ku}.json`). `src/proxy.ts` (the Next request interceptor) redirects locale-less URLs and enforces auth via the `token` cookie: `dashboard` and `bookmarks` require it; `login`/`signup` redirect away when logged in. Add new protected sections to `protectedRoutes` there.
- **Page pattern**: each route is a server `page.tsx` (metadata/SEO, `JsonLd`) plus a `*Client.tsx` that does the interactive fetching. `src/services/dataService.ts` wraps fetches in React `cache()` so `generateMetadata` and the page share one API call.
- **Data layer**: React Query wrappers in `src/react-query/` (`useGet`, `useAdd`, `useUpdate`, `useDelete`, `useInfiniteScroll`, `auth/*`); Zustand stores in `src/store/` (auth, qiraat list, edition, audio, surah ids).
- **Rendering Quran text**: the backend sends precompiled HTML (ayah text, tajweed spans, qiraat diff markup) and the frontend injects it with `dangerouslySetInnerHTML` (`AyahCard.tsx`, `MushafPageClient.tsx`). Selecting a non-base qiraat shows the differences + explanation alongside Hafs.
- **Fonts**: Quran fonts are shipped locally in `public/fonts/quran/` (`quran_font_1..5`, `hafs/`, Noto Naskh fallback) and Kurdish fonts in `public/fonts/kurdish/`, wired up in `src/lib/fonts.ts`. A glyph missing from these shows as tofu — this is exactly what `qiraat:audit-text` checks on the backend side.
- **Books page**: `reading-books/books/BooksClient.tsx` has a Kurdish/Arabic edition switch and sends `language=ku|ar` to `GET /books` (default: `ar` for the Arabic UI locale, otherwise `ku`).
- **Main routes**: `surah/[id]`, `surahs/page/[pageNumber]` (mushaf pages), `tags`, `advanced-search`, `bookmarks`, `reading-books/{books,qiraats}/[id]`, `site-index`, and an admin `dashboard/{tags,unapproved,users}`.

---

## Data-Cleaning (`/home/nightcore/Projects/Data-Cleaning`)

Plain Python scripts (no package/test suite; Windows-authored, `.venv` is gitignored) that turn the client-supplied Word/PDF/Excel material into the cleaned `.xlsx` and `.html` this backend imports. Deps: `pandas python-docx lxml openpyxl` (`Requirements/requirements.txt`); `pandoc` is needed for the HTML step. Scripts hardcode their input/output paths in a CONFIG block at the top (relative to the repo root, e.g. `./data/docs/IbnAamr.docx`), so edit the constants and run from the repo root: `python Code/<script>.py`.

Two independent pipelines:

1. **Differences spreadsheets** (`Code/step_1_…` → `step_4_…`, `main.py` is the older single-file version). Extract the per-reading difference tables from `.docx` → split into normalized sheets with columns `Surah, Ayah Number, Hafs Kalma, Qeraa Options, Wasf` → expand one-word rows (repeat multipliers like «معاً/الثلاثة») → split multi-word phrases on `؛`. Output feeds `qiraat:import-differences-excel` (which expects `qiraat_reading_id, ayah_id, hafs_text, qiraat_text, explanation`), so column naming between the two projects must line up. Key gotcha: the source Kurdish text is typed in legacy `Ali_K_*` fonts using **Arabic codepoints**, so extraction remaps characters per run font (`replacements`: `ة→ە ط→گ ث→پ ي→ی …`; `Ali_A_*` runs are left alone except the special `ه` case). Any new Kurdish extraction must keep this font-aware mapping or the text is silently wrong.
2. **Books** (`data/docs/`). Clean the `.docx` (`Code/remove_headers_and_footers.py`, `remove_specific_image.py`, `remove_extra_spaces.py`, plus the font tools `clean_docx_fonts_xml.py`, `font_converter.py`, `fix_final_font_mapping.py`, `find_fonts.py`, `highlight_fonts.py`, `log_docx_fonts_*.py`) → drop results into `data/docs/Cleaner/NN.docx` → `data/docs/convert_all.sh` runs `pandoc -t html5 --extract-media` into `html_files/` (this is what gets copied into the backend's `html_files/`). `convertalltohtml.sh` + `html_to_book.py` build standalone paginated reader HTML for review; `converttomd.sh` produces Markdown. Helpers: `target_brakets.py` (`{…}`→`<…>` when it contains `:`, RTL runs only), `to_eastern_arabic.py` (its `maketrans` currently maps Eastern→Western digits, the **opposite** of its name/docstring — check before use), `process_word_files.py` (uses `win32com`, Windows + MS Word only).

**Batch pipeline for new Word files (Linux, no MS Word)** — `Code/clean_batch.py` is a port of the Word-macro steps (`process_word_files.py` + header/footer, banner-image and `remove_extra_spaces` scripts) that works on the `.docx` XML (body + footnotes + endnotes) with lxml. Usage: `python Code/clean_batch.py --profile ku|ar IN_DIR OUT_DIR` (writes cleaned `.docx` + `clean_report.json`), then `python Code/docx_to_html_batch.py ku|ar CLEANED_DIR HTML_DIR` (pandoc, run from inside `HTML_DIR` so `<img src>` is relative to it). Last run's working tree: `data/docs/batch2/{input_ku,input_ar,cleaned_*,html_ku,html_ar}` (`html_ku/NN.html`, `html_ar/ar_NN_<slug>.html`; import with `--dir=… --language=…`). Both profiles remove reviewer pen-mark / hidden-shape floating images and the 5.21×1.70in banner (inline content figures stay), empty all headers/footers, collapse spaces, drop blank paragraphs and fold multi-paragraph Titles (pandoc only uses one Title as `<title>`). `ku` also applies the macro text rules (`Arial→Times New Roman`, `لَ→ڵ` in TNR runs only, `( ) [ ]→{ } ‹ ›`, superscript bracket removal). `ar` instead: converts legacy symbol glyphs to Unicode, normalises presentation forms (`ﭐ→ٱ`, QCF `ﭽﭼ→﴿﴾`), cuts the printed table of contents, and promotes 18/20pt non-table titles (`باب…`, `فصل…`, `القسم…`) to Heading 1 — the Arabic `.doc` files have no heading styles and the importer splits on `<h1>`; headings inside tables are ignored by the importer, so the per-surah paragraphs «( N ) سورة X» that precede the فرش tables of parts 2/3 are also promoted (rewritten as «القسم الثاني - سورة X») — without that each Arabic book imported as ~30 sections with one 200k–970k-char blob; now ~257 (114 surahs × 2 parts; Hamza's part 3 has 98 because that author omitted surahs with no differences; the sources contain typos like «سـورة»/«سوة» and a repeated «( 11 )» that the matcher tolerates). Both profiles also re-emit **text-box / diagram labels** as paragraphs after their anchor (pandoc drops text boxes: ~1.9k chars in Kurdish Warsh, ~5k in Hamza), and `ku` re-promotes the short 18pt chapter titles of books with < 20 Heading 1s (only Kurdish Warsh 05c lost its heading styles: 12 → 39 sections). Verify completeness with a digit-insensitive check of every cleaned-docx paragraph against the generated HTML (last run: all present). Legacy `.doc` must first be converted: `soffice --headless --convert-to docx`.

**PDFs** (`Code/docx_to_pdf_batch.py IN_DIR OUT_DIR`): cleaned `.docx` → LibreOffice PDF on a copy where every font is mapped to the installed *Scheherazade New* (the sources name Windows-only fonts), each section gets a centred page-number footer, header borders are stripped (they print as a rule on every page), and the document title is set; PDFs carry heading bookmarks. `clean_batch.py` also removes the orphan «الفهارس» heading and trailing blank paragraphs / an empty final section break so books don't end on a blank page. Output of the last run: `data/docs/batch2/pdf_ar/` (8) and `pdf_ku/` (11). The Arabic PDFs are copied to `storage/app/private/books/` as `كتاب <imam> -النسخة العربية.pdf` and linked by `QiraatImportMaps::arabicBookPdfByName()` (the importer sets `books.pdf_path` for `--language=ar` when the file exists, which is what makes `has_pdf`/the frontend download button appear). The 11 existing Kurdish PDFs in that folder are older typeset files and were deliberately NOT replaced by `pdf_ku/` (those are plain LibreOffice renders: no cover, much smaller).

**Arabic Quran text is typed in the legacy `Msh Quraan1` symbol font** (`alfont_com_AlFont_com_MSHQ.ttf`, stored as `<w:sym>` or as private-use chars in `<w:t>`), ~13k glyphs in each of Abu Amr/Hamza/Ibn Amir/Kisai; pandoc silently drops them, so decoding is mandatory. `Code/mshq_decode.py` holds the glyph tables, derived by aligning ~3000 words against the Hafs `ayahs.text` in this DB: glyphs inside a word are in visual (reversed) order, marks belong to the preceding letter, a few glyphs mutate the previous letter (wasla/hamza/madda/ظ/غ), glyph `70` (sukun) attaches two letters back after dotted "tooth" forms, ayah-number frames keep stream order and become `۝<digits>`. It matches 92% of aligned words exactly against Hafs; the rest are mostly genuine qiraat variants (the point of these books). Honorific glyphs of the `AGA Arabesque` font (`AGA-Arabesque-2.ttf`, in `Assets/fonts/Other Fonts.zip`) were read off the rendered glyphs and live in `clean_batch.HONORIFICS` (`F072` ﷺ, `F074` رضي الله عنه, `F075` عليه السلام, `F049` سبحانه وتعالى, `F055` عز وجل, `F059` جل جلاله, `F077` صدق الله العظيم, `F079` رضي الله عنهم, `F042` تم بحمد الله); the HQPB3 `i/j/k` glyphs are the basmala ornament → ﷽. **A second font, `MshQuraanW`, is NOT available and uses a different glyph table:** the book's *highlighted* word of a quoted verse is typed in it (sometimes the whole word, sometimes one extra imala glyph). Decoding those with the Msh table gives garbage, so `Code/mshq_fill_w.py` recovers such words by aligning the quote's clean words (difflib) against the Hafs text (`data/reference/hafs_ayahs.json`, exported from `ayahs.text`) and taking the Hafs word at that position when the leftover letters are compatible; `MANUAL` in that file holds the few words the alignment must not decide (e.g. Ibn Amir's `ءَالِ`, not Hafs `إِلْ`). Remaining unresolved words and undecodable glyphs (~35 of ~56k) are listed per book as `W-UNRESOLVED …` / `UNDECODED …` in `clean_report.json` (currently 2 words in the Abu Amr book's «ياءات الزوائد» example). Round 3 (2026-11-22) verified the 24 recorded events against the source docx rendered with the Msh font, the author's published Word-produced PDFs (same fonts embedded, so they are an independent visual reference) and Hafs from this DB, and fixed the decoder: glyph `E6` after a letter is hamza-below (`ٱلْإِثْمِ`, `وَمَلَإِي۟هِ` via a `لإ` ligature), `60C` is a comma emitted after its word, standalone fatha / small-yeh / small-waw marks attach to the following word, a leading dagger alef joins the previous word, and `MANUAL` in `mshq_fill_w.py` now has Fatiha 1:4 as `مَلِكِ` (no alef: the book follows the qiraat it describes; the old `مَٰلِكِ` was copied from prose). Only 4 contexts stay unresolved and are never guessed: Abu Amr's two whole-word MshQuraanW sequences and the three `كٓهيعٓصٓ ۝١ ذَّكْر` groups (glyph 67 vs the published 66). The per-event evidence is in `output/quran-book-cleanup/production-transfer/glyph-event-resolution.csv`. Apply verified text fixes to existing outputs with `output/quran-book-cleanup/layout-v2/scripts/apply_verified_glyph_fixes.py` (exactly-once asserts, back up first) and re-import the affected HTML **without `--force`** so section IDs survive; the Kurdish tajweed booklet (book 31, PDF-only) cannot be decoded reliably (Kurdish typed in Arabic code points in the same font as real Arabic, ~100 per-page Quran glyph fonts) and stays PDF-only. Kurdish books are NOT letter-normalised (ك/ي vs ک/ی) because they quote Arabic; search normalises via `normalizeBookKey`.

`data/Tajweed/`: `createspans.py` converts the bracket-tag tajweed format in `ayah_edition.csv` (`[h:1[ٱ]…`) into `<span class="tajweed-…">` HTML (`tajweed_classes_quran.csv`, edition 104) — the same class names the frontend styles. `Assets/` holds stage-by-stage copies of the docs (`Original` → `Cleaned` → `Fixed` → `Final Version …`), PDFs and fonts; treat it as data, not code.

---

## Key Conventions

- Qiraat readings are referenced by either their numeric DB id or their stable string `code` (e.g. `asim_hafs`, `nafi_warsh`). Commands accept both interchangeably.
- `imam`, `riwaya`, and `name` columns on `qiraat_readings` are JSON objects with `en`, `ar`, `ku` keys.
- All heavy commands set `memory_limit` to `512M` via `ini_set`.
- PHPSpreadsheet is used for Excel imports (`phpoffice/phpspreadsheet`).
- Model stubs are generated by Reliese (`reliese/laravel`).
