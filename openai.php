<?php

// Laad de .env variabelen (alleen nodig bij CLI; bij include levert aanroeper de key)
$env = parse_ini_file(__DIR__ . '/.env');
$apiKey = $env['OPENAI_API_KEY'] ?? '';

// =============================================================
// LOKALE FUNCTIES
// Vervang de gesimuleerde data door echte API-aanroepen
// naar bijv. OpenWeatherMap, Google Search API, ExchangeRates API
// =============================================================

function get_weer(string $stad): array
{
    // Vervangen door: https://openweathermap.org/api
    $data = [
        'amsterdam' => ['temperatuur' => 14, 'beschrijving' => 'Bewolkt met lichte regen', 'luchtvochtigheid' => '78%'],
        'rotterdam' => ['temperatuur' => 13, 'beschrijving' => 'Zwaar bewolkt',             'luchtvochtigheid' => '82%'],
        'utrecht'   => ['temperatuur' => 15, 'beschrijving' => 'Gedeeltelijk bewolkt',      'luchtvochtigheid' => '71%'],
        'den haag'  => ['temperatuur' => 13, 'beschrijving' => 'Mistig',                    'luchtvochtigheid' => '88%'],
    ];

    return $data[strtolower($stad)] ?? ['temperatuur' => 12, 'beschrijving' => 'Geen data beschikbaar', 'luchtvochtigheid' => 'onbekend'];
}

// ---------- Helpers voor zoek_internet ----------

/** Alleen http/https; blokkeer localhost en private IPs (SSRF). */
function zoek_internet_url_veilig(string $url): bool
{
    $u = @parse_url($url);
    if (!isset($u['scheme'], $u['host']) || !in_array(strtolower($u['scheme']), ['http', 'https'], true)) {
        return false;
    }
    $host = strtolower($u['host']);
    $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
    if (in_array($host, $blocked, true)) {
        return false;
    }
    if (preg_match('/^127\.|^10\.|^172\.(1[6-9]|2\d|3[01])\.|^192\.168\.|^169\.254\./', $host)) {
        return false;
    }
    $ip = @gethostbyname($host);
    if ($ip && $ip !== $host && preg_match('/^(127\.|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|169\.254\.)/', $ip)) {
        return false;
    }
    return true;
}

/** Haal domein uit URL, bijv. "en.wikipedia.org". */
function zoek_internet_url_naar_bron(string $url): string
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    return $host !== '' ? $host : 'onbekend';
}

/** Veilig een URL ophalen: timeouts, max 1MB, user-agent. */
function zoek_internet_fetch(string $url, array $postData = []): ?string
{
    if (!zoek_internet_url_veilig($url)) {
        return null;
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_ENCODING       => '',
        CURLOPT_NOPROGRESS     => true,
    ];
    if ($postData !== []) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($postData);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    if ($err || $body === false) {
        return null;
    }
    $len = (int) ($info['size_download'] ?? strlen($body));
    if ($len > 1024 * 1024) {
        return null;
    }
    return $body;
}

/** Eerste zinvolle tekst uit HTML halen, max ~240 tekens. */
function zoek_internet_snippet_uit_html(string $html, int $maxLen = 240): string
{
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
    $html = preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', '', $html);
    $html = preg_replace('/<footer\b[^>]*>.*?<\/footer>/is', '', $html);
    $html = preg_replace('/<header\b[^>]*>.*?<\/header>/is', '', $html);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//p | //article//*[self::p or self::div] | //main//p | //div[contains(@class,"content")]//p');
    $tekst = '';
    foreach ($nodes as $n) {
        $t = trim(preg_replace('/\s+/', ' ', $n->textContent ?? ''));
        if (strlen($t) >= 40) {
            $tekst = $t;
            break;
        }
    }
    if ($tekst === '') {
        $tekst = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }
    $tekst = preg_replace('/\s+/', ' ', $tekst);
    if (strlen($tekst) > $maxLen) {
        $tekst = substr($tekst, 0, $maxLen - 3) . '...';
    }
    return $tekst;
}

