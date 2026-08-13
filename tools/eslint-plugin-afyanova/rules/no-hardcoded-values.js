/**
 * afyanova/no-hardcoded-values
 * =============================
 * Enforces Volume 0.2 §13 ("No one-off values") and §12.3 ("No component
 * defines a color, size, or motion value directly"). A component may only
 * reference tokens — via Tailwind utilities that resolve through the
 * `@theme inline` bridge (tokens.css → tailwind.css), or via `var(--token)`
 * for edge cases (Volume 0.2 §12.3).
 *
 * Flags three concrete, unambiguous patterns:
 *   1. Raw color literals — hex (#0d9488), or a color function
 *      (rgb/rgba/hsl/hsla/oklch/oklab/lab/lch) called directly, not through
 *      a var(--token). This is what caught `progress: { color: '#0d9488' }`
 *      in app.ts, duplicating what tokens.ts already authors as --primary.
 *   2. Raw Tailwind palette-scale color utilities (bg-red-500, text-blue-600,
 *      ...) — these bypass the semantic layer entirely (Volume 0.2 §4.3) and
 *      are exactly the "one-off value" the rule exists to catch. Semantic
 *      utilities (bg-primary, text-critical-foreground, bg-surface, ...)
 *      are unaffected — they don't match this pattern.
 *   3. Raw arbitrary-value Tailwind brackets with a hardcoded px/rem/em
 *      (h-[32px], p-[10px]) instead of a token reference (h-[var(--size-
 *      control-md)]). A bracket containing var(...) is always allowed.
 *
 * Deliberately NOT enforced here (see Volume 0.2 §7.1 vs this rule's
 * limits): whether a specific interactive control uses the density-aware
 * --size-control-* scale versus Tailwind's plain spacing scale (h-8, p-4,
 * ...) is a per-component judgment call — icons, avatars, and status dots
 * legitimately use plain spacing utilities. A generic AST rule cannot tell
 * "this h-8 is a button" from "this h-8 is an avatar" without false
 * positives, so that distinction stays a code-review concern (as it already
 * is for Button.vue, the one place it's been fixed so far).
 *
 * Known limitation: the hex-color check can false-positive on a URL/anchor
 * fragment that happens to be a bare 3–8 character hex string (e.g.
 * `href="#deadbeef"`). None exist in this codebase today; if one is ever
 * needed, disable the rule for that single line rather than loosening the
 * pattern for everyone.
 *
 * `resources/ts/design/tokens.ts` is exempt — it is Layer 1, the single
 * place primitives are authored (Volume 0.2 §3: "Layer 1 never references
 * Layer 2"). Raw OKLCH literals there are correct, not a violation.
 */

const HEX_COLOR = /#[0-9a-fA-F]{3,8}\b/;
const COLOR_FN = /\b(?:rgb|rgba|hsl|hsla|oklch|oklab|lab|lch)\(/;
const PALETTE_UTILITY =
    /\b(?:bg|text|border|ring|from|via|to|fill|stroke|divide|outline|decoration|caret|accent)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|gray|grey|slate|zinc|neutral|stone)-(?:50|100|150|200|250|300|350|400|450|500|550|600|650|700|750|800|850|900|950)\b/;
const ARBITRARY_UTILITY = /\b[\w-]+-\[([^\]]+)\]/g;

const TOKENS_SOURCE_FILE = /[/\\]design[/\\]tokens\.ts$/;

/** @param {string} text */
function findViolation(text) {
    if (HEX_COLOR.test(text)) {
        return 'a raw hex color';
    }
    if (COLOR_FN.test(text)) {
        return 'a raw color function (rgb/hsl/oklch/...)';
    }
    if (PALETTE_UTILITY.test(text)) {
        return 'a raw Tailwind palette-scale color utility (bypasses the semantic token layer, Volume 0.2 §4.3)';
    }
    ARBITRARY_UTILITY.lastIndex = 0;
    let match;
    while ((match = ARBITRARY_UTILITY.exec(text)) !== null) {
        const inner = match[1];
        if (/^\d/.test(inner) && !inner.includes('var(')) {
            return `a raw arbitrary value "${match[0]}" (reference a token: e.g. h-[var(--size-control-md)])`;
        }
    }
    return null;
}

/** @type {import('eslint').Rule.RuleModule} */
export default {
    meta: {
        type: 'problem',
        docs: {
            description: 'Disallow hardcoded colors and sizes that bypass the Afyanova design token system (Volume 0.2 §13).',
            url: 'documents/codex/00-foundations/02-design-tokens-and-theming.md#13-token-governance',
        },
        schema: [],
        messages: {
            hardcoded: 'Afyanova token rule: {{reason}}. Reference a design token instead (Volume 0.2 §12.3, §13).',
        },
    },
    create(context) {
        const filename = context.filename ?? context.getFilename();
        if (TOKENS_SOURCE_FILE.test(filename)) {
            return {};
        }

        /** @param {import('eslint').Rule.Node} node @param {string} text */
        function check(node, text) {
            const reason = findViolation(text);
            if (reason) {
                context.report({ node, messageId: 'hardcoded', data: { reason } });
            }
        }

        // Script-side checks: plain string literals (app.ts's `color: '#0d9488'`)
        // and template-literal chunks, wherever they occur — a .ts file, or the
        // <script> block of a .vue file.
        const scriptVisitor = {
            Literal(node) {
                if (typeof node.value === 'string') check(node, node.value);
            },
            TemplateElement(node) {
                check(node, node.value.raw);
            },
        };

        // Template-side check: static attribute values (class="...") parse as
        // VLiteral, a vue-eslint-parser node type, not an ESTree Literal — and
        // the template AST is a separate tree from Program that ESLint's core
        // traversal never enters on its own. A rule must opt in via
        // parserServices.defineTemplateBodyVisitor to see it (this is also why
        // an earlier version of this rule silently matched nothing in .vue
        // templates despite passing on .ts files).
        const parserServices = context.sourceCode?.parserServices ?? context.parserServices;
        if (parserServices?.defineTemplateBodyVisitor) {
            return parserServices.defineTemplateBodyVisitor(
                {
                    VLiteral(node) {
                        check(node, node.value);
                    },
                },
                scriptVisitor,
            );
        }

        // Plain .ts/.js file — no template body to visit.
        return scriptVisitor;
    },
};
