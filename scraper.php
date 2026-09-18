<?php
/**
 * CrimeStats — GitHub Actions skreipimise skript
 *
 * Käivitatakse: php scraper.php <job>
 * Näiteks:      php scraper.php red
 *
 * Vajalikud keskkonnamuutujad (GitHub Actions secrets):
 *   SITE_URL      — nt https://crimestatistics.eu
 *   SCRAPE_SECRET — sama väärtus, mis crime3/includes/config.php SCRAPE_SECRET konstant
 *
 * See skript EI ühendu kunagi otse andmebaasiga (InfinityFree tasuta plaan ei luba
 * väljast andmebaasi ühendust). Kõik käib HTTP kaudu CrimeStats oma ajax-endpointide läbi.
 */

$job = $argv[1] ?? null;
if (!$job) {
    fwrite(STDERR, "Kasutus: php scraper.php <job>\n");
    exit(1);
}

$siteUrl = rtrim(getenv('SITE_URL') ?: '', '/');
$secret  = getenv('SCRAPE_SECRET') ?: '';
if (!$siteUrl || !$secret) {
    fwrite(STDERR, "SITE_URL ja SCRAPE_SECRET keskkonnamuutujad peavad olema seatud.\n");
    exit(1);
}

function httpGet(string $url): string {
    $curl = curl_init();

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);

    // AJUTINE TEST
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($curl);

    $error = curl_error($curl);
    $errno = curl_errno($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($response === false) {
        fwrite(STDERR, "cURL viga ($errno): $error\n");
    }

    echo "HTTP status: $code\n";
    echo "URL: $url\n";

    curl_close($curl);

    if ($response === false) {
        return '';
    }

    return $response;
}

function httpPostForm(string $url, array $fields): array {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $decoded = json_decode((string)$response, true);
    return ['code' => $code, 'body' => $decoded, 'raw' => $response];
}

/**
 * Parsib ühe mängija crime.ee lehe HTML-ist andmed välja.
 * Sama loogika, mis vanades update_*.php skriptides oli.
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

// 1. Küsi CrimeStats'ilt, keda selle töö jaoks kontrollida
$usersUrl = $siteUrl . '/crime3/ajax/scrape_get_users.php?job=' . urlencode($job) . '&secret=' . urlencode($secret);
$usersResp = json_decode(httpGet($usersUrl), true);

if (!$usersResp || isset($usersResp['error'])) {
    fwrite(STDERR, "Kasutajate nimekirja saamine ebaõnnestus: " . ($usersResp['error'] ?? 'tundmatu viga') . "\n");
    exit(1);
}

$urlM = $usersResp['url_m'];
$users = $usersResp['users'];
echo "Töö '$job': " . count($users) . " kasutajat kontrollitavad.\n";

// 2. Käi kõik kasutajad läbi, skreipi crime.ee-lt
$records = [];
foreach ($users as $u) {
    $username = $u['kasutajanimi'];
    $uname = str_contains($username, ' ') ? str_replace(' ', '+', $username) : $username;
    $url = "https://www.crime.ee/index.php?a=11&m=$urlM&k=$uname";

    $html = httpGet($url);
    $parsed = parsePlayerPage($html, $username);

    if ($parsed) {
        $parsed['id'] = (int)$u['id'];
        $records[] = $parsed;
        echo "  OK: $username\n";
    } else {
        echo "  VAHELE: $username (lehte ei õnnestunud parsida)\n";
    }

    sleep(1); // sama viisakusvahe crime.ee vastu, mis vanades skriptides
}

if (empty($records)) {
    echo "Ei saadud ühtegi kasutajat töödelda, ei saada midagi.\n";
    exit(0);
}

// 3. Saada tulemused CrimeStats'ile kirjutamiseks
$submitUrl = $siteUrl . '/crime3/ajax/scrape_submit.php';
$result = httpPostForm($submitUrl, [
    'secret'  => $secret,
    'job'     => $job,
    'records' => json_encode($records, JSON_UNESCAPED_UNICODE),
]);

if ($result['code'] !== 200 || empty($result['body']['success'])) {
    fwrite(STDERR, "Tulemuste saatmine ebaõnnestus (HTTP {$result['code']}): {$result['raw']}\n");
    exit(1);
}

echo "Valmis. Töödeldud: {$result['body']['processed']} / " . count($records) . "\n";
if (!empty($result['body']['errors'])) {
    echo "Vigu: " . count($result['body']['errors']) . "\n";
    foreach ($result['body']['errors'] as $err) echo "  - $err\n";
}
