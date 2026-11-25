<?php
/**
 * 展示ブースアンケート
 * 第2画面：アンケート本体（Q1〜Q4・スタッフ使用欄）
 *
 *   POST step=basic  … index.php から。基本情報を保存 → 要通知リスト照合・通知 → GET survey.php へリダイレクト（PRG）
 *   GET              … セッションの response_id のレコードについて Q1〜Q4 を表示
 *   POST step=answer … 既存レコードを UPDATE（status=completed）→ thankyou.php
 *
 * 一方通行：response_id が無い GET / 不正な POST は index.php へ戻す。戻るボタン・基本情報の修正リンクは置かない。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/notify.php';

survey_session_start();

function redirect_to(string $path): void {
    header('Location: ' . $path);
    exit;
}

/**
 * セッションの response_id に対応する「基本情報のみ」のレコードを取得。無ければ index.php へ戻す。
 */
function load_basic_record(): array {
    $id = (int)($_SESSION['response_id'] ?? 0);
    if ($id <= 0) {
        redirect_to('index.php');
    }
    try {
        $stmt = getDb()->prepare("SELECT id, name, facility, prefecture, status FROM surveys WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $row = false;
    }
    if (!$row || $row['status'] !== 'basic') {
        unset($_SESSION['response_id']);
        redirect_to('index.php');
    }
    return $row;
}

$method = $_SERVER['REQUEST_METHOD'];
$step   = $_POST['step'] ?? '';

// ============================================================
// 基本情報の受付（index.php から）
// ============================================================
if ($method === 'POST' && $step === 'basic') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        redirect_to('index.php');
    }

    // ワンタイムトークン：戻る→再送信・ボタン連打による二重登録を防ぐ
    $nonce = (string)($_POST['form_nonce'] ?? '');
    if ($nonce === '' || empty($_SESSION['basic_nonce']) || !hash_equals($_SESSION['basic_nonce'], $nonce)) {
        redirect_to(!empty($_SESSION['response_id']) ? 'survey.php' : 'index.php');
    }
    unset($_SESSION['basic_nonce']);

    $name       = trim($_POST['name']       ?? '');
    $facility   = trim($_POST['facility']   ?? '');
    $prefecture = trim($_POST['prefecture'] ?? '');

    $errors = [];
    if ($name === '')       $errors[] = 'ご芳名を入力してください';
    if ($facility === '')   $errors[] = 'ご所属を入力してください';
    if ($prefecture === '') $errors[] = '都道府県を選択してください';

    $back = function (array $errors) use ($name, $facility, $prefecture): void {
        $_SESSION['basic_flash'] = [
            'errors' => $errors,
            'old'    => ['name' => $name, 'facility' => $facility, 'prefecture' => $prefecture],
        ];
        redirect_to('index.php');
    };

    if ($errors) {
        $back($errors);
    }

    $name       = mb_substr($name, 0, 100);
    $facility   = mb_substr($facility, 0, 200);
    $prefecture = mb_substr($prefecture, 0, 20);

    try {
        $pdo  = getDb();
        $stmt = $pdo->prepare("
            INSERT INTO surveys (created_at, name, facility, prefecture, name_hash, status, notified)
            VALUES (:created_at, :name, :facility, :prefecture, :name_hash, 'basic', 0)
        ");
        $stmt->execute([
            ':created_at' => date('Y-m-d H:i:s'),
            ':name'       => encrypt_data($name),
            ':facility'   => encrypt_data($facility),
            ':prefecture' => $prefecture,
            ':name_hash'  => nameHashEnabled() ? nameHash($name) : null,
        ]);
        $responseId = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        $back(['データの保存に失敗しました。もう一度お試しください。']);
    }

    $_SESSION['response_id'] = $responseId;

    // 要通知リスト照合・通知（失敗してもアンケートは継続。エラーは data/mail_error.log へ）
    try {
        notify_registered_visitor($pdo, $responseId, $name, $facility, $prefecture);
    } catch (\Throwable $e) {
        mail_error_log('EXCEPTION', get_class($e) . ' response_id=' . $responseId);
    }

    redirect_to('survey.php');
}

