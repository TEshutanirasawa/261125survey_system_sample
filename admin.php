<?php
/**
 * 管理画面
 * IgG4関連疾患アンケート
 *
 * 集計・一覧・CSV の設問・選択肢は lib/questions.php の定義を参照する。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/questions.php';

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

// ===== 表示ラベル =====
function statusLabel(?string $status): string {
    return $status === 'completed' ? '完了' : '基本情報のみ';
}
function typeLabel(array $r): string {
    if (($r['status'] ?? '') !== 'completed') return '-';
    return ['doctor' => '医師', 'other' => '医師以外'][$r['respondent_type']] ?? '-';
}
function routeLabel(array $r): string {
    if (($r['status'] ?? '') !== 'completed') return '-';
    if ($r['respondent_type'] === 'doctor') return $r['route'] !== '' ? $r['route'] : '-';
    if ($r['respondent_type'] === 'other')  return ((int)$r['ended_early'] === 1) ? '早期終了' : '-';
    return '-';
}

// 集計バーの幅計算ヘルパー
function barWidth(int $cnt, int $total): int {
    return $total > 0 ? (int) round($cnt / $total * 100) : 0;
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// DB接続 & データ取得
$rows    = [];
$summary = ['completed' => 0, 'basic' => 0, 'doctor' => 0, 'other' => 0, 'top2' => 0, 'bottom2' => 0, 'ended_early' => 0];
$stats   = [];   // 設問ID => ['answered' => 母数, 'counts' => [選択肢 => 件数]]
$prefStats = [];
$dbError = '';

if ($isLoggedIn) {
    try {
        $pdo = getDb();

        $summary['completed']   = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'completed'")->fetchColumn();
        $summary['basic']       = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'basic'")->fetchColumn();
        $summary['doctor']      = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'completed' AND respondent_type = 'doctor'")->fetchColumn();
        $summary['other']       = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'completed' AND respondent_type = 'other'")->fetchColumn();
        $summary['top2']        = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'completed' AND respondent_type = 'doctor' AND route = 'top2'")->fetchColumn();
        $summary['bottom2']     = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'completed' AND respondent_type = 'doctor' AND route = 'bottom2'")->fetchColumn();
        $summary['ended_early'] = (int) $pdo->query("SELECT COUNT(*) FROM surveys WHERE status = 'completed' AND respondent_type = 'other' AND ended_early = 1")->fetchColumn();

        // 設問ごとの集計：母数はその設問に回答した人数（d5 は top2、n3〜n5 は早期終了を除いた人数になる）
        $completedRows = $pdo->query("SELECT " . implode(', ', array_keys($QUESTIONS)) . " FROM surveys WHERE status = 'completed'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($QUESTIONS as $qid => $q) {
            $counts = array_fill_keys($q['options'], 0); // 0件の選択肢も表示する
            $answered = 0;
            foreach ($completedRows as $cr) {
                $selected = split_answer((string)$cr[$qid], $q);
                if (!$selected) continue;
                $answered++;
                foreach ($selected as $opt) {
                    $counts[$opt]++;
                }
            }
            $stats[$qid] = ['answered' => $answered, 'counts' => $counts];
        }

        // 都道府県（上位10）
        $prefStats = $pdo->query("SELECT prefecture, COUNT(*) as cnt FROM surveys WHERE status = 'completed' GROUP BY prefecture ORDER BY cnt DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

        // 全データ（新しい順・最大1000件）
        $rows = $pdo->query("SELECT * FROM surveys ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

// CSVエクスポート（UTF-8 BOM付き・全値をダブルクォートで囲む・氏名等は復号して出力）
if ($isLoggedIn && $dbError === '' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="igg4_survey_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM

    $csv = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"';

    $cols = ['ID', '日時', '状態', '区分', '経路／早期終了', 'ご芳名', 'ご所属', '都道府県'];
    foreach ($QUESTIONS as $q) $cols[] = $q['short'];
    $cols[] = '担当者';
    $cols[] = 'メモ';
    echo implode(',', array_map($csv, $cols)) . "\r\n";

    $exportRows = $pdo->query("SELECT * FROM surveys ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($exportRows as $r) {
        $line = [
            $r['id'],
            $r['created_at'],
            statusLabel($r['status'] ?? null),
            typeLabel($r),
            routeLabel($r),
            decrypt_data((string)$r['name']),
            decrypt_data((string)$r['facility']),
            $r['prefecture'],
        ];
        foreach ($QUESTIONS as $qid => $q) $line[] = $r[$qid] ?? '';
        $line[] = decrypt_data((string)($r['staff_name'] ?? ''));
        $line[] = decrypt_data((string)($r['staff_memo'] ?? ''));
        echo implode(',', array_map($csv, $line)) . "\r\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IgG4関連疾患アンケート 管理画面</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            /* 田辺ファーマ／ユプリズナ版パレット（css/style.css の :root と同系） */
            --brand-teal: #00B0B9;
            --primary: #007C83;
            --primary-dark: #00636A;
            --primary-light: #E6F7F8;
            --lime: #DAD700;
            --bg: #F5F8F8;
            --surface: #FFFFFF;
            --white: #fff;
            --border: #D5E3E5;
            --text: #1F2A30;
            --sub: #5F6B70;
            --danger: #B3261E;
            --danger-light: #FBEAE9;
            --success: #2E7D5B;
            --primary-rgb: 0, 124, 131;
        }
        body { font-family: 'Yu Gothic UI', 'Hiragino Sans', sans-serif; background: var(--bg); color: var(--text); font-size: 14px; }

        /* ===== ログイン ===== */
        .login-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-box { background: var(--white); border-radius: 10px; padding: 48px 40px; width: 360px; max-width: calc(100% - 32px); box-shadow: 0 4px 20px rgba(var(--primary-rgb),.15); }
        .login-box h2 { font-size: 1.2em; color: var(--primary-dark); text-align: center; margin-bottom: 28px; }
        .login-box input[type="password"] {
            width: 100%; padding: 12px 14px; border: 1.5px solid var(--border);
            border-radius: 6px; font-size: 1em; margin-bottom: 14px;
        }
        .login-box input[type="password"]:focus { outline: none; border-color: var(--brand-teal); }
        .login-box button {
            width: 100%; padding: 13px; background: var(--primary); color: #fff;
            border: none; border-radius: 6px; font-size: 1em; font-weight: 700; cursor: pointer;
        }
        .login-box button:hover { background: var(--primary-dark); }
        .login-error { color: var(--danger); font-size: .9em; text-align: center; margin-bottom: 12px; }

        /* ===== 管理画面全体 ===== */
        .admin-header {
            background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
            padding: 14px 28px; border-bottom: 3px solid var(--lime);
        }
        .admin-header h1 { font-size: 1.05em; font-weight: 700; }
        .admin-header .header-links a {
            color: rgba(255,255,255,.9); text-decoration: none; font-size: .88em;
            margin-left: 12px; padding: 6px 12px; border-radius: 4px; border: 1px solid rgba(255,255,255,.45);
            display: inline-block;
        }
        .admin-header .header-links a:hover { background: rgba(255,255,255,.15); }

        .admin-body { max-width: 1280px; margin: 0 auto; padding: 28px 20px; }

        /* ===== サマリーカード ===== */
        .summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 28px; }
        .summary-card { background: var(--surface); border-radius: 8px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(var(--primary-rgb),.08); border-top: 3px solid var(--brand-teal); }
        .summary-card .num { font-size: 2.2em; font-weight: 800; color: var(--primary); line-height: 1.1; }
        .summary-card .lbl { font-size: .82em; color: var(--sub); margin-top: 4px; }
        .summary-card.muted { border-top-color: var(--border); }
        .summary-card.muted .num { color: var(--sub); }

        /* ===== アクションバー ===== */
        .action-bar { display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border-radius: 6px; border: none; font-size: .9em; font-weight: 600; cursor: pointer; text-decoration: none; transition: opacity .2s; }
        .btn:hover { opacity: .85; }
        .btn-accent  { background: var(--primary-dark); color: #fff; }
        .btn-outline { background: var(--white); color: var(--primary); border: 1.5px solid var(--primary); }

        /* ===== エラー ===== */
        .db-error { background: var(--danger-light); color: var(--danger); border: 1px solid var(--danger); padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; }

        /* ===== 集計セクション ===== */
        .section-title { font-size: 1em; font-weight: 700; color: var(--primary-dark); margin: 8px 0 12px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 18px; margin-bottom: 28px; }
        .stat-card { background: var(--surface); border-radius: 8px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(var(--primary-rgb),.08); }
        .stat-card h3 { font-size: .92em; color: var(--text); font-weight: 700; border-bottom: 2px solid var(--brand-teal); padding-bottom: 8px; margin-bottom: 6px; line-height: 1.5; }
        .stat-card .stat-meta { font-size: .78em; color: var(--sub); margin-bottom: 12px; }
        .stat-row { display: grid; grid-template-columns: minmax(0, 1fr) 120px 76px; align-items: center; gap: 10px; margin-bottom: 8px; }
        .stat-label { font-size: .82em; color: var(--text); line-height: 1.45; }
        .stat-bar-wrap { background: var(--primary-light); border-radius: 4px; height: 14px; overflow: hidden; }
        .stat-bar { height: 100%; background: var(--brand-teal); border-radius: 4px; transition: width .4s; }
        .stat-cnt { font-size: .82em; font-weight: 700; color: var(--primary-dark); white-space: nowrap; text-align: right; }
        .stat-cnt small { font-weight: 400; color: var(--sub); }

        /* ===== データテーブル ===== */
        .table-section { background: var(--surface); border-radius: 8px; padding: 20px 22px; box-shadow: 0 2px 8px rgba(var(--primary-rgb),.08); }
        .table-section h3 { font-size: .95em; color: var(--primary-dark); font-weight: 700; margin-bottom: 14px; }
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { border-collapse: collapse; min-width: 100%; }
        thead th { background: var(--primary-light); color: var(--primary-dark); font-size: .8em; font-weight: 700; padding: 10px 10px; text-align: left; white-space: nowrap; border-bottom: 2px solid var(--brand-teal); }
        tbody td { padding: 9px 10px; font-size: .8em; border-bottom: 1px solid var(--border); vertical-align: top; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        tbody tr:hover td { background: var(--bg); }
        .badge { display: inline-block; font-size: .75em; padding: 2px 7px; border-radius: 10px; font-weight: 600; }
        .badge-blue { background: var(--primary-light); color: var(--primary-dark); }
        .badge-gray { background: #EEF1F2; color: var(--sub); }
        .empty-row td { text-align: center; color: #aaa; padding: 40px; }

        /* ===== インライン編集 ===== */
        .editable-cell { cursor: pointer; position: relative; }
        .editable-cell:hover { background: var(--primary-light) !important; }
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

        @media (max-width: 600px) {
            .admin-header { padding: 12px 16px; }
            .admin-header .header-links a { margin-left: 0; margin-right: 6px; }
            .stats-grid { grid-template-columns: 1fr; }
            .stat-row { grid-template-columns: minmax(0, 1fr) 70px 64px; }
        }
    </style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<!-- ===== ログイン画面 ===== -->
<div class="login-wrap">
    <div class="login-box">
        <h2>IgG4関連疾患アンケート 管理画面</h2>
        <?php if ($loginError): ?>
        <p class="login-error"><?= e($loginError) ?></p>
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
    <h1>IgG4関連疾患アンケート 管理画面</h1>
    <div class="header-links">
        <a href="index.php">アンケートへ</a>
        <a href="admin_encryption_check.php" style="opacity:.8;">暗号化確認</a>
        <a href="?logout=1">ログアウト</a>
    </div>
</header>

<div class="admin-body">

    <?php if ($dbError): ?>
    <div class="db-error">データベースエラー: <?= e($dbError) ?></div>
    <?php endif; ?>

    <!-- サマリー -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="num"><?= $summary['completed'] ?></div>
            <div class="lbl">回答完了数</div>
        </div>
        <div class="summary-card muted">
            <div class="num"><?= $summary['basic'] ?></div>
            <div class="lbl">基本情報のみ（途中離脱）</div>
        </div>
        <div class="summary-card">
            <div class="num"><?= $summary['doctor'] ?></div>
            <div class="lbl">医師</div>
        </div>
        <div class="summary-card">
            <div class="num"><?= $summary['other'] ?></div>
            <div class="lbl">医師以外の医療関係者</div>
        </div>
        <div class="summary-card">
            <div class="num"><?= $summary['top2'] ?> <span style="font-size:.5em;color:var(--sub);">/</span> <?= $summary['bottom2'] ?></div>
            <div class="lbl">医師の経路 top2 / bottom2</div>
        </div>
        <div class="summary-card">
            <div class="num"><?= $summary['ended_early'] ?></div>
            <div class="lbl">医師以外の早期終了（今回初めて知った）</div>
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
    <?php foreach (['doctor' => '医師向け設問', 'other' => '医師以外の医療関係者向け設問'] as $group => $groupLabel): ?>
    <h2 class="section-title"><?= e($groupLabel) ?></h2>
    <div class="stats-grid">
        <?php foreach ($QUESTIONS as $qid => $q): if ($q['group'] !== $group) continue; $st = $stats[$qid] ?? ['answered' => 0, 'counts' => array_fill_keys($q['options'], 0)]; ?>
        <div class="stat-card">
            <h3><?= e($q['short']) ?><?= e(question_suffix($q['type'])) ?></h3>
            <p class="stat-meta" title="<?= e($q['text']) ?>">回答者 <?= $st['answered'] ?>人（割合の母数）</p>
            <?php foreach ($st['counts'] as $opt => $cnt): ?>
            <div class="stat-row">
                <span class="stat-label"><?= e($opt) ?></span>
                <div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= barWidth($cnt, $st['answered']) ?>%"></div></div>
                <span class="stat-cnt"><?= $cnt ?>件 <small>(<?= barWidth($cnt, $st['answered']) ?>%)</small></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <h2 class="section-title">都道府県</h2>
    <div class="stats-grid">
        <div class="stat-card">
            <h3>都道府県（上位10）</h3>
            <p class="stat-meta">回答完了 <?= $summary['completed'] ?>件（割合の母数）</p>
            <?php foreach ($prefStats as $r): ?>
            <div class="stat-row">
                <span class="stat-label"><?= e($r['prefecture']) ?></span>
                <div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= barWidth((int)$r['cnt'], $summary['completed']) ?>%"></div></div>
                <span class="stat-cnt"><?= (int)$r['cnt'] ?>件</span>
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
                        <th>区分</th>
                        <th>経路／早期終了</th>
                        <th>ご芳名</th>
                        <th>ご所属</th>
                        <th>都道府県</th>
                        <?php foreach ($QUESTIONS as $q): ?>
                        <th><?= e($q['short']) ?></th>
                        <?php endforeach; ?>
                        <th class="col-staff-name">担当者</th>
                        <th class="col-staff-memo">メモ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr class="empty-row"><td colspan="<?= 10 + count($QUESTIONS) ?>">回答データがありません</td></tr>
                    <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td style="white-space:nowrap;"><?= e($r['created_at']) ?></td>
                        <td><span class="badge <?= ($r['status'] ?? '') === 'completed' ? 'badge-blue' : 'badge-gray' ?>"><?= statusLabel($r['status'] ?? null) ?></span></td>
                        <td><?= e(typeLabel($r)) ?></td>
                        <td><?= e(routeLabel($r)) ?></td>
                        <td><?= e(decrypt_data((string)$r['name'])) ?: '-' ?></td>
                        <td><?= e(decrypt_data((string)$r['facility'])) ?: '-' ?></td>
                        <td><?= e($r['prefecture']) ?: '-' ?></td>
                        <?php foreach ($QUESTIONS as $qid => $q): $val = (string)($r[$qid] ?? ''); ?>
                        <td title="<?= e($val) ?>"><?= $val !== '' ? e(mb_strimwidth($val, 0, 30, '…')) : '-' ?></td>
                        <?php endforeach; ?>
                        <?php $sn = decrypt_data((string)($r['staff_name'] ?? '')); ?>
                        <td class="editable-cell col-staff-name"
                            data-id="<?= (int)$r['id'] ?>"
                            data-field="staff_name"
                            data-value="<?= e($sn) ?>"
                            onclick="startEdit(this)">
                            <?php if ($sn !== ''): ?>
                            <?= e($sn) ?>
                            <?php else: ?><span class="cell-empty">—</span><?php endif; ?>
                        </td>
                        <?php $sm = decrypt_data((string)($r['staff_memo'] ?? '')); ?>
                        <td class="editable-cell col-staff-memo"
                            data-id="<?= (int)$r['id'] ?>"
                            data-field="staff_memo"
                            data-value="<?= e($sm) ?>"
                            onclick="startEdit(this)">
                            <?php if ($sm !== ''): ?>
                            <span title="<?= e($sm) ?>">
                                <?= e(mb_strimwidth($sm, 0, 30, '…')) ?>
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
