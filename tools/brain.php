#!/usr/bin/env php
<?php
/**
 * brain.php — Block-based second brain (Markdown + SQLite) in pure PHP.
 *
 * Blocks are addressable via ^blockid appended to a line, e.g.:
 *   - Experiments violate Bell inequalities. ^b63f8a
 *   Paragraph line as block too. ^b1a2c3
 *
 * Commands:
 *   php tools/brain.php init
 *   php tools/brain.php stamp <file-or-dir>
 *   php tools/brain.php index
 *   php tools/brain.php find "<query>" [--limit=20]
 *   php tools/brain.php open <blockid> [--editor=code]
 */

declare(strict_types=1);

main($argv);

function main(array $argv): void {
    $root = realpath(__DIR__ . '/..');
    if (!$root) fail("Cannot resolve project root.");

    $vaultDir = $root . '/vault';
    $dbPath   = $root . '/brain.db';

    $cmd = $argv[1] ?? '';
    if ($cmd === '' || in_array($cmd, ['-h','--help','help'], true)) {
        echo usage();
        exit(0);
    }

    switch ($cmd) {
        case 'init':
            initDb($dbPath);
            echo "Initialized DB at: {$dbPath}\n";
            break;

        case 'stamp':
            $target = $argv[2] ?? '';
            if ($target === '') fail("stamp requires <file-or-dir>");
            $path = resolvePath($root, $target);
            $files = gatherMarkdownFiles($path);
            $countFiles = 0;
            $countStamped = 0;

            foreach ($files as $file) {
                [$changed, $stamped] = stampFile($file);
                $countFiles++;
                $countStamped += $stamped;
                if ($changed) echo "Stamped {$stamped} blocks in: {$file}\n";
            }
            echo "Done. Files scanned: {$countFiles}, blocks stamped: {$countStamped}\n";
            break;

        case 'index':
            ensureExists($vaultDir, "Vault dir not found: {$vaultDir}");
            initDb($dbPath);
            $db = openDb($dbPath);

            // Wipe existing index (v1 behavior)
            $db->exec("DELETE FROM refs;");
            $db->exec("DELETE FROM blocks;");
            $db->exec("DELETE FROM blocks_fts;");

            $files = gatherMarkdownFiles($vaultDir);
            $totalBlocks = 0;
            $totalRefs = 0;

            foreach ($files as $file) {
                // If file isn't stamped, we still index blocks that have IDs.
                $parsed = parseBlocksFromFile($file);
                $inserted = upsertBlocks($db, $parsed['blocks']);
                $totalBlocks += $inserted;

                $refs = extractRefs($parsed['blocks']);
                $totalRefs += insertRefs($db, $refs);
            }

            echo "Indexed files: " . count($files) . "\n";
            echo "Indexed blocks: {$totalBlocks}\n";
            echo "Indexed refs: {$totalRefs}\n";
            break;

        case 'find':
            $query = $argv[2] ?? '';
            if ($query === '') fail("find requires a query string.");
            $limit = (int) argValue($argv, '--limit', '20');
            initDb($dbPath);
            $db = openDb($dbPath);

            $rows = findBlocks($db, $query, $limit);
            if (!$rows) {
                echo "No matches.\n";
                exit(0);
            }

            foreach ($rows as $r) {
                $id = $r['id'];
                $file = $r['file_path'];
                $line = $r['line_start'];
                $content = trim($r['content']);
                $content = mb_strlen($content) > 140 ? mb_substr($content, 0, 140) . "…" : $content;
                echo "{$id}  {$file}:{$line}\n  {$content}\n\n";
            }
            break;

        case 'open':
            $blockId = $argv[2] ?? '';
            if ($blockId === '') fail("open requires <blockid>.");
            $editor = argValue($argv, '--editor', 'code');

            initDb($dbPath);
            $db = openDb($dbPath);
            $row = getBlock($db, $blockId);
            if (!$row) fail("Block not found: {$blockId}");

            $file = $row['file_path'];
            $line = (int)$row['line_start'];

            // Prefer VS Code if available; otherwise print location.
            if ($editor === 'code' && commandExists('code')) {
                // VS Code: --goto file:line:column
                $cmdline = 'code --goto ' . escapeshellarg($file . ':' . $line . ':1');
                passthru($cmdline);
            } else {
                echo "{$file}:{$line}\n";
            }
            break;

        default:
            fail("Unknown command: {$cmd}\n\n" . usage());
    }
}