/** SerpAPI: zoek en retourneer [['titel'=>,'url'=>,'samenvatting'=>,'bron'=>], ...]. */
function zoek_internet_serpapi(string $query, string $apiKey, int $aantal): array
{
    $url = 'https://serpapi.com/search?q=' . rawurlencode($query) . '&api_key=' . rawurlencode($apiKey);
    $body = zoek_internet_fetch($url);
    if ($body === null) {
        return [];
    }
    $data = json_decode($body, true);
    if (!isset($data['organic_results']) || !is_array($data['organic_results'])) {
        return [];
    }
    $out = [];
    foreach ($data['organic_results'] as $r) {
        $link = $r['link'] ?? '';
        if ($link === '' || !zoek_internet_url_veilig($link)) {
            continue;
        }
        $out[] = [
            'titel'       => $r['title'] ?? 'Geen titel',
            'url'         => $link,
            'samenvatting' => $r['snippet'] ?? '',
            'bron'        => zoek_internet_url_naar_bron($link),
        ];
        if (count($out) >= $aantal) {
            break;
        }
    }
    return $out;
}

/** DuckDuckGo Instant Answer API: retourneer lijst met titel, url, samenvatting, bron. */
function zoek_internet_duckduckgo_instant(string $query, int $aantal): array
{
    $url = 'https://api.duckduckgo.com/?q=' . rawurlencode($query) . '&format=json';
    $body = zoek_internet_fetch($url);
    if ($body === null) {
        return [];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    if (!empty($data['AbstractURL']) && !empty($data['Abstract'])) {
        $out[] = [
            'titel'        => $data['Heading'] ?? $data['AbstractTitle'] ?? 'Resultaat',
            'url'          => $data['AbstractURL'],
            'samenvatting' => $data['Abstract'],
            'bron'         => zoek_internet_url_naar_bron($data['AbstractURL']),
        ];
    }
    foreach ($data['Results'] ?? [] as $r) {
        $link = $r['FirstURL'] ?? '';
        if ($link === '' || !zoek_internet_url_veilig($link)) {
            continue;
        }
        $out[] = [
            'titel'        => $r['Text'] ?? 'Geen titel',
            'url'          => $link,
            'samenvatting' => $r['Text'] ?? '',
            'bron'         => zoek_internet_url_naar_bron($link),
        ];
        if (count($out) >= $aantal) {
            break;
        }
    }
    foreach ($data['RelatedTopics'] ?? [] as $t) {
        if (count($out) >= $aantal) {
            break;
        }
        $link = $t['FirstURL'] ?? '';
        if ($link === '' || !zoek_internet_url_veilig($link)) {
            continue;
        }
        $out[] = [
            'titel'        => $t['Text'] ?? 'Geen titel',
            'url'          => $link,
            'samenvatting' => $t['Text'] ?? '',
            'bron'         => zoek_internet_url_naar_bron($link),
        ];
    }
    return array_slice($out, 0, $aantal);
}

/** Normaliseer DuckDuckGo result links naar echte doel-URL (uddg=...). */
function zoek_internet_ddg_normalize_url(string $href): ?string
{
    $href = trim($href);
    if ($href === '') return null;

    // //duckduckgo.com/...
    if (str_starts_with($href, '//')) {
        $href = 'https:' . $href;
    }

    // /l/?uddg=...
    if (str_starts_with($href, '/')) {
        $href = 'https://duckduckgo.com' . $href;
    }

    // Als het een DDG redirect is, decodeer uddg naar echte URL
    $u = @parse_url($href);
    if (is_array($u) && !empty($u['query'])) {
        parse_str($u['query'], $qs);
        if (!empty($qs['uddg'])) {
            $decoded = urldecode($qs['uddg']);
            if (str_starts_with($decoded, 'http://') || str_starts_with($decoded, 'https://')) {
                return $decoded;
            }
        }
    }

    // Al een normale URL?
    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
        return $href;
    }

    return null;
}

