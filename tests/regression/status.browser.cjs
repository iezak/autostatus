/** AutoStatus UI works independently of ActualTime. Requires Playwright, jQuery and Select2. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const php = process.env.PHP_BINARY || 'php';
const fixture = path.join(__dirname, 'ui-fixture.php');
let state = '';
let queries = 0;
let hold = false;
let held;
let onHeld;
function backend(mode, input = {}) {
    const result = JSON.parse(execFileSync(php, [fixture, mode, JSON.stringify(input), state], {encoding: 'utf8'}));
    state = result.state;
    return result.response;
}
const common = process.env.AUTOSTATUS_GLPI_CORE ? fs.readFileSync(path.join(process.env.AUTOSTATUS_GLPI_CORE, 'js/common.js'), 'utf8') : '';
const tracking = common.match(/window\.glpiUnsavedFormChanges = false;\n\$\(document\)\.ready\(function\(\) \{[\s\S]*?\n\}\);/);
if (common) assert.ok(tracking, 'GLPI form tracker not found');
const assets = {
    '/assets/jquery.js': fs.readFileSync(require.resolve('jquery/dist/jquery.min.js')),
    '/assets/select2.js': fs.readFileSync(require.resolve('select2/dist/js/select2.full.min.js')),
    '/assets/autostatus.js': fs.readFileSync(path.join(__dirname, '../../js/autostatus.js')),
    '/assets/adapter.js': [
        "window.CFG_GLPI = {root_doc: ''};",
        "window.GLPI_PLUGINS_PATH = {autostatus: 'plugins/autostatus', actualtime: 'plugins/actualtime'};",
        tracking ? tracking[0] : "window.glpiUnsavedFormChanges = false; $(document).on('input change', 'form[data-track-changes=true] :input', () => { window.glpiUnsavedFormChanges = true; });",
        "$('#dropdown_status_main').select2();"
    ].join('\n')
};
const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    try {
        if (assets[url.pathname]) { res.writeHead(200, {'Content-Type': 'application/javascript'}); res.end(assets[url.pathname]); return; }
        if (url.pathname.endsWith('/plugins/autostatus/ajax/status.php')) {
            ++queries;
            const result = backend('status', Object.fromEntries(url.searchParams));
            if (hold) { hold = false; held = {res, result}; onHeld(); return; }
            res.writeHead(200, {'Content-Type': 'application/json'}); res.end(JSON.stringify(result)); return;
        }
        if (req.method === 'POST') {
            let body = '';
            for await (const chunk of req) body += chunk;
            const input = Object.fromEntries(new URLSearchParams(body));
            if (input.fail === '1') { res.writeHead(403); res.end('Denied'); return; }
            let result;
            if (url.pathname.endsWith('/front/itilfollowup.form.php')) result = backend('followup', input);
            else if (url.pathname.endsWith('/front/tickettask.form.php')) result = backend('task', input);
            else if (url.pathname.endsWith('/ajax/timeline.php')) result = backend('toggle', input);
            else if (url.pathname.endsWith('/plugins/actualtime/ajax/timer.php')) {
                backend('set_status', {status: input.action === 'start' ? 2 : 4});
                result = {type: 'info', runtime: {parent_type: 'Ticket', parent_id: 1}};
            } else throw new Error('Unknown mutation endpoint');
            res.writeHead(200, {'Content-Type': 'application/json'}); res.end(JSON.stringify(result)); return;
        }
        res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        res.end(backend('page', {readonly: url.pathname === '/readonly'}));
    } catch (error) { res.writeHead(500); res.end(String(error)); }
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({executablePath: process.env.BROWSER_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-dev-shm-usage']});
        const context = await browser.newContext();
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://127.0.0.1:' + server.address().port);
        await page.waitForFunction(() => window.glpiAutoStatus && document.querySelector('#dropdown_status_main')?.value === '4');
        assert.equal(await page.evaluate(() => typeof window.actualTime), 'undefined', 'ActualTime is absent');
        await page.evaluate(() => $.post('/front/itilfollowup.form.php', {add: 1, itemtype: 'Ticket', items_id: 1, requester: 1}));
        await page.waitForFunction(() => document.querySelector('#dropdown_status_main')?.value === '2');
        assert.match(await page.locator('#select2-dropdown_status_main-container').innerText(), /Processing/);
        assert.equal(await page.evaluate(() => window.glpiUnsavedFormChanges), false);
        await page.locator('#ticket-draft').fill('Unsaved followup draft');

        const waiting = new Promise(resolve => { onHeld = resolve; });
        hold = true;
        await page.evaluate(() => window.glpiAutoStatus.refresh());
        await waiting;
        await page.evaluate(() => $.post('/ajax/timeline.php', {action: 'change_task_state', tasks_id: 10, tickets_id: 1, parenttype: 'Ticket', saved_status: 4}));
        await page.waitForFunction(() => document.querySelector('#dropdown_status_main')?.value === '4');
        if (!held.res.destroyed) { held.res.writeHead(200, {'Content-Type': 'application/json'}); held.res.end(JSON.stringify(held.result)); }
        held = null;
        assert.equal(await page.locator('#ticket-draft').inputValue(), 'Unsaved followup draft', 'Task close preserves drafts and cancels stale reads');
        await page.evaluate(() => $.post('/ajax/timeline.php', {action: 'change_task_state', tasks_id: 10, tickets_id: 1, parenttype: 'Ticket', saved_status: 2, state: 1}));
        await page.waitForFunction(() => document.querySelector('#dropdown_status_main')?.value === '2');
        backend('set_status', {status: 3});
        await page.evaluate(() => $.post('/ajax/timeline.php', {action: 'viewsubitem', type: 'TicketTask', tickets_id: 1, parenttype: 'Ticket'}));
        await page.waitForFunction(() => document.querySelector('#dropdown_status_main')?.value === '3');
        backend('set_status', {status: 4});
        await page.locator('.close-edit-content').click();
        await page.waitForFunction(() => document.querySelector('#dropdown_status_main')?.value === '4');
        await page.evaluate(() => $.post('/front/tickettask.form.php', {add: 1, tickets_id: 1}));
        await page.waitForFunction(() => document.querySelector('#dropdown_status_main')?.value === '2');
        assert.equal(await page.locator('#other-form select').inputValue(), '9');

        const before = queries;
        await page.evaluate(async () => {
            await $.post('/front/itilfollowup.form.php', {add: 1, itemtype: 'Ticket', items_id: 2, requester: 1});
            try { await $.post('/front/itilfollowup.form.php', {add: 1, itemtype: 'Ticket', items_id: 1, fail: 1}); } catch (error) { /* rejected */ }
        });
        assert.equal(queries, before, 'Other tickets and failed writes are ignored');
        await page.evaluate(() => $.post('/plugins/actualtime/ajax/timer.php', {action: 'pause', task_id: 10, itemtype: 'TicketTask'}));
        await page.waitForFunction(() => document.querySelector('#dropdown_status_main')?.value === '4');
        backend('set_status', {status: 2});
        await page.evaluate(() => window.dispatchEvent(new CustomEvent('actualtime:timer-changed', {detail: {action: 'start', task_id: 10, itemtype: 'TicketTask', parent_type: 'Ticket', parent_id: 1}})));
        await page.waitForFunction(() => document.querySelector('#dropdown_status_main')?.value === '2');
        await page.evaluate(() => { CFG_GLPI.root_doc = '/glpi'; });
        await page.evaluate(() => $.post('/glpi/front/itilfollowup.form.php', {add: 1, itemtype: 'Ticket', items_id: 1}));
        await page.waitForFunction(() => document.querySelector('#dropdown_status_main')?.value === '4');
        await page.locator('#dropdown_status_main').selectOption('3');
        await page.evaluate(() => $.post('/glpi/front/itilfollowup.form.php', {add: 1, itemtype: 'Ticket', items_id: 1, requester: 1}));
        await page.waitForFunction(() => document.querySelector('#heading-main-item .status-recall i')?.classList.contains('status-2'));
        assert.equal(await page.locator('#dropdown_status_main').inputValue(), '3', 'Manual unsaved status is preserved');
        assert.equal(await page.locator('#ticket-draft').inputValue(), 'Unsaved followup draft');

        const readonly = await context.newPage();
        readonly.on('pageerror', error => errors.push(error.message));
        await readonly.goto('http://127.0.0.1:' + server.address().port + '/readonly');
        await readonly.waitForFunction(() => document.querySelector('[data-autostatus-ticket-status]')?.textContent.includes('Processing'));
        await readonly.evaluate(() => $.post('/front/itilfollowup.form.php', {add: 1, itemtype: 'Ticket', items_id: 1}));
        await readonly.waitForFunction(() => document.querySelector('[data-autostatus-ticket-status]')?.textContent.includes('Pending'));
        assert.equal(await readonly.locator('#reopen-link').count(), 1);
        assert.equal(await readonly.locator('[data-autostatus-ticket-status]').count(), 1);
        assert.deepEqual(errors, []);
        console.log('OK: AutoStatus standalone UI, followups, tasks, read-only status, drafts, permissions/scoping, stale requests and optional timer adapters.');
    } finally {
        if (held && !held.res.destroyed) held.res.end(JSON.stringify(held.result));
        if (browser) await browser.close();
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