function usage(): string {
    return <<<TXT
Block Brain (PHP) — Markdown blocks with ^ids + SQLite index

Usage:
  php tools/brain.php init
  php tools/brain.php stamp <file-or-dir>
  php tools/brain.php index
  php tools/brain.php find "<query>" [--limit=20]
  php tools/brain.php open <blockid> [--editor=code]

Notes:
  - A block is any non-empty line that is:
      * a list item ("- ", "* ", "1. ") OR
      * a paragraph line (non-heading) OR
      * a heading (optional; currently indexed if it has an ID)
  - Block IDs are appended as:  ^b63f8a
  - References inside content can be written like:
      ((^b63f8a))   or   path/to/file.md#^b63f8a  (v1 extracts the ^id)

Requirements:
  - PHP with SQLite3 enabled (extension sqlite3)
  - Optional: FTS5 enabled in SQLite (many distros have it); tool falls back if not.

TXT;
}

function fail(string $msg): void {
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

function ensureExists(string $path, string $err): void {
    if (!file_exists($path)) fail($err);
}

function resolvePath(string $root, string $maybeRelative): string {
    $p = $maybeRelative;
    if (!str_starts_with($p, '/')) $p = $root . '/' . ltrim($p, '/');
    $real = realpath($p);
    if (!$real) fail("Path not found: {$maybeRelative}");
    return $real;
}

function gatherMarkdownFiles(string $path): array {
    $files = [];
    if (is_file($path)) {
        if (preg_match('/\.md$/i', $path)) $files[] = $path;
        return $files;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isFile() && preg_match('/\.md$/i', $f->getFilename())) {
            $files[] = $f->getRealPath();
        }
    }
    sort($files);
    return $files;
}

function stampFile(string $file): array {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) fail("Failed reading: {$file}");

    $changed = false;
    $stamped = 0;

    // Track existing IDs in this file to avoid duplicates.
    $existing = [];
    foreach ($lines as $ln) {
        if (preg_match('/\^([a-z0-9]{4,12})\b/i', $ln, $m)) {
            $existing[strtolower($m[1])] = true;
        }
    }

    foreach ($lines as $i => $line) {
        $trim = trim($line);
        if ($trim === '') continue;

        // Skip code fences and their contents (simple state machine)
        // We'll do a quick second pass with a state flag:
    }

    // Better: iterate with fenced-code tracking
    $inFence = false;
    foreach ($lines as $i => $line) {
        $trim = trim($line);

        if (preg_match('/^```/', $trim)) {
            $inFence = !$inFence;
            continue;
        }
        if ($inFence) continue;

        if ($trim === '') continue;

        // If already has ^id, skip
        if (preg_match('/\^([a-z0-9]{4,12})\b/i', $line)) continue;

        // Decide if this line is a "block" worth stamping
        if (!isBlockLine($trim)) continue;

        $id = generateId($existing);
        $existing[$id] = true;

        $lines[$i] = rtrim($line) . ' ^' . $id;
        $changed = true;
        $stamped++;
    }

    if ($changed) {
        $out = implode("\n", $lines) . "\n";
        file_put_contents($file, $out);
    }

    return [$changed, $stamped];
}

