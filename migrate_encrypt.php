<?php
/**
 * 既存データ暗号化マイグレーションスクリプト
 *
 * !! このスクリプトは一度だけ実行してください !!
 * !! 実行前に必ずデータベースのバックアップを取ってください !!
 *
 * 実行方法：
 *   ブラウザ: （本番ドメイン）/migrate_encrypt.php?key=（環境変数SURVEY_ADMIN_PASSWORDの値）
 *   CLI:      php migrate_encrypt.php
 */

require_once __DIR__ . '/config.php';

// ----- 認証 -----
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    // ブラウザからの実行は管理パスワードで保護
    // !! 本番環境では必ず環境変数 SURVEY_ADMIN_PASSWORD に強固なパスワードを設定してください !!
    define('MIGRATE_KEY', getenv('SURVEY_ADMIN_PASSWORD') ?: 'sample-demo');
    if (($_GET['key'] ?? '') !== MIGRATE_KEY) {
        http_response_code(403);
        die('Forbidden: ?key= パラメータが必要です');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

// ----- 対象カラム -----
$encryptColumns = ['name', 'facility', 'email', 'staff_name', 'staff_memo'];

echo "=== 暗号化マイグレーション開始 ===\n";
echo "暗号化方式: " . ENCRYPTION_CIPHER . "\n";
echo "対象カラム: " . implode(', ', $encryptColumns) . "\n\n";

// ----- DBバックアップ確認 -----
$backupPath = DB_PATH . '.backup_' . date('Ymd_His');
if (!copy(DB_PATH, $backupPath)) {
    die("エラー: バックアップの作成に失敗しました。処理を中止します。\n");
}
echo "バックアップ作成: " . basename($backupPath) . "\n\n";

// ----- マイグレーション実行 -----
try {
    $pdo = getDb();
    $rows = $pdo->query("SELECT * FROM surveys ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $total      = count($rows);
    $skipped    = 0;  // 既に暗号化済み
    $encrypted  = 0;  // 今回暗号化した
    $errors     = 0;

    echo "総レコード数: {$total} 件\n\n";

    if ($total === 0) {
        echo "マイグレーション対象のレコードがありません。\n";
        exit;
    }

    $pdo->beginTransaction();

    foreach ($rows as $row) {
        $id      = (int)$row['id'];
        $updates = [];
        $params  = [':id' => $id];
        $allAlreadyEncrypted = true;

        foreach ($encryptColumns as $col) {
            $val = $row[$col] ?? '';
            if ($val === '') continue; // 空は対象外

            if (is_encrypted($val)) {
                // 既に暗号化済み → スキップ
                continue;
            }

            // 平文 → 暗号化
            $allAlreadyEncrypted = false;
            $updates[]           = "{$col} = :{$col}";
            $params[":{$col}"]   = encrypt_data($val);
        }

        if (empty($updates)) {
            $skipped++;
            echo "  ID {$id}: スキップ（既に暗号化済みまたはデータなし）\n";
            continue;
        }

        try {
            $sql  = "UPDATE surveys SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $encrypted++;
            echo "  ID {$id}: 暗号化完了 (" . implode(', ', array_keys(array_diff_key($params, [':id' => null]))) . ")\n";
        } catch (Exception $e) {
            $errors++;
            echo "  ID {$id}: エラー - " . $e->getMessage() . "\n";
        }
    }

    if ($errors > 0) {
        $pdo->rollBack();
        echo "\nエラーが発生したためロールバックしました。\n";
    } else {
        $pdo->commit();
        echo "\n=== マイグレーション完了 ===\n";
        echo "暗号化: {$encrypted} 件\n";
        echo "スキップ: {$skipped} 件（暗号化済み）\n";
        echo "エラー: {$errors} 件\n";
    }

} catch (Exception $e) {
    echo "致命的エラー: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n!! このスクリプトはマイグレーション完了後に削除またはアクセス制限してください !!\n";
