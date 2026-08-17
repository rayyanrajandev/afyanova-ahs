#!/usr/bin/env node
/**
 * i18n key-sync check (Volume 0.4 §2 — en/sw parity requirement, Volume 3.7
 * A6). Compares every locale namespace file under
 * `resources/ts/i18n/locales/{locale}/*.json` against the `en` baseline and
 * fails (non-zero exit) if any locale is missing keys `en` has, or carries
 * keys `en` doesn't (dead/renamed translations that silently drift instead
 * of erroring). Read-only — reports drift, never edits translation files.
 *
 * Only scans files actually imported by `resources/ts/i18n/index.ts`'s
 * locale message bundles, not every `.json` file that happens to sit under
 * `locales/` — `resources/ts/i18n/locales/sw/common_sw_Tanzania.json` is a
 * real, confirmed example of a file that exists on disk but is never
 * imported anywhere (dead file, same shape as `common.json`, not wired into
 * vue-i18n's actual message bundle). Comparing it as if it were live would
 * report false drift for a file no user-facing string ever resolves
 * through. `--include-unwired` opts into scanning those too, reported
 * separately, for exactly this kind of orphaned-file audit.
 *
 * Usage: node scripts/i18n-key-sync-check.mjs [--include-unwired]
 */

import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(__dirname, '..');
const LOCALES_DIR = join(REPO_ROOT, 'resources/ts/i18n/locales');
const I18N_INDEX = join(REPO_ROOT, 'resources/ts/i18n/index.ts');
const BASE_LOCALE = 'en';

const includeUnwired = process.argv.includes('--include-unwired');

function flattenKeys(obj, prefix = '') {
    const keys = new Set();
    for (const [key, value] of Object.entries(obj)) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
            for (const nested of flattenKeys(value, path)) keys.add(nested);
        } else {
            keys.add(path);
        }
    }
    return keys;
}

/**
 * `resources/ts/i18n/index.ts` statically imports each locale's JSON files
 * by relative path (e.g. `import sw from './locales/sw/common.json'`). That
 * import list is the actual source of truth for what's "wired" — parsed
 * directly rather than hardcoding a filename pattern here, so this script
 * stays correct if a new namespace file (e.g. `clinical.json`, named in
 * Volume 0.4 §... as a planned split) gets added and imported later.
 */
function wiredLocaleFiles() {
    if (!existsSync(I18N_INDEX)) {
        throw new Error(`Cannot find ${I18N_INDEX} — is the i18n setup at a different path now?`);
    }
    const source = readFileSync(I18N_INDEX, 'utf8');
    const importRe = /from\s+['"]\.\/locales\/([^/'"]+)\/([^'"]+\.json)['"]/g;
    const files = [];
    let match;
    while ((match = importRe.exec(source)) !== null) {
        files.push({ locale: match[1], file: match[2] });
    }
    return files;
}

function allLocaleFilesOnDisk() {
    const files = [];
    for (const locale of readdirSync(LOCALES_DIR, { withFileTypes: true })) {
        if (!locale.isDirectory()) continue;
        const localeDir = join(LOCALES_DIR, locale.name);
        for (const entry of readdirSync(localeDir, { withFileTypes: true })) {
            if (entry.isFile() && entry.name.endsWith('.json')) {
                files.push({ locale: locale.name, file: entry.name });
            }
        }
    }
    return files;
}

function loadJson(locale, file) {
    return JSON.parse(readFileSync(join(LOCALES_DIR, locale, file), 'utf8'));
}

function reportDrift(baseKeys, locale, file) {
    const keys = flattenKeys(loadJson(locale, file));
    const missing = [...baseKeys].filter((k) => !keys.has(k)).sort();
    const extra = [...keys].filter((k) => !baseKeys.has(k)).sort();
    return { locale, file, missing, extra, keyCount: keys.size };
}

function main() {
    const wired = wiredLocaleFiles();
    const baseEntry = wired.find((w) => w.locale === BASE_LOCALE);
    if (!baseEntry) {
        console.error(`No wired ${BASE_LOCALE} locale file found in ${I18N_INDEX} — cannot establish a baseline.`);
        process.exitCode = 1;
        return;
    }

    const baseKeys = flattenKeys(loadJson(baseEntry.locale, baseEntry.file));
    console.log(`Baseline: ${BASE_LOCALE}/${baseEntry.file} — ${baseKeys.size} keys\n`);

    let hasDrift = false;

    for (const { locale, file } of wired) {
        if (locale === BASE_LOCALE) continue;
        const result = reportDrift(baseKeys, locale, file);
        if (result.missing.length === 0 && result.extra.length === 0) {
            console.log(`✅ ${locale}/${file} — in sync (${result.keyCount} keys)`);
            continue;
        }
        hasDrift = true;
        console.log(`❌ ${locale}/${file} — ${result.keyCount} keys, drift found:`);
        if (result.missing.length > 0) {
            console.log(`   Missing (in ${BASE_LOCALE}, not in ${locale}): ${result.missing.length}`);
            for (const key of result.missing) console.log(`     - ${key}`);
        }
        if (result.extra.length > 0) {
            console.log(`   Extra (in ${locale}, not in ${BASE_LOCALE}): ${result.extra.length}`);
            for (const key of result.extra) console.log(`     - ${key}`);
        }
    }

    // Unwired files: reported separately, never counted toward pass/fail —
    // a file vue-i18n never loads can't cause a live translation gap, but
    // it's worth surfacing since it usually means dead work or a forgotten
    // wire-up, not a translation problem.
    const wiredSet = new Set(wired.map((w) => `${w.locale}/${w.file}`));
    const unwired = allLocaleFilesOnDisk().filter((f) => !wiredSet.has(`${f.locale}/${f.file}`));
    if (unwired.length > 0) {
        console.log(`\n⚠ ${unwired.length} locale file(s) on disk are not imported by ${I18N_INDEX.replace(REPO_ROOT + '/', '')}:`);
        for (const { locale, file } of unwired) console.log(`   - ${locale}/${file}`);
        if (includeUnwired) {
            console.log('\n--include-unwired: comparing these against the baseline too (informational, not a failure):');
            for (const { locale, file } of unwired) {
                const result = reportDrift(baseKeys, locale, file);
                console.log(
                    `   ${locale}/${file} — ${result.keyCount} keys, ${result.missing.length} missing, ${result.extra.length} extra vs ${BASE_LOCALE}`,
                );
            }
        } else {
            console.log('   (not compared — never loaded by the app; pass --include-unwired to audit them anyway)');
        }
    }

    if (hasDrift) {
        console.log('\ni18n key sync check FAILED — see drift above.');
        process.exitCode = 1;
    } else {
        console.log('\ni18n key sync check passed — all wired locales match the en baseline.');
    }
}

main();
