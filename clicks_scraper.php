<?php
/**
 * CrimeStats — klikkide edetabeli skreipimise skript (crime.ee osa)
 *
 * Käivitatakse: php clicks_scraper.php <job> <users.json> <records.json>
 *
 * <users.json> on sama fail, mille browser_bridge.mjs "get-users" režiim
 * juba toodab (sisaldab {"url_m":...}). Me ei vaja siin kasutajate
 * nimekirja ennast, vaid ainult url_m väärtust, et teada millise maailma
 * klikkide edetabelit crime.ee-lt lugeda.
 *
 * Klikkide edetabel (a=8&top=98) on crime.ee peal, mida ei blokeerita,
 * seega lihtne cURL piisab (browserit pole vaja).
 */

$job         = $argv[1] ?? null;
$usersPath   = $argv[2] ?? null;
$recordsPath = $argv[3] ?? null;

if (!$job || !$usersPath || !$recordsPath) {
    fwrite(STDERR, "Kasutus: php clicks_scraper.php <job> <users.json> <records.json>\n");
    exit(1);
}

$usersRaw = file_exists($usersPath) ? file_get_contents($usersPath) : false;
if ($usersRaw === false) {
    fwrite(STDERR, "Ei suutnud lugeda faili: $usersPath\n");
    exit(1);
}

$usersResp = json_decode($usersRaw, true);
if (!$usersResp || !isset($usersResp['url_m'])) {
    fwrite(STDERR, "Fail ei sisaldanud url_m väärtust.\n");
    exit(1);
}

$urlM = $usersResp['url_m'];

// Sama parandus, mis scraper.php-s: roheline/must kasutavad crime.ee URL-is
// world1/world2, mitte värvinime.
$urlMOverrides = [
    'green' => 'world1',
    'w2'    => 'world2',
];
if (isset($urlMOverrides[$job])) {
    $urlM = $urlMOverrides[$job];
}

$cookieJar = tempnam(sys_get_temp_dir(), 'clk');

/**
 * GET või POST sama küpsisesessiooniga. Crime.ee lehitsemisnool (liigu-vorm)
 * ei saada vormiga kaasa ühtegi offset-välja, seega lehekülje positsioon
 * hoitakse tõenäoliselt serveri poolel sessioonis — selleks on küpsised vajalikud.
 */
function httpReq(string $url, ?array $post = null): string {
    global $cookieJar;
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($curl, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($curl, CURLOPT_USERAGENT,
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
        curl_setopt($curl, CURLOPT_REFERER, $url);
    }
    $response = curl_exec($curl);
    if ($response === false) {
        fwrite(STDERR, "cURL viga: " . curl_error($curl) . "\n");
        $response = '';
    }
    curl_close($curl);
    return $response;
}

/**
 * Parsib klikkide edetabeli HTML-ist [{"kasutajanimi":..,"klikid":..}, ...]
 */
function parseClicksPage(string $html): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_use_internal_errors(false);
    $xpath = new DOMXPath($dom);

    $rows = $xpath->query("//table[contains(@class,'tstyle')]//tr");
    $out = [];

    foreach ($rows as $row) {
        $link = $xpath->query(".//a[contains(@href,'a=11')]", $row)->item(0);
        if (!$link) {
            continue; // päiserida vms, kus linki pole
        }

        $username = trim($link->textContent);
        if ($username === '') {
            continue;
        }

        $tds = $row->getElementsByTagName('td');
        if ($tds->length < 3) {
            continue;
        }

        // Viimane <td> sisaldab klikkide arvu (nt "190&nbsp;881")
        $clicksRaw = $tds->item($tds->length - 1)->textContent;
        $clicks = (int)preg_replace('/[^0-9]/', '', $clicksRaw);

        $rank = (int)preg_replace('/[^0-9]/', '', $tds->item(0)->textContent);

        $out[] = [
            'rank'         => $rank,
            'kasutajanimi' => $username,
            'klikid'       => $clicks,
        ];
    }

    return $out;
}

