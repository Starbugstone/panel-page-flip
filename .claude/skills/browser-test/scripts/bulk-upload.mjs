/**
 * Drive the bulk upload queue and report exactly what the server said.
 *
 * The queue reports "Failed" and nothing else, so a failing upload gives an
 * operator no way to tell a rejected file from a timed-out one. This prints
 * every /upload/* response with its body, which is how the qpdf structural
 * check was found to be timing out on large PDFs.
 *
 * Swap the fixtures below for a copy of whatever is actually failing; the
 * shipped ones are small and only prove the happy path.
 */
import { chromium } from 'playwright';

const BASE = process.env.APP_BASE || 'http://nginx';
const RUN = Date.now().toString(36).slice(-5);
const SHOTS = '/out';
// Supplied by drive.sh from the fixture accounts up.sh creates. Read rather
// than embedded: a literal password in a committed file is what secret
// scanners exist to catch, and they are right to catch it.
const USER = process.env.PPF_USER_EMAIL || 'navtest@example.com';
const PASSWORD = process.env.PPF_USER_PASSWORD;
if (!PASSWORD) {
  console.error('PPF_USER_PASSWORD is not set. Run this through drive.sh.');
  process.exit(2);
}

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
const page = await ctx.newPage();

page.on('console', (m) => console.log(`  console[${m.type()}]: ${m.text()}`));
page.on('pageerror', (e) => console.log(`  pageerror: ${e.message}`));
page.on('response', async (r) => {
  const u = r.url();
  if (!/\/api\/comics\/upload\//.test(u)) return;
  let body = '';
  try { body = (await r.text()).slice(0, 600); } catch { body = '<unreadable>'; }
  console.log(`  ${r.request().method()} ${u.replace(BASE, '')} -> ${r.status()} ${body}`);
});

try {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"]', USER);
  await page.fill('input[type="password"]', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 20000 });
  const gotIt = page.getByRole('button', { name: /got it/i });
  if (await gotIt.count()) { await gotIt.first().click().catch(() => {}); await page.waitForTimeout(300); }
  console.log('logged in');

  await page.goto(`${BASE}/upload/bulk`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);

  await page.locator('input[type="file"]').first().setInputFiles([
    '/fixtures/Navigator Test CBZ.cbz',
    '/fixtures/Navigator Tagged 007 (1997).cbz',
  ]);
  await page.waitForTimeout(1000);

  // Distinct titles so this run cannot collide with a previous one.
  const titles = await page.locator('input[aria-label^="Title for"]').all();
  for (const [i, field] of titles.entries()) {
    await field.fill(`Bulk ${RUN} #${i + 1}`);
  }
  await page.screenshot({ path: `${SHOTS}/b1-queued.png` });

  console.log('\n--- starting queue ---');
  await page.getByRole('button', { name: /start all/i }).click();
  await page.waitForTimeout(30000);

  await page.screenshot({ path: `${SHOTS}/b2-after.png` });

  const rows = await page.evaluate(() => [...document.querySelectorAll('tbody tr')].map((tr) => {
    const cells = [...tr.querySelectorAll('td')].map((td) => td.innerText.replace(/\n+/g, ' | ').trim());
    return cells;
  }));
  console.log('\n--- queue rows ---');
  rows.forEach((r) => console.log(`  ${JSON.stringify(r)}`));

  const summary = await page.evaluate(() => {
    const el = [...document.querySelectorAll('div')].find((d) => /queued\.|completed,/.test(d.textContent) && d.children.length === 0);
    return el?.textContent?.trim() || null;
  });
  console.log(`\nsummary: ${summary}`);
} catch (e) {
  console.log(`DRIVER THREW: ${e.message}`);
  await page.screenshot({ path: `${SHOTS}/b-error.png` });
} finally {
  await browser.close();
}
