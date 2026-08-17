#!/usr/bin/env node
/**
 * codex_reference_check (Volume 3.5, Volume 3.7 A7) — validates every
 * `Volume X.Y §Z` comment found under a target source directory actually
 * points at a real codex volume file. Catches the class of drift where a
 * comment cites a volume/section that was renamed, split, or never
 * existed — the doc equivalent of a broken link, left to silently rot
 * because nothing ever checked it.
 *
 * The Volume-number → file map is NOT hardcoded here: it's built at
 * runtime by scanning `documents/codex/**\/*.md` for the real `# Volume
 * X.Y — Title` header every volume file starts with (confirmed pattern,
 * checked against all 28 current volume files before writing this). That
 * makes the check self-updating if a volume is renamed or added — this
 * script fails loudly if a *cited* volume number has no matching header
 * anywhere, rather than silently trusting a stale hardcoded table.
 *
 * §-section validation is intentionally a *warning*, not a failure:
 * comments routinely cite subsections (`§6.2`, `§7.3`) that are part of a
 * numbered `## 6. ...` heading's body rather than their own `## ` heading,
 * and multi-cite comments (`Volume 2.1 §6, §6.2/§7.3, Volume 3.7 T2.4`)
 * mix real section numbers with task IDs (`T2.4`) that aren't section
 * numbers at all. Hard-failing on those would produce false positives
 * more often than it catches real drift. The volume-exists check is the
 * hard gate; the section-number check is best-effort evidence for a human
 * to glance at, matching this repo's own "real status checked against
 * running code" discipline rather than a rule that would need constant
 * silencing.
 *
 * Usage: node scripts/codex-reference-check.mjs [targetDir]
 *   targetDir defaults to resources/ts/pages/reception (Volume 3.7 A7's
 *   own scope) — pass a different path to run it elsewhere, e.g.
 *   resources/ts/pages/nursing for Volume 3.8's equivalent.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(__dirname, '..');
const CODEX_DIR = join(REPO_ROOT, 'documents/codex');
const DEFAULT_TARGET = join(REPO_ROOT, 'resources/ts/pages/reception');

const SCANNABLE_EXTENSIONS = new Set(['.ts', '.tsx', '.vue', '.php']);
const SKIPPED_DIRECTORIES = new Set(['node_modules', 'vendor', '.git', 'dist', 'build']);

// Matches "Volume 2.1 §6" / "Volume 2.1 §6.2" — the primary cite that
// anchors a comment to a specific volume + section.
const VOLUME_SECTION_RE = /Volume\s+(\d+\.\d+)\s+§(\d+(?:\.\d+)?)/g;

function walk(dir, onFile) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (entry.isDirectory()) {
            if (SKIPPED_DIRECTORIES.has(entry.name)) continue;
            walk(join(dir, entry.name), onFile);
        } else {
            onFile(join(dir, entry.name));
        }
    }
}

/** Build { "2.1": { file, headingNumbers: Set<string> } } from every real codex volume file. */
function loadVolumeIndex() {
    const index = new Map();
    walk(CODEX_DIR, (filePath) => {
        if (!filePath.endsWith('.md')) return;
        const text = readFileSync(filePath, 'utf8');
        // Tolerant of stray leading whitespace before "#" — confirmed a real,
        // if minor, drift in one volume file (documents/codex/01-platform/
        // 02-global-component-library.md starts with " # Volume 1.2 — ...",
        // a leading space) that would otherwise make that volume invisible
        // to this check and produce a false "volume doesn't exist" failure.
        const headerMatch = /^\s*# Volume\s+(\d+\.\d+)\s+—/m.exec(text);
        if (!headerMatch) return;
        const volume = headerMatch[1];
        const headingNumbers = new Set();
        for (const m of text.matchAll(/^## (\d+(?:\.\d+)?)\./gm)) {
            headingNumbers.add(m[1]);
        }
        index.set(volume, { file: relative(REPO_ROOT, filePath), headingNumbers });
    });
    return index;
}

function scanTarget(targetDir) {
    const citations = [];
    walk(targetDir, (filePath) => {
        const ext = filePath.slice(filePath.lastIndexOf('.'));
        if (!SCANNABLE_EXTENSIONS.has(ext)) return;
        const text = readFileSync(filePath, 'utf8');
        const lines = text.split(/\r?\n/);
        lines.forEach((line, idx) => {
            for (const m of line.matchAll(VOLUME_SECTION_RE)) {
                citations.push({
                    file: relative(REPO_ROOT, filePath),
                    line: idx + 1,
                    volume: m[1],
                    section: m[2],
                    snippet: line.trim(),
                });
            }
        });
    });
    return citations;
}

function main() {
    const targetArg = process.argv[2];
    const targetDir = targetArg ? join(REPO_ROOT, targetArg) : DEFAULT_TARGET;

    try {
        statSync(targetDir);
    } catch {
        console.error(`Target directory does not exist: ${relative(REPO_ROOT, targetDir)}`);
        process.exitCode = 1;
        return;
    }

    const volumeIndex = loadVolumeIndex();
    const citations = scanTarget(targetDir);

    if (citations.length === 0) {
        console.log(`No "Volume X.Y §Z" citations found under ${relative(REPO_ROOT, targetDir)}.`);
        return;
    }

    console.log(`Found ${citations.length} "Volume X.Y §Z" citation(s) under ${relative(REPO_ROOT, targetDir)}.`);
    console.log(`Codex index: ${volumeIndex.size} real volume file(s) found under documents/codex/.\n`);

    const brokenVolume = [];
    const uncertainSection = [];

    for (const cite of citations) {
        const entry = volumeIndex.get(cite.volume);
        if (!entry) {
            brokenVolume.push(cite);
            continue;
        }
        const topLevelSection = cite.section.split('.')[0];
        if (!entry.headingNumbers.has(topLevelSection)) {
            uncertainSection.push({ ...cite, resolvedFile: entry.file });
        }
    }

    if (brokenVolume.length > 0) {
        console.error(`❌ ${brokenVolume.length} citation(s) reference a Volume number with no matching codex file:`);
        for (const c of brokenVolume) {
            console.error(`   - ${c.file}:${c.line} — "Volume ${c.volume} §${c.section}" | ${c.snippet}`);
        }
        console.error('');
    }

    if (uncertainSection.length > 0) {
        console.log(`⚠ ${uncertainSection.length} citation(s) reference a section number not found as a "## N." heading in the resolved file (informational — may be a subsection, not a drift):`);
        for (const c of uncertainSection) {
            console.log(`   - ${c.file}:${c.line} — "Volume ${c.volume} §${c.section}" → ${c.resolvedFile} | ${c.snippet}`);
        }
        console.log('');
    }

    if (brokenVolume.length > 0) {
        console.error('codex_reference_check FAILED — one or more cited volumes do not exist.');
        process.exitCode = 1;
    } else {
        console.log('codex_reference_check passed — every cited Volume number resolves to a real codex file.');
    }
}

main();
