<?php
/**
 * 展示ブースアンケート
 * 第1画面：基本情報（ご芳名・ご所属・都道府県）
 * 送信先は survey.php（基本情報の保存・要通知リスト照合・Q1〜Q4表示）
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/csrf.php';

survey_session_start();

// survey.php でサーバー側検証エラーになった場合のエラー・入力値（1回限り）
$flash  = $_SESSION['basic_flash'] ?? null;
unset($_SESSION['basic_flash']);
$errors = $flash['errors'] ?? [];
$old    = $flash['old'] ?? [];

// 二重登録防止用のワンタイムトークン（survey.php で1回だけ消費される）
$_SESSION['basic_nonce'] = bin2hex(random_bytes(16));

// 都道府県リスト
$prefectures = [
    '北海道','青森県','岩手県','宮城県','秋田県','山形県','福島県',
    '茨城県','栃木県','群馬県','埼玉県','千葉県','東京都','神奈川県',
    '新潟県','富山県','石川県','福井県','山梨県','長野県','岐阜県',
    '静岡県','愛知県','三重県','滋賀県','京都府','大阪府','兵庫県',
    '奈良県','和歌山県','鳥取県','島根県','岡山県','広島県','山口県',
    '徳島県','香川県','愛媛県','高知県','福岡県','佐賀県','長崎県',
    '熊本県','大分県','宮崎県','鹿児島県','沖縄県',
];

// 再表示用の入力値
$v = [
    'name'       => htmlspecialchars($old['name'] ?? '', ENT_QUOTES, 'UTF-8'),
    'facility'   => htmlspecialchars($old['facility'] ?? '', ENT_QUOTES, 'UTF-8'),
    'prefecture' => $old['prefecture'] ?? '',
];

function selected_option(string $key, string $val, array $v): string {
    return ($v[$key] === $val) ? ' selected' : '';
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

    <!-- イントロ -->
    <div class="intro-card">
        <div class="intro-logo">
            <img src="images/alexion_logo.png" alt="アレクシオンファーマ合同会社">
        </div>
        <p class="intro-company">アレクシオンファーマ合同会社</p>
        <h1 class="intro-page-title">展示ブースアンケート</h1>
        <p>本日は，弊社の展示ブースにお立ち寄りいただきまして誠にありがとうございます。</p>
        <p>今後のサービス向上のため，下記アンケートにご協力賜りますようお願い致します。ご回答頂いたアンケートの内容は，本件の目的以外には使用いたしません。アンケート結果の取り扱いに関しては、弊社のプライバシーポリシーに則り管理いたします。</p>
        <p class="intro-responsible">アンケート実施者　アレクシオンファーマ合同会社　●●部　●● ●●</p>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="error-msg" style="margin-bottom:20px;">
        <?php echo htmlspecialchars($errors[0], ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <form id="surveyForm" method="post" action="survey.php">
        <?= csrf_field() ?>
        <input type="hidden" name="form_nonce" value="<?= htmlspecialchars($_SESSION['basic_nonce'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="step" value="basic">

        <!-- ========== 基本情報 ========== -->
        <div class="form-card">
            <div class="form-card-title">基本情報</div>

            <!-- 名札OCR -->
            <div class="ocr-area">
                <p class="ocr-desc">名札を撮影してご芳名・ご所属を自動入力</p>

                <div class="ocr-guide" id="ocr-guide">
                    <div class="ocr-guide-frame">
                        <span class="corner corner-tl"></span>
                        <span class="corner corner-tr"></span>
                        <span class="corner corner-bl"></span>
                        <span class="corner corner-br"></span>
                        <span class="ocr-guide-text">名札全体が枠に<br>収まるように撮影してください</span>
                    </div>
                </div>

                <label class="btn-ocr" for="ocr-input" id="ocr-btn-label">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    名札を撮影する
                </label>
                <input type="file" id="ocr-input" accept="image/*" capture="environment" style="display:none;">

                <div class="ocr-preview-wrap" id="ocr-preview-wrap" style="display:none;">
                    <img id="ocr-preview" class="ocr-preview" alt="撮影プレビュー">
                    <button type="button" class="btn-ocr-retake" id="ocr-retake">撮り直す</button>
                </div>

                <p id="ocr-status" class="ocr-status"></p>
            </div>

            <!-- ご芳名 -->
            <div class="form-group">
                <label for="name">ご芳名<span class="required-mark">※</span></label>
                <input type="text" id="name" name="name" value="<?= $v['name'] ?>"
                       placeholder="山田 太郎" autocomplete="name">
            </div>

            <!-- ご所属 -->
            <div class="form-group">
                <label for="facility">ご所属<span class="required-mark">※</span></label>
                <input type="text" id="facility" name="facility" value="<?= $v['facility'] ?>"
                       placeholder="○○病院" autocomplete="organization">
            </div>

            <!-- 都道府県 -->
            <div class="form-group">
                <label for="prefecture">都道府県<span class="required-mark">※</span></label>
                <select id="prefecture" name="prefecture">
                    <option value="">-- 選択してください --</option>
                    <?php foreach ($prefectures as $pref): ?>
                    <option value="<?= htmlspecialchars($pref, ENT_QUOTES, 'UTF-8') ?>"<?= selected_option('prefecture', $pref, $v) ?>>
                        <?= htmlspecialchars($pref, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- ========== 送信ボタン ========== -->
        <div class="submit-area">
            <button type="submit" class="btn-submit" data-loading-text="処理中...">アンケートへ進む</button>
        </div>

    </form>
</div>

<script src="js/app.js?v=20261007a"></script>
</body>
</html>
