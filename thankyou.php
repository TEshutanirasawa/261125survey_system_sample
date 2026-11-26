<?php
/**
 * IgG4関連疾患に関するアンケート
 * ご協力ありがとうページ
 *
 * survey.php から1回限りのフラグ thanks_ended_early を受け取り、
 * 医師以外で N2「今回初めて知った」により早期終了した場合は疾患展示の案内を表示する。
 */
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/layout.php';
date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

survey_session_start();
$endedEarly = !empty($_SESSION['thanks_ended_early']);
unset($_SESSION['thanks_ended_early']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<?php layout_head('ご協力ありがとうございました | 田辺ファーマ株式会社'); ?>
</head>
<body>

<?php layout_header(); ?>

<main class="thanks-wrapper">

    <?php layout_title(); ?>

    <div class="thanks-card has-corner-deco">
        <div class="thanks-icon" aria-hidden="true">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>

        <h2 class="thanks-title">アンケートのご協力<br>ありがとうございました。</h2>

        <?php if ($endedEarly): ?>
        <p class="closing-msg">IgG4関連疾患について、ブース内の疾患関連の展示をぜひご覧ください。</p>
        <?php else: ?>
        <p class="closing-msg">どうぞブース内にてごゆっくりお過ごしください。</p>
        <?php endif; ?>

        <img class="corner-deco" src="images/tanabe3.png" alt="" aria-hidden="true" width="799" height="493">
    </div>

</main>

<?php layout_footer_deco('images/tanabe2.png', 1280, 136); ?>

<!-- スタッフが次の回答者向けに戻す用 -->
<a href="index.php" class="back-to-top">アンケートページに戻る</a>

<?php layout_script(); ?>

</body>
</html>