// ============================================================
// アンケート本体の表示・最終送信
// ============================================================
if ($method === 'POST' && $step !== 'answer') {
    redirect_to('index.php');
}

if ($method === 'POST' && !csrf_verify($_POST['csrf_token'] ?? null)) {
    redirect_to('index.php');
}

$record = load_basic_record();
$errors = [];
$posted = ($method === 'POST');

if ($posted) {
    // --- Q1 ---
    $q1       = trim($_POST['q1']       ?? '');
    $q1_other = trim($_POST['q1_other'] ?? '');
    $q1_stored = ($q1 === 'その他' && $q1_other !== '') ? 'その他：' . $q1_other : $q1;

    // --- Q2（単一選択） ---
    $q2       = trim($_POST['q2']       ?? '');
    $q2_other = trim($_POST['q2_other'] ?? '');
    $q2_stored = ($q2 === 'その他' && $q2_other !== '') ? 'その他：' . $q2_other : $q2;

    // --- Q3（複数選択） ---
    $q3_selected = isset($_POST['q3']) && is_array($_POST['q3']) ? $_POST['q3'] : [];
    $q3_selected = array_map('trim', $q3_selected);
    $q3_other    = trim($_POST['q3_other'] ?? '');
    $q3_labels   = array_filter($q3_selected, fn($v) => $v !== 'その他');
    if (in_array('その他', $q3_selected, true) && $q3_other !== '') {
        $q3_labels[] = 'その他：' . $q3_other;
    }
    $q3_stored = implode('、', $q3_labels);

    // --- Q4（自由記述・任意） ---
    $q4 = trim($_POST['q4'] ?? '');

    // --- スタッフ用 ---
    $staff_name = trim($_POST['staff_name'] ?? '');
    $staff_memo = trim($_POST['staff_memo'] ?? '');

    // --- バリデーション ---
    if ($q1 === '')         $errors[] = 'Q1を選択してください';
    if ($q1 === 'その他' && $q1_other === '') $errors[] = 'Q1「その他」の内容を入力してください';
    if ($q2 === '')         $errors[] = 'Q2を選択してください';
    if ($q2 === 'その他' && $q2_other === '') $errors[] = 'Q2「その他」の内容を入力してください';
    if (in_array('その他', $q3_selected, true) && $q3_other === '') $errors[] = 'Q3「その他」の内容を入力してください';

    // --- DB更新（基本情報で作成した既存レコードを UPDATE。新規INSERTはしない） ---
    if (empty($errors)) {
        try {
            $pdo  = getDb();
            $stmt = $pdo->prepare("
                UPDATE surveys SET
                    q1 = :q1, q2 = :q2, q3 = :q3, q3_other = :q3_other, q4 = :q4,
                    staff_name = :staff_name, staff_memo = :staff_memo,
                    status = 'completed', completed_at = :completed_at
                WHERE id = :id AND status = 'basic'
            ");
            $stmt->execute([
                ':q1'           => mb_substr($q1_stored, 0, 100),
                ':q2'           => mb_substr($q2_stored, 0, 100),
                ':q3'           => mb_substr($q3_stored, 0, 300),
                ':q3_other'     => mb_substr($q3_other, 0, 200),
                ':q4'           => mb_substr($q4, 0, 1000),
                ':staff_name'   => encrypt_data(mb_substr($staff_name, 0, 100)),
                ':staff_memo'   => encrypt_data(mb_substr($staff_memo, 0, 1000)),
                ':completed_at' => date('Y-m-d H:i:s'),
                ':id'           => (int)$record['id'],
            ]);

            unset($_SESSION['response_id']);
            redirect_to('thankyou.php');
        } catch (Exception $e) {
            $errors[] = 'データの保存に失敗しました。もう一度お試しください。';
        }
    }
}

// 再表示用にPOST値を保持
if ($posted) {
    $v = [
        'q1'         => $q1,
        'q1_other'   => htmlspecialchars($q1_other, ENT_QUOTES, 'UTF-8'),
        'q2'         => $q2,
        'q2_other'   => htmlspecialchars($q2_other, ENT_QUOTES, 'UTF-8'),
        'q3'         => $q3_selected,
        'q3_other'   => htmlspecialchars($q3_other, ENT_QUOTES, 'UTF-8'),
        'q4'         => htmlspecialchars($q4, ENT_QUOTES, 'UTF-8'),
        'staff_name' => htmlspecialchars($staff_name, ENT_QUOTES, 'UTF-8'),
        'staff_memo' => htmlspecialchars($staff_memo, ENT_QUOTES, 'UTF-8'),
    ];
} else {
    $v = array_fill_keys(['q1','q1_other','q2','q2_other','q3_other','q4','staff_name','staff_memo'], '');
    $v['q3'] = [];
}

function checked_radio(string $name, string $val, array $v): string {
    return ($v[$name] === $val) ? ' checked' : '';
}
function checked_checkbox(string $val, array $selected): string {
    return in_array($val, $selected, true) ? ' checked' : '';
}
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>展示ブースアンケート | アレクシオンファーマ合同会社</title>
    <link rel="stylesheet" href="css/style.css?v=20261007b">
</head>
<body>

<div class="survey-wrapper">

    <!-- ヘッダー -->
    <div class="intro-card">
        <div class="intro-logo">
            <img src="images/alexion_logo.png" alt="アレクシオンファーマ合同会社">
        </div>
        <p class="intro-company">アレクシオンファーマ合同会社</p>
        <h1 class="intro-page-title" style="margin-bottom:0;padding-bottom:0;border-bottom:none;">展示ブースアンケート</h1>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="error-msg" style="margin-bottom:20px;">
        <?= h($errors[0]) ?>
    </div>
    <?php endif; ?>

    <!-- ========== 基本情報（確認表示・読み取り専用） ========== -->
    <div class="form-card">
        <div class="form-card-title">基本情報</div>
        <dl class="basic-summary">
            <dt>ご芳名</dt>
            <dd><?= h(decrypt_data((string)$record['name'])) ?></dd>
            <dt>ご所属</dt>
            <dd><?= h(decrypt_data((string)$record['facility'])) ?></dd>
            <dt>都道府県</dt>
            <dd><?= h((string)$record['prefecture']) ?></dd>
        </dl>
    </div>

    <form id="surveyForm" method="post" action="survey.php">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="answer">

        <!-- ========== Q1 ========== -->
        <div class="form-card">
            <div class="form-card-title">
                <span class="section-badge">Q1</span>本ブースの感想をお聞かせください。<span class="required-mark">※</span>
            </div>
            <div class="radio-list">
                <?php
                $q1_options = ['大変満足した', '満足した', 'どちらともいえない', 'やや不満だった', '不満だった'];
                foreach ($q1_options as $opt):
                ?>
                <label class="radio-item">
                    <input type="radio" name="q1" value="<?= h($opt) ?>"<?= checked_radio('q1', $opt, $v) ?>>
                    <span class="radio-label"><?= h($opt) ?></span>
                </label>
                <?php endforeach; ?>
                <label class="radio-item">
                    <input type="radio" name="q1" value="その他" id="q1_other_check"<?= checked_radio('q1', 'その他', $v) ?>>
                    <span class="radio-label">その他</span>
                </label>
            </div>
            <div class="other-input-container<?= ($v['q1'] === 'その他') ? ' visible' : '' ?>" id="q1_other_container">
                <input type="text" name="q1_other" class="other-input"
                       value="<?= $v['q1_other'] ?>" placeholder="具体的にご記入ください"
                       style="min-height:48px;">
            </div>
        </div>

        <!-- ========== Q2 ========== -->
        <div class="form-card">
            <div class="form-card-title">
                <span class="section-badge">Q2</span>本ブースを知ったきっかけを教えてください。<span class="required-mark">※</span>
            </div>
            <div class="radio-list">
                <?php
                $q2_options = ['Webサイト', 'SNS', '知人の紹介', '会場で見かけて'];
                foreach ($q2_options as $opt):
                ?>
                <label class="radio-item">
                    <input type="radio" name="q2" value="<?= h($opt) ?>"<?= checked_radio('q2', $opt, $v) ?>>
                    <span class="radio-label"><?= h($opt) ?></span>
                </label>
                <?php endforeach; ?>
                <label class="radio-item">
                    <input type="radio" name="q2" value="その他" id="q2_other_check"<?= checked_radio('q2', 'その他', $v) ?>>
                    <span class="radio-label">その他</span>
                </label>
            </div>
            <div class="other-input-container<?= ($v['q2'] === 'その他') ? ' visible' : '' ?>" id="q2_other_container">
                <input type="text" name="q2_other" class="other-input"
                       value="<?= $v['q2_other'] ?>" placeholder="具体的にご記入ください"
                       style="min-height:48px;">
            </div>
        </div>

        <!-- ========== Q3 ========== -->
        <div class="form-card">
            <div class="form-card-title">
                <span class="section-badge">Q3</span>ご興味のある分野を教えてください。（複数選択可）
            </div>
            <div class="checkbox-list">
                <?php
                $q3_options = ['新製品情報', '導入事例', '価格・費用', 'サポート体制'];
                foreach ($q3_options as $opt):
                ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="q3[]" value="<?= h($opt) ?>"<?= checked_checkbox($opt, $v['q3']) ?>>
                    <span class="checkbox-label"><?= h($opt) ?></span>
                </label>
                <?php endforeach; ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="q3[]" value="その他" id="q3_other_check"<?= checked_checkbox('その他', $v['q3']) ?>>
                    <span class="checkbox-label">その他</span>
                </label>
            </div>
            <div class="other-input-container<?= in_array('その他', $v['q3'], true) ? ' visible' : '' ?>" id="q3_other_container">
                <input type="text" name="q3_other" class="other-input"
                       value="<?= $v['q3_other'] ?>" placeholder="具体的にご記入ください"
                       style="min-height:48px;">
            </div>
        </div>

        <!-- ========== Q4 ========== -->
        <div class="form-card">
            <div class="form-card-title">
                <span class="section-badge">Q4</span>ご意見・ご要望がございましたらご記入ください。（任意）
            </div>
            <div class="other-input-container visible">
                <textarea name="q4" class="other-input" rows="4"
                          placeholder="ご自由にご記入ください"><?= $v['q4'] ?></textarea>
            </div>
        </div>

        <!-- ========== 送信ボタン ========== -->
        <div class="submit-area">
            <button type="submit" class="btn-submit">送信する</button>
        </div>

        <!-- ========== スタッフ用入力欄 ========== -->
        <div class="staff-section">
            <p class="staff-section-title">アレクシオンファーマ合同会社使用欄</p>
            <div class="staff-form-group">
                <label for="staff_name">アンケート実施者</label>
                <input type="text" id="staff_name" name="staff_name"
                       value="<?= $v['staff_name'] ?>" placeholder="担当者名">
            </div>
            <div class="staff-form-group">
                <label for="staff_memo">メモ</label>
                <textarea id="staff_memo" name="staff_memo" rows="3"
                          placeholder="メモを入力してください"><?= $v['staff_memo'] ?></textarea>
            </div>
        </div>

    </form>
</div>

<script src="js/app.js?v=20261007a"></script>
</body>
</html>
