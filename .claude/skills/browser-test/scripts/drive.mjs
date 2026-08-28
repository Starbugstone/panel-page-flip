import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = process.env.APP_BASE || 'http://nginx';
// Comics accumulate across runs, so this run's uploads are tagged and only
// those are driven. Re-running otherwise tests whichever comic sorted first.
const RUN = Date.now().toString(36).slice(-5);
const SHOTS = '/out';
const errors = [];
const netFail = [];
const pageResponses = [];

const step = (m) => console.log(`\n=== ${m} ===`);
const ok = (m) => console.log(`  PASS  ${m}`);
const bad = (m) => { console.log(`  FAIL  ${m}`); errors.push(m); };

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();

page.on('console', (m) => {
  if (m.type() === 'error') netFail.push(`console: ${m.text()}`);
});
page.on('response', async (r) => {
  const u = r.url();
  if (/\/api\/comics\/\d+\/pages\/\d+/.test(u)) {
    pageResponses.push({ url: u, status: r.status(), type: r.headers()['content-type'], etag: r.headers()['etag'] });
  }
  if (u.startsWith(BASE) && r.status() >= 400 && !/\/api\/reader\/preferences/.test(u)) {
    netFail.push(`${r.status()} ${u}`);
  }
});

const shot = async (name) => {
  await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
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

try {
  // ---------- 1. Admin enables PDF (PR #103 admin surface) ----------
  step('1. Admin login + enable PDF format');
  await login('navadmin@example.com', 'NavAdmin123!');
  ok('admin logged in');
  await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await shot('01-admin');

  const formatsTab = page.getByRole('tab', { name: /format/i });
  if (await formatsTab.count()) {
    await formatsTab.first().click();
    await page.waitForTimeout(1200);
  }
  await shot('02-admin-formats');

  const bodyText = await page.textContent('body');
  for (const f of ['CBZ', 'CBR', 'CB7', 'CBT', 'PDF']) {
    if (bodyText.includes(f)) ok(`formats panel lists ${f}`); else bad(`formats panel missing ${f}`);
  }
  if (/WebP/i.test(bodyText)) ok('page delivery row reports WebP'); else console.log('  note: no WebP row text found');

  // Formats are checkboxes with id="format-<name>".
  const pdfBox = page.locator('#format-pdf');
  if (await pdfBox.count()) {
    if ((await pdfBox.getAttribute('aria-checked')) !== 'true') await pdfBox.click();
    await page.waitForTimeout(400);
    ok(`pdf checkbox now ${await pdfBox.getAttribute('aria-checked')}`);
  } else bad('no #format-pdf checkbox');

  const saveBtn = page.getByRole('button', { name: /save enabled formats/i });
  if (await saveBtn.count()) { await saveBtn.first().click(); await page.waitForTimeout(2500); ok('saved formats'); }
  else bad('no save button');
  await shot('03-admin-formats-saved');

  // Confirm the server really persisted it.
  const enabled = await page.evaluate(async () => {
    const r = await fetch('/api/admin/comic-formats', { credentials: 'include' });
    return r.ok ? await r.json() : { error: r.status };
  });
  const pdfEnabled = JSON.stringify(enabled).match(/"pdf":\{[^}]*"enabled":true/);
  if (pdfEnabled) ok('server reports PDF enabled'); else bad(`server did not persist PDF: ${JSON.stringify(enabled).slice(0, 300)}`);

  // ---------- 2. Upload as a normal user ----------
  step('2. User login + upload CBZ and PDF');
  const logout = page.getByRole('button', { name: /logout/i }).or(page.getByRole('link', { name: /logout/i }));
  if (await logout.count()) { await logout.first().click().catch(() => {}); await page.waitForTimeout(1500); }
  await ctx.clearCookies();
  await login('navtest@example.com', 'NavTest123!');
  ok('user logged in');

  // Reader preferences are saved per account and outlive the run that set
  // them, exactly as comics do. A previous driver that left the reader in
  // two-page mode makes the page assertions below fail against an app that is
  // working perfectly — so start every run from the shipped defaults.
  const prefsReset = await page.evaluate(async () => {
    const token = document.cookie.split(';').map((c) => c.trim())
      .find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length) || '';
    const r = await fetch('/api/reader/preferences', {
      method: 'DELETE',
      credentials: 'include',
      headers: { 'X-XSRF-TOKEN': decodeURIComponent(token) },
    });
    return r.status;
  });
  if (prefsReset < 400) ok(`reader preferences reset to defaults (${prefsReset})`);
  else bad(`could not reset reader preferences: ${prefsReset}`);

  await shot('04-dashboard');

  for (const [file, title] of [
    ['/fixtures/Navigator Test CBZ.cbz', `Navigator CBZ ${RUN}`],
    ['/fixtures/Navigator Test PDF.pdf', `Navigator PDF ${RUN}`],
  ]) {
    await page.goto(`${BASE}/upload`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    const input = page.locator('input[type="file"]').first();
    if (!(await input.count())) { bad(`no file input on /upload for ${title}`); continue; }
    await input.setInputFiles(file);
    await page.waitForTimeout(800);
    const titleField = page.locator('input#title, input[name="title"]').first();
    if (await titleField.count()) { await titleField.fill(title); }
    const submit = page.getByRole('button', { name: /^upload comic$/i }).last();
    try {
      await submit.waitFor({ state: 'visible', timeout: 5000 });
      await page.waitForFunction(
        () => { const b = [...document.querySelectorAll('button')].find(x => /^upload comic$/i.test(x.textContent.trim())); return b && !b.disabled; },
        { timeout: 10000 }
      );
    } catch { bad(`${title}: upload button never enabled (file rejected?)`); await shot(`err-${title.replace(/\W+/g,'-')}`); continue; }
    await submit.click();
    await page.waitForTimeout(9000);
    console.log(`  submitted ${title}`);
  }
  await shot('05-after-uploads');

  // ---------- 3. Library ----------
  step('3. Library shows both comics');
  await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await shot('06-library');
  const libText = await page.textContent('body');
  for (const t of [`Navigator CBZ ${RUN}`, `Navigator PDF ${RUN}`]) {
    if (libText.includes(t)) ok(`library shows ${t}`); else bad(`library missing ${t}`);
  }

  // ---------- 4. Reader (PR #104) ----------
  const comics = await page.evaluate(async () => {
    const r = await fetch('/api/comics', { credentials: 'include' });
    const d = await r.json();
    const list = d.comics || d.data || d;
    return (Array.isArray(list) ? list : []).map((c) => ({ id: c.id, title: c.title, pageCount: c.pageCount, sourceType: c.sourceType }));
  });
  console.log('  comics: ' + JSON.stringify(comics));

  for (const c of comics.filter((c) => (c.title || '').includes(RUN))) {
    step(`4. Reader: ${c.title} (${c.sourceType}, ${c.pageCount} pages)`);
    pageResponses.length = 0;
    await page.goto(`${BASE}/read/${c.id}`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('img[alt*="Page"]', { timeout: 25000 }).catch(() => {});
    await page.waitForTimeout(1500);

    // A comic opens where it was last left, not at page 1 — that is the reader
    // restoring saved progress, and asserting on page 1 without this reads as
    // a bug in the app when it is a bug in the test.
    const pageBox = page.locator('#reader-page-input');
    if (await pageBox.count()) {
      await pageBox.fill('1');
      await pageBox.press('Enter');
      await page.waitForSelector('img[alt*="Page 1"]', { timeout: 15000 }).catch(() => {});
    }
    await shot(`07-reader-${c.sourceType}-page1`);

    if (await page.locator('img[alt*="Page 1"]').count()) ok(`${c.title}: page 1 rendered`);
    else bad(`${c.title}: page 1 did not render`);

    // Next page
    const next = page.getByRole('button', { name: /^next/i }).first();
    if (await next.count()) {
      await next.click();
      await page.waitForSelector('img[alt*="Page 2"]', { timeout: 15000 }).catch(() => {});
      if (await page.locator('img[alt*="Page 2"]').count()) ok(`${c.title}: navigated to page 2`);
      else bad(`${c.title}: next did not reach page 2`);
      await shot(`08-reader-${c.sourceType}-page2`);
    } else bad(`${c.title}: no Next button`);

    // Keyboard nav
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(1200);
    if (await page.locator('img[alt*="Page 3"]').count()) ok(`${c.title}: ArrowRight advanced to page 3`);
    else bad(`${c.title}: ArrowRight did not advance`);

    // Reader settings popover (PR #104)
    const gear = page.getByRole('button', { name: /reader settings/i }).first();
    if (await gear.count()) {
      await gear.click();
      await page.waitForTimeout(1000);
      await shot(`09-reader-${c.sourceType}-settings`);
      ok(`${c.title}: settings popover opened`);

      // Every control must be enabled once preferences have loaded — except
      // the ones a reading mode genuinely does not have. "Show first page
      // alone" only means anything in two-page mode and is disabled outside
      // it, so asserting on every switch reported a failure against a
      // perfectly healthy app on every single run.
      // "Different page size here" is gated the same way, to the mirror case:
      // it keeps a per-viewport page size, and continuous mode — the shipped
      // default, so the first comic of a run meets it — has no page size to
      // keep. Both come back below.
      const modeGated = ['reader-cover-alone', 'reader-context-override'];
      const disabled = [];
      for (const s of await page.locator('[role="switch"]').all()) {
        if (modeGated.includes(await s.getAttribute('id'))) continue;
        if (await s.isDisabled()) disabled.push((await s.getAttribute('aria-label')) || '?');
      }
      if (disabled.length === 0) ok(`${c.title}: all setting switches enabled after load`);
      else bad(`${c.title}: switches still disabled after load: ${disabled.join(', ')}`);

      // And prove the gate is a gate, not a stuck control: it has to come back
      // to life when the mode it belongs to is selected.
      const coverAlone = page.locator('#reader-cover-alone');
      if (await coverAlone.count()) {
        if (!(await coverAlone.isDisabled())) ok(`${c.title}: cover-alone already enabled`);
        else {
          const modeCombo = page.getByRole('combobox').first();
          await modeCombo.click();
          await page.waitForTimeout(500);
          const twoPages = page.getByRole('option', { name: /two pages/i }).first();
          if (await twoPages.count()) {
            await twoPages.click();
            await page.waitForTimeout(1200);
            if (!(await coverAlone.isDisabled())) ok(`${c.title}: cover-alone enables in two-page mode`);
            else bad(`${c.title}: cover-alone stays disabled even in two-page mode`);
            const contextOverride = page.locator('#reader-context-override');
            if (await contextOverride.count()) {
              if (!(await contextOverride.isDisabled())) ok(`${c.title}: context override enables outside continuous mode`);
              else bad(`${c.title}: context override stays disabled even in two-page mode`);
            }
            // Put the mode back so the fit assertions below are unaffected.
            await modeCombo.click();
            await page.waitForTimeout(500);
            await page.getByRole('option', { name: /single page/i }).first().click().catch(() => {});
            await page.waitForTimeout(900);
          } else bad(`${c.title}: no two-page reading mode option`);
        }
      }

      // Change fit -> Fit width
      const combo = page.getByRole('combobox', { name: /page size/i }).first();
      if (await combo.count()) {
        await combo.click();
        await page.waitForTimeout(500);
        const opt = page.getByRole('option', { name: /fit width/i }).first();
        if (await opt.count()) {
          await opt.click();
          await page.waitForTimeout(1200);
          const fit = await page.locator('[data-page-fit]').first().getAttribute('data-page-fit');
          if (fit === 'width') ok(`${c.title}: fit changed to width (data-page-fit=${fit})`);
          else bad(`${c.title}: fit did not change, data-page-fit=${fit}`);
          await shot(`10-reader-${c.sourceType}-fitwidth`);
        }
      }

      // Toggle progress bar off
      const progSwitch = page.locator('[role="switch"][aria-label*="progress" i]').first();
      if (await progSwitch.count()) {
        const before = await page.locator('[role="progressbar"]').count();
        await progSwitch.click();
        await page.waitForTimeout(900);
        const after = await page.locator('[role="progressbar"]').count();
        if (before > 0 && after === 0) ok(`${c.title}: progress bar toggled off`);
        else bad(`${c.title}: progress toggle had no effect (${before} -> ${after})`);
        await progSwitch.click();
        await page.waitForTimeout(700);
      }
      await page.keyboard.press('Escape');
      await page.waitForTimeout(400);
    } else bad(`${c.title}: no reader settings button`);

    // Persistence: reload and confirm the saved fit came back from the server.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-page-fit]', { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(2500);
    const persisted = await page.locator('[data-page-fit]').first().getAttribute('data-page-fit');
    if (persisted === 'width') ok(`${c.title}: fit persisted across reload (${persisted})`);
    else bad(`${c.title}: fit did NOT persist, got ${persisted}`);
    await shot(`11-reader-${c.sourceType}-persisted`);

    const served = pageResponses.filter((r) => r.status === 200);
    console.log(`  page responses: ${JSON.stringify(served.slice(0, 4))}`);
    if (served.some((r) => (r.type || '').includes('webp'))) ok(`${c.title}: pages delivered as WebP`);
    else bad(`${c.title}: pages NOT WebP (${[...new Set(served.map((r) => r.type))].join(', ')})`);
  }

  // ---------- 5. Authorization spot checks ----------
  step('5. Authorization');
  const probe = await page.evaluate(async () => {
    const out = {};
    const r1 = await fetch('/api/admin/comic-formats', { credentials: 'include' });
    out.adminAsUser = r1.status;
    const r2 = await fetch('/uploads/comics/', { credentials: 'include' });
    out.uploadsDirect = r2.status;
    return out;
  });
  console.log('  ' + JSON.stringify(probe));
  if (probe.adminAsUser === 403 || probe.adminAsUser === 401) ok(`non-admin blocked from admin formats (${probe.adminAsUser})`);
  else bad(`non-admin reached admin formats: ${probe.adminAsUser}`);
  if (probe.uploadsDirect >= 400) ok(`/uploads not directly served (${probe.uploadsDirect})`);
  else bad(`/uploads directly served: ${probe.uploadsDirect}`);

} catch (e) {
  bad(`EXCEPTION: ${e.message}`);
  await shot('99-exception');
} finally {
  step('SUMMARY');
  console.log(`failures: ${errors.length}`);
  errors.forEach((e) => console.log(`  - ${e}`));
  if (netFail.length) {
    console.log(`\nconsole/network problems (${netFail.length}):`);
    [...new Set(netFail)].slice(0, 25).forEach((e) => console.log(`  - ${e}`));
  } else console.log('\nno console errors, no 4xx/5xx');
  fs.writeFileSync(`${SHOTS}/result.json`, JSON.stringify({ failures: errors, netFail: [...new Set(netFail)] }, null, 2));
  await browser.close();
  process.exit(errors.length ? 1 : 0);
}