// inactiveusers=1, et saada täielikum nimekiri (mitte ainult hetkel online olijad)
$url = (getenv("CLICKS_BASE") ?: "https://www.crime.ee/index.php") . "?a=8&top=98&m=$urlM&inactiveusers=1";

echo "Töö '$job': loen klikkide edetabelit (url_m = '$urlM')\n";

$MAX_PAGES = 60; // ohutuspiir (60 x 40 = 2400 kasutajat)
$all = [];
$seen = [];

function addPage(array $page, array &$all, array &$seen): int {
    $new = 0;
    foreach ($page as $r) {
        if (isset($seen[$r['kasutajanimi']])) continue;
        $seen[$r['kasutajanimi']] = true;
        $all[] = ['kasutajanimi' => $r['kasutajanimi'], 'klikid' => $r['klikid']];
        $new++;
    }
    return $new;
}

$html = httpReq($url);
$page = parseClicksPage($html);
if (empty($page)) {
    fwrite(STDERR, "Hoiatus: esimesel lehel pole kirjeid. Vastuse pikkus: " . strlen($html) . " baiti.\n");
    @file_put_contents(__DIR__ . "/debug_clicks_{$job}.html", $html);
}
addPage($page, $all, $seen);
$firstRank = $page ? $page[0]['rank'] : 0;
$lastRank  = $page ? end($page)['rank'] : 0;
echo "Leht 1: kohad {$firstRank}–{$lastRank} (" . count($page) . " rida)\n";

// --- Strateegia A: vajuta "edasi" noolt (POST edasi_x/edasi_y, sama sessioon) ---
$pageNo = 1;
$arrowWorks = true;
while ($page && $pageNo < $MAX_PAGES) {
    $lastClicks = end($page)['klikid'];
    if ($lastClicks <= 0) break;

    $html = httpReq($url, ['edasi_x' => 10, 'edasi_y' => 10, 'edasi' => '']);
    $next = parseClicksPage($html);
    if (empty($next)) break;

    $nFirst = $next[0]['rank'];
    $nLast  = end($next)['rank'];
    $pageNo++;
    echo "Leht $pageNo: kohad {$nFirst}–{$nLast} (" . count($next) . " rida)\n";

    // Kui nool ei liigutanud (sama esimene koht või ei suurene), ei toimi sessioonipõhine lehitsemine.
    if ($nFirst <= $lastRank) {
        $arrowWorks = false;
        fwrite(STDERR, "Hoiatus: nool ei liigutanud edasi (sai kohad {$nFirst}–{$nLast} pärast kohta $lastRank).\n");
        @file_put_contents(__DIR__ . "/debug_clicks_{$job}_arrow.html", $html);
        break;
    }
    if (addPage($next, $all, $seen) === 0) break;
    $page = $next;
    $lastRank = $nLast;
}

// --- Strateegia B (varu): "Otsi koha järgi" vorm (POST rank=N) ---
if (!$arrowWorks) {
    echo "Proovin varuvarianti: otsing koha järgi.\n";
    $rankTry = $lastRank + 1;
    $guard = 0;
    while ($guard++ < $MAX_PAGES) {
        $html = httpReq($url, ['rank' => (string)$rankTry]);
        $next = parseClicksPage($html);
        if (empty($next)) break;
        $nLast = end($next)['rank'];
        echo "Koha-otsing $rankTry: kohad {$next[0]['rank']}–$nLast\n";
        if ($nLast < $rankTry) { // ei jõudnud soovitud kohani
            @file_put_contents(__DIR__ . "/debug_clicks_{$job}_rank.html", $html);
            break;
        }
        $added = addPage($next, $all, $seen);
        if ($added === 0 || end($next)['klikid'] <= 0) break;
        $rankTry = $nLast + 1;
    }
}

file_put_contents($recordsPath, json_encode($all, JSON_UNESCAPED_UNICODE));
echo "Kokku kirjutatud " . count($all) . " kirjet faili $recordsPath\n";
@unlink($cookieJar);
