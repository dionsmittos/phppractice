<?php
require_once __DIR__ . '/openai.php';

$antwoord = '';
$toolOutputs = [];
$fout = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vraag = trim($_POST['vraag'] ?? '');

    if ($vraag === '') {
        $fout = 'Voer een vraag in.';
    } else {
        $env = parse_ini_file(__DIR__ . '/.env');
        $apiKey = $env['OPENAI_API_KEY'] ?? '';

        if (empty($apiKey)) {
            $fout = 'OPENAI_API_KEY staat niet in .env';
        } else {
            $uitkomst = voerChatUit($vraag, $apiKey, $tools);
            $antwoord = $uitkomst['antwoord'];
            $toolOutputs = $uitkomst['tool_outputs'];
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
        .tool-json {
            margin-top: 12px;
            padding: 12px;
            background: #f9f9f9;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-family: monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .tool-details {
            margin-top: 20px;
            padding: 12px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .tool-details summary {
            cursor: pointer;
            font-weight: 600;
            color: #333;
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
        <details class="tool-details" <?= !empty($toolOutputs) ? 'open' : '' ?>>
            <summary>Functieresultaten (JSON)</summary>
            <?php if (!empty($toolOutputs)): ?>
                <?php foreach ($toolOutputs as $t): ?>
                    <p><strong><?= htmlspecialchars($t['functie']) ?></strong></p>
                    <pre class="tool-json"><?= htmlspecialchars($t['json']) ?></pre>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="tool-json">Geen functies aangeroepen voor dit antwoord.</p>
            <?php endif; ?>
        </details>
    <?php endif; ?>
</body>
</html>
