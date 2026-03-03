<?php
$antwoord = '';
$fout = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vraag = trim($_POST['vraag'] ?? '');

    if ($vraag === '') {
        $fout = 'Voer een vraag in.';
    } else {
        $env = parse_ini_file(__DIR__ . '/.env');
        $apiKey = $env['OPENAI_API_KEY'] ?? '';

        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => $vraag],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);

        $response = curl_exec($ch);
        $curlFout = curl_error($ch);

        if ($curlFout) {
            $fout = 'cURL fout: ' . $curlFout;
        } else {
            $result = json_decode($response, true);
            if (isset($result['error'])) {
                $fout = 'API fout: ' . $result['error']['message'];
            } else {
                $antwoord = $result['choices'][0]['message']['content'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenAI Chat</title>
    <style>
        body {
            font-family: sans-serif;
            max-width: 700px;
            margin: 60px auto;
            padding: 0 20px;
            background: #f5f5f5;
        }
        h1 { color: #333; }
        textarea {
            width: 100%;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 6px;
            resize: vertical;
            box-sizing: border-box;
        }
        button {
            margin-top: 10px;
            padding: 10px 24px;
            background: #0070f3;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
        }
        button:hover { background: #005dd1; }
        .antwoord {
            margin-top: 24px;
            padding: 16px;
            background: white;
            border-radius: 6px;
            border: 1px solid #ddd;
            white-space: pre-wrap;
        }
        .fout {
            margin-top: 16px;
            color: #c0392b;
        }
    </style>
</head>
<body>
    <h1>OpenAI Chat</h1>
    <form method="POST">
        <textarea name="vraag" rows="4" placeholder="Stel een vraag..."><?= htmlspecialchars($_POST['vraag'] ?? '') ?></textarea>
        <button type="submit">Verstuur</button>
    </form>

    <?php if ($fout): ?>
        <p class="fout"><?= htmlspecialchars($fout) ?></p>
    <?php endif; ?>

    <?php if ($antwoord): ?>
        <div class="antwoord"><?= htmlspecialchars($antwoord) ?></div>
    <?php endif; ?>
</body>
</html>
