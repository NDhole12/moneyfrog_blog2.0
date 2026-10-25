// Full-page screenshots at 1440 and 390 wide, plus health checks.
// Usage (from migration/, where playwright is installed):
//   node ../tests/visual/shoot.mjs <url> <name>
// Saves migration/output/shots/<name>-1440.png and <name>-390.png (git-ignored).
// Exits non-zero on console errors, failed requests, horizontal overflow, or a main
// document status other than 200 (pass --allow-status=404 for the 404 page).
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(resolve(here, '../../migration/package.json'));
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const flags = args.filter((a) => a.startsWith('--'));
const [url, name] = args.filter((a) => !a.startsWith('--'));
const allowed = flags.map((f) => /^--allow-status=(\d+)$/.exec(f)?.[1]).filter(Boolean).map(Number);
const expectedStatus = allowed.length ? allowed : [200];
if (!url || !name) {
  console.error('usage: node shoot.mjs <url> <name>');
  process.exit(2);
}

const outDir = resolve(here, '../../migration/output/shots');
mkdirSync(outDir, { recursive: true });

const VIEWPORTS = [
  { width: 1440, height: 900 },
  { width: 390, height: 844 },
];

const problems = [];
const browser = await chromium.launch();
try {
  for (const viewport of VIEWPORTS) {
    const page = await browser.newPage({ viewport });
    const tag = `${viewport.width}px`;
    page.on('console', (msg) => {
      if (msg.type() === 'error' && !(expectedStatus.includes(404) && /status of 404/.test(msg.text()))) problems.push(`[${tag}] console error: ${msg.text()}`);
    });
    page.on('pageerror', (err) => problems.push(`[${tag}] page error: ${err.message}`));
    page.on('requestfailed', (req) => {
      problems.push(`[${tag}] request failed: ${req.url()} (${req.failure()?.errorText})`);
    });
    page.on('response', (res) => {
      if (res.status() >= 400 && res.url() !== url) {
        problems.push(`[${tag}] HTTP ${res.status()}: ${res.url()}`);
      }
    });

    const main = await page.goto(url, { waitUntil: 'networkidle' });
    const status = main ? main.status() : 0;
    if (!expectedStatus.includes(status)) {
      problems.push(`[${tag}] main document HTTP ${status}, expected ${expectedStatus.join('/')}`);
    }
    await page.evaluate(() => document.fonts.ready);

    const overflow = await page.evaluate(() => {
      const doc = document.documentElement;
      return { scroll: doc.scrollWidth, client: doc.clientWidth };
    });
    if (overflow.scroll > overflow.client) {
      problems.push(`[${tag}] horizontal overflow: scrollWidth ${overflow.scroll} > ${overflow.client}`);
    }

    const file = resolve(outDir, `${name}-${viewport.width}.png`);
    await page.screenshot({ path: file, fullPage: true });
    console.log(`saved ${file}`);
    await page.close();
  }
} finally {
  await browser.close();
}

if (problems.length) {
  console.error(problems.join('\n'));
  process.exit(1);
}
console.log('clean: no console errors, failed requests or horizontal overflow');
