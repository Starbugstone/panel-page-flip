/**
 * The two reader changes that jsdom cannot judge.
 *
 * 1. A reading unit holding a single page — the cover, kept alone by default —
 *    must be wholly visible in two-page mode rather than stretched to the full
 *    width and clipped at the bottom by the container's own overflow-hidden.
 * 2. A mouse click turns the page from the mat around the artwork and never
 *    from the artwork itself.
 *
 * Both are pure layout and hit-testing, so they need a browser that lays out.
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
const errors = [];

const step = (m) => console.log(`\n=== ${m} ===`);
const ok = (m) => console.log(`  PASS  ${m}`);
const bad = (m) => { console.log(`  FAIL  ${m}`); errors.push(m); };

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
page.on('console', (m) => { if (m.type() === 'error') console.log(`  console: ${m.text()}`); });

const shot = async (name) => {
  await page.screenshot({ path: `${SHOTS}/${name}.png` });
  console.log(`  shot: ${name}.png`);
};

async function login(email, password) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 20000 });
  const gotIt = page.getByRole('button', { name: /got it/i });
  if (await gotIt.count()) { await gotIt.first().click().catch(() => {}); await page.waitForTimeout(300); }
}

const currentPage = () => page.locator('#reader-page-input').inputValue();

const setMode = async (label) => {
  await page.getByRole('button', { name: /reader settings/i }).first().click();
  await page.waitForTimeout(800);
  await page.getByRole('combobox').first().click();
  await page.waitForTimeout(400);
  await page.getByRole('option', { name: label }).first().click();
  await page.waitForTimeout(1200);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(600);
};

try {
  step('1. Log in and upload a comic for this run');
  await login(USER, PASSWORD);

  await page.evaluate(async () => {
    const token = document.cookie.split(';').map((c) => c.trim())
      .find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length) || '';
    await fetch('/api/reader/preferences', {
      method: 'DELETE',
      credentials: 'include',
      headers: { 'X-XSRF-TOKEN': decodeURIComponent(token) },
    });
  });
  ok('reader preferences reset to the shipped defaults');

  const title = `Mat Spread ${RUN}`;
  await page.goto(`${BASE}/upload`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  await page.locator('input[type="file"]').first().setInputFiles('/fixtures/Navigator Test CBZ.cbz');
  await page.waitForTimeout(800);
  await page.locator('input#title, input[name="title"]').first().fill(title);
  await page.waitForFunction(
    () => [...document.querySelectorAll('button')].some((b) => /^upload comic$/i.test(b.textContent.trim()) && !b.disabled),
    { timeout: 10000 }
  );
  await page.getByRole('button', { name: /^upload comic$/i }).last().click();
  await page.waitForTimeout(9000);
  ok(`uploaded ${title}`);

  step('2. The default reading mode');
  await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await page.getByText(title).first().click();
  await page.waitForTimeout(1500);
  const readUrl = page.url();
  await page.waitForTimeout(2500);

  const continuous = await page.locator('[data-reader-mode="continuous"]').count();
  if (continuous > 0) ok('a fresh account reads continuously by default');
  else bad('the default reading mode is not continuous scroll');
  await shot('r1-default-continuous');

  step('3. Two-page mode: the lone cover must be whole');
  await setMode(/two pages/i);
  await page.locator('#reader-page-input').fill('1');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(2500);

  const spread = await page.locator('[data-reader-mode="double"]').count();
  if (spread > 0) ok('two-page mode is active');
  else bad('two-page mode did not engage on a 1280x900 desktop');

  const geometry = await page.evaluate(() => {
    const viewport = document.querySelector('[data-reader-mode="double"]');
    const images = [...document.querySelectorAll('img[data-reader-artwork]')];
    if (!viewport || images.length === 0) return null;
    const v = viewport.getBoundingClientRect();

    return {
      imageCount: images.length,
      viewport: { top: v.top, bottom: v.bottom, height: v.height, width: v.width },
      images: images.map((img) => {
        const r = img.getBoundingClientRect();
        return {
          alt: img.alt,
          top: r.top,
          bottom: r.bottom,
          height: r.height,
          width: r.width,
          naturalWidth: img.naturalWidth,
          naturalHeight: img.naturalHeight,
        };
      }),
    };
  });

  console.log(`  geometry: ${JSON.stringify(geometry, null, 2)}`);
  await shot('r2-spread-lone-cover');

  if (!geometry) {
    bad('no spread geometry to measure');
  } else {
    if (geometry.imageCount === 1) ok('the cover is shown alone, as a cover should be');
    else bad(`expected a lone cover, got ${geometry.imageCount} images in the unit`);

    for (const img of geometry.images) {
      // A one-pixel tolerance: sub-pixel layout rounding is not clipping.
      const clippedBelow = img.bottom - geometry.viewport.bottom;
      const clippedAbove = geometry.viewport.top - img.top;

      if (clippedBelow <= 1) ok(`${img.alt}: bottom is inside the reader (overflow ${clippedBelow.toFixed(1)}px)`);
      else bad(`${img.alt}: clipped at the bottom by ${clippedBelow.toFixed(1)}px`);

      if (clippedAbove <= 1) ok(`${img.alt}: top is inside the reader`);
      else bad(`${img.alt}: clipped at the top by ${clippedAbove.toFixed(1)}px`);

      // The whole artwork, not just a box that happens to fit: the rendered
      // aspect ratio has to match the file's.
      const rendered = img.width / img.height;
      const natural = img.naturalWidth / img.naturalHeight;
      if (Math.abs(rendered - natural) < 0.02) ok(`${img.alt}: aspect ratio intact (${rendered.toFixed(3)} vs ${natural.toFixed(3)})`);
      else bad(`${img.alt}: distorted, ${rendered.toFixed(3)} rendered vs ${natural.toFixed(3)} natural`);

      // And it must not be a sliver: a page bounded to nothing is "not clipped"
      // in the most useless possible way.
      if (img.height > geometry.viewport.height * 0.5) ok(`${img.alt}: fills the height it was given (${img.height.toFixed(0)}px of ${geometry.viewport.height.toFixed(0)}px)`);
      else bad(`${img.alt}: only ${img.height.toFixed(0)}px tall in a ${geometry.viewport.height.toFixed(0)}px reader`);
    }
  }

  step('4. Clicking the mat turns the page; clicking the page does not');
  await page.goto(readUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await setMode(/single page/i);
  await page.locator('#reader-page-input').fill('1');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(2000);
  await shot('r3-single-page-1');

  const boxes = await page.evaluate(() => {
    const viewport = document.querySelector('[data-page-fit]');
    const img = document.querySelector('img[data-reader-artwork]');
    if (!viewport || !img) return null;
    const v = viewport.getBoundingClientRect();
    const i = img.getBoundingClientRect();

    return { viewport: { x: v.x, y: v.y, width: v.width, height: v.height }, image: { x: i.x, y: i.y, width: i.width, height: i.height } };
  });
  console.log(`  boxes: ${JSON.stringify(boxes)}`);

  if (!boxes) {
    bad('no single-page geometry to click against');
  } else {
    const matOnLeft = boxes.image.x - boxes.viewport.x;
    const matOnRight = (boxes.viewport.x + boxes.viewport.width) - (boxes.image.x + boxes.image.width);
    console.log(`  mat: ${matOnLeft.toFixed(0)}px left, ${matOnRight.toFixed(0)}px right`);

    if (matOnLeft > 20 && matOnRight > 20) {
      ok('a best-fit page leaves a mat on both sides to aim at');

      const before = await currentPage();

      // The middle of the artwork: the click that used to turn the page.
      await page.mouse.click(boxes.image.x + boxes.image.width / 2, boxes.image.y + boxes.image.height / 2);
      await page.waitForTimeout(1200);
      const afterArtwork = await currentPage();
      if (afterArtwork === before) ok(`clicking the artwork left the page alone (still ${afterArtwork})`);
      else bad(`clicking the artwork turned the page: ${before} -> ${afterArtwork}`);

      // The mat on the right, well inside the right-hand tap zone.
      const matX = boxes.viewport.x + boxes.viewport.width - Math.min(matOnRight / 2, 40);
      await page.mouse.click(matX, boxes.viewport.y + boxes.viewport.height / 2);
      await page.waitForTimeout(1500);
      const afterMat = await currentPage();
      if (Number(afterMat) === Number(before) + 1) ok(`clicking the mat advanced the page (${before} -> ${afterMat})`);
      else bad(`clicking the mat did not advance: ${before} -> ${afterMat}`);
      await shot('r4-after-mat-click');

      // And back again on the left.
      const leftX = boxes.viewport.x + Math.min(matOnLeft / 2, 40);
      await page.mouse.click(leftX, boxes.viewport.y + boxes.viewport.height / 2);
      await page.waitForTimeout(1500);
      const afterLeft = await currentPage();
      if (Number(afterLeft) === Number(before)) ok(`clicking the left mat went back (${afterMat} -> ${afterLeft})`);
      else bad(`clicking the left mat did not go back: ${afterMat} -> ${afterLeft}`);
    } else {
      bad(`no mat to click: ${matOnLeft.toFixed(0)}px left, ${matOnRight.toFixed(0)}px right`);
    }
  }

  step('5. Zoom offers a grab cursor');
  await page.getByRole('button', { name: /zoom in/i }).first().click();
  await page.waitForTimeout(1200);
  const cursor = await page.evaluate(() => {
    const viewport = document.querySelector('[data-page-fit]');
    return viewport ? { cursor: getComputedStyle(viewport).cursor, zoomed: viewport.dataset.pageZoomed } : null;
  });
  console.log(`  cursor: ${JSON.stringify(cursor)}`);
  if (cursor?.zoomed === 'true' && cursor.cursor === 'grab') ok('a zoomed page offers the grab cursor');
  else bad(`zoomed page cursor was ${JSON.stringify(cursor)}`);
  await shot('r5-zoomed');

  await page.keyboard.press('Escape');
  await page.waitForTimeout(1000);
  const afterEscape = await page.locator('[data-page-fit]').first().getAttribute('data-page-zoomed');
  if (afterEscape === 'false') ok('Escape leaves the zoom');
  else bad(`Escape did not leave the zoom (data-page-zoomed=${afterEscape})`);
  await shot('r6-after-escape');
} catch (e) {
  bad(`driver threw: ${e.message}`);
  await shot('r-error');
} finally {
  console.log(`\n${errors.length === 0 ? 'ALL PASS' : `${errors.length} FAILURE(S)`}`);
  errors.forEach((e) => console.log(`  - ${e}`));
  await browser.close();
  process.exit(errors.length === 0 ? 0 : 1);
}
