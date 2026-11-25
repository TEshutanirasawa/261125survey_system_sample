<?php
/**
 * 要通知リスト管理画面
 * 展示ブースアンケート
 *
 * ここに登録した先生が index.php で基本情報を入力すると、登録された通知先へ来訪通知メールが送られる。
 * 認証は admin.php と同じログインセッション（未ログイン時は admin.php のログイン画面へ）。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/name_util.php';

survey_session_start();

if (!isset($_SESSION['sample_admin_auth']) || $_SESSION['sample_admin_auth'] !== true) {
    header('Location: admin.php');
    exit;
}

const CSV_HEADER = ['氏名', 'メールアドレス', '担当者名', '備考'];
const CSV_MAX_BYTES = 2 * 1024 * 1024;

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function redirect_self(string $query = ''): void {
    header('Location: admin_notify.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}
function flash(string $type, string $msg): void {
    $_SESSION['notify_flash'][] = ['type' => $type, 'msg' => $msg];
}
function now_jst(): string {
    return date('Y-m-d H:i:s');
}

/**
 * 登録・編集フォームの入力値を検証し、正規化済みの値を返す
 */
function validate_entry(array $in, array &$errors): array {
    $e = [
        'name'       => mb_substr(trim((string)($in['name'] ?? '')), 0, 100),
        'email'      => mb_substr(trim((string)($in['email'] ?? '')), 0, 254),
        'staff_name' => mb_substr(trim((string)($in['staff_name'] ?? '')), 0, 100),
        'note'       => mb_substr(trim((string)($in['note'] ?? '')), 0, 500),
    ];
    if (normalizeName($e['name']) === '') {
        $errors[] = 'ご芳名を入力してください';
    }
    if ($e['email'] === '') {
        $errors[] = '通知先メールアドレスを入力してください';
    } elseif (!filter_var($e['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = '通知先メールアドレスの形式が正しくありません';
    }
    return $e;
}

/**
 * アップロードされたCSVを読み込み、取り込み候補行とスキップ行に振り分ける
 */
function parse_import_csv(string $raw, PDO $pdo): array {
    // BOM付きUTF-8 → BOM除去 / UTF-8でなければ Shift_JIS（Excel出力）として変換
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    } elseif (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');
    }

    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $raw);
    rewind($fp);

    $header = fgetcsv($fp, null, ',', '"', '');
    $header = is_array($header) ? array_map(fn($c) => trim((string)$c), $header) : [];
    if (array_slice($header, 0, 4) !== CSV_HEADER) {
        fclose($fp);
        return ['error' => 'ヘッダー行が正しくありません。1行目を「' . implode(',', CSV_HEADER) . '」にしてください。'];
    }

    // 登録済みの照合ハッシュ（削除済みは除く）
    $existing = array_flip($pdo->query("SELECT name_hash FROM notify_list WHERE is_deleted = 0")->fetchAll(PDO::FETCH_COLUMN));

    $rows = [];
    $skips = [];
    $seenInFile = [];
    $lineNo = 1;
    while (($cols = fgetcsv($fp, null, ',', '"', '')) !== false) {
        $lineNo++;
        if ($cols === [null] || implode('', array_map('trim', array_map('strval', $cols))) === '') {
            continue; // 空行は無視
        }
        $errs = [];
        $e = validate_entry([
            'name'       => $cols[0] ?? '',
            'email'      => $cols[1] ?? '',
            'staff_name' => $cols[2] ?? '',
            'note'       => $cols[3] ?? '',
        ], $errs);
        if ($errs) {
            $reason = (normalizeName($e['name']) === '') ? '氏名が空です' : 'メールアドレスの形式が不正です';
            $skips[] = ['line' => $lineNo, 'name' => $e['name'], 'reason' => $reason];
            continue;
        }
        $norm = normalizeName($e['name']);
        $hash = nameHash($e['name']);
        if (isset($existing[$hash])) {
            $skips[] = ['line' => $lineNo, 'name' => $e['name'], 'reason' => '既に登録済みです（照合名：' . $norm . '）'];
            continue;
        }
        if (isset($seenInFile[$hash])) {
            $skips[] = ['line' => $lineNo, 'name' => $e['name'], 'reason' => 'ファイル内で ' . $seenInFile[$hash] . ' 行目と重複しています（照合名：' . $norm . '）'];
            continue;
        }
        $seenInFile[$hash] = $lineNo;
        // セッションに一時保持する間も個人情報は暗号化しておく
        $rows[] = [
            'line'       => $lineNo,
            'name'       => encrypt_data($e['name']),
            'email'      => encrypt_data($e['email']),
            'staff_name' => encrypt_data($e['staff_name']),
            'note'       => $e['note'],
        ];
    }
    fclose($fp);
    return ['rows' => $rows, 'skips' => $skips];
}

