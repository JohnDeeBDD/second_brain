# Second Brain (PHP)

A tiny, local-first block knowledge base built with:

- Markdown files in `vault/`
- Stable line-level block IDs like `^b63f8a`
- SQLite indexing for search, references, backlinks, and graph export

The main CLI is `tools/brain.php`.

## How it works

### 1) Blocks
A block is a non-empty Markdown line with a trailing block ID:

```md
- Experiments violate Bell inequalities. ^b63f8a
Paragraph line as block too. ^b1a2c3
```

`stamp` can auto-append IDs to eligible lines.

### 2) Indexing
`index` scans Markdown files under `vault/`, parses addressable blocks, and writes:

- `blocks` table: block metadata/content
- `refs` table: extracted links between blocks
- `blocks_fts` table (if available): full-text search index

### 3) References / backlinks
References are extracted from block content in two forms:

- `((^b63f8a))`
- `path/to/file.md#^b63f8a`

`backlinks <id>` shows all blocks that point to the target.

### 4) Graph export
`graph --format=dot` exports a Graphviz DOT digraph.

`graph --format=json` exports machine-friendly `{ nodes, edges }` JSON.

### 5) Auto-link suggestions
`suggest-links <id>` computes a lightweight TF/IDF-ish overlap against other blocks and returns ranked candidates.

It is intentionally local/simple so it can later be replaced by embeddings.

### 6) Block ranges and heading context
During indexing, each block stores:

- `block_type` (`heading`, `list_item`, `line`)
- `heading_path` (e.g. `Topic A > Subtopic`)
- `line_start` / `line_end`

For headings, `line_end` expands to the end of that heading section (until next heading of same/higher level).

## Commands

```bash
php tools/brain.php init
php tools/brain.php stamp <file-or-dir>
php tools/brain.php index
php tools/brain.php find "<query>" [--limit=20]
php tools/brain.php open <blockid> [--editor=code]
php tools/brain.php backlinks <blockid>
php tools/brain.php graph [--format=dot|json]
php tools/brain.php suggest-links <blockid> [--limit=10] [--min-score=0.2]
```

## Typical workflow

```bash
# 1) initialize DB
php tools/brain.php init

# 2) stamp IDs in your notes
php tools/brain.php stamp vault

# 3) rebuild index
php tools/brain.php index

# 4) query
php tools/brain.php find "bell inequality"
php tools/brain.php backlinks b63f8a
php tools/brain.php graph --format=dot > brain.dot
php tools/brain.php suggest-links b63f8a --limit=10 --min-score=0.15
```

## Requirements

- PHP with `sqlite3` extension
- Optional SQLite FTS5 support (tool falls back to `LIKE` search if absent)
