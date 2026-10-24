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

function httpGet(string $url): string {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_USERAGENT,
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
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

        $out[] = [
            'kasutajanimi' => $username,
            'klikid'       => $clicks,
        ];
    }

    return $out;
}

// inactiveusers=1, et saada täielikum nimekiri (mitte ainult hetkel online olijad)
$url = "https://www.crime.ee/index.php?a=8&top=98&m=$urlM&inactiveusers=1";

echo "Töö '$job': loen klikkide edetabelit (url_m = '$urlM')\n";

$html = httpGet($url);
$records = parseClicksPage($html);

if (empty($records)) {
    fwrite(STDERR, "Hoiatus: ei leidnud ühtegi kirjet. Vastuse pikkus: " . strlen($html) . " baiti.\n");
    @file_put_contents(__DIR__ . "/debug_clicks_{$job}.html", $html);
}

file_put_contents($recordsPath, json_encode($records, JSON_UNESCAPED_UNICODE));
echo "Kirjutatud " . count($records) . " kirjet faili $recordsPath\n";