// ============================================================
// CSVテンプレートのダウンロード（ヘッダー行のみ）
// ============================================================
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="notify_list_template.csv"');
    echo "\xEF\xBB\xBF" . implode(',', CSV_HEADER) . "\r\n";
    exit;
}

$dbError = '';
try {
    $pdo = getDb();
} catch (Exception $e) {
    $pdo = null;
    $dbError = $e->getMessage();
}

// ============================================================
// POST処理（PRG）
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    // CSVの一時ファイルはどの分岐でも処理後に必ず削除する
    $tmpUpload = $_FILES['csv']['tmp_name'] ?? '';
    $cleanupUpload = function () use ($tmpUpload): void {
        if ($tmpUpload !== '' && is_file($tmpUpload)) {
            @unlink($tmpUpload);
        }
    };

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $cleanupUpload();
        flash('error', '画面の有効期限が切れました。もう一度操作してください。');
        redirect_self();
    }

    $action = $_POST['action'] ?? '';
    $needsKey = in_array($action, ['create', 'update', 'import_preview', 'import_commit'], true);
    if ($needsKey && !nameHashEnabled()) {
        $cleanupUpload();
        flash('error', 'NAME_HASH_KEY が未設定のため登録できません（data/secrets.php を確認してください）。');
        redirect_self();
    }

    try {
        switch ($action) {
            case 'create':
            case 'update':
                $errors = [];
                $e = validate_entry($_POST, $errors);
                $id = (int)($_POST['id'] ?? 0);
                if ($errors) {
                    $_SESSION['notify_form'] = ['values' => $e, 'errors' => $errors, 'id' => $id];
                    redirect_self($action === 'update' ? 'edit=' . $id : '');
                }
                $params = [
                    ':name_enc'       => encrypt_data($e['name']),
                    ':name_hash'      => nameHash($e['name']),   // 氏名変更時も必ず再計算
                    ':email_enc'      => encrypt_data($e['email']),
                    ':staff_name_enc' => encrypt_data($e['staff_name']),
                    ':note'           => $e['note'],
                    ':now'            => now_jst(),
                ];
                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO notify_list (name_enc, name_hash, email_enc, staff_name_enc, note, is_deleted, created_at, updated_at)
                        VALUES (:name_enc, :name_hash, :email_enc, :staff_name_enc, :note, 0, :now, :now)
                    ");
                    $stmt->execute($params);
                    flash('success', '登録しました（照合名：' . normalizeName($e['name']) . '）');
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE notify_list SET name_enc = :name_enc, name_hash = :name_hash, email_enc = :email_enc,
                               staff_name_enc = :staff_name_enc, note = :note, updated_at = :now
                        WHERE id = :id AND is_deleted = 0
                    ");
                    $stmt->execute($params + [':id' => $id]);
                    flash($stmt->rowCount() ? 'success' : 'error',
                          $stmt->rowCount() ? '更新しました（照合名：' . normalizeName($e['name']) . '）' : '対象のレコードが見つかりません');
                }
                redirect_self();

            case 'delete':
                $stmt = $pdo->prepare("UPDATE notify_list SET is_deleted = 1, updated_at = :now WHERE id = :id AND is_deleted = 0");
                $stmt->execute([':now' => now_jst(), ':id' => (int)($_POST['id'] ?? 0)]);
                flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? '削除しました' : '対象のレコードが見つかりません');
                redirect_self();

            case 'import_preview':
                $file = $_FILES['csv'] ?? null;
                if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                    $cleanupUpload();
                    flash('error', 'CSVファイルを選択してください');
                    redirect_self();
                }
                if ($file['size'] > CSV_MAX_BYTES) {
                    $cleanupUpload();
                    flash('error', 'ファイルサイズが大きすぎます（上限2MB）');
                    redirect_self();
                }
                $raw = (string)file_get_contents($file['tmp_name']);
                $cleanupUpload();

                $result = parse_import_csv($raw, $pdo);
                if (isset($result['error'])) {
                    flash('error', $result['error']);
                    redirect_self();
                }
                $_SESSION['notify_import'] = [
                    'filename' => mb_substr((string)$file['name'], 0, 100),
                    'rows'     => $result['rows'],
                    'skips'    => $result['skips'],
                ];
                redirect_self('import=preview');

            case 'import_commit':
                $import = $_SESSION['notify_import'] ?? null;
                unset($_SESSION['notify_import']);
                if (!$import) {
                    flash('error', '取り込み対象がありません。もう一度CSVを選択してください。');
                    redirect_self();
                }
                $skipped = count($import['skips']);
                $success = 0;
                $pdo->beginTransaction();
                $dup = $pdo->prepare("SELECT COUNT(*) FROM notify_list WHERE is_deleted = 0 AND name_hash = :h");
                $ins = $pdo->prepare("
                    INSERT INTO notify_list (name_enc, name_hash, email_enc, staff_name_enc, note, is_deleted, created_at, updated_at)
                    VALUES (:name_enc, :name_hash, :email_enc, :staff_name_enc, :note, 0, :now, :now)
                ");
                foreach ($import['rows'] as $r) {
                    $name = decrypt_data($r['name']);
                    $hash = nameHash($name);
                    // プレビュー後に手動登録された場合に備えて確定時にも重複を再確認
                    $dup->execute([':h' => $hash]);
                    if ((int)$dup->fetchColumn() > 0) {
                        $skipped++;
                        continue;
                    }
                    $ins->execute([
                        ':name_enc'       => $r['name'],
                        ':name_hash'      => $hash,
                        ':email_enc'      => $r['email'],
                        ':staff_name_enc' => $r['staff_name'],
                        ':note'           => $r['note'],
                        ':now'            => now_jst(),
                    ]);
                    $success++;
                }
                $pdo->commit();
                flash('success', 'CSVインポート結果：成功 ' . $success . '件／スキップ ' . $skipped . '件');
                redirect_self();

            case 'import_cancel':
                unset($_SESSION['notify_import']);
                flash('success', 'CSVインポートを取り消しました');
                redirect_self();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $cleanupUpload();
        flash('error', 'データベースエラー: ' . $e->getMessage());
        redirect_self();
    }

    $cleanupUpload();
    redirect_self();
}

