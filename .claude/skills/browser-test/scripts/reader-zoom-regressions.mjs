/**
 * The two zoom behaviours changed on this branch, in a browser that lays out.
 *
 * Both were found by review and fixed with jsdom tests, which cannot judge
 * either one: continuous mode needs a real scroller with real overflow, and the
 * page-turn case needs artwork that is genuinely taller than its viewport.
 *
 *   .claude/skills/browser-test/scripts/drive.sh \
 *     .claude/skills/browser-test/scripts/reader-zoom-regressions.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.APP_BASE || 'http://nginx';
const EMAIL = process.env.PPF_USER_EMAIL;
const PASSWORD = process.env.PPF_USER_PASSWORD;
const RUN = Date.now().toString(36).slice(-5);
const SHOTS = '/out';
const errors = [];
const noise = [];

if (!EMAIL || !PASSWORD) {
  console.error('Set PPF_USER_EMAIL and PPF_USER_PASSWORD before running this driver.');
  process.exit(2);
}

const step = (m) => console.log(`\n=== ${m} ===`);
const ok = (m) => console.log(`  PASS  ${m}`);
const bad = (m) => { console.log(`  FAIL  ${m}`); errors.push(m); };

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, hasTouch: true, isMobile: true });
const page = await ctx.newPage();
page.on('console', (m) => { if (m.type() === 'error') noise.push(`console: ${m.text()}`); });
page.on('response', (r) => { if (r.url().startsWith(BASE) && r.status() >= 400) noise.push(`${r.status()} ${r.url()}`); });

const shot = async (name) => { await page.screenshot({ path: `${SHOTS}/${name}.png` }); console.log(`  shot: ${name}.png`); };

const cdp = await ctx.newCDPSession(page);
const touch = (type, points) => cdp.send('Input.dispatchTouchEvent', {
  type,
  touchPoints: points.map((p, i) => ({ x: p.x, y: p.y, id: p.id ?? i })),
});

/**
 * The controls take themselves off screen while nothing is happening, and the
 * artwork then sits over them — Playwright reports the button as visible and
 * the click as intercepted. A tap in the middle is what a reader would do.
 */
async function revealChrome() {
  for (let attempt = 0; attempt < 3; attempt++) {
    const hidden = await page.evaluate(() => {
      const controls = document.querySelector('[aria-label="Reader page controls"]');
      return controls ? getComputedStyle(controls).opacity === '0' : false;
    });
    if (!hidden) return;
    await touch('touchStart', [{ x: 195, y: 400 }]);
    await touch('touchEnd', []);
    await page.waitForTimeout(700);
  }
}

async function login(email, password) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 20000 });
  const gotIt = page.getByRole('button', { name: /got it/i });
  if (await gotIt.count()) { await gotIt.first().click().catch(() => {}); await page.waitForTimeout(300); }
}

const setMode = (mode) => page.evaluate(async (wanted) => {
  const token = document.cookie.split(';').map((c) => c.trim().split('=')).find(([k]) => k === 'XSRF-TOKEN')?.[1] || '';
  const current = await (await fetch('/api/reader/preferences', { credentials: 'include' })).json();
  const preferences = { ...current.preferences, settings: { ...current.preferences.settings, mode: wanted } };
  const r = await fetch('/api/reader/preferences', {
    method: 'PUT',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
    body: JSON.stringify({ preferences }),
  });
  return r.status;
}, mode);

const savedPage = (id) => page.evaluate(async (comicId) => {
  const r = await fetch(`/api/comics/${comicId}`, { credentials: 'include' });
  const d = await r.json();
  return d.comic?.readingProgress?.currentPage ?? null;
}, id);

