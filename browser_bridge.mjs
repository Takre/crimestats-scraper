// browser_bridge.mjs
//
// Kasutus:
//   node browser_bridge.mjs get-users <siteUrl> <secret> <job> > users.json
//   node browser_bridge.mjs submit    <siteUrl> <secret> <job> <records.json>
//
// See skript suhtleb crimestatistics.eu-ga läbi PÄRIS headless Chromium
// brauseri (Playwright), mitte cURL-i kaudu. Sinu hostingu ees olev
// robotitõrje (AES/JS-väljakutse) nõuab JavaScripti käivitamist, mida
// tavaline cURL-päring ei tee — reaalne brauser aga teeb seda automaatselt,
// täpselt nagu siis, kui sa ise lehte külastad.
//
// Töövoog:
//   1. Külastame saidi avalehte, et brauser saaks lahendada JS-väljakutse
//      ja saada õige küpsise (nii nagu sinu enda brauser seda tegi).
//   2. Sama brauserisessiooni (küpsistega) sees teeme fetch()-päringud
//      otse lehe kontekstist — need on same-origin päringud, nii et
//      küpsis liigub automaatselt kaasa.

import { chromium } from 'playwright';

const [, , mode, siteUrl, secret, jobOrRecordsPath, recordsPathArg] = process.argv;

if (!mode || !siteUrl || !secret) {
    console.error('Kasutus: node browser_bridge.mjs <get-users|submit> <siteUrl> <secret> <job> [records.json]');
    process.exit(1);
}

const job = jobOrRecordsPath;

async function withChallengeSolvedPage(fn) {
    const browser = await chromium.launch();
    const context = await browser.newContext({
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            + '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    });
    const page = await context.newPage();

    // 1. Lahenda JS-väljakutse avalehel (kergem leht kui otse ajax-endpoint)
    await page.goto(siteUrl + '/', { waitUntil: 'domcontentloaded', timeout: 30000 });
    // Anna JS-väljakutsele aega end lahendada + võimalikule redirectile aega
    try {
        await page.waitForLoadState('networkidle', { timeout: 15000 });
    } catch (e) {
        // kui networkidle ei saabu, pole hullu — proovime niikuinii edasi
    }
    // Väike lisapuhver juhuks kui redirect käivitub veidi hiljem
    await page.waitForTimeout(1500);

    try {
        return await fn(page);
    } finally {
        await browser.close();
    }
}

if (mode === 'get-users') {
    const usersUrl = `${siteUrl}/ajax/scrape_get_users.php?job=${encodeURIComponent(job)}&secret=${encodeURIComponent(secret)}`;

    const result = await withChallengeSolvedPage(async (page) => {
        return await page.evaluate(async (url) => {
            const res = await fetch(url, { credentials: 'include' });
            const text = await res.text();
            return { status: res.status, text };
        }, usersUrl);
    });

    if (result.status !== 200) {
        console.error(`get-users: HTTP ${result.status}. Vastus (esimesed 500 märki):\n${result.text.slice(0, 500)}`);
        process.exit(1);
    }

    let parsed;
    try {
        parsed = JSON.parse(result.text);
    } catch (e) {
        console.error('get-users: vastus polnud valiidne JSON. Toorvastus (esimesed 500 märki):');
        console.error(result.text.slice(0, 500));
        process.exit(1);
    }

    if (parsed.error) {
        console.error('get-users: server tagastas vea: ' + parsed.error);
        process.exit(1);
    }

    // Kirjutame JSON-i stdout'i, et PHP saaks selle failina salvestada
    process.stdout.write(JSON.stringify(parsed));
    process.exit(0);

} else if (mode === 'submit') {
    const recordsPath = recordsPathArg;
    if (!recordsPath) {
        console.error('submit: puudub records.json faili tee.');
        process.exit(1);
    }

    const fs = await import('node:fs/promises');
    const recordsJson = await fs.readFile(recordsPath, 'utf8');

    const submitUrl = `${siteUrl}/ajax/scrape_submit.php`;

    const result = await withChallengeSolvedPage(async (page) => {
        return await page.evaluate(async ({ url, secret, job, recordsJson }) => {
            const body = new URLSearchParams({ secret, job, records: recordsJson });
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const text = await res.text();
            return { status: res.status, text };
        }, { url: submitUrl, secret, job, recordsJson });
    });

    if (result.status !== 200) {
        console.error(`submit: HTTP ${result.status}. Vastus (esimesed 500 märki):\n${result.text.slice(0, 500)}`);
        process.exit(1);
    }

    let parsed;
    try {
        parsed = JSON.parse(result.text);
    } catch (e) {
        console.error('submit: vastus polnud valiidne JSON. Toorvastus (esimesed 500 märki):');
        console.error(result.text.slice(0, 500));
        process.exit(1);
    }

    if (!parsed.success) {
        console.error('submit: ebaõnnestus: ' + JSON.stringify(parsed));
        process.exit(1);
    }

    console.log(`Valmis. Töödeldud: ${parsed.processed}`);
    if (parsed.errors && parsed.errors.length) {
        console.log(`Vigu: ${parsed.errors.length}`);
        for (const err of parsed.errors) console.log('  - ' + err);
    }
    process.exit(0);

} else {
    console.error('Tundmatu režiim: ' + mode + ' (oota "get-users" või "submit")');
    process.exit(1);
}
