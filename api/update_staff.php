<?php
/**
 * スタッフ用フィールドのインライン更新API
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=UTF-8');

// POST以外は拒否
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$id    = isset($_POST['id'])    ? (int)$_POST['id']         : 0;
$field = isset($_POST['field']) ? trim($_POST['field'])      : '';
$value = isset($_POST['value']) ? trim($_POST['value'])      : '';

// idの検証
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid id']);
    exit;
}

// 更新可能フィールドのホワイトリスト（SQLインジェクション対策）
$allowedFields = ['staff_name', 'staff_memo'];
if (!in_array($field, $allowedFields, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid field']);
    exit;
}

// 文字数制限
$maxLen = ($field === 'staff_memo') ? 1000 : 100;
$value  = mb_substr($value, 0, $maxLen);

try {
    $pdo = getDb();

    // 対象レコードの存在確認
    $check = $pdo->prepare("SELECT id FROM surveys WHERE id = :id");
    $check->execute([':id' => $id]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Record not found']);
        exit;
    }

    // UPDATE（フィールド名はホワイトリスト済みなので直接埋め込み可）
    $stmt = $pdo->prepare("UPDATE surveys SET {$field} = :value WHERE id = :id");
    $stmt->execute([':value' => encrypt_data($value), ':id' => $id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