try {
  step('1. Log in and upload a comic for this run');
  await login(EMAIL, PASSWORD);
  ok('logged in');

  await page.goto(`${BASE}/upload`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await page.locator('input[type="file"]').first().setInputFiles('/fixtures/Navigator Test CBZ.cbz');
  await page.waitForTimeout(600);
  await page.locator('input#title, input[name="title"]').first().fill(`Zoom ${RUN}`);
  await page.waitForFunction(
    () => { const b = [...document.querySelectorAll('button')].find((x) => /^upload comic$/i.test(x.textContent.trim())); return b && !b.disabled; },
    { timeout: 15000 }
  );
  await page.getByRole('button', { name: /^upload comic$/i }).last().click();
  await page.waitForTimeout(9000);

  const comic = await page.evaluate(async (title) => {
    const r = await fetch('/api/comics', { credentials: 'include' });
    const d = await r.json();
    return (d.comics || d).find((c) => c.title === title) ?? null;
  }, `Zoom ${RUN}`);
  if (!comic) throw new Error(`uploaded comic "Zoom ${RUN}" not found`);
  ok(`uploaded as comic ${comic.id} (${comic.pageCount} pages)`);

  step('2. Continuous zoom keeps the reader where they were');
  await setMode('continuous');
  await page.goto(`${BASE}/read/${comic.id}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-reader-mode="continuous"]', { timeout: 20000 });
  await page.waitForTimeout(2500);

  // Scroll onto a later page, and let the observer report it before zooming.
  const scroller = page.locator('[data-reader-mode="continuous"]');
  await scroller.evaluate((el) => { el.scrollTop = el.scrollHeight * 0.55; });
  await page.waitForTimeout(2500);
  const before = await scroller.evaluate((el) => el.scrollTop);
  const heightBefore = await scroller.evaluate((el) => el.scrollHeight);
  const pageBefore = await savedPage(comic.id);
  console.log(`  scrollTop before: ${before}, saved page: ${pageBefore}`);
  if (before <= 0) bad('could not scroll the continuous reader; the rest of this section proves nothing');
  await shot('01-continuous-scrolled');

  await revealChrome();
  await page.getByRole('button', { name: /reader settings/i }).first().click();
  await page.waitForTimeout(800);
  const slider = page.getByRole('slider', { name: /zoom level/i });
  // 100 -> 175 is the transition that used to zero the scroller: the first step
  // off natural scale. Mid-range steps never did.
  await slider.evaluate((el) => {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(el, '175');
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await page.waitForTimeout(2500);

  const zoom = await page.locator('[data-reader-mode="continuous"]').getAttribute('data-continuous-zoom');
  if (zoom === '1.75') ok('continuous zoom applied (1.75)');
  else bad(`continuous zoom did not apply: ${zoom}`);

  const after = await scroller.evaluate((el) => el.scrollTop);
  const heightAfter = await scroller.evaluate((el) => el.scrollHeight);
  console.log(`  scrollTop after: ${after}, scrollHeight ${heightBefore} -> ${heightAfter}`);
  if (after > 0) ok(`zooming kept the reader's place (${before} -> ${after})`);
  else bad(`zooming threw the reader back to the top (${before} -> ${after})`);
  await shot('02-continuous-zoomed');

  await page.waitForTimeout(1500);
  const pageAfter = await savedPage(comic.id);
  console.log(`  saved page after: ${pageAfter}`);
  // What the fix claims is that zoom no longer throws the reader back to the
  // start. It holds the scroll *offset*, and wider pages are taller pages, so
  // the same offset can land one page earlier — that is the column growing
  // under a fixed anchor, not the reader being reset.
  if (pageAfter === 1 && pageBefore > 1) {
    bad(`zooming sent reading progress back to the first page: ${pageBefore} -> ${pageAfter}`);
  } else if (pageAfter === pageBefore) {
    ok(`zooming held reading progress exactly (page ${pageAfter})`);
  } else {
    ok(`zooming kept the reader mid-comic (page ${pageBefore} -> ${pageAfter}), not reset to the start`);
  }

  step('3. A page turn while zoomed opens at the top of the new page');
  await setMode('single');
  await page.goto(`${BASE}/read/${comic.id}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-page-fit]', { timeout: 20000 });
  await page.waitForTimeout(2000);

  // Fit to width so the artwork is taller than the viewport and there is a real
  // top edge to land on; at "contain" a zoomed page may still fit vertically.
  await revealChrome();
  await page.getByRole('button', { name: /reader settings/i }).first().click();
  await page.waitForTimeout(800);
  await page.getByRole('combobox').nth(1).click().catch(() => {});
  await page.waitForTimeout(400);
  const widthOption = page.getByRole('option', { name: /width/i }).first();
  if (await widthOption.count()) { await widthOption.click(); await page.waitForTimeout(1200); }
  await page.keyboard.press('Escape');
  await page.waitForTimeout(800);

  await revealChrome();
  await page.locator('#reader-page-input').fill('1');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(1500);

  await revealChrome();
  const zoomIn = page.getByRole('button', { name: /^zoom in$/i }).first();
  if (await zoomIn.count()) { await zoomIn.click(); await page.waitForTimeout(1200); }

  const zoomedState = await page.evaluate(() => {
    const surface = document.querySelector('[data-page-fit]');
    const image = surface?.querySelector('img');
    return {
      zoomed: surface?.getAttribute('data-page-zoomed'),
      transform: image?.style.transform,
      overflow: image ? (image.offsetHeight * (Number(/scale\(([\d.]+)\)/.exec(image.style.transform)?.[1] ?? 1)) - surface.clientHeight) / 2 : 0,
    };
  });
  console.log(`  zoomed: ${JSON.stringify(zoomedState)}`);
  if (zoomedState.zoomed !== 'true') bad('could not zoom the paged reader; the turn below proves nothing');
  else ok(`page zoomed: ${zoomedState.transform}`);
  await shot('03-paged-zoomed');

  await revealChrome();
  await page.getByRole('button', { name: /^next/i }).first().click();
  await page.waitForTimeout(2000);

  const turned = await page.evaluate(() => {
    const surface = document.querySelector('[data-page-fit]');
    const image = surface?.querySelector('img');
    const scale = Number(/scale\(([\d.]+)\)/.exec(image?.style.transform || '')?.[1] ?? 1);
    const y = Number(/translate3d\([^,]+,\s*([-\d.]+)px/.exec(image?.style.transform || '')?.[1] ?? 0);
    return {
      alt: image?.getAttribute('alt'),
      zoomed: surface?.getAttribute('data-page-zoomed'),
      transform: image?.style.transform,
      scale,
      y,
      topEdge: Math.max(0, image.offsetHeight * scale - surface.clientHeight) / 2,
    };
  });
  console.log(`  after turn: ${JSON.stringify(turned)}`);
  await shot('04-paged-turned');

  if (turned.zoomed === 'true') ok(`the turn kept the zoom (${turned.scale}x)`);
  else bad(`the turn dropped the zoom: ${turned.transform}`);

  // The top edge is the positive extreme of y. Before the fix this was 0 — the
  // middle of the page — whenever the artwork overflowed its viewport.
  if (turned.topEdge <= 1) {
    ok('page does not overflow at this zoom, so top and middle coincide (nothing to prove here)');
  } else if (Math.abs(turned.y - turned.topEdge) <= 2) {
    ok(`the new page opened at its top edge (y=${turned.y}, top=${turned.topEdge.toFixed(1)})`);
  } else {
    bad(`the new page opened away from its top (y=${turned.y}, top=${turned.topEdge.toFixed(1)})`);
  }
} catch (error) {
  bad(`threw: ${error.message}`);
} finally {
  step('Summary');
  if (noise.length) { console.log('  noise:'); noise.slice(0, 8).forEach((n) => console.log(`    ${n}`)); }
  if (errors.length) {
    console.log(`\n${errors.length} FAILED:`);
    errors.forEach((e) => console.log(`  - ${e}`));
  } else {
    console.log('\nAll assertions passed.');
  }
  await browser.close();
  process.exit(errors.length ? 1 : 0);
}
