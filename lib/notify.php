<?php
/**
 * 要通知リスト照合 & 来訪通知メール
 *
 * 通知は「あれば嬉しい付加機能」。ここで起きた失敗（キー未設定・DBエラー・送信失敗）は
 * data/mail_error.log に記録するだけで、呼び出し元（アンケート画面）には絶対に影響させない。
 */

mb_language('Japanese');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/name_util.php';

const NOTIFY_MAIL_FROM = 'info@totaledge-software.jp';
const NOTIFY_ADMIN_URL = 'https://totaledge-software.jp/survey/261007survey_system_sample/admin.php';
define('MAIL_ERROR_LOG', dirname(__DIR__) . '/data/mail_error.log');

/**
 * メールアドレスをログ用にマスクする（例: info@totaledge-software.jp → in***@tot***.jp）
 */
function mask_email(string $email): string {
    $at = strrpos($email, '@');
    if ($at === false) {
        return substr($email, 0, 2) . '***';
    }
    $local  = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    $dot    = strrpos($domain, '.');
    $host   = ($dot === false) ? $domain : substr($domain, 0, $dot);
    $tld    = ($dot === false) ? '' : substr($domain, $dot);
    return substr($local, 0, 2) . '***@' . substr($host, 0, 3) . '***' . $tld;
}

/**
 * data/mail_error.log に1行追記する（個人情報は書かない。宛先はマスクして渡すこと）
 */
function mail_error_log(string $type, string $detail = ''): void {
    $now  = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $line = '[' . $now . '] ' . $type . ($detail !== '' ? ' ' . $detail : '') . "\n";
    @file_put_contents(MAIL_ERROR_LOG, $line, FILE_APPEND | LOCK_EX);
    error_log('[survey notify] ' . $type . ($detail !== '' ? ' ' . $detail : ''));
}

/**
 * 来訪者が要通知リストに登録されていれば担当者へ通知メールを送る。
 * 例外は外へ投げない。戻り値は送信成功件数。
 */
function notify_registered_visitor(PDO $pdo, int $responseId, string $name, string $facility, string $prefecture): int {
    try {
        if (!nameHashEnabled()) {
            mail_error_log('SKIP_NO_KEY', 'NAME_HASH_KEY 未設定のため照合・通知をスキップ response_id=' . $responseId);
            return 0;
        }

        $stmt = $pdo->prepare('SELECT id, email_enc, staff_name_enc FROM notify_list WHERE is_deleted = 0 AND name_hash = :h ORDER BY id');
        $stmt->execute([':h' => nameHash($name)]);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$targets) {
            return 0;
        }

        $receivedAt = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y年n月j日 H:i');
        $sent = 0;
        foreach ($targets as $t) {
            $to = decrypt_data((string)$t['email_enc']);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                mail_error_log('INVALID_ADDRESS', 'list_id=' . (int)$t['id'] . ' response_id=' . $responseId);
                continue;
            }
            try {
                $ok = send_visit_notification($to, decrypt_data((string)($t['staff_name_enc'] ?? '')),
                                              $name, $facility, $prefecture, $receivedAt);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
            } else {
                mail_error_log('SEND_FAILED', 'to=' . mask_email($to) . ' list_id=' . (int)$t['id'] . ' response_id=' . $responseId);
            }
        }

        if ($sent > 0) {
            $upd = $pdo->prepare('UPDATE surveys SET notified = 1, notified_at = :at WHERE id = :id');
            $upd->execute([':at' => date('Y-m-d H:i:s'), ':id' => $responseId]);
        }
        return $sent;
    } catch (\Throwable $e) {
        mail_error_log('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' response_id=' . $responseId);
        return 0;
    }
}

/**
 * 来訪通知メールを1通送信する
 */
function send_visit_notification(string $to, string $staffName, string $name, string $facility,
                                 string $prefecture, string $receivedAt): bool {
    // ヘッダーインジェクション対策（件名に入る値から改行を除去）
    $oneLine = fn(string $s): string => trim(preg_replace('/[\r\n]+/', ' ', $s));

    $subject = '【展示ブース】登録先生のご来訪通知：' . $oneLine($name) . ' 様';

    $body = '';
    if ($staffName !== '') {
        $body .= $oneLine($staffName) . " 様\n\n";
    }
    $body .= "登録済みの先生がアンケートにご記入されました。\n"
           . "\n"
           . "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
           . '■ ご芳名　：' . $oneLine($name) . "\n"
           . '■ ご所属　：' . $oneLine($facility) . "\n"
           . '■ 都道府県：' . $oneLine($prefecture) . "\n"
           . '■ 受付日時：' . $receivedAt . "（JST）\n"
           . "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
           . "\n"
           . '管理画面：' . NOTIFY_ADMIN_URL . "\n"
           . "\n"
           . "※本メールは自動送信です。ご返信いただいてもお答えできません。\n";

    $headers = 'From: ' . NOTIFY_MAIL_FROM . "\r\n";

    return (bool) @mb_send_mail($to, $subject, $body, $headers, '-f ' . NOTIFY_MAIL_FROM);
}
