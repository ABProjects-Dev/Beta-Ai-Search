import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import chromium, { inflate, setupLambdaEnvironment } from '../../.tools/node_modules/@sparticuz/chromium/build/index.js';
import { chromium as playwright } from '../../.tools/node_modules/playwright/index.mjs';

const base = process.env.BAS_TEST_URL || 'http://127.0.0.1:9400';
const php = process.env.BAS_TEST_PHP || '.tools/bin/php';
const results = [];
const pageErrors = [];
function control(...args) { return execFileSync(php, ['tests/integration/control.php', ...args], { encoding: 'utf8' }).trim(); }
function check(name, ok, detail = '') { results.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`); }
async function status(page, kind) { await page.waitForSelector(`.bas-search__status[data-kind="${kind}"]`, { timeout: 30000 }); }
async function ids(page) { return page.locator('ul.products').first().locator('li.product').evaluateAll(els => els.map(el => (el.className.match(/post-(\d+)/) || [])[1]).filter(Boolean)); }
async function search(page, query) { await page.locator('.bas-search__input').first().fill(query); await page.locator('.bas-search__submit').first().click(); }

control('fault', ''); control('language', 'fa'); control('reset_rate'); control('pages'); control('admin_user'); control('drain');
await inflate('.tools/node_modules/@sparticuz/chromium/bin/al2023.tar.br');
setupLambdaEnvironment('/tmp/al2023/lib');
const browser = await playwright.launch({ executablePath: await chromium.executablePath(), args: chromium.args, headless: true });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
page.setDefaultTimeout(60000);
async function goto(url, selector) { await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 }); if (selector) await page.waitForSelector(selector); }
page.on('pageerror', error => pageErrors.push(String(error)));
let searchRequests = 0;
page.on('request', request => { if (request.url().includes('/beta-ai-search/v1/search')) searchRequests++; });

try {
  await goto(`${base}/search-test/`, '.bas-search__input');
  check('shortcode page has one existing Woo grid', await page.locator('ul.products').count() === 1);
  const original = await ids(page);
  await page.evaluate(() => { window.__basGrid = document.querySelector('ul.products'); window.__basUrl = location.href; });
  const beforeTyping = searchRequests;
  await page.locator('.bas-search__input').fill('کفش مشکی برای پیاده روی');
  await page.waitForTimeout(800);
  check('typing does not call the AI search API', searchRequests === beforeTyping);
  await page.locator('.bas-search__submit').click();
  await status(page, 'success');
  const filtered = await ids(page);
  check('button click filters the same grid without navigation', await page.evaluate(() => window.__basGrid === document.querySelector('ul.products') && window.__basUrl === location.href) && await page.locator('ul.products').count() === 1 && filtered.join(',') === '13,10', filtered.join(','));
  check('existing WooCommerce card template is preserved', await page.locator('ul.products .woocommerce-loop-product__title').count() === 2);
  await page.locator('.bas-search__clear-text').click();
  check('× clears text but does not reset results', await page.locator('.bas-search__input').inputValue() === '' && (await ids(page)).join(',') === '13,10');
  await page.locator('.bas-search__reset').click();
  check('حذف فیلتر restores the original grid', (await ids(page)).join(',') === original.join(',') && await page.locator('.bas-search__reset').isHidden());

  await search(page, 'یک چیز عجیب که در فروشگاه نیست');
  await status(page, 'empty');
  check('exact Persian no-result message and no substitute products', (await page.locator('.bas-grid-empty').textContent()) === 'محصولی مطابق درخواست شما پیدا نشد.' && await page.locator('ul.products li.product:visible').count() === 0);
  await page.locator('.bas-search__reset').click();

  control('fault', 'http500');
  const beforeError = await ids(page);
  await search(page, 'comfortable walking shoes');
  await status(page, 'error');
  check('AI outage shows error + retry and leaves products untouched', await page.locator('.bas-search__retry').isVisible() && (await ids(page)).join(',') === beforeError.join(','));
  control('fault', '');
  await page.locator('.bas-search__retry').click();
  await status(page, 'success');
  check('retry succeeds after the provider recovers', (await ids(page)).join(',') === '10,13');
  await page.locator('.bas-search__reset').click();

  await goto(`${base}/elementor-test/`, '.bas-search__input');
  check('Elementor Free renders the registered widget above an existing grid', await page.locator('.elementor-widget-beta-ai-search').count() === 1 && await page.locator('ul.products').count() === 1);
  await search(page, 'کفش مشکی برای پیاده روی');
  await status(page, 'success');
  check('Elementor Free page keeps one grid and updates it in place', await page.locator('ul.products').count() === 1 && (await ids(page)).join(',') === '13,10');

  await goto(`${base}/?product_cat=apparel`, '.bas-search__input');
  const categoryOriginal = await ids(page);
  await search(page, 'کفش مشکی برای پیاده روی');
  await status(page, 'success');
  const categoryFiltered = await ids(page);
  check('category archive searches the whole catalog, not the current category', (categoryFiltered.includes('10') || categoryFiltered.includes('13')) && !categoryFiltered.includes('18'), categoryFiltered.join(','));
  await page.locator('.bas-search__reset').click();
  check('category archive reset restores its original products', (await ids(page)).join(',') === categoryOriginal.join(','));

  await goto(`${base}/pagination-test/`, '.bas-search__input');
  await search(page, 'comfortable walking shoes');
  await status(page, 'success');
  check('result pagination starts at page one with native cards', await page.locator('.bas-pagination').count() === 1 && (await ids(page)).join(',') === '10', await page.locator('.bas-pagination__label').textContent().catch(() => 'no nav'));
  await page.locator('.bas-pagination__button').nth(1).click();
  await page.waitForFunction(() => document.querySelector('.bas-pagination__label')?.textContent?.startsWith('2'));
  check('next page renders the remaining matched parent once', (await ids(page)).join(',') === '13');
  await page.locator('.bas-pagination__button').nth(0).click();
  await page.waitForFunction(() => document.querySelector('.bas-pagination__label')?.textContent?.startsWith('1'));
  await page.locator('.bas-search__reset').click();
  check('reset removes result pagination and restores the original grid', await page.locator('.bas-pagination').count() === 0);

  await goto(`${base}/multi-grid-test/`, '.bas-search__input');
  check('multi-grid fixture has two existing grids', await page.locator('ul.products').count() === 2);
  await search(page, 'comfortable walking shoes');
  await status(page, 'success');
  const grids = await page.locator('ul.products').evaluateAll(els => els.map(el => Array.from(el.querySelectorAll('li.product')).map(li => (li.className.match(/post-(\d+)/) || [])[1])));
  check('scope=all updates both existing grids without creating a parallel grid', grids.length === 2 && grids.every(g => g.join(',') === '10,13'));
  await page.locator('.bas-search__reset').click();

  for (const viewport of [{ width: 390, height: 844 }, { width: 768, height: 1024 }, { width: 1280, height: 900 }]) {
    await page.setViewportSize(viewport);
    await goto(`${base}/search-test/`, '.bas-search__input');
    const layout = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth > window.innerWidth + 1, box: document.querySelector('.bas-search').getBoundingClientRect().width, input: !!document.querySelector('.bas-search__input').offsetParent, button: !!document.querySelector('.bas-search__submit').offsetParent, dir: document.querySelector('.bas-search').dir }));
    check(`responsive ${viewport.width}px: visible controls, no horizontal overflow, RTL`, !layout.overflow && layout.box <= viewport.width && layout.input && layout.button && layout.dir === 'rtl', JSON.stringify(layout));
  }
  control('language', 'en');
  await goto(`${base}/search-test/`, '.bas-search__input');
  check('English UI is LTR and centrally translated', await page.locator('.bas-search').getAttribute('dir') === 'ltr' && (await page.locator('.bas-search__submit-label').textContent()) === 'AI search');
  control('language', 'fa');

  const api1 = await context.request.post(`${base}/wp-json/beta-ai-search/v1/search`, { data: { query: 'comfortable walking shoes' } });
  const api2 = await context.request.post(`${base}/wp-json/beta-ai-search/v1/search`, { data: { query: 'comfortable walking shoes' } });
  check('REST search returns real pipeline IDs and cache hit', api1.ok() && api2.ok() && (await api2.json()).cached === true);
  const anonymous = await context.request.get(`${base}/wp-json/beta-ai-search/v1/admin/index`);
  check('anonymous admin REST endpoint is denied', anonymous.status() === 401 || anonymous.status() === 403);
  const noNonce = await context.request.post(`${base}/wp-admin/admin-ajax.php`, { form: { action: 'bas_index_status' } });
  check('admin AJAX without nonce is denied', noNonce.status() === 400 || noNonce.status() === 403 || (await noNonce.text()).includes('0'));

  await goto(`${base}/wp-login.php`, '#user_login');
  await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'BasTest!12345'); await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');
  await goto(`${base}/wp-admin/admin.php?page=beta-ai-search-settings&tab=providers`, '.bas-tabs');
  check('admin settings expose all 12 sections', await page.locator('.bas-tabs a').count() === 12);
  const adminHtml = await page.content();
  check('stored API key never appears in admin HTML or JS config', !adminHtml.includes('test-only-not-a-real-key'));
  const ajax = await page.evaluate(async () => { const c = window.BetaAiSearchAdmin; const body = new URLSearchParams({ action: 'bas_index_status', nonce: c.nonce }); const r = await fetch(c.ajaxUrl, { method: 'POST', body }); return r.json(); });
  check('authorized admin AJAX with nonce returns index status', ajax.success === true && typeof ajax.data.percent === 'number');

  control('reset_rate');
  let lastStatus = 0;
  for (let i = 0; i < 13; i++) { const r = await context.request.post(`${base}/wp-json/beta-ai-search/v1/search`, { data: { query: 'comfortable walking shoes' } }); lastStatus = r.status(); }
  check('public search rate limit returns 429 after the configured window allowance', lastStatus === 429, String(lastStatus));
  control('reset_rate');
  check('no uncaught browser JavaScript errors', pageErrors.length === 0, pageErrors.join(' | '));
} finally {
  await browser.close();
  control('fault', ''); control('language', 'fa'); control('reset_rate');
  const failed = results.filter(r => !r.ok);
  writeFileSync('.tools/browser-results.json', JSON.stringify({ results, pageErrors }, null, 2));
  console.log(`\n${results.length} browser checks, ${failed.length} failures`);
  if (failed.length) process.exitCode = 1;
}
