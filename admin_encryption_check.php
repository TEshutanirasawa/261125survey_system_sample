<?php
/**
 * 暗号化確認画面
 * 展示ブースアンケート
 */

require_once __DIR__ . '/config.php';

session_start();
// !! 本番環境では必ず環境変数 SURVEY_ADMIN_PASSWORD に強固なパスワードを設定してください !!
define('ADMIN_PASSWORD', getenv('SURVEY_ADMIN_PASSWORD') ?: 'sample-demo');

$isLoggedIn = isset($_SESSION['sample_admin_auth']) && $_SESSION['sample_admin_auth'] === true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['sample_admin_auth'] = true;
        $isLoggedIn = true;
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin_encryption_check.php');
    exit;
}

// ----- 暗号化対象カラム定義 -----
$encCols = [
    'name'       => 'ご芳名',
    'facility'   => 'ご施設名',
    'email'      => 'メールアドレス',
    'staff_name' => 'アンケート実施者',
    'staff_memo' => 'メモ',
];

// ----- データ取得 -----
$rows        = [];
$totalCount  = 0;
$encCount    = 0;   // 全暗号化済みレコード数
$plainCount  = 0;   // 未暗号化レコード数（name基準）
$dbError     = '';

if ($isLoggedIn) {
    try {
        $pdo = getDb();
        $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM surveys")->fetchColumn();

        // 最新5件を詳細表示用に取得
        $rows = $pdo->query("SELECT * FROM surveys ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

        // 全件の暗号化状況を集計（nameカラムで判定）
        $allRows = $pdo->query("SELECT name FROM surveys")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allRows as $n) {
            if ($n === '') continue; // 空は集計対象外
            if (is_encrypted($n)) $encCount++;
            else $plainCount++;
        }

    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

$encRate = ($encCount + $plainCount) > 0
    ? round($encCount / ($encCount + $plainCount) * 100, 1)
    : 100.0;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>暗号化確認 | サンプル管理</title>
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        :root {
            /* アレクシオン版パレット（css/style.css の :root と同値） */
            --primary:#4B1A66; --primary-dark:#3A1350; --primary-light:#7B4F96;
            --accent:#B9A3D3; --accent-light:#E8E0F0;
            --bg:#F4F1F8; --surface:#FFFFFF; --white:#fff; --border:#D9CFE6;
            --text:#2B2B32; --sub:#6B6B78;
            --danger:#B3261E; --danger-light:#FBEAE9; --success:#2E7D5B; --green:var(--success);
            --primary-rgb:75, 26, 102; --accent-rgb:185, 163, 211;
        }
        body { font-family:'Yu Gothic UI','Hiragino Sans',sans-serif; background:var(--bg); color:var(--text); font-size:14px; }

        /* login */
        .login-wrap { display:flex; align-items:center; justify-content:center; min-height:100vh; }
        .login-box { background:var(--surface); border-radius:10px; padding:48px 40px; width:360px; box-shadow:0 4px 20px rgba(var(--primary-rgb),.15); }
        .login-box h2 { font-size:1.2em; color:var(--primary); text-align:center; margin-bottom:28px; }
        .login-box input[type="password"] { width:100%; padding:12px 14px; border:1.5px solid var(--border); border-radius:6px; font-size:1em; margin-bottom:14px; }
        .login-box button { width:100%; padding:13px; background:var(--primary); color:#fff; border:none; border-radius:6px; font-size:1em; font-weight:700; cursor:pointer; }

        /* layout */
        .admin-header { background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:space-between; padding:14px 28px; }
        .admin-header h1 { font-size:1.05em; font-weight:700; }
        .admin-header .header-links a { color:rgba(255,255,255,.85); text-decoration:none; font-size:.88em; margin-left:16px; padding:6px 12px; border-radius:4px; border:1px solid rgba(255,255,255,.4); }
        .admin-header .header-links a:hover { background:rgba(255,255,255,.15); }
        .body { max-width:1100px; margin:0 auto; padding:28px 20px; }

        /* cards */
        .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:28px; }
        .card { background:var(--surface); border-radius:8px; padding:20px 22px; box-shadow:0 2px 8px rgba(var(--primary-rgb),.1); border-top:3px solid var(--primary); }
        .card .num { font-size:2.2em; font-weight:800; color:var(--primary); line-height:1.1; }
        .card .lbl { font-size:.82em; color:var(--sub); margin-top:4px; }
        .card.green { border-top-color:var(--green); }
        .card.green .num { color:var(--green); }
        .card.red { border-top-color:var(--danger); }
        .card.red .num { color:var(--danger); }

        /* section */
        .section { background:var(--surface); border-radius:8px; padding:22px; box-shadow:0 2px 8px rgba(var(--primary-rgb),.08); margin-bottom:24px; }
        .section h2 { font-size:1em; color:var(--primary-dark); font-weight:700; border-bottom:2px solid var(--primary); padding-bottom:8px; margin-bottom:18px; }

        /* status grid */
        .status-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
        .status-item { background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:14px 16px; }
        .status-item .s-label { font-size:.82em; color:var(--sub); margin-bottom:6px; }
        .status-item .s-value { font-size:.95em; font-weight:600; color:var(--text); }
        .badge-ok  { color:var(--green); }
        .badge-ng  { color:var(--danger); }

        /* table */
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; min-width:700px; }
        thead th { background:var(--accent-light); color:var(--primary-dark); font-size:.82em; font-weight:700; padding:9px 10px; text-align:left; white-space:nowrap; border-bottom:2px solid var(--primary); }
        tbody td { padding:8px 10px; font-size:.82em; border-bottom:1px solid var(--border); vertical-align:top; }
        tbody tr:hover td { background:var(--bg); }
        .enc-ok  { color:var(--green); font-weight:600; }
        .enc-ng  { color:var(--danger); font-weight:600; }
        .raw-val { font-family:monospace; font-size:.78em; color:#888; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block; }
        .plain-val { color:var(--text); }
        .empty-val { color:#ccc; font-style:italic; }
        .db-error { background:var(--danger-light); color:var(--danger); border:1px solid var(--danger); padding:12px 16px; border-radius:6px; margin-bottom:20px; }

        /* progress bar */
        .progress-wrap { background:var(--accent-light); border-radius:4px; height:16px; overflow:hidden; margin-top:10px; }
        .progress-bar { height:100%; background:var(--green); border-radius:4px; transition:width .4s; }
    </style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<div class="login-wrap">
    <div class="login-box">
        <h2>サンプル管理ログイン</h2>
        <form method="POST">
            <input type="password" name="password" placeholder="パスワードを入力" required autofocus>
            <button type="submit" name="login" value="1">ログイン</button>
        </form>
    </div>
</div>

<?php else: ?>
<header class="admin-header">
    <h1>暗号化ステータス確認</h1>
    <div class="header-links">
        <a href="admin.php">← 管理画面へ戻る</a>
        <a href="?logout=1">ログアウト</a>
    </div>
</header>

<div class="body">

    <?php if ($dbError): ?>
    <div class="db-error">データベースエラー: <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- サマリーカード -->
    <div class="cards">
        <div class="card">
            <div class="num"><?= $totalCount ?></div>
            <div class="lbl">総レコード数</div>
        </div>
        <div class="card green">
            <div class="num"><?= $encCount ?></div>
            <div class="lbl">暗号化済みレコード数</div>
        </div>
        <div class="card <?= $plainCount > 0 ? 'red' : 'green' ?>">
            <div class="num"><?= $plainCount ?></div>
            <div class="lbl">未暗号化レコード数</div>
        </div>
        <div class="card <?= $encRate >= 100 ? 'green' : ($encRate > 0 ? '' : 'red') ?>">
            <div class="num"><?= $encRate ?>%</div>
            <div class="lbl">暗号化率</div>
            <div class="progress-wrap">
                <div class="progress-bar" style="width:<?= $encRate ?>%"></div>
            </div>
        </div>
    </div>

    <!-- 暗号化設定ステータス -->
    <div class="section">
        <h2>暗号化設定</h2>
        <div class="status-grid">
            <div class="status-item">
                <div class="s-label">暗号化機能</div>
                <div class="s-value badge-ok">✓ 有効</div>
            </div>
            <div class="status-item">
                <div class="s-label">暗号化方式</div>
                <div class="s-value"><?= htmlspecialchars(ENCRYPTION_CIPHER, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="status-item">
                <div class="s-label">暗号化キー</div>
                <?php $isDefaultKey = (ENCRYPTION_KEY === 'Akp2026#JcrSurvey$Kbzr@ChangeMe!'); ?>
                <div class="s-value <?= $isDefaultKey ? 'badge-ng' : 'badge-ok' ?>">
                    <?= $isDefaultKey ? '⚠ デフォルトキー（要変更）' : '✓ カスタムキー設定済み' ?>
                </div>
            </div>
            <div class="status-item">
                <div class="s-label">OpenSSL</div>
                <div class="s-value <?= extension_loaded('openssl') ? 'badge-ok' : 'badge-ng' ?>">
                    <?= extension_loaded('openssl') ? '✓ 利用可能' : '✗ 利用不可' ?>
                </div>
            </div>
            <div class="status-item">
                <div class="s-label">暗号化対象カラム</div>
                <div class="s-value" style="font-size:.85em;"><?= implode(', ', array_keys($encCols)) ?></div>
            </div>
            <div class="status-item">
                <div class="s-label">未暗号化データ</div>
                <div class="s-value <?= $plainCount > 0 ? 'badge-ng' : 'badge-ok' ?>">
                    <?php if ($plainCount > 0): ?>
                    ⚠ <?= $plainCount ?>件あり → <a href="migrate_encrypt.php?key=sample-demo" target="_blank" style="color:var(--danger);">マイグレーション実行</a>
                    <?php else: ?>
                    ✓ なし
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 最新5件の詳細確認テーブル -->
    <div class="section">
        <h2>データ確認（最新5件）</h2>
        <?php if (empty($rows)): ?>
        <p style="color:#aaa;text-align:center;padding:20px;">データがありません</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>カラム名</th>
                        <th>DB内の生データ（先頭30字）</th>
                        <th>復号後の値</th>
                        <th>暗号化状態</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <?php foreach ($encCols as $col => $colLabel): ?>
                    <?php
                        $rawVal   = $row[$col] ?? '';
                        $decVal   = decrypt_data($rawVal);
                        $isEnc    = is_encrypted($rawVal);
                        $isEmpty  = ($rawVal === '');
                        $rawDisp  = $isEmpty ? '' : mb_strimwidth($rawVal, 0, 40, '…');
                        $decDisp  = $isEmpty ? '' : mb_strimwidth($decVal, 0, 30, '…');
                    ?>
                    <tr>
                        <?php if ($col === array_key_first($encCols)): ?>
                        <td rowspan="<?= count($encCols) ?>" style="font-weight:700;vertical-align:middle;text-align:center;">
                            <?= (int)$row['id'] ?>
                        </td>
                        <?php endif; ?>
                        <td style="color:var(--sub);"><?= htmlspecialchars($colLabel, ENT_QUOTES, 'UTF-8') ?><br><small style="color:#aaa;"><?= $col ?></small></td>
                        <td>
                            <?php if ($isEmpty): ?>
                            <span class="empty-val">（空）</span>
                            <?php elseif ($isEnc): ?>
                            <span class="raw-val" title="<?= htmlspecialchars($rawVal, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($rawDisp, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php else: ?>
                            <span class="plain-val"><?= htmlspecialchars($rawDisp, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isEmpty): ?>
                            <span class="empty-val">—</span>
                            <?php else: ?>
                            <?= htmlspecialchars($decDisp, ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isEmpty): ?>
                            <span style="color:#aaa;">—</span>
                            <?php elseif ($isEnc): ?>
                            <span class="enc-ok">✓ 暗号化済み</span>
                            <?php else: ?>
                            <span class="enc-ng">✗ 未暗号化</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- 注意事項 -->
    <div class="section" style="background:#fffbf0;border:1px solid #f0d080;">
        <h2 style="color:#806000;border-color:#c0a000;">⚠ 注意事項</h2>
        <ul style="padding-left:20px;line-height:1.9;font-size:.9em;color:#555;margin-top:4px;">
            <li>暗号化キー（<code>ENCRYPTION_KEY</code>）は絶対にGit等にコミットしないでください</li>
            <li>キーを変更・紛失すると既存の暗号化データを復元できなくなります</li>
            <li>デフォルトキーのまま本番運用しないでください。<code>config.php</code>のキーを変更してください</li>
            <li>新キー生成コマンド: <code>php -r "echo bin2hex(random_bytes(32));"</code></li>
            <li>キーのバックアップは安全な場所（パスワードマネージャー等）に保管してください</li>
            <li><code>migrate_encrypt.php</code> は実行完了後、削除またはアクセス制限してください</li>
        </ul>
    </div>

</div>
<?php endif; ?>
</body>
</html>
