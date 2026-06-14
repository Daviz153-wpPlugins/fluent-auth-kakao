<?php
namespace FluentAuthKakao;

defined( 'ABSPATH' ) || exit;

class KakaoAuth {

	private const AUTHORIZE_URL = 'https://kauth.kakao.com/oauth/authorize';
	private const TOKEN_URL     = 'https://kauth.kakao.com/oauth/token';
	private const USER_INFO_URL = 'https://kapi.kakao.com/v2/user/me';

	private string $clientId;
	private string $clientSecret;
	private string $redirectUri;

	public function __construct() {
		$settings  = get_option( 'fak_settings', array() );
		$keyMethod = $settings['key_method'] ?? 'db';

		if ( $keyMethod === 'wp_config' ) {
			$this->clientId     = defined( 'FAK_REST_API_KEY' ) ? FAK_REST_API_KEY : '';
			$this->clientSecret = defined( 'FAK_CLIENT_SECRET' ) ? FAK_CLIENT_SECRET : '';
		} else {
			$this->clientId     = sanitize_text_field( $settings['rest_api_key'] ?? '' );
			$this->clientSecret = sanitize_text_field( $settings['client_secret'] ?? '' );
		}
		$this->redirectUri = wp_login_url();
	}

	public function isConfigured(): bool {
		return ! empty( $this->clientId );
	}

	public function getAuthorizeUrl( string $state ): string {
		return self::AUTHORIZE_URL . '?' . http_build_query(
			array(
				'client_id'     => $this->clientId,
				'redirect_uri'  => $this->redirectUri,
				'response_type' => 'code',
				'state'         => $state,
				'scope'         => 'profile_nickname,account_email',
			)
		);
	}

	/**
	 * @return array{access_token: string}|WP_Error
	 */
	public function getToken( string $code ): array|\WP_Error {
		$body = array(
			'grant_type'   => 'authorization_code',
			'client_id'    => $this->clientId,
			'redirect_uri' => $this->redirectUri,
			'code'         => $code,
		);
		if ( $this->clientSecret ) {
			$body['client_secret'] = $this->clientSecret;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'kakao_token_parse', '토큰 응답 파싱 실패 (HTTP ' . $status . ')' );
		}
		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'kakao_token_error', $data['error_description'] ?? '토큰 발급 실패 (HTTP ' . $status . ')' );
		}
		return $data;
	}

	/**
	 * @return array{id: int, email: string, nickname: string}|WP_Error
	 */
	public function getUserInfo( string $accessToken ): array|\WP_Error {
		$response = wp_remote_get(
			self::USER_INFO_URL,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $accessToken ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return new \WP_Error( 'kakao_user_error', '사용자 정보 조회 실패 (HTTP ' . $status . ')' );
		}

		$account = $data['kakao_account'] ?? array();

		$profile  = $account['profile'] ?? array();
		$email    = sanitize_email( $account['email'] ?? '' );
		$nickname = sanitize_text_field( $profile['nickname'] ?? '' );

		// 둘 다 true여야 소유 검증 완료 — 미검증 이메일로 기존 계정 탈취 방지
		$emailVerified = ! empty( $account['is_email_valid'] ) && ! empty( $account['is_email_verified'] );

		return array(
			'id'             => (int) $data['id'],
			'email'          => $email,
			'email_verified' => $emailVerified,
			'nickname'       => $nickname ?: 'kakao_' . $data['id'],
		);
	}
}
