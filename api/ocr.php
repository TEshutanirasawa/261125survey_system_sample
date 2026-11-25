<?php
/**
 * 名札OCR API プロキシ
 * クライアント側で圧縮済みの画像をClaude Vision APIに送り、
 * ご芳名・ご所属・都道府県をJSONで返す。
 * 呼び出し元（js/app.js）は撮影・圧縮後にこのAPIを非同期(fetch)で呼び出し、
 * 応答を待つ間も回答者は他の設問を進められる。
 */

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

// POSTのみ受け付ける
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// リクエストボディを取得
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['image'])) {
    http_response_code(400);
    echo json_encode(['error' => '画像データがありません']);
    exit;
}

// base64データの検証・分離
$imageData = $input['image'];
if (!preg_match('/^data:(image\/(jpeg|png|gif|webp));base64,(.+)$/s', $imageData, $matches)) {
    http_response_code(400);
    echo json_encode(['error' => '画像形式が不正です']);
    exit;
}
$mediaType  = $matches[1];
$base64Body = $matches[3];

// Claude Vision API リクエスト
$prompt = <<<EOT
この画像は展示会・学会の名札または名刺です。
以下の情報を抽出し、JSONのみを返してください（説明文は不要）。
- name: 氏名（フルネーム）
- facility: 所属施設名・病院名・大学名・会社名
- prefecture: 都道府県名（日本の47都道府県のいずれか。住所や施設名から判断できる場合のみ。「東京都」「大阪府」のように都・道・府・県まで含めてください）

抽出できない項目は空文字（または null）にしてください。読み取りに自信が持てない項目は無理に埋めず空にしてください。
出力例: {"name":"山田 太郎","facility":"○○病院","prefecture":"東京都"}
EOT;

$requestBody = json_encode([
    'model'      => CLAUDE_OCR_MODEL,
    'max_tokens' => 256,
    'messages'   => [
        [
            'role'    => 'user',
            'content' => [
                [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $mediaType,
                        'data'       => $base64Body,
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ],
        ],
    ],
], JSON_UNESCAPED_UNICODE);

// cURL で Claude API を呼び出す
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $requestBody,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'API通信エラー']);
    exit;
}

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'API応答エラー: ' . $httpCode]);
    exit;
}

// Claude の応答からテキストを取り出す
$apiResult = json_decode($response, true);
$text = $apiResult['content'][0]['text'] ?? '';

// 応答からJSONを抽出
if (!preg_match('/\{[^}]+\}/s', $text, $jsonMatch)) {
    http_response_code(500);
    echo json_encode(['error' => 'OCR結果の解析に失敗しました']);
    exit;
}

$ocr = json_decode($jsonMatch[0], true);
if (!is_array($ocr)) {
    http_response_code(500);
    echo json_encode(['error' => 'OCR結果のJSON解析に失敗しました']);
    exit;
}

function ocr_str($v, int $maxLen): string {
    if (!is_string($v)) return '';
    return mb_substr(trim($v), 0, $maxLen);
}

echo json_encode([
    'name'       => ocr_str($ocr['name']       ?? '', 100),
    'facility'   => ocr_str($ocr['facility']   ?? '', 200),
    'prefecture' => ocr_str($ocr['prefecture'] ?? '', 20),
], JSON_UNESCAPED_UNICODE);