/** DuckDuckGo HTML: haal result-URLs op, fetch pagina's en extraheer snippet. */
function zoek_internet_duckduckgo_html(string $query, int $aantal): array
{
    $url = 'https://html.duckduckgo.com/html/';
    $body = zoek_internet_fetch($url, ['q' => $query]);
    if ($body === null) {
        return [];
    }
    $dom = new DOMDocument();
    @$dom->loadHTML($body, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    $links = $xpath->query('//a[contains(@class,"result__a")]');
    if ($links->length === 0) {
        $links = $xpath->query('//*[contains(@class,"result__title")]//a');
    }
    if ($links->length === 0) {
        $links = $xpath->query('//div[contains(@class,"result")]//a');
    }
    $kandidaten = [];
    $gevondenHosts = [];
    foreach ($links as $a) {
        $hrefRaw = trim($a->getAttribute('href') ?? '');
        $href = zoek_internet_ddg_normalize_url($hrefRaw);
        if ($href === null || !zoek_internet_url_veilig($href)) {
            continue;
        }
        $host = zoek_internet_url_naar_bron($href);
        if (isset($gevondenHosts[$host])) {
            continue;
        }
        $gevondenHosts[$host] = true;
        $titel = trim(preg_replace('/\s+/', ' ', $a->textContent ?? ''));
        $kandidaten[] = ['titel' => $titel !== '' ? $titel : 'Geen titel', 'url' => $href];
        if (count($kandidaten) >= $aantal) {
            break;
        }
    }
    $out = [];
    foreach ($kandidaten as $k) {
        $page = zoek_internet_fetch($k['url']);
        $samenvatting = $page !== null ? zoek_internet_snippet_uit_html($page, 240) : '';
        if ($samenvatting === '') {
            $samenvatting = 'Geen samenvatting beschikbaar.';
        }
        $out[] = [
            'titel'        => $k['titel'],
            'url'          => $k['url'],
            'samenvatting' => $samenvatting,
            'bron'         => zoek_internet_url_naar_bron($k['url']),
        ];
    }
    return $out;
}

/** De-dupliceer op url en bron; behoud volgorde. */
function zoek_internet_dedupe(array $items): array
{
    $seenUrl = $seenBron = [];
    $out = [];
    foreach ($items as $r) {
        $url = $r['url'] ?? '';
        $bron = $r['bron'] ?? '';
        if ($url === '' || isset($seenUrl[$url]) || isset($seenBron[$bron])) {
            continue;
        }
        $seenUrl[$url] = $seenBron[$bron] = true;
        $out[] = $r;
    }
    return $out;
}

/** Eenvoudige fallback-zoekterm: eerste woorden of Engelstalige variant voor veelvoorkomende vragen. */
function zoek_internet_fallback_query(string $query): ?string
{
    $q = trim($query);
    if ($q === '') {
        return null;
    }
    $woorden = preg_split('/\s+/', $q, 4);
    $kort = implode(' ', array_slice($woorden, 0, 3));
    if ($kort !== $q) {
        return $kort;
    }
    $nl2en = [
        'meest gesproken taal' => 'most spoken language world',
        'meest gesproken'      => 'most spoken language',
        'grootste taal'        => 'most spoken language',
        'populairste taal'     => 'most spoken language',
    ];
    $qLower = strtolower($q);
    foreach ($nl2en as $nl => $en) {
        if (str_contains($qLower, $nl)) {
            return $en;
        }
    }
    return $q . ' wikipedia';
}

function zoek_internet(string $query, int $aantal = 3): array
{
    $aantal = max(1, min(5, (int) $aantal));
    $query = trim($query);
    if ($query === '') {
        return ['query' => '', 'resultaten' => [], 'fout' => 'Lege zoekopdracht.'];
    }

    $env = @parse_ini_file(__DIR__ . '/.env') ?: [];
    $serpKey = $env['SERPAPI_KEY'] ?? '';

    $resultaten = [];
    if ($serpKey !== '') {
        $resultaten = zoek_internet_serpapi($query, $serpKey, $aantal);
    }
    if ($resultaten === []) {
        $resultaten = zoek_internet_duckduckgo_instant($query, $aantal);
    }
    if ($resultaten === []) {
        $resultaten = zoek_internet_duckduckgo_html($query, $aantal);
    }
    if ($resultaten === []) {
        $fallback = zoek_internet_fallback_query($query);
        if ($fallback !== null && $fallback !== $query) {
            $resultaten = zoek_internet_duckduckgo_instant($fallback, $aantal);
            if ($resultaten === []) {
                $resultaten = zoek_internet_duckduckgo_html($fallback, $aantal);
            }
        }
    }

    $resultaten = zoek_internet_dedupe($resultaten);
    $resultaten = array_slice($resultaten, 0, $aantal);

    $out = ['query' => $query, 'resultaten' => array_values($resultaten)];
    if ($resultaten === []) {
        $out['fout'] = 'Geen zoekresultaten gevonden. Zoekprovider niet beschikbaar of geen resultaten.';
    }
    return $out;
}

function bereken(string $expressie): array
{
    // Alleen cijfers, operatoren en haakjes toegestaan (veiligheid)
    if (!preg_match('/^[0-9+\-*\/\(\)\.\s]+$/', $expressie)) {
        return ['fout' => 'Ongeldige expressie. Gebruik alleen cijfers en + - * / ( )'];
    }

    $resultaat = eval("return $expressie;");

    return ['expressie' => $expressie, 'resultaat' => $resultaat];
}

function get_valuta(string $van, string $naar, float $bedrag): array
{
    // Vervangen door: https://exchangeratesapi.io of https://openexchangerates.org
    $koersen = [
        'EUR' => ['USD' => 1.08, 'GBP' => 0.86, 'JPY' => 161.5, 'CHF' => 0.97],
        'USD' => ['EUR' => 0.93, 'GBP' => 0.79, 'JPY' => 149.5, 'CHF' => 0.90],
        'GBP' => ['EUR' => 1.16, 'USD' => 1.27, 'JPY' => 187.8, 'CHF' => 1.13],
    ];

    $van  = strtoupper($van);
    $naar = strtoupper($naar);

    if (!isset($koersen[$van][$naar])) {
        return ['fout' => "Omrekening van $van naar $naar niet beschikbaar."];
    }

    $omgerekend = round($bedrag * $koersen[$van][$naar], 2);

    return ['van' => "$bedrag $van", 'naar' => "$omgerekend $naar", 'koers' => $koersen[$van][$naar]];
}

function get_nieuws(string $onderwerp): array
{
    // Vervangen door: https://newsapi.org
    $nieuws = [
        'technologie' => [
            ['kop' => 'OpenAI lanceert nieuwe GPT-versie',      'bron' => 'TechCrunch', 'tijd' => '2 uur geleden'],
            ['kop' => 'Apple kondigt nieuwe MacBook Pro aan',    'bron' => 'The Verge',  'tijd' => '4 uur geleden'],
            ['kop' => 'PHP 8.4 brengt nieuwe array-functies',   'bron' => 'PHP.net',    'tijd' => '6 uur geleden'],
        ],
        'sport' => [
            ['kop' => 'Ajax wint met 3-1 van PSV',              'bron' => 'Nu.nl',      'tijd' => '1 uur geleden'],
            ['kop' => 'Formule 1: Verstappen pakt pole position','bron' => 'AD',         'tijd' => '3 uur geleden'],
        ],
        'default' => [
            ['kop' => "Laatste nieuws over: $onderwerp",        'bron' => 'Nieuws.nl',  'tijd' => 'zojuist'],
        ],
    ];

    $sleutel = strtolower($onderwerp);
    $artikelen = $nieuws[$sleutel] ?? $nieuws['default'];

    return ['onderwerp' => $onderwerp, 'artikelen' => $artikelen];
}

// =============================================================
// TOOL-DEFINITIES VOOR HET MODEL
// =============================================================

$tools = [
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_weer',
            'description' => 'Geeft het actuele weer terug voor een opgegeven stad.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'stad' => ['type' => 'string', 'description' => 'Naam van de stad, bijv. Amsterdam'],
                ],
                'required' => ['stad'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name'        => 'zoek_internet',
            'description' => 'Zoekt op het internet naar informatie over een gegeven zoekopdracht.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'query'  => ['type' => 'string',  'description' => 'De zoekopdracht'],
                    'aantal' => ['type' => 'integer', 'description' => 'Aantal resultaten (standaard 3, max 5)'],
                ],
                'required' => ['query'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name'        => 'bereken',
            'description' => 'Voert een wiskundige berekening uit, bijv. (12 * 4) + 8 / 2.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'expressie' => ['type' => 'string', 'description' => 'De wiskundige expressie, bijv. 100 * 1.21'],
                ],
                'required' => ['expressie'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_valuta',
            'description' => 'Rekent een bedrag om van de ene naar de andere valuta.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'van'    => ['type' => 'string', 'description' => 'Bronvaluta, bijv. EUR'],
                    'naar'   => ['type' => 'string', 'description' => 'Doelvaluta, bijv. USD'],
                    'bedrag' => ['type' => 'number', 'description' => 'Het om te rekenen bedrag'],
                ],
                'required' => ['van', 'naar', 'bedrag'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name'        => 'get_nieuws',
            'description' => 'Haalt de laatste nieuwsartikelen op over een opgegeven onderwerp.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'onderwerp' => ['type' => 'string', 'description' => 'Het nieuwsonderwerp, bijv. technologie, sport, politiek'],
                ],
                'required' => ['onderwerp'],
            ],
        ],
    ],
];

