<?php
/**
 * CrimeStats — GitHub Actions skreipimise skript (crime.ee osa)
 *
 * Käivitatakse: php scraper.php <job> <users.json> <records.json>
 *
 * See skript EI suhtle enam otse crimestatistics.eu ajax-endpointidega —
 * seda teeb nüüd browser_bridge.mjs (Playwright/headless Chromium), sest
 * crimestatistics.eu hosting kasutab robotitõrjet, mis nõuab päris
 * brauserit (JS-i käivitamist). scraper.php tegeleb ainult crime.ee
 * skreipimisega, mis pole blokeeritud.
 *
 * Sisend:  <users.json>   — browser_bridge.mjs "get-users" väljund,
 *                            sisaldab {"url_m":..., "users":[...]}
 * Väljund: <records.json> — skreiptud kasutajate andmed, mille
 *                            browser_bridge.mjs "submit" hiljem saadab
 */

$job         = $argv[1] ?? null;
$usersPath   = $argv[2] ?? null;
$recordsPath = $argv[3] ?? null;

if (!$job || !$usersPath || !$recordsPath) {
    fwrite(STDERR, "Kasutus: php scraper.php <job> <users.json> <records.json>\n");
    exit(1);
}

$usersRaw = file_exists($usersPath) ? file_get_contents($usersPath) : false;
if ($usersRaw === false) {
    fwrite(STDERR, "Ei suutnud lugeda kasutajate faili: $usersPath\n");
    exit(1);
}

$usersResp = json_decode($usersRaw, true);
if (!$usersResp || !isset($usersResp['users'])) {
    fwrite(STDERR, "Kasutajate fail ei sisaldanud oodatud JSON-struktuuri.\n");
    exit(1);
}

$urlM  = $usersResp['url_m'];
$users = $usersResp['users'];

// crime.ee kasutab "roheline" ja "must" maailmade jaoks URL-is erinevaid
// termineid (world1 / world2) võrreldes teiste maailmadega (mis kasutavad
// värvinimesid nagu "punane", "sinine" jne). CrimeStats API tagastab
// url_m väljal värvinime ka nende jaoks, seega parandame selle siin ise.
$urlMOverrides = [
    'green' => 'world1', // roheline
    'w2'    => 'world2', // must
];
if (isset($urlMOverrides[$job])) {
    $urlM = $urlMOverrides[$job];
}

echo "Töö '$job': " . count($users) . " kasutajat kontrollitavad. (url_m = '$urlM')\n";

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
 * Parsib ühe mängija crime.ee lehe HTML-ist andmed välja.
 */
function parsePlayerPage(string $html, string $username): ?array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_use_internal_errors(false);

    $table = $dom->getElementsByTagName('table')->item(0);
    if (!$table) return null;
    $rows = $table->getElementsByTagName('tr');

    $skillArray = [];
    foreach ($rows as $row) {
        $cols = $row->getElementsByTagName('td');
        foreach ($cols as $col) {
            $rawText = '';
            foreach ($col->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $rawText .= $child->nodeValue;
                } elseif ($child->nodeName === 'strong' || $child->nodeName === 'a') {
                    $rawText .= $child->nodeValue;
                }
            }
            $rawText = trim(preg_replace('/\s+/', ' ', $rawText));
            if ($rawText === '') continue;

            $parts = explode(': ', $rawText, 2);
            if (count($parts) === 2) {
                $skillArray[trim($parts[0])] = trim($parts[1]);
            }
        }
    }

    if (empty($skillArray) || !isset($skillArray['Vastupidavus'])) return null;

    $kambanimi = (!empty($skillArray['Kamp'] ?? '')) ? $skillArray['Kamp'] : 'Üksikmängija';
    $kambaliige = (($skillArray['Kuulub kampa'] ?? '') === 'JAH' || ($skillArray['Kuulub kampa'] ?? '') === 'LIIDER') ? 1 : 0;
    $vip = (($skillArray['VIP liige'] ?? '') === 'JAH') ? 1 : 0;

    return [
        'kasutajanimi'  => $username,
        'vastupidavus'  => (int)($skillArray['Vastupidavus'] ?? 0),
        'relvakasitsus' => (int)($skillArray['Relvakäsitsus'] ?? 0),
        'kaitse'        => (int)($skillArray['Kaitse'] ?? 0),
        'joud'          => (int)($skillArray['Jõud'] ?? 0),
        'kiirus'        => (int)($skillArray['Kiirus'] ?? 0),
        'osavus'        => (int)($skillArray['Osavus'] ?? 0),
        'kokandus'      => (int)($skillArray['Kokandus'] ?? 0),
        'aiandus'       => (int)($skillArray['Aiandus'] ?? 0),
        'varastamine'   => (int)($skillArray['Varastamine'] ?? 0),
        'keemik'        => (int)($skillArray['Keemik'] ?? 0),
        'kasitoo'       => (int)($skillArray['Käsitöö'] ?? 0),
        'sepistamine'   => (int)($skillArray['Sepistamine'] ?? 0),
        'joogimeister'  => (int)($skillArray['Joogimeister'] ?? 0),
        'raviteadus'    => (int)($skillArray['Raviteadus'] ?? 0),
        'kaevandamine'  => (int)($skillArray['Kaevandamine'] ?? 0),
        'sojandus'      => (int)($skillArray['Sõjandus'] ?? 0),
        'vip'           => $vip,
        'aktiivsus'     => (float)($skillArray['Aktiivsus'] ?? 0),
        'kuulub_kampa'  => $kambaliige,
        'aktiivne'      => $skillArray['Aktiivne'] ?? '',
        'registreerus'  => $skillArray['Registreerus'] ?? '',
        'raha'          => (int)preg_replace('/[^0-9]/', '', $skillArray['Raha'] ?? '0'),
        'kamp'          => $kambanimi,
        'kasarmu'       => $skillArray['Kasarmu level'] ?? '',
        'zhetoonid'     => (int)preg_replace('/[^0-9]/', '', $skillArray['Žetoone'] ?? '0'),
        'pank'          => (int)preg_replace('/[^0-9]/', '', $skillArray['Panga level'] ?? '0'),
        'maja'          => (int)preg_replace('/[^0-9]/', '', $skillArray['Maja level'] ?? '0'),
        'korts'         => (int)preg_replace('/[^0-9]/', '', $skillArray['Kõrtsi reputatsioon'] ?? '0'),
        'haigla'        => $skillArray['Haigla level'] ?? '',
        'haigla_kasum'  => (int)preg_replace('/[^0-9]/', '', $skillArray['Haigla üldkasum'] ?? '0'),
        'auto'          => $skillArray['Auto level'] ?? '',
        'kalalaev'      => $skillArray['Kalastuslaeva level'] ?? '',
        'sadameetrit'   => $skillArray['100m rekord'] ?? '',
    ];
}

