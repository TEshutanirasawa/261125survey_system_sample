<?php
/**
 * 管理画面
 * 展示ブースアンケート
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/csrf.php';

survey_session_start();

// パスワード
// !! 本番環境では必ず環境変数 SURVEY_ADMIN_PASSWORD に強固なパスワードを設定してください !!
define('ADMIN_PASSWORD', getenv('SURVEY_ADMIN_PASSWORD') ?: 'sample-demo');

$isLoggedIn = isset($_SESSION['sample_admin_auth']) && $_SESSION['sample_admin_auth'] === true;
$loginError = '';

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $loginError = '画面の有効期限が切れました。もう一度ログインしてください';
    } elseif ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['sample_admin_auth'] = true;
        $isLoggedIn = true;
    } else {
        $loginError = 'パスワードが正しくありません';
    }
}

// ログアウト処理
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// DB接続 & データ取得
$rows       = [];
$totalCount = 0;
$basicCount = 0;
$stats      = [];
$dbError    = '';

if ($isLoggedIn) {
    try {
        $pdo = getDb();

        // 総回答数（アンケート送信済み）／基本情報のみ（離脱）
        $totalCount = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'completed'")->fetchColumn();
        $basicCount = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'basic'")->fetchColumn();

        // 全データ（新しい順・最大1000件）
        $rows = $pdo->query("SELECT * FROM surveys ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);

        // 集計は総回答数（status='completed'）を母数とする
        // 集計: 都道府県（上位10）
        $stmtPref = $pdo->query("SELECT prefecture, COUNT(*) as cnt FROM surveys WHERE status = 'completed' GROUP BY prefecture ORDER BY cnt DESC LIMIT 10");
        $stats['prefecture'] = $stmtPref->fetchAll(PDO::FETCH_ASSOC);

        // 集計: Q1
        $stmtQ1 = $pdo->query("SELECT q1, COUNT(*) as cnt FROM surveys WHERE status = 'completed' GROUP BY q1 ORDER BY cnt DESC");
        $stats['q1'] = $stmtQ1->fetchAll(PDO::FETCH_ASSOC);

        // 集計: Q2
        $stmtQ2 = $pdo->query("SELECT q2, COUNT(*) as cnt FROM surveys WHERE status = 'completed' AND q2 != '' GROUP BY q2 ORDER BY cnt DESC");
        $stats['q2'] = $stmtQ2->fetchAll(PDO::FETCH_ASSOC);

        // 集計: Q3（複数選択・カンマ区切りのため展開してカウント）
        $q3Counts = [];
        $stmtQ3raw = $pdo->query("SELECT q3 FROM surveys WHERE status = 'completed' AND q3 != ''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($stmtQ3raw as $q3raw) {
            foreach (explode('、', $q3raw) as $tag) {
                $tag = trim($tag);
                if ($tag === '') continue;
                $q3Counts[$tag] = ($q3Counts[$tag] ?? 0) + 1;
            }
        }
        arsort($q3Counts);
        $stats['q3'] = array_map(fn($k, $v) => ['q3' => $k, 'cnt' => $v], array_keys($q3Counts), array_values($q3Counts));

    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

// CSVエクスポート
if ($isLoggedIn && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="survey_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM

    $cols = ['ID','登録日時','状態','通知','ご芳名','ご所属','都道府県',
             'Q1:本ブースの感想','Q2:きっかけ','Q3:興味のある分野','Q4:ご意見・ご要望',
             'アンケート実施者','メモ'];
    echo implode(',', $cols) . "\n";

    $exportRows = $pdo->query("SELECT * FROM surveys ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($exportRows as $r) {
        $line = [
            $r['id'],
            $r['created_at'],
            '"' . statusLabel($r['status'] ?? null) . '"',
            '"' . notifiedLabel($r['notified'] ?? 0) . '"',
            '"' . str_replace('"', '""', decrypt_data($r['name']))     . '"',
            '"' . str_replace('"', '""', decrypt_data($r['facility'])) . '"',
            '"' . str_replace('"', '""', $r['prefecture'])             . '"',
            '"' . str_replace('"', '""', $r['q1'])               . '"',
            '"' . str_replace('"', '""', $r['q2'])               . '"',
            '"' . str_replace('"', '""', $r['q3'])               . '"',
            '"' . str_replace('"', '""', $r['q4'])               . '"',
            '"' . str_replace('"', '""', decrypt_data($r['staff_name'] ?? '')) . '"',
            '"' . str_replace('"', '""', decrypt_data($r['staff_memo'] ?? '')) . '"',
        ];
        echo implode(',', $line) . "\n";
    }
    exit;
}

// 状態・通知の表示ラベル
function statusLabel(?string $status): string {
    return $status === 'basic' ? '基本情報のみ' : '完了';
}
function notifiedLabel($notified): string {
    return ((int)$notified === 1) ? '送信済' : '-';
}

// 集計バーの幅計算ヘルパー
function barWidth(int $cnt, int $total): int {
    return $total > 0 ? (int) round($cnt / $total * 100) : 0;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理画面 | 展示ブースアンケート</title>
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
            --primary-rgb: 75, 26, 102;
            --accent-rgb: 185, 163, 211;
        }
        body { font-family: 'Yu Gothic UI', 'Hiragino Sans', sans-serif; background: var(--bg); color: var(--text); font-size: 14px; }

        /* ===== ログイン ===== */
        .login-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-box { background: var(--white); border-radius: 10px; padding: 48px 40px; width: 360px; box-shadow: 0 4px 20px rgba(var(--primary-rgb),.15); }
        .login-box h2 { font-size: 1.2em; color: var(--primary); text-align: center; margin-bottom: 28px; }
        .login-box input[type="password"] {
            width: 100%; padding: 12px 14px; border: 1.5px solid var(--border);
            border-radius: 6px; font-size: 1em; margin-bottom: 14px;
        }
        .login-box input[type="password"]:focus { outline: none; border-color: var(--primary); }
        .login-box button {
            width: 100%; padding: 13px; background: var(--primary); color: #fff;
            border: none; border-radius: 6px; font-size: 1em; font-weight: 700; cursor: pointer;
        }
        .login-box button:hover { background: var(--primary-dark); }
        .login-error { color: var(--danger); font-size: .9em; text-align: center; margin-bottom: 12px; }

        /* ===== 管理画面全体 ===== */
        .admin-header {
            background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 28px;
        }
        .admin-header h1 { font-size: 1.05em; font-weight: 700; }
        .admin-header .header-links a {
            color: rgba(255,255,255,.85); text-decoration: none; font-size: .88em;
            margin-left: 16px; padding: 6px 12px; border-radius: 4px; border: 1px solid rgba(255,255,255,.4);
        }
        .admin-header .header-links a:hover { background: rgba(255,255,255,.15); }

        .admin-body { max-width: 1200px; margin: 0 auto; padding: 28px 20px; }

        /* ===== サマリーカード ===== */
        .summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .summary-card { background: var(--surface); border-radius: 8px; padding: 20px 22px; box-shadow: 0 2px 8px rgba(var(--primary-rgb),.10); border-top: 3px solid var(--primary); }
        .summary-card .num { font-size: 2.4em; font-weight: 800; color: var(--primary); line-height: 1.1; }
        .summary-card .lbl { font-size: .82em; color: var(--sub); margin-top: 4px; }

        /* ===== アクションバー ===== */
        .action-bar { display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border-radius: 6px; border: none; font-size: .9em; font-weight: 600; cursor: pointer; text-decoration: none; transition: opacity .2s; }
        .btn:hover { opacity: .85; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-accent  { background: var(--primary-dark); color: #fff; }
        .btn-outline { background: var(--white); color: var(--primary); border: 1.5px solid var(--primary); }

        /* ===== エラー ===== */
        .db-error { background: var(--danger-light); color: var(--danger); border: 1px solid var(--danger); padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; }

        /* ===== 集計セクション ===== */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: var(--surface); border-radius: 8px; padding: 20px 22px; box-shadow: 0 2px 8px rgba(var(--primary-rgb),.08); }
        .stat-card h3 { font-size: .95em; color: var(--primary-dark); font-weight: 700; border-bottom: 2px solid var(--primary); padding-bottom: 8px; margin-bottom: 14px; }
        .stat-row { display: flex; align-items: center; gap: 10px; margin-bottom: 9px; }
        .stat-label { font-size: .85em; color: var(--text); min-width: 160px; max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-shrink: 0; }
        .stat-bar-wrap { flex: 1; background: var(--accent-light); border-radius: 4px; height: 14px; overflow: hidden; }
        .stat-bar { height: 100%; background: var(--primary); border-radius: 4px; transition: width .4s; }
        .stat-cnt { font-size: .85em; font-weight: 700; color: var(--primary-dark); white-space: nowrap; }

        /* ===== データテーブル ===== */
        .table-section { background: var(--surface); border-radius: 8px; padding: 20px 22px; box-shadow: 0 2px 8px rgba(var(--primary-rgb),.08); }
        .table-section h3 { font-size: .95em; color: var(--primary-dark); font-weight: 700; margin-bottom: 14px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1100px; }
        thead th { background: var(--accent-light); color: var(--primary-dark); font-size: .82em; font-weight: 700; padding: 10px 10px; text-align: left; white-space: nowrap; border-bottom: 2px solid var(--primary); }
        tbody td { padding: 9px 10px; font-size: .82em; border-bottom: 1px solid var(--border); vertical-align: top; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        tbody tr:hover td { background: var(--bg); }
        .badge { display: inline-block; font-size: .75em; padding: 2px 7px; border-radius: 10px; font-weight: 600; }
        .badge-blue { background: var(--accent-light); color: var(--primary); }
        .badge-red  { background: var(--danger-light); color: var(--danger); }
        .badge-gray { background: #EEEEF2; color: var(--sub); }
        .empty-row td { text-align: center; color: #aaa; padding: 40px; }

        /* ===== インライン編集 ===== */
        .editable-cell { cursor: pointer; position: relative; }
        .editable-cell:hover { background: var(--accent-light) !important; }
        .editable-cell:hover::after {
            content: '✎'; position: absolute; right: 4px; top: 50%;
            transform: translateY(-50%); font-size: 11px; color: #aaa; pointer-events: none;
        }
        .editable-cell.editing { cursor: default; }
        .editable-cell.editing::after { display: none; }
        .editable-cell.saving { opacity: .5; pointer-events: none; }
        .editable-cell.saved { background: #e6f9ee !important; }
        .editable-cell.save-error { background: var(--danger-light) !important; }
        .edit-input {
            width: 100%; padding: 4px 6px; font-size: .82em; font-family: inherit;
            border: 1.5px solid var(--primary); border-radius: 4px;
            box-sizing: border-box; resize: vertical;
        }
        .edit-input:focus { outline: none; }
        .cell-empty { color: #ccc; font-style: italic; font-size: .8em; }
        th.col-staff-name { min-width: 90px; }
        th.col-staff-memo { min-width: 160px; }
        td.col-staff-memo { max-width: 200px; }
    </style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<!-- ===== ログイン画面 ===== -->
<div class="login-wrap">
    <div class="login-box">
        <h2>展示ブースアンケート管理</h2>
        <?php if ($loginError): ?>
        <p class="login-error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="password" name="password" placeholder="パスワードを入力" required autofocus>
            <button type="submit" name="login" value="1">ログイン</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ===== 管理画面 ===== -->
<header class="admin-header">
    <h1>展示ブースアンケート管理画面</h1>
    <div class="header-links">
        <a href="index.php">アンケートへ</a>
        <a href="admin_notify.php">要通知リスト</a>
        <a href="admin_encryption_check.php" style="opacity:.7;">暗号化確認</a>
        <a href="?logout=1">ログアウト</a>
    </div>
</header>

<div class="admin-body">

    <?php if ($dbError): ?>
    <div class="db-error">データベースエラー: <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- サマリー -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="num"><?= $totalCount ?></div>
            <div class="lbl">総回答数</div>
        </div>
        <div class="summary-card" style="border-top-color:var(--accent);">
            <div class="num" style="color:var(--sub);"><?= $basicCount ?></div>
            <div class="lbl">基本情報のみ（離脱）</div>
        </div>
        <?php
        $today = array_filter($rows, fn($r) => ($r['status'] ?? '') === 'completed'
                                              && str_starts_with($r['created_at'], date('Y-m-d')));
        ?>
        <div class="summary-card" style="border-top-color:var(--success);">
            <div class="num" style="color:var(--success);"><?= count($today) ?></div>
            <div class="lbl">本日の回答数</div>
        </div>
    </div>

    <!-- アクションバー -->
    <div class="action-bar">
        <a href="?export=csv" class="btn btn-accent">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            CSVエクスポート
        </a>
        <button onclick="location.reload()" class="btn btn-outline">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            更新
        </button>
    </div>

    <!-- 集計グラフ -->
    <div class="stats-grid">

        <!-- 都道府県 -->
        <div class="stat-card">
            <h3>都道府県（上位10）</h3>
            <?php foreach ($stats['prefecture'] ?? [] as $r): ?>
            <div class="stat-row">
                <span class="stat-label"><?= htmlspecialchars($r['prefecture'], ENT_QUOTES, 'UTF-8') ?></span>
                <div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= barWidth((int)$r['cnt'], $totalCount) ?>%"></div></div>
                <span class="stat-cnt"><?= $r['cnt'] ?>件</span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Q1 -->
        <div class="stat-card">
            <h3>Q1 本ブースの感想</h3>
            <?php foreach ($stats['q1'] ?? [] as $r): ?>
            <div class="stat-row">
                <span class="stat-label"><?= htmlspecialchars($r['q1'], ENT_QUOTES, 'UTF-8') ?></span>
                <div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= barWidth((int)$r['cnt'], $totalCount) ?>%"></div></div>
                <span class="stat-cnt"><?= $r['cnt'] ?>件</span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Q2 -->
        <div class="stat-card">
            <h3>Q2 きっかけ</h3>
            <?php foreach ($stats['q2'] ?? [] as $r): ?>
            <div class="stat-row">
                <span class="stat-label"><?= htmlspecialchars($r['q2'], ENT_QUOTES, 'UTF-8') ?></span>
                <div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= barWidth((int)$r['cnt'], $totalCount) ?>%"></div></div>
                <span class="stat-cnt"><?= $r['cnt'] ?>件</span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Q3 -->
        <div class="stat-card">
            <h3>Q3 興味のある分野（複数選択）</h3>
            <?php foreach ($stats['q3'] ?? [] as $r): ?>
            <div class="stat-row">
                <span class="stat-label"><?= htmlspecialchars($r['q3'], ENT_QUOTES, 'UTF-8') ?></span>
                <div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= barWidth((int)$r['cnt'], $totalCount) ?>%"></div></div>
                <span class="stat-cnt"><?= $r['cnt'] ?>件</span>
            </div>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- 回答一覧テーブル -->
    <div class="table-section">
        <h3>回答一覧（新しい順・最大1000件）</h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>日時</th>
                        <th>状態</th>
                        <th>通知</th>
                        <th>ご芳名</th>
                        <th>ご所属</th>
                        <th>都道府県</th>
                        <th>Q1 感想</th>
                        <th>Q2 きっかけ</th>
                        <th>Q3 興味分野</th>
                        <th>Q4 意見・要望</th>
                        <th class="col-staff-name">実施者</th>
                        <th class="col-staff-memo">メモ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr class="empty-row"><td colspan="13">回答データがありません</td></tr>
                    <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars($r['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= ($r['status'] ?? '') === 'basic' ? 'badge-gray' : 'badge-blue' ?>"><?= statusLabel($r['status'] ?? null) ?></span></td>
                        <td><?php if ((int)($r['notified'] ?? 0) === 1): ?><span class="badge badge-blue">送信済</span><?php else: ?>-<?php endif; ?></td>
                        <td><?= htmlspecialchars(decrypt_data($r['name']),     ENT_QUOTES, 'UTF-8') ?: '-' ?></td>
                        <td><?= htmlspecialchars(decrypt_data($r['facility']), ENT_QUOTES, 'UTF-8') ?: '-' ?></td>
                        <td><?= htmlspecialchars($r['prefecture'], ENT_QUOTES, 'UTF-8') ?: '-' ?></td>
                        <td title="<?= htmlspecialchars($r['q1'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(mb_strimwidth($r['q1'], 0, 30, '…'), ENT_QUOTES, 'UTF-8') ?: '-' ?>
                        </td>
                        <td title="<?= htmlspecialchars($r['q2'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(mb_strimwidth($r['q2'], 0, 30, '…'), ENT_QUOTES, 'UTF-8') ?: '-' ?>
                        </td>
                        <td title="<?= htmlspecialchars($r['q3'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(mb_strimwidth($r['q3'], 0, 30, '…'), ENT_QUOTES, 'UTF-8') ?: '-' ?>
                        </td>
                        <td title="<?= htmlspecialchars($r['q4'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(mb_strimwidth($r['q4'], 0, 30, '…'), ENT_QUOTES, 'UTF-8') ?: '-' ?>
                        </td>
                        <?php $sn = decrypt_data($r['staff_name'] ?? ''); ?>
                        <td class="editable-cell col-staff-name"
                            data-id="<?= (int)$r['id'] ?>"
                            data-field="staff_name"
                            data-value="<?= htmlspecialchars($sn, ENT_QUOTES, 'UTF-8') ?>"
                            onclick="startEdit(this)">
                            <?php if ($sn !== ''): ?>
                            <?= htmlspecialchars($sn, ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?><span class="cell-empty">—</span><?php endif; ?>
                        </td>
                        <?php $sm = decrypt_data($r['staff_memo'] ?? ''); ?>
                        <td class="editable-cell col-staff-memo"
                            data-id="<?= (int)$r['id'] ?>"
                            data-field="staff_memo"
                            data-value="<?= htmlspecialchars($sm, ENT_QUOTES, 'UTF-8') ?>"
                            onclick="startEdit(this)">
                            <?php if ($sm !== ''): ?>
                            <span title="<?= htmlspecialchars($sm, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(mb_strimwidth($sm, 0, 30, '…'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php else: ?><span class="cell-empty">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /admin-body -->
<?php endif; ?>
<script>
function startEdit(td) {
    if (td.classList.contains('editing') || td.classList.contains('saving')) return;

    const rowId    = td.dataset.id;
    const field    = td.dataset.field;
    const origVal  = td.dataset.value || '';

    td.classList.add('editing');

    const isMemo = (field === 'staff_memo');
    const input  = document.createElement(isMemo ? 'textarea' : 'input');
    input.className = 'edit-input';
    input.value     = origVal;
    if (!isMemo) input.type = 'text';
    if (isMemo)  input.rows = 3;

    td.innerHTML = '';
    td.appendChild(input);
    input.focus();
    if (!isMemo) {
        // カーソルを末尾に
        const len = input.value.length;
        input.setSelectionRange(len, len);
    }

    let saved = false;

    function save() {
        if (saved) return;
        saved = true;

        const newVal = input.value.trim();

        // 変更なし → 元に戻すだけ
        if (newVal === origVal) {
            restoreCell(td, origVal, field);
            return;
        }

        td.classList.remove('editing');
        td.classList.add('saving');
        td.innerHTML = '<span style="color:#999;font-size:.8em;">保存中…</span>';

        fetch('api/update_staff.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: rowId, field: field, value: newVal })
        })
        .then(r => r.json())
        .then(data => {
            td.classList.remove('saving');
            if (data.success) {
                td.dataset.value = newVal;
                restoreCell(td, newVal, field);
                td.classList.add('saved');
                setTimeout(() => td.classList.remove('saved'), 1500);
            } else {
                restoreCell(td, origVal, field);
                flashError(td, '保存失敗: ' + (data.error || 'エラー'));
            }
        })
        .catch(() => {
            td.classList.remove('saving');
            restoreCell(td, origVal, field);
            flashError(td, '通信エラーが発生しました');
        });
    }

    function cancel() {
        if (saved) return;
        saved = true;
        restoreCell(td, origVal, field);
    }

    input.addEventListener('blur', save);

    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            input.removeEventListener('blur', save);
            cancel();
        }
        // staff_name: Enterで確定
        if (!isMemo && e.key === 'Enter') {
            e.preventDefault();
            input.removeEventListener('blur', save);
            save();
        }
    });
}

function restoreCell(td, value, field) {
    td.classList.remove('editing', 'saving');
    if (value && value !== '') {
        const display = (field === 'staff_memo' && value.length > 30)
            ? value.slice(0, 30) + '…'
            : value;
        td.innerHTML = '<span title="' + esc(value) + '">' + esc(display) + '</span>';
    } else {
        td.innerHTML = '<span class="cell-empty">—</span>';
    }
}

function flashError(td, msg) {
    td.classList.add('save-error');
    setTimeout(() => td.classList.remove('save-error'), 2000);
    console.error(msg);
}

function esc(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
