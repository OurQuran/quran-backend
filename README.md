# Quran Backend

Laravel 12 REST API for labelling, annotating and serving Quran verses with full support for the 10 canonical Qiraat (20 readings). Base reading is Asim/Hafs; every other reading is mapped to it at ayah and word level, and differences are exposed as precompiled HTML.

Part of the Quran workspace (siblings: `../quran-frontend`, `../quran-semantic-search`, `../Data-Cleaning`, `../quran-data-kfgqpc`). See `CLAUDE.md` for architecture, the qiraat import pipeline and cross-project details.

## Requirements

- PHP 8.3+ and Composer
- PostgreSQL with the `pg_trgm` and `vector` (pgvector) extensions
- The embedding service in `../quran-semantic-search` for semantic search (`AI_URL`)

## Setup

```bash
composer install
cp .env.example .env      # set DB_* and AI_URL
php artisan key:generate
php artisan migrate
php artisan db:seed --class=QiraatReadingsSeeder
php artisan db:seed --class=BooksSeeder
php artisan serve
```

## Common commands

```bash
php artisan test                      # run tests
php artisan test --filter=SomeTest    # single test
./vendor/bin/pint                     # code style
php artisan process:qiraat-mushaf asim_hafs   # full import pipeline for one reading
```

The qiraat XML source data is read from `../quran-data-kfgqpc` by default (override with `--dataset-root=`).
