<?php
/**
 * IgG4関連疾患に関するアンケート
 * 第2画面：アンケート本体（1問1画面のステップ形式・設問分岐・スライド表示・スタッフ使用欄）
 *
 *   POST step=basic  … index.php から。基本情報を保存 → GET survey.php へリダイレクト（PRG）
 *   GET              … セッションの response_id のレコードについて設問を表示
 *   POST step=answer … lib/questions.php の定義でサーバー側検証 → 既存レコードを UPDATE（status=completed）→ thankyou.php
 *
 * 設問・選択肢・分岐条件・スライドは lib/questions.php で定義する。
 * 画面は1つのフォームの中に全ステップを持ち、js/app.js で1ステップずつ表示する（送信は最後に1回だけ）。
 * 一方通行：response_id が無い GET / 不正な POST は index.php へ戻す。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/questions.php';
require_once __DIR__ . '/lib/layout.php';

survey_session_start();

function redirect_to(string $path): void {
    header('Location: ' . $path);
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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

/**
 * POST値を「文字列」または「文字列の配列」に正規化する（想定外の型は空扱い）
 */
function posted_single(string $key): string {
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}
function posted_multi(string $key): array {
    $v = $_POST[$key] ?? [];
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $item) {
        if (is_string($item) && trim($item) !== '') $out[] = trim($item);
    }
    return array_values(array_unique($out));
}

/**
 * 1問分の回答を lib/questions.php の定義で検証する。
 * 戻り値：[保存する文字列, エラー文言（無ければ null）]
 */
function validate_question(array $q, $raw): array {
    if ($q['type'] === 'single') {
        if ($raw === '') return ['', '1つ選択してください'];
        if (!in_array($raw, $q['options'], true)) return ['', '選択内容が正しくありません。選び直してください'];
        return [$raw, null];
    }

    // multi / max2
    if (count($raw) === 0) return ['', '1つ以上選択してください'];
    foreach ($raw as $item) {
        if (!in_array($item, $q['options'], true)) return ['', '選択内容が正しくありません。選び直してください'];
    }
    if ($q['type'] === 'max2' && count($raw) > 2) return ['', '2つまで選択してください'];
    foreach ($q['exclusive'] as $ex) {
        if (in_array($ex, $raw, true) && count($raw) > 1) {
            return ['', '「' . $ex . '」は他の選択肢と同時に選べません'];
        }
    }
    // 保存順は定義の選択肢順にそろえる
    $ordered = array_values(array_filter($q['options'], fn($o) => in_array($o, $raw, true)));
    return [implode('、', $ordered), null];
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
            INSERT INTO surveys (created_at, name, facility, prefecture, status)
            VALUES (:created_at, :name, :facility, :prefecture, 'basic')
        ");
        $stmt->execute([
            ':created_at' => date('Y-m-d H:i:s'),
            ':name'       => encrypt_data($name),
            ':facility'   => encrypt_data($facility),
            ':prefecture' => $prefecture,
        ]);
        $responseId = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        $back(['データの保存に失敗しました。もう一度お試しください。']);
    }

    $_SESSION['response_id'] = $responseId;
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

$record      = load_basic_record();
$posted      = ($method === 'POST');
$stepErrors  = [];   // ステップID => エラー文言（経路順に入る）
$globalError = '';

// 再表示用の入力値（設問ID => 文字列 or 配列）
$old = ['respondent_type' => '', 'staff_name' => '', 'staff_memo' => ''];
foreach ($QUESTIONS as $qid => $q) {
    $old[$qid] = ($q['type'] === 'single') ? '' : [];
}

