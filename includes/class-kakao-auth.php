<?php
namespace FluentAuthKakao;

defined('ABSPATH') || exit;

class KakaoAuth {

    private const AUTHORIZE_URL = 'https://kauth.kakao.com/oauth/authorize';
    private const TOKEN_URL     = 'https://kauth.kakao.com/oauth/token';
    private const USER_INFO_URL = 'https://kapi.kakao.com/v2/user/me';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct() {
        $settings          = get_option('fak_settings', []);
        $this->clientId     = sanitize_text_field($settings['rest_api_key'] ?? '');
        $this->clientSecret = sanitize_text_field($settings['client_secret'] ?? '');
        $this->redirectUri  = wp_login_url();
    }

    public function isConfigured(): bool {
        return !empty($this->clientId);
    }

    public function getAuthorizeUrl(string $state): string {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'state'         => $state,
            'scope'         => 'profile_nickname,account_email',
        ]);
    }

    /**
     * @return array{access_token: string}|WP_Error
     */
    public function getToken(string $code): array|\WP_Error {
        $body = [
            'grant_type'   => 'authorization_code',
            'client_id'    => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'code'         => $code,
        ];
        if ($this->clientSecret) {
            $body['client_secret'] = $this->clientSecret;
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'body'    => $body,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return $response;

        $status = wp_remote_retrieve_response_code($response);
        $data   = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new \WP_Error('kakao_token_parse', '토큰 응답 파싱 실패 (HTTP ' . $status . ')');
        }
        if (empty($data['access_token'])) {
            return new \WP_Error('kakao_token_error', $data['error_description'] ?? '토큰 발급 실패 (HTTP ' . $status . ')');
        }
        return $data;
    }

    /**
     * @return array{id: int, email: string, nickname: string}|WP_Error
     */
    public function getUserInfo(string $accessToken): array|\WP_Error {
        $response = wp_remote_get(self::USER_INFO_URL, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return $response;

        $status = wp_remote_retrieve_response_code($response);
        $data   = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['id'])) {
            return new \WP_Error('kakao_user_error', '사용자 정보 조회 실패 (HTTP ' . $status . ')');
        }

        $account  = $data['kakao_account'] ?? [];
        $profile  = $account['profile'] ?? [];
        $email    = sanitize_email($account['email'] ?? '');
        $nickname = sanitize_text_field($profile['nickname'] ?? '');

        // 이메일 미제공 시 빈 문자열 반환 — 가상 이메일은 핸들러가 생성
        if (!$email) {
            $email = '';
        }

        // 카카오가 이메일 소유권을 검증했는지 확인 (계정 탈취 방지 핵심)
        $emailVerified = !empty($account['is_email_valid']) && !empty($account['is_email_verified']);

        return [
            'id'             => (int) $data['id'],
            'email'          => $email,
            'email_verified' => $emailVerified,
            'nickname'       => $nickname ?: 'kakao_' . $data['id'],
        ];
    }
}
