<?php
namespace FluentAuthKakao;

defined( 'ABSPATH' ) || exit;

class Settings {

	private const OPTION_KEY = 'fak_settings';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRestRoutes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminScript' ) );
	}

	public function registerRestRoutes(): void {
		register_rest_route(
			'fak/v1',
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'restGetSettings' ),
					'permission_callback' => fn() => current_user_can( 'manage_options' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restSaveSettings' ),
					'permission_callback' => fn() => current_user_can( 'manage_options' ),
				),
			)
		);
	}

	public function restGetSettings(): array {
		$s         = get_option( self::OPTION_KEY, array() );
		$keyMethod = $s['key_method'] ?? 'db';
		return array(
			'rest_api_key'     => $keyMethod === 'db' ? ( $s['rest_api_key'] ?? '' ) : '',
			'client_secret'    => $keyMethod === 'db' ? ( $s['client_secret'] ?? '' ) : '',
			'key_method'       => $keyMethod,
			'hide_email_login' => $s['hide_email_login'] ?? 'no',
			'redirect_uri'     => wp_login_url(),
		);
	}

	public function restSaveSettings( \WP_REST_Request $request ): array {
		$data = $this->sanitize( (array) $request->get_json_params() );
		update_option( self::OPTION_KEY, $data, false );
		return array( 'message' => '카카오 로그인 설정이 저장되었습니다.' );
	}

	private function sanitize( array $input ): array {
		$keyMethod = in_array( $input['key_method'] ?? '', array( 'db', 'wp_config' ), true )
			? $input['key_method'] : 'db';
		return array(
			'rest_api_key'     => sanitize_text_field( $input['rest_api_key'] ?? '' ),
			'client_secret'    => sanitize_text_field( $input['client_secret'] ?? '' ),
			'key_method'       => $keyMethod,
			'hide_email_login' => ( $input['hide_email_login'] ?? '' ) === 'yes' ? 'yes' : 'no',
		);
	}

	public function enqueueAdminScript( string $hook ): void {
		if ( $hook !== 'toplevel_page_fluent-auth' ) {
			return;
		}

		wp_enqueue_script( 'fak-admin', FAK_URL . 'assets/js/admin.js', array(), FAK_VERSION . '.' . filemtime( FAK_DIR . 'assets/js/admin.js' ), true );
		wp_localize_script(
			'fak-admin',
			'fakAdmin',
			array(
				'restUrl'     => rest_url( 'fak/v1/settings' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'redirectUri' => wp_login_url(),
			)
		);
	}
}