// =============================================================
// DISPATCHER: roept de juiste lokale functie aan op naam
// =============================================================

function voerFunctieUit(string $naam, array $args): array
{
    return match ($naam) {
        'get_weer'      => get_weer($args['stad']),
        'zoek_internet' => zoek_internet($args['query'], $args['aantal'] ?? 3),
        'bereken'       => bereken($args['expressie']),
        'get_valuta'    => get_valuta($args['van'], $args['naar'], $args['bedrag']),
        'get_nieuws'    => get_nieuws($args['onderwerp']),
        default         => ['fout' => "Onbekende functie: $naam"],
    };
}

// =============================================================
// API-VERZOEK
// =============================================================

function stuurVerzoek(array $messages, array $tools, string $apiKey): array
{
    $data = ['model' => 'gpt-4o-mini', 'messages' => $messages, 'tools' => $tools];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);

    $response = curl_exec($ch);
    $fout = curl_error($ch);

    if ($fout) die("cURL fout: $fout\n");

    $result = json_decode($response, true);
    if (isset($result['error'])) die("API fout: " . $result['error']['message'] . "\n");

    return $result;
}

// =============================================================
// HOOFDLUS — ondersteunt meerdere opeenvolgende tool-aanroepen
// Retourneert: ['antwoord' => string, 'tool_outputs' => array van JSON-strings]
// =============================================================

