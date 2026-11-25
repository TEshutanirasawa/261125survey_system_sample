<?php
/**
 * IgG4関連疾患に関するアンケート
 * ご協力ありがとうページ
 *
 * survey.php から1回限りのフラグ thanks_ended_early を受け取り、
 * 医師以外で N2「今回初めて知った」により早期終了した場合は疾患展示の案内を表示する。
 */
require_once __DIR__ . '/lib/csrf.php';
date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

survey_session_start();
$endedEarly = !empty($_SESSION['thanks_ended_early']);
unset($_SESSION['thanks_ended_early']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ご協力ありがとうございました | 田辺ファーマ株式会社</title>
    <link rel="stylesheet" href="css/style.css?v=20261007b">
</head>
<body>

<div class="thanks-wrapper">

    <!-- ロゴ -->
    <div class="intro-logo" style="margin-bottom:28px;">
        <img src="images/tanabe_logo.png" alt="田辺ファーマ株式会社">
    </div>

    <!-- アイコン & タイトル -->
    <div class="thanks-icon">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
            <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
    </div>

    <h1 class="thanks-title">アンケートのご協力<br>ありがとうございました。</h1>

    <?php if ($endedEarly): ?>
    <p class="closing-msg">IgG4関連疾患について、ブース内の疾患関連の展示をぜひご覧ください。</p>
    <?php else: ?>
    <p class="closing-msg">どうぞブース内にてごゆっくりお過ごしください。</p>
    <?php endif; ?>

</div>

<a href="index.php" style="
    position: fixed;
    bottom: 20px;
    right: 20px;
    font-size: 12px;
    color: #999;
    border: 1px solid #ccc;
    border-radius: 6px;
    padding: 7px 14px;
    text-decoration: none;
    background: #fff;
    opacity: 0.8;
">アンケートページに戻る</a>

</body>
</html>
