import { chromium } from 'playwright';
import fs from 'node:fs';

/**
 * The metadata paths: what a comic says about itself, what its filename
 * implies, and what a person accepts.
 *
 * Run after drive.mjs, or on its own — it uploads what it needs.
 */

const BASE = process.env.APP_BASE || 'http://nginx';
const SHOTS = '/out';
const RUN = Date.now().toString(36).slice(-5);
const errors = [];
const netFail = [];

const step = (m) => console.log(`\n=== ${m} ===`);
const ok = (m) => console.log(`  PASS  ${m}`);
const bad = (m) => { console.log(`  FAIL  ${m}`); errors.push(m); };

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
const page = await ctx.newPage();

page.on('console', (m) => { if (m.type() === 'error') netFail.push(`console: ${m.text()}`); });
page.on('response', (r) => {
  if (r.url().startsWith(BASE) && r.status() >= 400 && !/\/api\/(me|reader\/preferences)/.test(r.url())) {
    netFail.push(`${r.status()} ${r.url()}`);
  }
});

const shot = async (name) => {
  await page.screenshot({ path: `${SHOTS}/meta-${name}.png` });
  console.log(`  shot: meta-${name}.png`);
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

async function upload(file, title) {
  await page.goto(`${BASE}/upload`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await page.locator('input[type="file"]').first().setInputFiles(file);
  await page.waitForTimeout(600);
  const titleField = page.locator('input#title, input[name="title"]').first();
  if (await titleField.count()) await titleField.fill(title);
  try {
    await page.waitForFunction(
      () => { const b = [...document.querySelectorAll('button')].find(x => /^upload comic$/i.test(x.textContent.trim())); return b && !b.disabled; },
      { timeout: 10000 }
    );
  } catch { bad(`${title}: upload button never enabled`); return null; }
  await page.getByRole('button', { name: /^upload comic$/i }).last().click();
  await page.waitForTimeout(8000);

  const comics = await page.evaluate(async () => {
    const r = await fetch('/api/comics', { credentials: 'include' });
    const d = await r.json();
    return (d.comics || d.data || d || []).map((c) => ({ id: c.id, title: c.title }));
  });
  return comics.find((c) => c.title === title)?.id ?? null;
}

/**
 * The edit dialog is behind the card's overflow menu, whose trigger is labelled
 * "Actions for <title>" — the only stable hook, since cards carry no test id.
 */
const openEditDialog = async (title) => {
  await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);

  const trigger = page.getByRole('button', { name: `Actions for ${title}` });
  if (!(await trigger.count())) return false;

  await trigger.first().click();
  await page.waitForTimeout(600);

  const edit = page.getByRole('menuitem', { name: /edit/i });
  if (!(await edit.count())) return false;

  await edit.first().click();
  await page.waitForTimeout(1500);

  return true;
};

try {
  // ---------- ComicInfo.xml is read at import ----------
  step('1. A comic that describes itself');
  await login('navtest@example.com', 'NavTest123!');
  const taggedId = await upload('/fixtures/Navigator Tagged 007 (1997).cbz', `Tagged ${RUN}`);
  if (!taggedId) throw new Error('tagged comic did not import');
  ok(`imported as comic ${taggedId}`);

  const tagged = await page.evaluate(async (id) => {
    const r = await fetch(`/api/comics/${id}`, { credentials: 'include' });
    return (await r.json()).comic;
  }, taggedId);
  console.log('  stored: ' + JSON.stringify({
    series: tagged.series, issueNumber: tagged.issueNumber, issueCount: tagged.issueCount,
    volume: tagged.volume, publishedAt: tagged.publishedAt, languageCode: tagged.languageCode,
    ageRating: tagged.ageRating, readingDirection: tagged.readingDirection, creators: tagged.creators,
  }));

  const expected = {
    series: 'Navigator Chronicles', issueNumber: '7', issueCount: 13, volume: 1996,
    publishedAt: '1997-04-09', languageCode: 'en', ageRating: 'Teen', readingDirection: 'rtl',
  };
  for (const [field, want] of Object.entries(expected)) {
    if (tagged[field] === want) ok(`${field} = ${want}`);
    else bad(`${field}: expected ${want}, got ${JSON.stringify(tagged[field])}`);
  }
  if (tagged.creators?.writer?.[0] === 'Jeph Loeb') ok('creators read from the file');
  else bad(`creators: got ${JSON.stringify(tagged.creators)}`);

  // The uploader typed a title; the file must not have overwritten it.
  if (tagged.title === `Tagged ${RUN}`) ok('the typed title survived the file');
  else bad(`title was overwritten: ${tagged.title}`);

  // ---------- Filename suggestions ----------
  step('2. Suggestions from the filename');
  const plainId = await upload('/fixtures/Navigator Test CBZ.cbz', `Plain ${RUN}`);
  if (!plainId) throw new Error('plain comic did not import');

  const suggestions = await page.evaluate(async (id) => {
    const r = await fetch(`/api/comics/${id}/metadata-suggestions`, { credentials: 'include' });
    return (await r.json()).suggestions;
  }, plainId);
  console.log('  suggestions: ' + JSON.stringify(suggestions));
  if (suggestions.length > 0) ok(`${suggestions.length} suggestion(s) offered`);
  else bad('no suggestions from a filename that carries a series');
  if (suggestions.every((s) => s.source === 'filename')) ok('all marked as coming from the filename');
  else bad('a suggestion claimed the wrong source');

  // Reading suggestions must not change the comic.
  const after = await page.evaluate(async (id) => {
    const r = await fetch(`/api/comics/${id}`, { credentials: 'include' });
    return (await r.json()).comic;
  }, plainId);
  if (after.series === null) ok('reading suggestions changed nothing');
  else bad(`series was written without acceptance: ${after.series}`);

  // ---------- Provider candidates degrade without credentials ----------
  step('3. Provider lookup with nothing configured');
  const candidates = await page.evaluate(async (id) => {
    const r = await fetch(`/api/comics/${id}/metadata-candidates`, { credentials: 'include' });
    return { status: r.status, body: await r.json() };
  }, plainId);
  if (candidates.status === 200 && Array.isArray(candidates.body.candidates)) {
    ok(`unconfigured providers return an empty list, not an error (${candidates.body.candidates.length} candidates)`);
  } else bad(`provider lookup returned ${candidates.status}`);

  // ---------- Review and apply, through the UI ----------
  step('4. Accepting a suggestion in the edit dialog');
  const opened = await openEditDialog(`Plain ${RUN}`);
  await shot('01-library');

  if (opened) {
    await shot('02-edit-dialog');

    // The heading carries an icon, so it is not a clean text node. This line is,
    // and it is also the promise the panel makes to the user.
    const panelPromise = page.getByText(/Nothing here changes the comic until you use it/i);
    if (await panelPromise.count()) ok('the suggestions panel is in the edit dialog, and says nothing applies until used');
    else bad('no suggestions panel in the edit dialog');

    const useButton = page.getByRole('button', { name: /^use series/i }).first();
    if (await useButton.count()) {
      await useButton.click();
      await page.waitForTimeout(600);
      const seriesField = page.locator('#series');
      const staged = await seriesField.inputValue();
      if (staged && staged.length > 0) ok(`accepting staged "${staged}" into the form`);
      else bad('accepting did not stage anything into the form');
      await shot('03-staged');

      // Not saved until Save is pressed.
      const beforeSave = await page.evaluate(async (id) => {
        const r = await fetch(`/api/comics/${id}`, { credentials: 'include' });
        return (await r.json()).comic.series;
      }, plainId);
      if (beforeSave === null) ok('staging did not write to the server');
      else bad(`staging wrote to the server: ${beforeSave}`);

      await page.getByRole('button', { name: /^save/i }).first().click();
      await page.waitForTimeout(2500);

      const afterSave = await page.evaluate(async (id) => {
        const r = await fetch(`/api/comics/${id}`, { credentials: 'include' });
        return (await r.json()).comic.series;
      }, plainId);
      if (afterSave === staged) ok(`saving persisted "${afterSave}"`);
      else bad(`saving did not persist: expected ${staged}, got ${JSON.stringify(afterSave)}`);
      await shot('04-saved');
    } else bad('no "Use" button offered for series');
  } else bad('could not open the edit dialog');

  // ---------- Admin credentials ----------
  step('5. Admin metadata provider credentials');
  const logout = page.getByRole('button', { name: /logout/i }).or(page.getByRole('link', { name: /logout/i }));
  if (await logout.count()) { await logout.first().click().catch(() => {}); await page.waitForTimeout(1200); }
  await ctx.clearCookies();
  await login('navadmin@example.com', 'NavAdmin123!');

  await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  const metaTab = page.getByRole('tab', { name: /metadata/i });
  if (await metaTab.count()) {
    await metaTab.first().click();
    await page.waitForTimeout(1200);
    await shot('05-admin-metadata');
    ok('the metadata tab is in the admin panel');

    const body = await page.textContent('body');
    if (/Metron/.test(body) && /Comic Vine/.test(body)) ok('both providers listed');
    else bad('a provider is missing from the panel');
    if (/Not configured/.test(body)) ok('reports them as not configured');

    const keyField = page.locator('#comicVineApiKey');
    if (await keyField.count()) {
      await keyField.fill('placeholder-key-for-the-smoke-test');
      await page.getByRole('button', { name: /save credentials/i }).click();
      await page.waitForTimeout(2000);
      await shot('06-admin-configured');

      const shown = await page.textContent('body');
      if (/Configured/.test(shown)) ok('saving reports the provider as configured');
      else bad('saving did not report the provider as configured');
      if (!/placeholder-key-for-the-smoke-test/.test(shown)) ok('the key is never echoed back');
      else bad('the key was rendered back into the page');
      if ((await keyField.inputValue()) === '') ok('the field is cleared after saving');
      else bad('the key stayed in the form after saving');

      const remove = page.getByRole('button', { name: /^remove$/i });
      if (await remove.count()) {
        await remove.first().click();
        await page.waitForTimeout(1800);
        if (/Not configured/.test(await page.textContent('body'))) ok('removing a credential works');
        else bad('removing a credential did not take effect');
      }
    } else bad('no Comic Vine key field');
  } else bad('no metadata tab in the admin panel');

} catch (e) {
  bad(`EXCEPTION: ${e.message}`);
  await shot('99-exception');
} finally {
  step('SUMMARY');
  console.log(`failures: ${errors.length}`);
  errors.forEach((e) => console.log(`  - ${e}`));
  const noise = [...new Set(netFail)];
  if (noise.length) {
    console.log(`\nconsole/network problems (${noise.length}):`);
    noise.slice(0, 20).forEach((e) => console.log(`  - ${e}`));
  } else console.log('\nno console errors, no unexpected 4xx/5xx');
  fs.writeFileSync(`${SHOTS}/result-metadata.json`, JSON.stringify({ failures: errors, netFail: noise }, null, 2));
  await browser.close();
  process.exit(errors.length ? 1 : 0);
}