function voerChatUit(string $userMessage, string $apiKey, array $tools): array
{
    $messages = [
        ['role' => 'system', 'content' => "Je hebt tools. Gebruik ze altijd wanneer de vraag erom vraagt:\n- get_weer: weer in een stad\n- zoek_internet: feiten (lengte, leeftijd, statistieken, biografie, actuele info). Bij vragen als 'Hoe lang is X?' of 'Wat is de leeftijd van Y?' MOET je eerst zoek_internet aanroepen met een zoekterm (bijv. 'David Raya height' of 'David Raya lengte'), de resultaten bekijken en pas dan antwoorden. Nooit zeggen 'ik kon geen informatie vinden' zonder eerst zoek_internet te hebben aangeroepen.\n- bereken: rekenvragen\n- get_valuta: valuta omrekenen\n- get_nieuws: nieuws over een onderwerp\nAntwoord altijd kort (max. 2 zinnen) op basis van de tool-resultaten. Als de zoekresultaten de gevraagde feiten niet bevatten, zeg dat kort en vermeld wat je wél uit de resultaten hebt kunnen halen."],
        ['role' => 'user', 'content' => $userMessage],
    ];

    $toolOutputs = [];

    while (true) {
        $result = stuurVerzoek($messages, $tools, $apiKey);
        $keuze  = $result['choices'][0];

        if ($keuze['finish_reason'] !== 'tool_calls') {
            $antwoord = $keuze['message']['content'] ?? '';
            return ['antwoord' => trim($antwoord), 'tool_outputs' => $toolOutputs];
        }

        $messages[] = $keuze['message'];

        foreach ($keuze['message']['tool_calls'] as $toolCall) {
            $naam   = $toolCall['function']['name'];
            $args   = json_decode($toolCall['function']['arguments'], true) ?? [];
            $output = voerFunctieUit($naam, $args);
            $json   = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $toolOutputs[] = ['functie' => $naam, 'json' => $json];

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $toolCall['id'],
                'content'      => $json,
            ];
        }
    }
}

// CLI: direct uitvoeren met voorbeeldvraag
if (php_sapi_name() === 'cli') {
    $env = parse_ini_file(__DIR__ . '/.env');
    $apiKey = $env['OPENAI_API_KEY'] ?? '';
    if (empty($apiKey)) die("Fout: OPENAI_API_KEY is niet ingesteld in .env\n");

    $voorbeeld = $argv[1] ?? 'Wat is het nieuws over technologie, en reken ook 250 EUR om naar USD?';
    echo "Gebruiker: $voorbeeld\n\n";

    $uitkomst = voerChatUit($voorbeeld, $apiKey, $tools);

    foreach ($uitkomst['tool_outputs'] as $t) {
        echo "--- Functie: {$t['functie']} ---\n";
        echo $t['json'] . "\n\n";
    }
    echo "--- Antwoord aan gebruiker ---\n";
    echo $uitkomst['antwoord'] . "\n";
}
