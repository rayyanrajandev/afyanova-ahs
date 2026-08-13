/**
 * Build, commit all changes, and push. Use from repo root:
 *   npm run ship -- "feat: describe your change"
 * If no message is given, defaults to "chore: ship".
 */
import { execSync } from 'node:child_process';

const messageFromArgs = process.argv.slice(2).join(' ').trim();
const message = messageFromArgs || 'chore: ship';

function run(cmd, opts = {}) {
    execSync(cmd, { stdio: 'inherit', shell: true, ...opts });
}

run('npm run build');

const status = execSync('git status --porcelain', { encoding: 'utf8' });
if (!status.trim()) {
    console.log('Nothing to commit; pushing in case the branch is ahead.');
    run('git push');
    process.exit(0);
}

run('git add -A');
run(`git commit -m ${JSON.stringify(message)}`);
run('git push');
