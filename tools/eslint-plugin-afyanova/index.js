/**
 * eslint-plugin-afyanova
 * =======================
 * Local (unpublished) ESLint plugin enforcing two governance rules the
 * codex documents but had never wired into CI:
 *
 *   - no-hardcoded-values  (Volume 0.2 §13, Volume 0.3 §11.1)
 *   - no-hardcoded-strings (Volume 0.4 §3.3, Volume 3.6 §7)
 *
 * Registered in eslint.config.js as the `afyanova` plugin namespace.
 */

import noHardcodedStrings from './rules/no-hardcoded-strings.js';
import noHardcodedValues from './rules/no-hardcoded-values.js';

export default {
    meta: {
        name: 'eslint-plugin-afyanova',
        version: '0.1.0',
    },
    rules: {
        'no-hardcoded-values': noHardcodedValues,
        'no-hardcoded-strings': noHardcodedStrings,
    },
};
