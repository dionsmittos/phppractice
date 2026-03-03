<?php

// Laad de .env variabelen
$env = parse_ini_file(__DIR__ . '/.env');
$apiKey = $env['OPENAI_API_KEY'] ?? '';

if (empty($apiKey)) {
    die("Fout: OPENAI_API_KEY is niet ingesteld in .env\n");
}

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

function zoek_internet(string $query, int $aantal = 3): array
{
    // Vervangen door: https://serpapi.com of https://developers.google.com/custom-search
    $resultaten = [
        'php function calling' => [
            ['titel' => 'PHP function calling met OpenAI', 'url' => 'https://platform.openai.com/docs/guides/function-calling', 'samenvatting' => 'Officiële OpenAI documentatie over function calling.'],
            ['titel' => 'PHP cURL voorbeeld',              'url' => 'https://www.php.net/manual/en/book.curl.php',              'samenvatting' => 'PHP handleiding voor cURL HTTP-verzoeken.'],
        ],
        'default' => [
            ['titel' => "Zoekresultaat voor: $query", 'url' => 'https://www.google.com/search?q=' . urlencode($query), 'samenvatting' => 'Klik om de volledige zoekresultaten te bekijken.'],
        ],
    ];

    $sleutel = strtolower($query);
    $gevonden = $resultaten[$sleutel] ?? $resultaten['default'];

    return ['query' => $query, 'resultaten' => array_slice($gevonden, 0, $aantal)];
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
    $data = ['model' => 'gpt-5-mini', 'messages' => $messages, 'tools' => $tools];

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
// =============================================================

$messages = [
    ['role' => 'system', 'content' => 'Geef altijd korte en bondige antwoorden. Maximaal 2 zinnen.'],
    ['role' => 'user', 'content' => 'Wat is het nieuws over technologie, en reken ook 250 EUR om naar USD?'],
];

echo "Gebruiker: " . $messages[0]['content'] . "\n\n";

while (true) {
    $result = stuurVerzoek($messages, $tools, $apiKey);
    $keuze  = $result['choices'][0];

    if ($keuze['finish_reason'] !== 'tool_calls') {
        echo "--- Antwoord aan gebruiker ---\n";
        echo $keuze['message']['content'] . "\n";
        break;
    }

    // Voeg het assistent-bericht toe aan de history
    $messages[] = $keuze['message'];

    // Verwerk alle tool-aanroepen in deze ronde
    foreach ($keuze['message']['tool_calls'] as $toolCall) {
        $naam   = $toolCall['function']['name'];
        $args   = json_decode($toolCall['function']['arguments'], true);
        $output = voerFunctieUit($naam, $args);
        $json   = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        echo "--- Functie aangeroepen: $naam ---\n";
        echo $json . "\n\n";

        $messages[] = [
            'role'         => 'tool',
            'tool_call_id' => $toolCall['id'],
            'content'      => $json,
        ];
    }
}