// Käi kõik kasutajad läbi, skreipi crime.ee-lt
$records = [];
$debugShown = 0;
$maxDebug = 3; // mitme esimese ebaõnnestumise kohta näitame diagnostikat

foreach ($users as $u) {
    $username = $u['kasutajanimi'];
    $uname = rawurlencode($username); // nt "Robin Sulg" -> "Robin%20Sulg", mitte "Robin+Sulg"
    $url = "https://www.crime.ee/index.php?a=11&m=$urlM&k=$uname";

    $html = httpGet($url);
    $parsed = parsePlayerPage($html, $username);

    if ($parsed) {
        $parsed['id'] = (int)$u['id'];
        $records[] = $parsed;
        echo "  OK: $username\n";
    } else {
        echo "  VAHELE: $username (lehte ei õnnestunud parsida)\n";
        if ($debugShown < $maxDebug) {
            $debugShown++;
            fwrite(STDERR, "--- DEBUG #$debugShown ($username) ---\n");
            fwrite(STDERR, "URL: $url\n");
            fwrite(STDERR, "Vastuse pikkus: " . strlen($html) . " baiti\n");
            fwrite(STDERR, "Sisaldab '<table': " . (str_contains($html, '<table') ? 'JAH' : 'EI') . "\n");
            fwrite(STDERR, "Sisaldab 'Vastupidavus': " . (str_contains($html, 'Vastupidavus') ? 'JAH' : 'EI') . "\n");
            fwrite(STDERR, "Sisaldab 'id=\"app\"' (Vue/SPA märk): " . (str_contains($html, 'id="app"') ? 'JAH' : 'EI') . "\n");
            fwrite(STDERR, "Sisaldab 'id=\"root\"' (React märk): " . (str_contains($html, 'id="root"') ? 'JAH' : 'EI') . "\n");
            fwrite(STDERR, "Sisaldab '__NEXT_DATA__' (Next.js märk): " . (str_contains($html, '__NEXT_DATA__') ? 'JAH' : 'EI') . "\n");
            fwrite(STDERR, "Sisaldab 'login' v6i 'logi sisse': " . (stripos($html, 'login') !== false || stripos($html, 'logi sisse') !== false ? 'JAH' : 'EI') . "\n");
            fwrite(STDERR, "Sisaldab 'kasutaja ei leitud' v6i 'not found': " . (stripos($html, 'ei leitud') !== false || stripos($html, 'not found') !== false ? 'JAH' : 'EI') . "\n");
            fwrite(STDERR, "Kasutajanimi ('$username') esineb vastuses: " . (str_contains($html, $username) ? 'JAH' : 'EI') . "\n");
            // Dumpi kogu HTML faili, et saaksime seda vajadusel täpsemalt uurida
            @file_put_contents(__DIR__ . "/debug_{$debugShown}_{$username}.html", $html);
            fwrite(STDERR, "Täisvastus salvestatud: debug_{$debugShown}_{$username}.html\n");
            fwrite(STDERR, "--- DEBUG #$debugShown LÕPP ---\n");
        }
    }

    sleep(1); // sama viisakusvahe crime.ee vastu, mis vanades skriptides
}

file_put_contents($recordsPath, json_encode($records, JSON_UNESCAPED_UNICODE));
echo "Kirjutatud " . count($records) . " kirjet faili $recordsPath\n";
