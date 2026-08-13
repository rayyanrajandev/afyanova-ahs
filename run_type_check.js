import { execSync } from 'node:child_process';

const focusArg = (process.argv[2] ?? '').trim().toLowerCase();

try {
  execSync('node node_modules/vue-tsc/bin/vue-tsc.js --noEmit', {
    cwd: process.cwd(),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });

  if (focusArg) {
    console.log(`No errors found for filter: ${focusArg}`);
  } else {
    console.log('No TypeScript errors found');
  }
  process.exit(0);
} catch (error) {
  const stdout = error?.stdout ? String(error.stdout) : '';
  const stderr = error?.stderr ? String(error.stderr) : '';
  const fullOutput = `${stdout}${stderr}`;

  if (focusArg) {
    const filteredLines = fullOutput
      .split('\n')
      .filter((line) => line.toLowerCase().includes(focusArg));

    if (filteredLines.length > 0) {
      console.log(`Errors found for filter: ${focusArg}`);
      console.log(filteredLines.join('\n'));
      process.exit(1);
    }

    console.log(`No errors found for filter: ${focusArg}`);
    process.exit(0);
  }

  console.log(fullOutput.trim() || 'Type check failed with no output');
  process.exit(1);
}
