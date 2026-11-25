<?php
/**
 * 共通レイアウト部品（index.php・survey.php・thankyou.php）
 *   白地のヘッダー（左：学会名／右：企業ロゴ）＋ヘッダー直下の装飾画像、ページタイトル、ページ最下部の装飾画像
 */

// css/js のキャッシュ対策（更新時はこの値を変える）
const ASSET_VERSION = '20261125a';

const EVENT_NAME   = '第71回日本リウマチ学会総会・学術集会';
const COMPANY_NAME = '田辺ファーマ株式会社';
const PAGE_TITLE   = 'IgG4関連疾患に関するアンケート';

function layout_head(string $title): void {
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="css/style.css?v=<?= ASSET_VERSION ?>">
    <?php
}

function layout_header(): void {
    ?>
    <header class="site-header">
        <div class="site-header-inner">
            <span class="site-event"><?= htmlspecialchars(EVENT_NAME, ENT_QUOTES, 'UTF-8') ?></span>
            <img class="site-logo" src="images/tanabe_logo.png" alt="<?= htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') ?>" width="208" height="27">
        </div>
    </header>
    <img class="deco deco-top" src="images/tanabe1.png" alt="" aria-hidden="true" width="1280" height="117">
    <?php
}

function layout_title(): void {
    ?>
    <h1 class="page-title"><?= htmlspecialchars(PAGE_TITLE, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php
}

/**
 * ページ最下部の装飾画像（index/survey：tanabe4.png、thankyou：tanabe2.png）
 */
function layout_footer_deco(string $src, int $w, int $h): void {
    ?>
    <img class="deco deco-bottom" src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true" width="<?= $w ?>" height="<?= $h ?>">
    <?php
}

function layout_script(): void {
    ?>
    <script src="js/app.js?v=<?= ASSET_VERSION ?>"></script>
    <?php
}
