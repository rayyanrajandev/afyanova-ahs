/**
 * afyanova/no-hardcoded-strings
 * ===============================
 * Enforces Volume 0.4 §3.3 ("No hardcoded strings in components. A
 * component never contains user-facing English text directly. All strings
 * go through t('key')") and Volume 3.6 §7 ("No hardcoded strings — ESLint
 * rule afyanova/no-hardcoded-strings enforces this").
 *
 * Scope, deliberately narrow to keep the false-positive rate low:
 *
 *   1. Static template text (VText) containing at least two consecutive
 *      letters — e.g. `<span>Notifications</span>`. A lone glyph or symbol
 *      (⌘K, ✓, 🏥, a "y" unit suffix) does not match and is left alone;
 *      real prose does.
 *   2. A fixed set of user-facing attributes — aria-label, title,
 *      placeholder, alt, aria-valuetext, aria-roledescription — when given
 *      as a static string (`aria-label="Notifications"`) or a bound plain
 *      string literal (`:aria-label="'Notifications'"`). A bound expression
 *      that isn't a bare string literal (`:aria-label="t('key')"`, a
 *      variable, a ternary) is assumed to already be handled and is not
 *      flagged — this rule catches literal English left in the template,
 *      not every possible way an attribute could end up untranslated.
 *
 * Out of scope by design: general `.ts`/`.js` string literals (status enum
 * values, event names, localStorage keys, HTTP headers — flagging those
 * would bury real violations in noise) and dynamic data values rendered
 * through interpolation (e.g. an untranslated FHIR `gender` enum value
 * passed straight through `{{ patient.gender }}`) — that's a data-
 * formatting concern for a display helper, not a "string left in the
 * template" concern this rule is built to catch.
 */

const CHECKED_ATTRS = new Set(['aria-label', 'title', 'placeholder', 'alt', 'aria-valuetext', 'aria-roledescription']);
const HAS_WORDS = /[A-Za-z]{2,}/;

/** @type {import('eslint').Rule.RuleModule} */
export default {
    meta: {
        type: 'problem',
        docs: {
            description: 'Disallow hardcoded user-facing text in Vue templates; require t() (Volume 0.4 §3.3).',
            url: 'documents/codex/00-foundations/04-internationalization-and-localization.md#3-string-architecture',
        },
        schema: [],
        messages: {
            text: 'Afyanova i18n rule: hardcoded text "{{text}}". Route it through t() (Volume 0.4 §3.3).',
            attr: 'Afyanova i18n rule: "{{attr}}" is hardcoded ("{{text}}"). Route it through t() (Volume 0.4 §3.3).',
        },
    },
    create(context) {
        // The template AST is a separate tree from Program (see the same note
        // in no-hardcoded-values.js) — VText/VAttribute must be reached via
        // parserServices.defineTemplateBodyVisitor, not returned directly.
        const parserServices = context.sourceCode?.parserServices ?? context.parserServices;
        if (!parserServices?.defineTemplateBodyVisitor) {
            // Not a .vue file (no template body) — nothing for this rule to check.
            return {};
        }

        return parserServices.defineTemplateBodyVisitor({
            // Static text between tags: <span>Notifications</span>
            VText(node) {
                const trimmed = node.value.trim();
                if (trimmed && HAS_WORDS.test(trimmed)) {
                    context.report({ node, messageId: 'text', data: { text: trimmed } });
                }
            },

            // Static attribute: aria-label="Notifications"
            'VAttribute[directive=false]'(node) {
                const attrName = node.key.name;
                if (!CHECKED_ATTRS.has(attrName)) return;
                const value = node.value?.value;
                if (typeof value === 'string' && HAS_WORDS.test(value)) {
                    context.report({ node, messageId: 'attr', data: { attr: attrName, text: value } });
                }
            },

            // Bound attribute with a bare string literal: :aria-label="'Notifications'"
            'VAttribute[directive=true]'(node) {
                const argName = node.key?.argument?.name;
                if (!argName || !CHECKED_ATTRS.has(argName)) return;
                const expr = node.value?.expression;
                if (expr?.type === 'Literal' && typeof expr.value === 'string' && HAS_WORDS.test(expr.value)) {
                    context.report({ node, messageId: 'attr', data: { attr: argName, text: expr.value } });
                }
            },
        });
    },
};
