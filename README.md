# CrimeStats — automaatne skreipimine GitHub Actionsiga

See repo täidab öised/vahepealsed augud, mis tekivad kuna InfinityFree
tasuta plaan ei luba cron-jobe.

## Kuidas see töötab

1. GitHub Actions käivitub ise iga 30 minuti tagant (kõigis ajavööndites, 24/7).
2. `scraper.php` küsib CrimeStats saidilt (`ajax/scrape_get_users.php`),
   keda antud "töö" (red/blue/white/white2/w2/green) jaoks kontrollida.
3. Skreipib crime.ee-lt iga mängija andmed (täpselt sama loogika, mis vanades
   `update_*.php` skriptides).
4. Saadab tulemused tagasi CrimeStats saidile (`ajax/scrape_submit.php`),
   mis need andmebaasi kirjutab.

InfinityFree ei luba väljastpoolt otse andmebaasi ühendust, seega käib
kõik suhtlus tavalise HTTPS-päringuna sinu enda saidi kahe uue
ajax-endpointi kaudu — täpselt sama moodi, nagu tavaline külastaja
su lehte vaataks.

**Oluline:** InfinityFree/iFastNeti hostingu ees on robotitõrje (JS/AES-
väljakutse TLS tasandil), mis blokeerib lihtsaid HTTP-kliente (cURL jms) —
isegi õige User-Agentiga. Seetõttu suhtleb `crimestatistics.eu`-ga (mõlemas
suunas) `browser_bridge.mjs` (Node + Playwright, päris headless Chromium),
mitte `scraper.php` otse. `scraper.php` tegeleb ainult crime.ee
skreipimisega, mis pole blokeeritud, ning loeb/kirjutab andmeid kohalike
JSON-failide kaudu (`users.json`, `records.json`).

## Seadistamine

### 1. Tekita salajane võti
Ükskõik milline pikk juhuslik string, nt:
```
openssl rand -hex 32
```
(või lihtsalt mõtle ise 40+ tähemärgiline juhuslik string välja)

### 2. Pane sama võti kahte kohta
- **CrimeStats saidile**: `crime3/includes/config.php` failis
  `SCRAPE_SECRET` konstant — asenda platsihoidja väärtus oma võtmega.
- **GitHub repos**: Settings → Secrets and variables → Actions → New repository secret
  - `SCRAPE_SECRET` = sama väärtus, mis config.php's
  - `SITE_URL` = `https://crimestatistics.eu` (ilma lõpu-kaldkriipsuta)

### 3. Loo uus GitHub repo — soovitavalt PUBLIC
Nimi pole oluline, nt `crimestats-scraper`.

**Miks public, mitte private:** GitHub Actions on public repode puhul
**täiesti tasuta ja piiramatute minutitega**, samas kui private repodel
on tasuta piir 2000 min/kuu — kuna see workflow käivitub iga 30 min,
6 tööd korraga, ja iga töö sees jookseb ka headless Chromium, võib
kuine minutite kulu kergesti ületada tasuta piiri. Kuna kood ise ei
sisalda ühtegi salasõna (need on GitHub Secrets, mis on ka public
repos alati peidetud/krüpteeritud), pole avalikkuse tõttu turvariski.

Kui soovid siiski privaatseks jätta, tuleb kas leppida piiratud
minutitega või kaaluda self-hosted runnerit — aga lihtsaim tasuta
lahendus on repo avalikuks teha.

Lae sellesse repositooriumisse:
- `scraper.php`, `browser_bridge.mjs`, `package.json` (repo juurde)
- `.github/workflows/scrape.yml` (täpselt selles kaustastruktuuris)

### 4. Lae CrimeStats saidile üles uued/muudetud failid
- `crime3/includes/config.php` (uuendatud, SCRAPE_SECRET lisatud)
- `crime3/includes/scrape_jobs.php` (uus)
- `crime3/ajax/scrape_get_users.php` (uus)
- `crime3/ajax/scrape_submit.php` (uus)

### 5. Testi käsitsi
GitHubi repos: Actions vahekaart → "CrimeStats Scraper" → "Run workflow"
nupp. Vali haru ja käivita. Vaata logisid — kas kõik 6 tööd (red/blue/
white/white2/w2/green) läbisid edukalt.

Kontrolli ka CrimeStats admin paneelist, et "viimati uuendatud" ajad
värskenesid ja logis (`admin/dashboard.php`) on kirjed GitHub Actions kohta.

### 6. Lase käia
Kui käsitsi käivitamine töötab, hakkab ajastus (iga 30 min) iseenesest
tööle. Ei ole vaja midagi enam teha.

## Tähelepanekud

- **GitHub'i 60-päeva reegel**: kui repos pole 60 päeva jooksul ühtegi
  commit'i, lülitab GitHub ajastatud workflow'd automaatselt välja.
  Kui plaanid seda pikalt käigus hoida ilma koodimuudatusteta, tuleb aeg-ajalt
  teha mõni tühi commit või workflow käsitsi taaskäivitada.
- **Ajastuse täpsus**: GitHub Actions ajastatud käivitused pole millisekundilise
  täpsusega — suurema koormuse ajal võivad need mõnevõrra hilineda (tavaliselt
  mõni minut, harva rohkem). See ei tohiks probleem olla, kuna eesmärk on
  "täita augud", mitte reaalajas jälgida.
- **Sagedus**: kui 30 min tundub liiga tihe/harv crime.ee või GitHub Actions
  ressursi mõttes, muuda lihtsalt `scrape.yml` faili `cron` väärtust (nt
  `*/60 * * * *` iga tunni jaoks).