// ============================================================
// 表示用データ
// ============================================================
$flashes = $_SESSION['notify_flash'] ?? [];
unset($_SESSION['notify_flash']);

$formState = $_SESSION['notify_form'] ?? null;
unset($_SESSION['notify_form']);

$editId = (int)($_GET['edit'] ?? 0);
$form = ['name' => '', 'email' => '', 'staff_name' => '', 'note' => ''];
$formErrors = $formState['errors'] ?? [];

$list = [];
if ($pdo) {
    try {
        $list = $pdo->query("SELECT * FROM notify_list WHERE is_deleted = 0 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

if ($editId > 0) {
    $editRow = null;
    foreach ($list as $r) {
        if ((int)$r['id'] === $editId) { $editRow = $r; break; }
    }
    if (!$editRow) {
        $editId = 0;
    } else {
        $form = [
            'name'       => decrypt_data((string)$editRow['name_enc']),
            'email'      => decrypt_data((string)$editRow['email_enc']),
            'staff_name' => decrypt_data((string)($editRow['staff_name_enc'] ?? '')),
            'note'       => (string)($editRow['note'] ?? ''),
        ];
    }
}
if ($formState && (int)$formState['id'] === $editId) {
    $form = $formState['values'];
}

$importPreview = (isset($_GET['import']) && !empty($_SESSION['notify_import'])) ? $_SESSION['notify_import'] : null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>要通知リスト | 展示ブースアンケート</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            /* アレクシオン版パレット（css/style.css の :root と同値） */
            --primary: #4B1A66;
            --primary-dark: #3A1350;
            --primary-light: #7B4F96;
            --accent: #B9A3D3;
            --accent-light: #E8E0F0;
            --bg: #F4F1F8;
            --surface: #FFFFFF;
            --white: #fff;
            --border: #D9CFE6;
            --text: #2B2B32;
            --sub: #6B6B78;
            --danger: #B3261E;
            --danger-light: #FBEAE9;
            --success: #2E7D5B;
            --success-light: #E6F4EC;
            --primary-rgb: 75, 26, 102;
            --accent-rgb: 185, 163, 211;
        }
        body { font-family: 'Yu Gothic UI', 'Hiragino Sans', sans-serif; background: var(--bg); color: var(--text); font-size: 14px; }

        .admin-header {
            background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
            padding: 14px 28px;
        }
        .admin-header h1 { font-size: 1.05em; font-weight: 700; }
        .admin-header .header-links a {
            color: rgba(255,255,255,.85); text-decoration: none; font-size: .88em;
            margin-left: 16px; padding: 6px 12px; border-radius: 4px; border: 1px solid rgba(255,255,255,.4);
            display: inline-block;
        }
        .admin-header .header-links a:hover { background: rgba(255,255,255,.15); }

        .admin-body { max-width: 1200px; margin: 0 auto; padding: 28px 20px; }

        .panel { background: var(--surface); border-radius: 8px; padding: 20px 22px; box-shadow: 0 2px 8px rgba(var(--primary-rgb),.08); margin-bottom: 24px; }
        .panel h2 { font-size: .95em; color: var(--primary-dark); font-weight: 700; border-bottom: 2px solid var(--accent); padding-bottom: 8px; margin-bottom: 16px; }
        .panel p.desc { font-size: .85em; color: var(--sub); margin-bottom: 14px; line-height: 1.7; }

        .msg { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: .9em; }
        .msg-success { background: var(--success-light); color: var(--success); border: 1px solid var(--success); }
        .msg-error   { background: var(--danger-light); color: var(--danger); border: 1px solid var(--danger); }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px 18px; }
        .form-grid label { display: block; font-size: .85em; font-weight: 600; margin-bottom: 5px; }
        .form-grid .req { color: var(--danger); margin-left: 3px; }
        .form-grid input[type="text"], .form-grid input[type="email"] {
            width: 100%; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 6px;
            font-size: 1em; font-family: inherit; color: var(--text); background: var(--surface);
        }
        .form-grid input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .2); }
        .form-actions { margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 6px; border: none; font-size: .9em; font-weight: 600; cursor: pointer; text-decoration: none; font-family: inherit; }
        .btn:hover { opacity: .85; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-outline { background: var(--surface); color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-danger  { background: var(--surface); color: var(--danger); border: 1.5px solid var(--danger); }
        .btn-sm { padding: 5px 12px; font-size: .82em; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 820px; }
        thead th { background: var(--accent-light); color: var(--primary-dark); font-size: .82em; font-weight: 700; padding: 10px; text-align: left; white-space: nowrap; border-bottom: 2px solid var(--primary); }
        tbody td { padding: 9px 10px; font-size: .85em; border-bottom: 1px solid var(--border); vertical-align: top; }
        tbody tr:hover td { background: var(--bg); }
        tbody tr.is-editing td { background: var(--accent-light); }
        .ops { white-space: nowrap; display: flex; gap: 6px; }
        .ops form { display: inline; }
        .empty-row td { text-align: center; color: #aaa; padding: 32px; }
        .norm { color: var(--sub); font-size: .85em; }

        .import-summary { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
        .import-summary div { background: var(--bg); border-radius: 6px; padding: 10px 16px; font-size: .9em; }
        .import-summary strong { font-size: 1.4em; color: var(--primary); margin-right: 4px; }
        .import-summary .skip strong { color: var(--danger); }
        .skip-list { list-style: none; margin: 10px 0 0; font-size: .85em; }
        .skip-list li { padding: 6px 0; border-bottom: 1px dashed var(--border); }
        .skip-list .line { display: inline-block; min-width: 64px; font-weight: 700; color: var(--danger); }

        @media (max-width: 600px) {
            .admin-header { padding: 12px 16px; }
            .admin-header .header-links a { margin-left: 0; margin-right: 6px; }
            .admin-body { padding: 16px 12px; }
        }
    </style>
</head>
<body>

<header class="admin-header">
    <h1>要通知リスト管理</h1>
    <div class="header-links">
        <a href="admin.php">回答一覧へ戻る</a>
        <a href="admin.php?logout=1">ログアウト</a>
    </div>
</header>

<div class="admin-body">

    <?php if ($dbError): ?>
    <div class="msg msg-error">データベースエラー: <?= h($dbError) ?></div>
    <?php endif; ?>

    <?php if (!nameHashEnabled()): ?>
    <div class="msg msg-error">NAME_HASH_KEY が未設定です。この状態では照合・来訪通知は行われず、リストへの登録もできません（data/secrets.php を確認してください）。</div>
    <?php endif; ?>

    <?php foreach ($flashes as $f): ?>
    <div class="msg <?= $f['type'] === 'success' ? 'msg-success' : 'msg-error' ?>"><?= h($f['msg']) ?></div>
    <?php endforeach; ?>

    <?php if ($importPreview): ?>
    <!-- ===== CSVインポート プレビュー ===== -->
    <div class="panel">
        <h2>CSVインポートの確認（<?= h($importPreview['filename']) ?>）</h2>
        <div class="import-summary">
            <div><strong><?= count($importPreview['rows']) ?></strong>件 登録予定</div>
            <div class="skip"><strong><?= count($importPreview['skips']) ?></strong>件 スキップ</div>
        </div>

        <?php if ($importPreview['rows']): ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>行</th><th>氏名</th><th>照合名</th><th>メールアドレス</th><th>担当者名</th><th>備考</th></tr></thead>
                <tbody>
                <?php foreach ($importPreview['rows'] as $r): $pn = decrypt_data($r['name']); ?>
                    <tr>
                        <td><?= (int)$r['line'] ?></td>
                        <td><?= h($pn) ?></td>
                        <td class="norm"><?= h(normalizeName($pn)) ?></td>
                        <td><?= h(decrypt_data($r['email'])) ?></td>
                        <td><?= h(decrypt_data($r['staff_name'])) ?: '-' ?></td>
                        <td><?= h($r['note']) ?: '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($importPreview['skips']): ?>
        <ul class="skip-list">
            <?php foreach ($importPreview['skips'] as $s): ?>
            <li><span class="line"><?= (int)$s['line'] ?>行目</span><?= h($s['reason']) ?><?= $s['name'] !== '' ? '　' . h($s['name']) : '' ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div class="form-actions">
            <?php if ($importPreview['rows']): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_commit">
                <button type="submit" class="btn btn-primary"><?= count($importPreview['rows']) ?>件を登録する</button>
            </form>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_cancel">
                <button type="submit" class="btn btn-outline">取り消す</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== 新規登録 / 編集 ===== -->
    <div class="panel" id="entry-form">
        <h2><?= $editId ? '編集（ID: ' . $editId . '）' : '新規登録' ?></h2>
        <p class="desc">ご芳名はスペースの有無・全角半角を無視して照合します（例：「佐藤 花子」「佐藤　花子」「佐藤花子」は同一）。同じ先生に複数の通知先を登録すると、全アドレスに送信されます。</p>

        <?php foreach ($formErrors as $err): ?>
        <div class="msg msg-error"><?= h($err) ?></div>
        <?php endforeach; ?>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editId ? 'update' : 'create' ?>">
            <?php if ($editId): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>
            <div class="form-grid">
                <div>
                    <label for="f-name">ご芳名<span class="req">※</span></label>
                    <input type="text" id="f-name" name="name" value="<?= h($form['name']) ?>" maxlength="100" required>
                </div>
                <div>
                    <label for="f-email">通知先メールアドレス<span class="req">※</span></label>
                    <input type="email" id="f-email" name="email" value="<?= h($form['email']) ?>" maxlength="254" required>
                </div>
                <div>
                    <label for="f-staff">担当者名（任意）</label>
                    <input type="text" id="f-staff" name="staff_name" value="<?= h($form['staff_name']) ?>" maxlength="100">
                </div>
                <div>
                    <label for="f-note">備考（任意）</label>
                    <input type="text" id="f-note" name="note" value="<?= h($form['note']) ?>" maxlength="500">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $editId ? '更新する' : '登録する' ?></button>
                <?php if ($editId): ?><a href="admin_notify.php" class="btn btn-outline">キャンセル</a><?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ===== CSV一括インポート ===== -->
    <div class="panel">
        <h2>CSV一括インポート</h2>
        <p class="desc">
            1行目はヘッダー行「<?= h(implode(',', CSV_HEADER)) ?>」が必須です。文字コードは UTF-8（BOM付き可）／Shift_JIS に対応しています。<br>
            氏名が空・メールアドレス形式が不正な行、既に登録済みの照合名の行はスキップされます。取り込み前に確認画面が表示されます。
        </p>
        <form method="post" enctype="multipart/form-data" class="form-actions" style="margin-top:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import_preview">
            <input type="file" name="csv" accept=".csv,text/csv" required>
            <button type="submit" class="btn btn-primary">確認画面へ</button>
            <a href="?template=1" class="btn btn-outline">CSVテンプレートをダウンロード</a>
        </form>
    </div>

    <!-- ===== 一覧 ===== -->
    <div class="panel">
        <h2>登録一覧（<?= count($list) ?>件）</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>ID</th><th>ご芳名</th><th>通知先メールアドレス</th><th>担当者名</th><th>備考</th><th>登録日時</th><th>操作</th></tr>
                </thead>
                <tbody>
                <?php if (!$list): ?>
                    <tr class="empty-row"><td colspan="7">登録がありません</td></tr>
                <?php else: ?>
                <?php foreach ($list as $r): $rn = decrypt_data((string)$r['name_enc']); ?>
                    <tr class="<?= (int)$r['id'] === $editId ? 'is-editing' : '' ?>">
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= h($rn) ?><br><span class="norm">照合名：<?= h(normalizeName($rn)) ?></span></td>
                        <td><?= h(decrypt_data((string)$r['email_enc'])) ?></td>
                        <td><?= h(decrypt_data((string)($r['staff_name_enc'] ?? ''))) ?: '-' ?></td>
                        <td><?= h($r['note'] ?? '') ?: '-' ?></td>
                        <td style="white-space:nowrap;"><?= h($r['created_at']) ?></td>
                        <td>
                            <div class="ops">
                                <a href="?edit=<?= (int)$r['id'] ?>#entry-form" class="btn btn-outline btn-sm">編集</a>
                                <form method="post" data-confirm="<?= h('「' . $rn . '」を要通知リストから削除しますか？') ?>"
                                      onsubmit="return confirm(this.dataset.confirm);">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">削除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>
