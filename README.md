# SuperExporter

Cross-CMS export/import tool for WordPress, Bitrix, OpenCart, Joomla, MODX, and Drupal. Content is serialized to a canonical JSON format with `manifest.json` and chunked entity files.

## Requirements

- PHP 8.1+
- PDO (SQLite for fast tests; MySQL/MariaDB for production CMS databases)
- No Composer dependency (drop-in)

## Quick start

1. Copy `superexport/config.php.example` to `superexport/config.php` and set `secret_key`.
2. Place `superexport.php` in your CMS web root (or run from CLI anywhere).

### CLI

```bash
php superexport.php detect
php superexport.php export --output=./superexport/storage
php superexport.php import --input=./superexport/storage --dry-run
php superexport.php import --input=./superexport/storage
```

### Web UI

```
https://yoursite.com/superexport.php?key=YOUR_SECRET_KEY
```

## Supported CMS

| CMS        | Export | Import | Entities                          |
|------------|--------|--------|-----------------------------------|
| WordPress  | yes    | yes    | posts, pages, categories, tags, products (WooCommerce), **custom post types** (`cpt:*`), **custom taxonomies** (`taxonomy:*`) |
| Bitrix     | yes    | yes    | posts, products (catalog), categories, **per-iblock entities** (`iblock:*`, `iblock_section:*`) |
| OpenCart   | yes    | yes    | products, categories              |
| Joomla     | yes    | yes    | posts, pages, categories          |
| MODX       | yes    | yes    | posts, pages                      |
| Drupal     | yes    | yes    | posts, pages, categories, products (Commerce) |

Cross-CMS import uses the canonical schema; field mapping defaults come from each adapter. Entity types are auto-discovered and mapped via `canonical_kind` (e.g. `cpt:portfolio` → `posts`, `iblock:5` → `posts`).

## Export format (manifest 1.1.0)

```
storage/
  manifest.json          # format 1.1.0, source CMS, schema, entity_definitions, stats, chunks
  entities/
    posts/posts_0001.json
    cpt__portfolio/cpt__portfolio_0001.json   # filesystem-safe path for cpt:portfolio
    iblock__12/iblock__12_0001.json
    ...
```

### Entity keys

| Key pattern | CMS | Example |
|-------------|-----|---------|
| `posts`, `pages`, `products` | Standard canonical types | `posts` |
| `cpt:{name}` | WordPress custom post type | `cpt:portfolio` |
| `taxonomy:{name}` | WordPress custom taxonomy | `taxonomy:genre` |
| `iblock:{id}` | Bitrix information block | `iblock:12` |
| `iblock_section:{id}` | Bitrix iblock sections | `iblock_section:12` |

Manifest `schema.entity_definitions` stores label, `canonical_kind`, and native source metadata for cross-CMS mapping.

### Cross-CMS entity mapping (examples)

| Source | Target WordPress | Target Bitrix |
|--------|------------------|---------------|
| `iblock:5` (post) | `posts` | — |
| `cpt:portfolio` | — | `posts` |
| `taxonomy:genre` | `tags` | `categories` |
| `products` | `products` | `products` |

## Export format (legacy 1.0.x)

```
storage/
  manifest.json          # format version, source CMS, schema, stats, chunks
  entities/
    posts/posts_0001.json
    categories/categories_0001.json
    ...
```

See **Export format (manifest 1.1.0)** above for dynamic entity keys.

## Testing

### Fast suite (SQLite, no external services)

Runs unit tests plus round-trip and cross-CMS integration on in-memory SQLite:

```bash
php tests/run.php
```

Coverage:

- **Unit:** `SchemaRegistry`, `IdRemapper`, `ManifestManager`
- **Round-trip:** WordPress, Bitrix, Joomla, MODX
- **Export smoke:** OpenCart, Drupal (import uses MySQL-only SQL functions)
- **Cross-CMS:** Bitrix → WordPress

### MySQL integration (docker-compose)

SQL fixtures live in `tests/sql/` (one file per CMS). They load into a shared `superexport_test` database:

```bash
docker compose up -d
# wait for healthy MySQL, then:
php tests/integration/run_mysql.php
```

Environment overrides (optional):

| Variable               | Default            |
|------------------------|--------------------|
| `SUPEREXPORT_DB_HOST`  | `127.0.0.1`        |
| `SUPEREXPORT_DB_PORT`  | `3307`             |
| `SUPEREXPORT_DB_NAME`  | `superexport_test` |
| `SUPEREXPORT_DB_USER`  | `superexport`      |
| `SUPEREXPORT_DB_PASS`  | `superexport`      |

MySQL tests cover full round-trip for WordPress, OpenCart, and Drupal.

### PHP fixtures

Programmatic schemas for SQLite tests are in `tests/Fixtures/`:

- `WordPressSchema.php`
- `BitrixSchema.php`
- `JoomlaSchema.php`
- `ModxSchema.php`
- `OpenCartSchema.php`
- `DrupalSchema.php`

Each provides `create(PDO)` and `writeConfig(string $dir)` for adapter detection.

## Project layout

```
superexport.php              # CLI + Web bootstrap
superexport/
  config.php.example
  src/
    Core/                    # Engine, pipelines, detector
    Adapters/                # CMS-specific adapters
    Universal/               # Canonical entities + schema
    Storage/                 # manifest.json, JSON chunks
    Cli/                     # CLI commands
    Web/                     # Web UI
tests/
  run.php                    # Main test entry
  TestRunner.php
  Unit/CoreUnitTests.php
  Fixtures/                  # SQLite schema builders
  sql/                       # MySQL init scripts for docker
  integration/run_mysql.php
docker-compose.yml
```

## Configuration

See `superexport/config.php.example`:

- `secret_key` — required for web access
- `batch_size` — export/import batch size (default 500)
- `storage_path` — default export directory
- `db` — optional explicit PDO/DSN override for CLI

## License

See repository license file if present.
