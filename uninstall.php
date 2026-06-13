<?php
// WordPress가 직접 호출할 때만 실행
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 플러그인 설정
delete_option('fak_settings');

// Plugin Update Checker 캐시
delete_option('external_updates-fluent-auth-kakao');

// 모든 사용자의 카카오 ID 연결 정보
$wpdb->delete($wpdb->usermeta, ['meta_key' => 'fak_kakao_id']);

// OAuth state transient + 만료 타임스탬프
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_fak\_%' OR option_name LIKE '\_transient\_timeout\_fak\_%'");
