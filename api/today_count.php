<?php
/**
 * 当日の回答数（スタッフ向け：ヘッダーのロゴを3秒以内に5回タップすると表示）
 *
 *   GET のみ。アンケート画面を開いたセッションからだけ呼べるよう、ヘッダー X-CSRF-Token を検証する。
 *   個人情報は返さない。件数だけを返す。
 *   {"date":"2027-04-26","completed":23,"doctor":15,"other":8,"basic_only":3}
 *
 *   当日 = 日本時間 0時〜24時。completed_at・created_at は JST の 'Y-m-d H:i:s' で保存されている。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/csrf.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function today_json(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    today_json(405, ['error' => 'method not allowed']);
}

survey_session_start();
if (!csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    today_json(403, ['error' => 'forbidden']);
}
session_write_close(); // 以降はセッションを使わない（他のリクエストを待たせない）

$today = date('Y-m-d');
$range = [
    ':from' => $today . ' 00:00:00',
    ':to'   => date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00',
];

try {
    $pdo = getDb();

    $stmt = $pdo->prepare("
        SELECT respondent_type, COUNT(*) AS cnt FROM surveys
        WHERE status = 'completed' AND completed_at >= :from AND completed_at < :to
        GROUP BY respondent_type
    ");
    $stmt->execute($range);
    $completed = 0;
    $byType = ['doctor' => 0, 'other' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $completed += (int)$r['cnt'];
        if (isset($byType[$r['respondent_type']])) $byType[$r['respondent_type']] = (int)$r['cnt'];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM surveys
        WHERE status = 'basic' AND created_at >= :from AND created_at < :to
    ");
    $stmt->execute($range);
    $basicOnly = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    today_json(500, ['error' => 'unavailable']);
}

today_json(200, [
    'date'       => $today,
    'completed'  => $completed,
    'doctor'     => $byType['doctor'],
    'other'      => $byType['other'],
    'basic_only' => $basicOnly,
]);