if ($posted) {
    $old['respondent_type'] = posted_single('respondent_type');
    foreach ($QUESTIONS as $qid => $q) {
        $old[$qid] = ($q['type'] === 'single') ? posted_single($qid) : posted_multi($qid);
    }
    $old['staff_name'] = posted_single('staff_name');
    $old['staff_memo'] = posted_single('staff_memo');

    $rt = $old['respondent_type'];
    if (!array_key_exists($rt, $RESPONDENT_TYPES)) {
        $stepErrors['type'] = '1つ選択してください';
        $rt = '';
    }

    // 経路はサーバー側で決める（クライアントから送られた経路は使わない）
    $route      = '';
    $endedEarly = false;
    if ($rt === 'doctor') {
        $route = in_array($old['d4'], $D4_TOP2_OPTIONS, true) ? 'top2' : 'bottom2';
    } elseif ($rt === 'other') {
        $endedEarly = ($old['n2'] === $N2_END_OPTION);
    }

    // 経路外の設問は送られてきても空として保存する
    $save = array_fill_keys(array_keys($QUESTIONS), '');
    foreach (question_path($rt, $route, $endedEarly) as $qid) {
        [$value, $err] = validate_question($QUESTIONS[$qid], $old[$qid]);
        if ($err !== null) {
            $stepErrors[$qid] = $err;
        } else {
            $save[$qid] = mb_substr($value, 0, 1000);
        }
    }

    if (empty($stepErrors)) {
        try {
            $sets = [];
            $params = [];
            foreach ($save as $qid => $value) {
                $sets[] = "{$qid} = :{$qid}"; // 設問IDは lib/questions.php の定義（固定値）のみ
                $params[":{$qid}"] = $value;
            }
            $pdo  = getDb();
            $stmt = $pdo->prepare("
                UPDATE surveys SET
                    respondent_type = :respondent_type, route = :route, ended_early = :ended_early,
                    " . implode(', ', $sets) . ",
                    staff_name = :staff_name, staff_memo = :staff_memo,
                    status = 'completed', completed_at = :completed_at
                WHERE id = :id AND status = 'basic'
            ");
            $stmt->execute($params + [
                ':respondent_type' => $rt,
                ':route'           => $route,
                ':ended_early'     => $endedEarly ? 1 : 0,
                ':staff_name'      => encrypt_data(mb_substr($old['staff_name'], 0, 100)),
                ':staff_memo'      => encrypt_data(mb_substr($old['staff_memo'], 0, 1000)),
                ':completed_at'    => date('Y-m-d H:i:s'),
                ':id'              => (int)$record['id'],
            ]);

            unset($_SESSION['response_id']);
            $_SESSION['thanks_ended_early'] = $endedEarly; // thankyou.php で1回だけ読む
            redirect_to('thankyou.php');
        } catch (Exception $e) {
            $globalError = 'データの保存に失敗しました。もう一度お試しください。';
        }
    }
}

// エラー時は最初にエラーが出たステップを開く（保存失敗時は最後のステップ）
$initialStep = '';
if ($stepErrors) {
    $initialStep = (string)array_key_first($stepErrors);
} elseif ($globalError !== '') {
    $initialStep = '__last__';
}

function is_checked(string $qid, string $val, array $old): string {
    $v = $old[$qid] ?? '';
    $hit = is_array($v) ? in_array($val, $v, true) : ($v === $val);
    return $hit ? ' checked' : '';
}

/**
 * 設問1ステップ分の HTML
 */
function render_question_step(string $qid, array $q, string $badge, array $old, array $stepErrors): void {
    $isSingle = ($q['type'] === 'single');
    $listCls  = $isSingle ? 'radio-list' : 'checkbox-list';
    $itemCls  = $isSingle ? 'radio-item' : 'checkbox-item';
    $labelCls = $isSingle ? 'radio-label' : 'checkbox-label';
    $inputType = $isSingle ? 'radio' : 'checkbox';
    $inputName = $isSingle ? $qid : $qid . '[]';
    $err = $stepErrors[$qid] ?? '';
    ?>
    <section class="step" data-step="<?= h($qid) ?>" hidden>
        <div class="form-card question-card">
            <div class="form-card-title">
                <span class="section-badge"><?= h($badge) ?></span><?= h($q['text']) ?><span class="q-suffix"><?= h(question_suffix($q['type'])) ?></span>
            </div>
            <div class="<?= $listCls ?>" data-qtype="<?= h($q['type']) ?>">
                <?php foreach ($q['options'] as $opt): ?>
                <label class="<?= $itemCls ?>">
                    <input type="<?= $inputType ?>" name="<?= h($inputName) ?>" value="<?= h($opt) ?>"<?= in_array($opt, $q['exclusive'], true) ? ' data-exclusive="1"' : '' ?><?= is_checked($qid, $opt, $old) ?>>
                    <span class="<?= $labelCls ?>"><?= h($opt) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="step-error" role="alert"<?= $err === '' ? ' hidden' : '' ?>><?= h($err) ?></p>
        </div>
    </section>
    <?php
}

/**
 * スライド表示1ステップ分の HTML（画像が空ならプレースホルダー）
 */
function render_slide_step(string $stepId, string $title, string $altPrefix, array $slides, string $placeholder): void {
    ?>
    <section class="step step-slide" data-step="<?= h($stepId) ?>" hidden>
        <div class="form-card slide-card">
            <div class="form-card-title"><?= h($title) ?></div>
            <?php if (empty($slides)): ?>
            <div class="slide-placeholder"><span><?= h($placeholder) ?></span></div>
            <?php else: ?>
            <div class="carousel" data-carousel>
                <div class="carousel-viewport" tabindex="0" aria-label="<?= h($title) ?>（左右にスワイプで切り替え）">
                    <?php foreach ($slides as $i => $src): ?>
                    <div class="carousel-slide">
                        <img src="<?= h($src) ?>" alt="<?= h($altPrefix . ' ' . ($i + 1)) ?>"<?= $i > 0 ? ' loading="lazy"' : '' ?> data-zoomable>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($slides) > 1): ?>
                <button type="button" class="carousel-btn carousel-prev" aria-label="前のスライド">&#8249;</button>
                <button type="button" class="carousel-btn carousel-next" aria-label="次のスライド">&#8250;</button>
                <?php endif; ?>
                <div class="carousel-counter" aria-live="polite"><span class="carousel-current">1</span> / <?= count($slides) ?></div>
            </div>
            <p class="slide-hint">画像をタップすると拡大表示します</p>
            <?php endif; ?>
        </div>
    </section>
    <?php
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<?php layout_head('IgG4関連疾患に関するアンケート | 田辺ファーマ株式会社'); ?>
</head>
<body>

<?php layout_header(); ?>

<main class="survey-wrapper">

    <?php layout_title(); ?>

    <?php if ($globalError !== ''): ?>
    <div class="error-msg"><?= h($globalError) ?></div>
    <?php endif; ?>

    <form id="surveyForm" class="stepper" method="post" action="survey.php"
          data-d4-top2="<?= h(json_encode($D4_TOP2_OPTIONS, JSON_UNESCAPED_UNICODE)) ?>"
          data-n2-end="<?= h($N2_END_OPTION) ?>"
          data-initial-step="<?= h($initialStep) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="answer">

        <!-- 進捗バー -->
        <div class="progress" aria-hidden="true">
            <div class="progress-track"><div class="progress-fill"></div></div>
            <span class="progress-text"></span>
        </div>

        <!-- ========== 職種選択（全員） ========== -->
        <section class="step" data-step="type" hidden>
            <!-- 基本情報（確認表示・読み取り専用）：最初のステップにだけ表示 -->
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

            <div class="form-card question-card">
                <div class="form-card-title"><?= h($RESPONDENT_TYPE_QUESTION) ?></div>
                <div class="radio-list" data-qtype="single">
                    <?php foreach ($RESPONDENT_TYPES as $val => $label): ?>
                    <label class="radio-item">
                        <input type="radio" name="respondent_type" value="<?= h($val) ?>"<?= $old['respondent_type'] === $val ? ' checked' : '' ?>>
                        <span class="radio-label"><?= h($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="step-error" role="alert"<?= isset($stepErrors['type']) ? '' : ' hidden' ?>><?= h($stepErrors['type'] ?? '') ?></p>
            </div>
        </section>

        <!-- ========== 医師向け ========== -->
        <?php
        $n = 0;
        foreach ($QUESTIONS as $qid => $q) {
            if ($q['group'] !== 'doctor') continue;
            $n++;
            render_question_step($qid, $q, 'Q' . $n, $old, $stepErrors);
            if ($qid === 'd4') {
                // route=bottom2 のときだけ通る
                render_slide_step('s_kei', 'ステロイド治療の課題', 'ステロイド治療の課題スライド', $SLIDES_KEIHATSU,
                                  'ステロイド治療による問題点 啓発スライド（準備中）');
            }
            if ($qid === 'd5') {
                // top2 は D5 の後、bottom2 は啓発スライドの後に通る
                render_slide_step('s_mit', 'MITIGATE試験のご紹介', 'MITIGATE試験スライド', $SLIDES_MITIGATE, '');
            }
        }
        ?>

        <!-- ========== 医師以外の医療関係者向け ========== -->
        <?php
        $n = 0;
        foreach ($QUESTIONS as $qid => $q) {
            if ($q['group'] !== 'other') continue;
            $n++;
            render_question_step($qid, $q, 'Q' . $n, $old, $stepErrors);
        }
        ?>

        <!-- ========== 戻る／次へ ========== -->
        <div class="step-nav">
            <button type="button" class="btn-back">戻る</button>
            <button type="button" class="btn-next">次へ</button>
        </div>

        <!-- ========== スタッフ用入力欄（最後のステップでのみ表示） ========== -->
        <div class="staff-section" hidden>
            <p class="staff-section-title">田辺ファーマ株式会社使用欄</p>
            <div class="staff-form-group">
                <label for="staff_name">アンケート実施者</label>
                <input type="text" id="staff_name" name="staff_name"
                       value="<?= h($old['staff_name']) ?>" placeholder="担当者名">
            </div>
            <div class="staff-form-group">
                <label for="staff_memo">メモ</label>
                <textarea id="staff_memo" name="staff_memo" rows="3"
                          placeholder="メモを入力してください"><?= h($old['staff_memo']) ?></textarea>
            </div>
        </div>

    </form>
</main>

<?php layout_footer_deco('images/tanabe4.png', 1280, 259); ?>

<!-- スライドの全画面表示 -->
<div class="lightbox" id="lightbox" hidden role="dialog" aria-modal="true" aria-label="スライドの拡大表示">
    <button type="button" class="lightbox-close" aria-label="閉じる">&times;</button>
    <img class="lightbox-img" alt="">
</div>

<?php layout_script(); ?>
</body>
</html>