function isBlockLine(string $trim): bool {
    // Treat list items and paragraph lines as blocks.
    // Skip horizontal rules and html comments.
    if (preg_match('/^(---|\*\*\*|___)\s*$/', $trim)) return false;
    if (preg_match('/^<!--/', $trim)) return false;

    // List item?
    if (preg_match('/^(\-|\*|\+)\s+\S/', $trim)) return true;
    if (preg_match('/^\d+\.\s+\S/', $trim)) return true;

    // Headings? Only if user later wants; for stamping we skip headings by default.
    // (You can flip this to true if you want headings block-addressable.)
    if (preg_match('/^#{1,6}\s+\S/', $trim)) return false;

    // Paragraph line: non-empty, not heading.
    return true;
}

function generateId(array $existing): string {
    // Short, human-friendly, reasonably unique within a vault.
    // b + 5 hex chars = 6 chars total like b63f8a
    for ($i = 0; $i < 50; $i++) {
        $id = 'b' . substr(bin2hex(random_bytes(4)), 0, 5);
        $id = strtolower($id);
        if (!isset($existing[$id])) return $id;
    }
    // fallback
    return 'b' . strtolower(substr(uniqid('', true), -6));
}

function initDb(string $dbPath): void {
    $db = new SQLite3($dbPath);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");

    $db->exec("
        CREATE TABLE IF NOT EXISTS blocks (
            id TEXT PRIMARY KEY,
            file_path TEXT NOT NULL,
            line_start INTEGER NOT NULL,
            line_end INTEGER NOT NULL,
            content TEXT NOT NULL,
            content_hash TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
    ");

    // FTS5 table (if available)
    // If FTS5 is missing, this statement may fail; we handle it by trying and ignoring.
    @$db->exec("
        CREATE VIRTUAL TABLE IF NOT EXISTS blocks_fts
        USING fts5(id, content, file_path, content='');
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS refs (
            from_block_id TEXT NOT NULL,
            to_block_id TEXT NOT NULL
        );
    ");

    $db->close();
}

function openDb(string $dbPath): SQLite3 {
    if (!class_exists('SQLite3')) fail("PHP SQLite3 extension not available.");
    $db = new SQLite3($dbPath);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    return $db;
}

function parseBlocksFromFile(string $file): array {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) fail("Failed reading: {$file}");

    $blocks = [];
    $inFence = false;

    foreach ($lines as $idx => $line) {
        $lineNo = $idx + 1;
        $trim = trim($line);

        if (preg_match('/^```/', $trim)) {
            $inFence = !$inFence;
            continue;
        }
        if ($inFence) continue;

        if ($trim === '') continue;

        // Must have ^id to be addressable in v1 index
        if (!preg_match('/\^([a-z0-9]{4,12})\b/i', $line, $m)) {
            continue;
        }

        $id = strtolower($m[1]);
        // Content without the trailing ^id
        $content = preg_replace('/\s*\^([a-z0-9]{4,12})\b/i', '', $line);
        $content = rtrim($content);

        $blocks[] = [
            'id' => $id,
            'file_path' => $file,
            'line_start' => $lineNo,
            'line_end' => $lineNo,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'updated_at' => gmdate('c'),
        ];
    }

    return ['blocks' => $blocks];
}

function upsertBlocks(SQLite3 $db, array $blocks): int {
    if (!$blocks) return 0;

    $stmt = $db->prepare("
        INSERT INTO blocks (id, file_path, line_start, line_end, content, content_hash, updated_at)
        VALUES (:id, :file_path, :line_start, :line_end, :content, :content_hash, :updated_at)
        ON CONFLICT(id) DO UPDATE SET
            file_path=excluded.file_path,
            line_start=excluded.line_start,
            line_end=excluded.line_end,
            content=excluded.content,
            content_hash=excluded.content_hash,
            updated_at=excluded.updated_at
    ");

    // FTS insert (best-effort)
    $ftsStmt = null;
    $hasFts = hasFts($db);
    if ($hasFts) {
        $ftsStmt = $db->prepare("
            INSERT INTO blocks_fts (id, content, file_path)
            VALUES (:id, :content, :file_path)
        ");
    }

    $count = 0;
    foreach ($blocks as $b) {
        $stmt->bindValue(':id', $b['id'], SQLITE3_TEXT);
        $stmt->bindValue(':file_path', $b['file_path'], SQLITE3_TEXT);
        $stmt->bindValue(':line_start', $b['line_start'], SQLITE3_INTEGER);
        $stmt->bindValue(':line_end', $b['line_end'], SQLITE3_INTEGER);
        $stmt->bindValue(':content', $b['content'], SQLITE3_TEXT);
        $stmt->bindValue(':content_hash', $b['content_hash'], SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', $b['updated_at'], SQLITE3_TEXT);
        $stmt->execute();

        if ($hasFts && $ftsStmt) {
            $ftsStmt->bindValue(':id', $b['id'], SQLITE3_TEXT);
            $ftsStmt->bindValue(':content', $b['content'], SQLITE3_TEXT);
            $ftsStmt->bindValue(':file_path', $b['file_path'], SQLITE3_TEXT);
            // Replace behavior for FTS: easiest is delete + insert on reindex; we already wiped table in index cmd.
            $ftsStmt->execute();
        }

        $count++;
    }

    return $count;
}

function hasFts(SQLite3 $db): bool {
    // If blocks_fts exists and is queryable, use it.
    $res = @$db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='blocks_fts'");
    return (string)$res === 'blocks_fts';
}

function findBlocks(SQLite3 $db, string $query, int $limit): array {
    $limit = max(1, min($limit, 200));

    if (hasFts($db)) {
        $stmt = $db->prepare("
            SELECT b.id, b.file_path, b.line_start, b.content
            FROM blocks_fts f
            JOIN blocks b ON b.id = f.id
            WHERE blocks_fts MATCH :q
            LIMIT :lim
        ");
        // FTS5 query: basic sanitization; user can still use quotes, etc.
        $stmt->bindValue(':q', $query, SQLITE3_TEXT);
        $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
        $res = $stmt->execute();
        return fetchAll($res);
    }

    // Fallback: LIKE search
    $stmt = $db->prepare("
        SELECT id, file_path, line_start, content
        FROM blocks
        WHERE content LIKE :q
        LIMIT :lim
    ");
    $stmt->bindValue(':q', '%' . $query . '%', SQLITE3_TEXT);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    return fetchAll($res);
}

function fetchAll(SQLite3Result $res): array {
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function getBlock(SQLite3 $db, string $blockId): ?array {
    $stmt = $db->prepare("SELECT * FROM blocks WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', strtolower($blockId), SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function extractRefs(array $blocks): array {
    // Extract ((^id)) or #^id references from block content.
    $refs = [];
    foreach ($blocks as $b) {
        $from = $b['id'];
        $content = $b['content'];

        // ((^b12345)) style
        if (preg_match_all('/\(\(\s*\^([a-z0-9]{4,12})\s*\)\)/i', $content, $m)) {
            foreach ($m[1] as $to) $refs[] = [$from, strtolower($to)];
        }
        // path#^b12345 style
        if (preg_match_all('/#\^([a-z0-9]{4,12})\b/i', $content, $m2)) {
            foreach ($m2[1] as $to) $refs[] = [$from, strtolower($to)];
        }
    }
    return $refs;
}

function insertRefs(SQLite3 $db, array $refs): int {
    if (!$refs) return 0;
    $stmt = $db->prepare("INSERT INTO refs (from_block_id, to_block_id) VALUES (:f, :t)");
    $count = 0;
    foreach ($refs as [$f, $t]) {
        $stmt->bindValue(':f', $f, SQLITE3_TEXT);
        $stmt->bindValue(':t', $t, SQLITE3_TEXT);
        $stmt->execute();
        $count++;
    }
    return $count;
}

function argValue(array $argv, string $name, string $default): string {
    foreach ($argv as $a) {
        if (str_starts_with($a, $name . '=')) {
            return substr($a, strlen($name) + 1);
        }
    }
    return $default;
}

function commandExists(string $cmd): bool {
    $which = trim((string)shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
    return $which !== '';
}
