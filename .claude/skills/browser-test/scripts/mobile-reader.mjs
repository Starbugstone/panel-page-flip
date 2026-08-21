/**
 * The reader on a phone, driven through real touch events.
 *
 * Gestures, transforms and viewport units are the part of this app that jsdom
 * cannot judge: it lays nothing out and has no pointer model. Everything here
 * has unit tests as well; this is what proves those tests describe a browser.
 *
 *   .claude/skills/browser-test/scripts/drive.sh \
 *     .claude/skills/browser-test/scripts/mobile-reader.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.APP_BASE || 'http://nginx';
const RUN = Date.now().toString(36).slice(-5);
const SHOTS = '/out';
const errors = [];
const noise = [];

const step = (m) => console.log(`\n=== ${m} ===`);
const ok = (m) => console.log(`  PASS  ${m}`);
const bad = (m) => { console.log(`  FAIL  ${m}`); errors.push(m); };

const browser = await chromium.launch({ args: ['--no-sandbox'] });
// A phone, as far as every capability query the reader makes is concerned.
const ctx = await browser.newContext({
  viewport: { width: 390, height: 844 },
  deviceScaleFactor: 3,
  hasTouch: true,
  isMobile: true,
});
const page = await ctx.newPage();
page.on('console', (m) => { if (m.type() === 'error') noise.push(`console: ${m.text()}`); });
page.on('response', (r) => {
  if (r.url().startsWith(BASE) && r.status() >= 400) noise.push(`${r.status()} ${r.url()}`);
});

const shot = async (name) => {
  await page.screenshot({ path: `${SHOTS}/${name}.png` });
  console.log(`  shot: ${name}.png`);
};

const cdp = await ctx.newCDPSession(page);
const touch = (type, points) => cdp.send('Input.dispatchTouchEvent', {
  type,
  touchPoints: points.map((p, i) => ({ x: p.x, y: p.y, id: p.id ?? i })),
});

async function swipe(from, to, steps = 6) {
  await touch('touchStart', [from]);
  for (let i = 1; i <= steps; i++) {
    await touch('touchMove', [{
      x: from.x + ((to.x - from.x) * i) / steps,
      y: from.y + ((to.y - from.y) * i) / steps,
    }]);
  }
  await touch('touchEnd', []);
  await page.waitForTimeout(600);
}

async function tap(x, y) {
  await touch('touchStart', [{ x, y }]);
  await touch('touchEnd', []);
}

async function doubleTap(x, y) {
  await tap(x, y);
  await page.waitForTimeout(60);
  await tap(x, y);
  await page.waitForTimeout(500);
}

async function pinchOut(centre, from = 40, to = 160) {
  await touch('touchStart', [
    { x: centre.x - from, y: centre.y, id: 0 },
    { x: centre.x + from, y: centre.y, id: 1 },
  ]);
  for (let i = 1; i <= 6; i++) {
    const spread = from + ((to - from) * i) / 6;
    await touch('touchMove', [
      { x: centre.x - spread, y: centre.y, id: 0 },
      { x: centre.x + spread, y: centre.y, id: 1 },
    ]);
  }
  await touch('touchEnd', []);
  await page.waitForTimeout(400);
}

const readerState = () => page.evaluate(() => {
  const surface = document.querySelector('[data-page-fit]');
  const image = surface?.querySelector('img');
  const controls = document.querySelector('[aria-label="Reader page controls"]');
  const shown = document.querySelector('img[alt*="Page"]');
  return {
    fit: surface?.getAttribute('data-page-fit'),
    zoomed: surface?.getAttribute('data-page-zoomed'),
    transform: image?.style.transform,
    touchAction: surface ? getComputedStyle(surface).touchAction : null,
    controlsHidden: controls?.className.includes('reader-chrome-hidden') ?? null,
    controlsOpacity: controls ? getComputedStyle(controls).opacity : null,
    controlsBottomPad: controls ? getComputedStyle(controls).paddingBottom : null,
    stageHeight: surface?.parentElement?.getBoundingClientRect().height,
    innerHeight: window.innerHeight,
    alt: shown?.getAttribute('alt'),
    navZones: document.querySelectorAll('.page-navigation').length,
    suggestion: document.querySelector('[role="status"]')?.textContent ?? null,
  };
});

const pageNumber = async () => {
  const { alt } = await readerState();
  return Number(/Page (\d+)/.exec(alt || '')?.[1] ?? 0);
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

try {
  step('1. Log in and upload a comic for this run');
  await login('navtest@example.com', 'NavTest123!');
  ok('logged in');

  // The account survives between runs, so its reader preferences do too. Start
  // from defaults or the suggestion below has already been taken.
  const reset = await page.evaluate(async () => {
    const token = document.cookie.split(';').map((c) => c.trim().split('=')).find(([k]) => k === 'XSRF-TOKEN')?.[1] || '';
    const r = await fetch('/api/reader/preferences', {
      method: 'DELETE',
      credentials: 'include',
      headers: { 'X-XSRF-TOKEN': token },
    });
    return r.status;
  });
  if (reset < 400) ok('reader preferences reset to defaults');
  else bad(`could not reset reader preferences: ${reset}`);

  await page.goto(`${BASE}/upload`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await page.locator('input[type="file"]').first().setInputFiles('/fixtures/Navigator Test CBZ.cbz');
  await page.waitForTimeout(600);
  await page.locator('input#title, input[name="title"]').first().fill(`Mobile ${RUN}`);
  await page.waitForFunction(
    () => { const b = [...document.querySelectorAll('button')].find((x) => /^upload comic$/i.test(x.textContent.trim())); return b && !b.disabled; },
    { timeout: 15000 }
  );
  await page.getByRole('button', { name: /^upload comic$/i }).last().click();
  await page.waitForTimeout(9000);

  const comics = await page.evaluate(async () => {
    const r = await fetch('/api/comics', { credentials: 'include' });
    const d = await r.json();
    const list = d.comics || d.data || d;
    return (Array.isArray(list) ? list : []).map((c) => ({ id: c.id, title: c.title, pageCount: c.pageCount }));
  });
  const comic = comics.find((c) => (c.title || '').includes(RUN));
  if (!comic) { bad('upload did not appear in the library'); throw new Error('no comic'); }
  ok(`uploaded ${comic.title} (${comic.pageCount} pages)`);

  step('2. The reader on a phone in portrait');
  await page.goto(`${BASE}/read/${comic.id}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('img[alt*="Page"]', { timeout: 25000 });
  await page.waitForTimeout(2000);
  await shot('01-phone-portrait');

  let state = await readerState();
  console.log('  ' + JSON.stringify(state));

  if (state.navZones === 0) ok('no mouse click zones on a touchscreen');
  else bad(`click zones rendered on touch: ${state.navZones}`);

  if (state.touchAction === 'pan-y') ok('vertical scrolling left to the browser (touch-action: pan-y)');
  else bad(`touch-action is ${state.touchAction}`);

  const expectedStage = state.innerHeight - 160;
  if (Math.abs(state.stageHeight - expectedStage) < 2) ok(`reading area follows the real viewport (${state.stageHeight}px of ${state.innerHeight})`);
  else bad(`stage ${state.stageHeight}px, expected about ${expectedStage}px`);

  if (/fit width/i.test(state.suggestion || '') && /phone in portrait/i.test(state.suggestion || '')) ok(`fit suggested: "${state.suggestion.trim()}"`);
  else bad(`no fit suggestion on a phone in portrait: ${JSON.stringify(state.suggestion)}`);

  step('3. Accepting the suggestion writes an override, not the account default');
  await page.getByRole('button', { name: /use it here/i }).click();
  await page.waitForTimeout(1500);
  state = await readerState();
  if (state.fit === 'width') ok('page is now fitted to the width');
  else bad(`fit is ${state.fit}`);

  const stored = await page.evaluate(async () => {
    const r = await fetch('/api/reader/preferences', { credentials: 'include' });
    return r.ok ? (await r.json()).preferences : { error: r.status };
  });
  console.log('  ' + JSON.stringify(stored));
  if (stored?.settings?.fit === 'contain') ok('account default untouched');
  else bad(`account default became ${stored?.settings?.fit}`);
  if (stored?.overrides?.[0]?.context?.device === 'phone' && stored.overrides[0].settings.fit === 'width') ok('override stored against phone:portrait');
  else bad(`override not stored: ${JSON.stringify(stored?.overrides)}`);
  await shot('02-fit-width-accepted');

  step('4. Swiping turns pages');
  await page.locator('#reader-page-input').fill('1');
  await page.locator('#reader-page-input').press('Enter');
  await page.waitForTimeout(1200);
  const start = await pageNumber();

  await swipe({ x: 320, y: 380 }, { x: 90, y: 386 });
  const afterLeft = await pageNumber();
  if (afterLeft === start + 1) ok(`swipe left turned page ${start} -> ${afterLeft}`);
  else bad(`swipe left did nothing (${start} -> ${afterLeft})`);
  await shot('03-after-swipe');

  await swipe({ x: 90, y: 380 }, { x: 320, y: 386 });
  const afterRight = await pageNumber();
  if (afterRight === start) ok(`swipe right went back to page ${afterRight}`);
  else bad(`swipe right did not go back (${afterLeft} -> ${afterRight})`);

  step('5. A vertical drag scrolls rather than turning the page');
  const beforeScroll = await pageNumber();
  await swipe({ x: 200, y: 600 }, { x: 205, y: 200 });
  const afterScroll = await pageNumber();
  if (afterScroll === beforeScroll) ok('vertical drag left the page alone');
  else bad(`vertical drag turned the page (${beforeScroll} -> ${afterScroll})`);

  step('6. Double tap zooms around what was tapped, and back');
  await doubleTap(150, 400);
  state = await readerState();
  if (state.zoomed === 'true') ok(`double tap zoomed: ${state.transform}`);
  else bad(`double tap did not zoom: ${JSON.stringify(state)}`);
  if (state.touchAction === 'none') ok('a zoomed page takes both axes for panning');
  else bad(`zoomed touch-action is ${state.touchAction}`);
  await shot('04-double-tap-zoom');

  step('7. A zoomed drag pans and never turns the page');
  const zoomedPage = await pageNumber();
  const before = (await readerState()).transform;
  await swipe({ x: 320, y: 400 }, { x: 90, y: 400 });
  state = await readerState();
  if (await pageNumber() === zoomedPage) ok('page unchanged while zoomed');
  else bad(`zoomed drag turned the page (${zoomedPage} -> ${await pageNumber()})`);
  if (state.transform !== before) ok(`panned instead: ${state.transform}`);
  else bad('nothing panned');
  await shot('05-zoomed-pan');

  await doubleTap(150, 400);
  state = await readerState();
  if (state.zoomed === 'false') ok('a second double tap returned to the fitted page');
  else bad(`still zoomed: ${state.transform}`);

  step('8. Pinch');
  await pinchOut({ x: 195, y: 400 });
  state = await readerState();
  if (state.zoomed === 'true') ok(`pinch zoomed: ${state.transform}`);
  else bad(`pinch did not zoom: ${JSON.stringify(state)}`);
  await shot('06-pinched');
  await doubleTap(195, 400);

  step('9. Controls take themselves off screen, and come back on a tap');
  // Start from a known state rather than from wherever the gestures above left
  // the controls: a keypress is activity, so they must be up.
  await page.keyboard.press('Shift');
  await page.waitForTimeout(400);
  state = await readerState();
  if (state.controlsHidden === false) ok('a keypress counts as reading and keeps the controls up');
  else bad(`controls stayed hidden through a keypress: ${JSON.stringify(state)}`);

  await page.waitForTimeout(4000);
  state = await readerState();
  if (state.controlsHidden === true && Number(state.controlsOpacity) < 0.1) ok('controls faded out once nothing was happening');
  else bad(`controls still up: hidden=${state.controlsHidden} opacity=${state.controlsOpacity}`);
  await shot('07-chrome-hidden');

  await tap(195, 400);
  await page.waitForTimeout(700);
  state = await readerState();
  if (state.controlsHidden === false) ok('a tap in the middle brought them back');
  else bad(`controls did not come back: ${JSON.stringify(state)}`);
  await shot('08-chrome-back');

  await tap(195, 400);
  await page.waitForTimeout(700);
  if ((await readerState()).controlsHidden === true) ok('and another put them away again');
  else bad('the middle tap does not toggle');

  step('9b. A tap on a control belongs to the control, not to paging');
  await page.locator('#reader-page-input').fill('2');
  await page.locator('#reader-page-input').press('Enter');
  await page.waitForTimeout(1200);
  const beforeControlTap = await pageNumber();
  const gear = await page.getByRole('button', { name: /reader settings/i }).first().boundingBox();
  await tap(gear.x + gear.width / 2, gear.y + gear.height / 2);
  await page.waitForTimeout(900);
  if (await pageNumber() === beforeControlTap) ok('pressing Reader settings left the page alone');
  else bad(`a tap on the settings button turned the page (${beforeControlTap} -> ${await pageNumber()})`);
  await shot('10-control-tap');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);

  step('9c. Two fingers that drag while they pinch move the page');
  await pinchOut({ x: 195, y: 400 });
  const pinchedTransform = (await readerState()).transform;
  await touch('touchStart', [{ x: 120, y: 400, id: 0 }, { x: 270, y: 400, id: 1 }]);
  for (let i = 1; i <= 5; i++) {
    await touch('touchMove', [{ x: 120 - i * 10, y: 400, id: 0 }, { x: 270 - i * 10, y: 400, id: 1 }]);
  }
  await touch('touchEnd', []);
  await page.waitForTimeout(400);
  const draggedTransform = (await readerState()).transform;
  if (draggedTransform !== pinchedTransform) ok(`two-finger drag moved the page: ${draggedTransform}`);
  else bad('two-finger drag moved nothing');
  await doubleTap(195, 400);
  await page.waitForTimeout(400);

  step('10. Rotating keeps the page');
  await page.locator('#reader-page-input').fill('2');
  await page.locator('#reader-page-input').press('Enter');
  await page.waitForTimeout(1500);
  const beforeRotate = await pageNumber();
  await page.setViewportSize({ width: 844, height: 390 });
  await page.waitForTimeout(1500);
  const afterRotate = await pageNumber();
  if (afterRotate === beforeRotate) ok(`page ${afterRotate} survived the rotation`);
  else bad(`rotation moved the page (${beforeRotate} -> ${afterRotate})`);
  await shot('09-landscape');

  const landscape = await readerState();
  console.log('  ' + JSON.stringify(landscape));
  if (landscape.zoomed === 'false') ok('rotation left the page at natural scale');
  else bad('rotation kept a zoom framed against the old viewport');
} catch (error) {
  bad(`threw: ${error.message}`);
  await shot('99-error').catch(() => {});
} finally {
  step('Summary');
  if (noise.length) console.log('  noise:\n    ' + noise.slice(0, 15).join('\n    '));
  console.log(errors.length ? `\n${errors.length} FAILED:\n  - ${errors.join('\n  - ')}` : '\nAll assertions passed.');
  await browser.close();
  process.exit(errors.length ? 1 : 0);
}
