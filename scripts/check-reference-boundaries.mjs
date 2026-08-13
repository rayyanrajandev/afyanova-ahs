#!/usr/bin/env node

import fs from "node:fs";
import path from "node:path";

const ROOT_DIR = process.cwd();
const FORBIDDEN_REFERENCE = /(^|[^A-Za-z0-9-])afyanova-ahs([^A-Za-z0-9-]|$)/i;

const INCLUDED_DIRECTORIES = [
    "app",
    "bootstrap",
    "config",
    "database",
    "resources",
    "routes",
    "tests",
    ".github",
];

const INCLUDED_ROOT_FILES = [
    "composer.json",
    "package.json",
    "vite.config.ts",
    "tsconfig.json",
    "eslint.config.js",
    "phpunit.xml",
];

const SCANNABLE_EXTENSIONS = new Set([
    ".php",
    ".js",
    ".mjs",
    ".cjs",
    ".ts",
    ".tsx",
    ".vue",
    ".json",
    ".yml",
    ".yaml",
    ".xml",
    ".env",
    ".sh",
    ".ps1",
]);

const SKIPPED_DIRECTORIES = new Set([
    "node_modules",
    "vendor",
    "storage",
    ".git",
    "dist",
    "build",
    "coverage",
    "public/build",
]);

const violations = [];

function shouldSkipDirectory(relativeDir) {
    if (!relativeDir) {
        return false;
    }

    return [...SKIPPED_DIRECTORIES].some((skipped) => {
        return relativeDir === skipped || relativeDir.startsWith(`${skipped}${path.sep}`);
    });
}

function scanFile(absoluteFilePath) {
    const relativePath = path.relative(ROOT_DIR, absoluteFilePath);
    const fileText = fs.readFileSync(absoluteFilePath, "utf8");
    const lines = fileText.split(/\r?\n/);

    lines.forEach((line, index) => {
        if (!FORBIDDEN_REFERENCE.test(line)) {
            return;
        }

        violations.push({
            path: relativePath.replace(/\\/g, "/"),
            line: index + 1,
            snippet: line.trim(),
        });
    });
}

function walkDirectory(absoluteDirPath) {
    const relativeDir = path.relative(ROOT_DIR, absoluteDirPath);
    if (shouldSkipDirectory(relativeDir)) {
        return;
    }

    const entries = fs.readdirSync(absoluteDirPath, { withFileTypes: true });
    for (const entry of entries) {
        const entryPath = path.join(absoluteDirPath, entry.name);
        const relativePath = path.relative(ROOT_DIR, entryPath);

        if (entry.isDirectory()) {
            if (shouldSkipDirectory(relativePath)) {
                continue;
            }

            walkDirectory(entryPath);
            continue;
        }

        const extension = path.extname(entry.name).toLowerCase();
        if (!SCANNABLE_EXTENSIONS.has(extension)) {
            continue;
        }

        scanFile(entryPath);
    }
}

for (const directory of INCLUDED_DIRECTORIES) {
    const absoluteDir = path.join(ROOT_DIR, directory);
    if (!fs.existsSync(absoluteDir)) {
        continue;
    }

    walkDirectory(absoluteDir);
}

for (const file of INCLUDED_ROOT_FILES) {
    const absoluteFile = path.join(ROOT_DIR, file);
    if (!fs.existsSync(absoluteFile)) {
        continue;
    }

    scanFile(absoluteFile);
}

if (violations.length > 0) {
    console.error("Reference boundary check failed. Forbidden references to 'afyanova-ahs' were found:");

    for (const violation of violations) {
        console.error(`- ${violation.path}:${violation.line} | ${violation.snippet}`);
    }

    process.exit(1);
}

console.log("Reference boundary check passed.");
