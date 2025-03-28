<?php

$token = file(__DIR__ . '/token.txt', FILE_IGNORE_NEW_LINES)[0] ?? null;

if (!$token) {
    echo 'No token found' . PHP_EOL;
    exit;
}

$answerLog = file(__DIR__ . '/answer.log', FILE_IGNORE_NEW_LINES);

$offset = end($answerLog) ?? 0;
$lastCheckedMessage = $offset;
$endpoint = "https://api.telegram.org/bot" . $token . "/getUpdates?offset=" . $offset . "&timeout=4";
$input = json_decode(file_get_contents($endpoint), true) ?? [];

if (!isset($input['result'])) {
    echo 'No updates received from the endpoint' . PHP_EOL;
    exit;
}

$commandsToAnswer = [
    '/help',
    '/generatesticker',
    '/generate',
    '@invincible_logo_bot /help',
    '@invincible_logo_bot /generatesticker',
    '@invincible_logo_bot /generate',
    '/help@invincible_logo_bot',
    '/generatesticker@invincible_logo_bot',
    '/generate@invincible_logo_bot',
];

$unansweredMessages = [];

// get unanswered messages
foreach($input['result'] as $item) {
    $updateId = $item['update_id'];
    $lastCheckedMessage = $updateId;
    $message = $item['message']['text'] ?? null;
    $messageType = $item['message']['entities'][0]['type'] ?? null;

    if (!$message || $messageType !== 'bot_command') {
        continue;
    }
    $chatId = $item['message']['chat']['id'];

    foreach ($commandsToAnswer as $command) {
        if (str_starts_with($message, $command) && !in_array($updateId, $answerLog)) {
            $unansweredMessages[$updateId] = $item;
            continue 2;
        }
    }
}

answerMessages($token, $commandsToAnswer, $unansweredMessages);

file_put_contents(__DIR__ . '/answer.log', $lastCheckedMessage . PHP_EOL, FILE_APPEND);

echo 'done' . PHP_EOL;
exit;

function answerMessages($token, $commandsToAnswer, $unansweredMessages)
{
    $answeredMessages = [];

    foreach ($unansweredMessages as $unansweredMessage) {
        $answeredMessages[] = $unansweredMessage['update_id'];
        $chatId = $unansweredMessage['message']['chat']['id'];
        $messageId = $unansweredMessage['message']['message_id'];
        $messageText = $unansweredMessage['message']['text'];
        $messageToGenerate = trim(str_replace($commandsToAnswer,'', $messageText ?? ''));

        if (str_contains($messageText, '/help')) {
            sendText($token, $chatId, $messageId, getHelp());
            continue;
        }
        if (!$messageToGenerate) {
            sendText($token, $chatId, $messageId, 'Please, enter a text right after the command (in the same message)');
            continue;
        }

        sendImage($token, $chatId, $messageId, $messageToGenerate, str_contains($messageText, 'sticker'));
    }

    file_put_contents(__DIR__ . '/answer.log', implode(PHP_EOL, $answeredMessages) . PHP_EOL, FILE_APPEND);
}

function getHelp()
{
    return '
`/generate Hello world` will return an image with the text "Hello world"
`/generatesticker Hello world` will return a sticker with the text "Hello world"
`/generate Hello\world` will return an image (or a sticker) with multi-line text
`/help` will return this message
Accepted characters: latin, cyrillic, numbers, spaces, punctuation and backslash (\)
    ';
}

function sendImage($token, $chatId, $replyId, $message, $isSticker)
{
    $filename = generateImage($replyId, $message, $isSticker);
    $file = new CURLFile($filename, 'image/webp', 'image.webp');
    $params = [
        'photo' => $file,
        'type' => 'photo',
        'chat_id' => $chatId,
        'reply_to_message_id' => $replyId,
    ];
    if ($isSticker) {
        unset($params['photo'], $params['type']);
        $params['sticker'] = $file;
    }

    send($token, $params, $isSticker ? 'sendSticker' : 'sendPhoto');
    unlink($filename);
}

