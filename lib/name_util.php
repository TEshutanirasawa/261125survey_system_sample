<?php
/**
 * 氏名の正規化・照合用ハッシュ
 *
 * 氏名は AES-256-CBC（IVランダム）で暗号化保存しているため、同じ氏名でも毎回異なる暗号文になり
 * 暗号文同士では比較できない。照合専用に「正規化した氏名の HMAC-SHA256」を別カラムに持たせ、
 * ハッシュ同士を比較する。
 *
 * ハッシュキー NAME_HASH_KEY は config.php で定義する。
 */

/**
 * 氏名を照合用に正規化する
 */
function normalizeName(string $name): string {
    // 1) 前後の空白を除去
    $s = trim($name);

    // 2) Unicode正規化（NFKC）。Normalizerが無い環境ではmb_convert_kanaで代替
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_KC);
        if ($s === false) {
            $s = trim($name); // 不正なUTF-8等で正規化に失敗した場合は元の値で続行
        }
    } else {
        // a: 全角英数 → 半角 / s: 全角スペース → 半角
        // K: 半角カナ → 全角カナ / V: 濁点・半濁点を結合
        $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    }

    // 3) 全種類の空白を完全に除去（半角/全角スペース・タブ・改行）
    $s = preg_replace('/[\s\x{3000}]+/u', '', $s) ?? $s;

    // 4) 英字は小文字に統一
    $s = mb_strtolower($s, 'UTF-8');

    return $s;
}

/**
 * 正規化した氏名から照合用ハッシュを生成する
 */
function nameHash(string $name): string {
    return hash_hmac('sha256', normalizeName($name), NAME_HASH_KEY);
}

/**
 * 照合用ハッシュキーが設定済みか（未設定なら照合・通知・リスト登録を行わない）
 */
function nameHashEnabled(): bool {
    return defined('NAME_HASH_KEY') && NAME_HASH_KEY !== '';
}