function generateImage($replyId, $text, $isSticker)
{
    $path = __DIR__ . '/temp/' . $replyId . '.webp';

    putenv('GDFONTPATH=' . realpath(__DIR__ . '/fonts'));
    $hasCyrillic = preg_match('/[А-Яа-яЁё]/u', $text);
    $font = $hasCyrillic ? 'Nevduplenysh-Regular.otf' : 'SHAXIZOR.ttf';

    $lines = array_map('trim', explode("\\", $text));
    $longestLine = max(array_map('strlen', $lines));

    $imageHeight = 512;
    $imageWidth = $isSticker ? $imageHeight : floor(($imageHeight/9)*16);
    $maxTextWidth = floor($imageWidth * 0.95);
    $maxTextHeight = floor($imageHeight * 0.95);
    $lineGap = 8;

    header('Content-Type: image/png');

    $im = imagecreatetruecolor($imageWidth, $imageHeight);
    $bgColor = imagecolorallocate($im, 55,181,255);
    $textColor = imagecolorallocate($im, 255,242,1);
    $shadowColor = imagecolorallocate($im, 0, 0, 0);
    $pxPerLetter = floor($maxTextWidth / $longestLine);
    $fontSize = min($maxTextWidth, floor($pxPerLetter * 2.5));

    $lineSizes = calculateLineSizes($lines, $maxTextWidth, $maxTextHeight, $fontSize, $im, $font, $textColor, $lineGap);
    $startY = $lineSizes[0]['height'] + floor(($imageHeight - getTotalHeight($lineSizes, $lineGap)) / 2);

    imagefilledrectangle($im, 0, 0, $imageWidth, $imageHeight, $bgColor);

    foreach ($lines as $i => $line) {
        $top = $startY + ($i * ($lineSizes[$i]['height'] + $lineGap));
        $left = floor(($imageWidth - $lineSizes[$i]['width']) / 2);
        imagettftext($im, $lineSizes[$i]['fontSize'], 0, $left, $top + 2, $shadowColor, $font, $line);
        imagettftext($im, $lineSizes[$i]['fontSize'], 0, $left, $top, $textColor, $font, $line);
    }

    imagepng($im, $path);
    return $path;
}

function calculateLineSizes($lines, $maxTextWidth, $maxTextHeight, $fontSize, $im, $font, $textcolor, $lineGap)
{
    foreach ($lines as $i => $line) {
        $lineFontSize = $fontSize;
        $rect = imagettftext($im, $lineFontSize, 0, 0, 0, $textcolor, $font, $line);
        $textWidth = $rect[2];
        $textHeight = abs($rect[1] - $rect[7]);

        while ($textWidth >= $maxTextWidth || $textHeight >= $maxTextHeight) {
            $lineFontSize -= 1;
            $rect = imagettftext($im, $lineFontSize, 0, 0, 0, $textcolor, $font, $line);
            $textWidth = $rect[2];
            $textHeight = abs($rect[1] - $rect[7]);
        }
        $lineSizes[$i] = [
            'width' => $textWidth,
            'height' => abs($rect[1] - $rect[7]),
            'fontSize' => $lineFontSize,
        ];
    }

    if (getTotalHeight($lineSizes, $lineGap) > $maxTextHeight) {
        $fontSize--;
        return calculateLineSizes($lines, $maxTextWidth, $maxTextHeight, $fontSize, $im, $font, $textcolor, $lineGap);
    }

    return $lineSizes;
}

function getTotalHeight($lineSizes, $lineGap)
{
    return array_sum(array_column($lineSizes, 'height')) + ($lineGap * count($lineSizes) - 1);
}

function sendText($token, $chatId, $replyId, $text)
{
    send($token, [
        'text' => $text,
        'parse_mode' => 'Markdown',
        'chat_id' => $chatId,
        'reply_to_message_id' => $replyId,
    ]);
}

function send($token, $postFields, $messageCommand = 'sendMessage')
{
    $contentType = 'multipart/form-data';
    if ($messageCommand === 'sendMessage') {
        $contentType = 'application/json';
        $postFields = json_encode($postFields);
    }
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_POST => 1,
        CURLOPT_HEADER => 'Content-Type: application/json',
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => "https://api.telegram.org/bot$token/$messageCommand",
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => array_merge(array("Content-Type: $contentType"))
    ]);
    curl_exec($curl);
    curl_close($curl);
}
