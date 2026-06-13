<?php
namespace FluentAuthKakao;

defined( 'ABSPATH' ) || exit;

use FluentAuth\App\Services\AuthService;

class KakaoHandler {

	private const STATE_KEY         = 'fak_oauth_state';
	private const INTENT_COOKIE_KEY = 'fak_intent_redirect';
	private const RATE_LIMIT_KEY    = 'fak_rl_';
	private const RATE_LIMIT_MAX    = 10;
	private const RATE_LIMIT_TTL    = 60;

	private const ERROR_MESSAGES = array(
		'csrf_fail'       => '보안 검증에 실패했습니다. 다시 시도해주세요.',
		'token_fail'      => '카카오 인증에 실패했습니다. 다시 시도해주세요.',
		'userinfo_fail'   => '카카오 사용자 정보를 가져올 수 없습니다. 다시 시도해주세요.',
		'compat_fail'     => 'FluentAuth 버전이 호환되지 않습니다. 플러그인을 업데이트해주세요.',
		'auth_fail'       => '로그인 처리 중 오류가 발생했습니다. 다시 시도해주세요.',
		'signup_disabled' => '신규 가입이 제한되어 있습니다. 기존 계정으로 로그인하거나 관리자에게 문의하세요.',
		'rate_limit'      => '잠시 후 다시 시도해주세요. (요청이 너무 많습니다)',
		'email_mismatch'  => '이미 다른 계정으로 로그인되어 있습니다. 로그아웃 후 다시 시도해주세요.',
	);

	public function register(): void {
		// priority 0: FluentAuth SocialAuthHandler(priority 1)이 Google ?code&state를 가로채기 전에 실행
		add_action( 'login_init', array( $this, 'handleLoginInit' ), 0 );
		add_action( 'login_form', array( $this, 'renderButton' ) );
		add_action( 'login_head', array( $this, 'maybeHideEmailForm' ) );
		add_shortcode( 'fak_kakao_login', array( $this, 'renderShortcode' ) );
	}

	public function handleLoginInit(): void {
		$auth = sanitize_text_field( wp_unslash( $_GET['fak_auth'] ?? '' ) );

		if ( $auth === 'kakao' ) {
			$kakao = new KakaoAuth();
			if ( ! $kakao->isConfigured() ) {
				wp_die( '카카오 로그인 설정이 완료되지 않았습니다. 관리자에게 문의하세요.' );
			}

			if ( $this->isRateLimited() ) {
				$this->loginError( 'rate_limit' );
				return;
			}

			// OAuth 라운드트립 동안 redirect_to를 쿠키로 보존 (Google의 fs_intent_redirect와 동일)
			$redirectTo = sanitize_url( wp_unslash( $_GET['redirect_to'] ?? '' ) );
			if ( $redirectTo ) {
				setcookie(
					self::INTENT_COOKIE_KEY,
					$redirectTo,
					array(
						'expires'  => time() + 3600,
						'path'     => COOKIEPATH,
						'domain'   => COOKIE_DOMAIN,
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}

			$state = wp_generate_password( 32, false );
			set_transient( self::STATE_KEY . '_' . $state, 1, 300 );
			setcookie(
				self::STATE_KEY,
				$state,
				array(
					'expires'  => time() + 300,
					'path'     => COOKIEPATH,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			wp_redirect( $kakao->getAuthorizeUrl( $state ) );
			exit;
		}

		$code  = sanitize_text_field( wp_unslash( $_GET['code']  ?? '' ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

		if ( ! $code || ! $state ) {
			return;
		}

		// 쿠키 없으면 Google 등 다른 소셜 콜백 — FluentAuth에 위임
		$cookieState = sanitize_text_field( wp_unslash( $_COOKIE[ self::STATE_KEY ] ?? '' ) );
		if ( ! $cookieState ) {
			return;
		}

		if ( ! hash_equals( $cookieState, $state ) ) {
			$this->loginError( 'csrf_fail' );
			return;
		}

		$transientKey = self::STATE_KEY . '_' . $state;
		if ( ! get_transient( $transientKey ) ) {
			$this->loginError( 'csrf_fail' );
			return;
		}
		delete_transient( $transientKey );
		setcookie( self::STATE_KEY, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

		$this->processCallback( $code );
	}

	private function processCallback( string $code ): void {
		$kakao = new KakaoAuth();

		$tokenData = $kakao->getToken( $code );
		if ( is_wp_error( $tokenData ) ) {
			$this->loginError( 'token_fail' );
			return;
		}

		$userInfo = $kakao->getUserInfo( $tokenData['access_token'] );
		if ( is_wp_error( $userInfo ) ) {
			$this->loginError( 'userinfo_fail' );
			return;
		}

		if ( ! method_exists( AuthService::class, 'doUserAuth' ) ) {
			$this->loginError( 'compat_fail' );
			return;
		}

		$kakaoUsers = get_users(
			array(
				'meta_key'   => 'fak_kakao_id',
				'meta_value' => $userInfo['id'],
				'number'     => 1,
			)
		);

		if ( ! empty( $kakaoUsers ) ) {
			$email = $kakaoUsers[0]->user_email;
		} elseif ( $userInfo['email_verified'] && $userInfo['email'] ) {
			$email = $userInfo['email'];
		} else {
			$email = self::virtualEmail( $userInfo['id'] );
		}

		$existingUser = ! empty( $kakaoUsers ) ? $kakaoUsers[0] : get_user_by( 'email', $email );
		$isNewUser    = ! $existingUser;

		// 이미 로그인된 사용자가 다른 카카오 계정으로 로그인 시도 시 차단 (Google과 동일)
		if ( is_user_logged_in() && $existingUser && $existingUser->ID !== get_current_user_id() ) {
			$this->loginError( 'email_mismatch' );
			return;
		}

		// 기존 사용자 2FA 게이트 (Google과 동일 — doUserAuth 전에 체크)
		if ( $existingUser && class_exists( '\FluentAuth\App\Hooks\Handlers\TwoFaHandler' ) ) {
			$twoFaHandler = new \FluentAuth\App\Hooks\Handlers\TwoFaHandler();
			if ( $twoFaUrl = $twoFaHandler->sendAndGet2FaConfirmFormUrl( $existingUser ) ) {
				setcookie( self::INTENT_COOKIE_KEY, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
				wp_redirect( $twoFaUrl );
				exit;
			}
		}

		$result = AuthService::doUserAuth(
			array(
				'email'      => $email,
				'first_name' => $userInfo['nickname'],
				'username'   => 'kakao_' . $userInfo['id'],
			),
			'kakao'
		);

		if ( is_wp_error( $result ) ) {
			$errCode = $result->get_error_code() === 'signup_disabled' ? 'signup_disabled' : 'auth_fail';
			$this->loginError( $errCode );
			return;
		}

		update_user_meta( $result->ID, 'fak_kakao_id', $userInfo['id'] );

		// FluentCRM 등록: 실제 이메일이 있는 신규 사용자만 (빈 이메일 → createOrUpdate 예외 방지)
		if ( $isNewUser && $userInfo['email'] ) {
			$this->registerToFluentCrm( $result->ID, $userInfo );
		}

		// 쿠키에서 리다이렉트 목적지 읽고 즉시 삭제 (Google의 fs_intent_redirect와 동일)
		$intentRedirectTo = sanitize_url( wp_unslash( $_COOKIE[ self::INTENT_COOKIE_KEY ] ?? '' ) );
		setcookie( self::INTENT_COOKIE_KEY, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

		// Google과 동일한 역할/멀티사이트 기반 기본 리다이렉트 목적지 결정
		if ( ! $intentRedirectTo ) {
			if ( is_multisite() && ! get_active_blog_for_user( $result->ID ) && ! is_super_admin( $result->ID ) ) {
				$intentRedirectTo = user_admin_url();
			} elseif ( is_multisite() && ! $result->has_cap( 'read' ) ) {
				$intentRedirectTo = get_dashboard_url( $result->ID );
			} elseif ( ! $result->has_cap( 'edit_posts' ) ) {
				$intentRedirectTo = $result->has_cap( 'read' ) ? admin_url( 'profile.php' ) : home_url();
			} else {
				$intentRedirectTo = admin_url();
			}
		}

		$redirect = apply_filters( 'login_redirect', $intentRedirectTo, $intentRedirectTo, $result );
		wp_safe_redirect( $redirect );
		exit;
	}

	private function registerToFluentCrm( int $userId, array $userInfo ): void {
		if ( ! defined( 'FLUENTCRM' ) ) {
			return;
		}
		try {
			$api = \FluentCrmApi( 'contacts' );
			// 이미 존재하는 연락처는 건드리지 않음 — 이름·출처 덮어쓰기 방지
			if ( $api->getContact( $userInfo['email'] ) ) {
				return;
			}
			$api->createOrUpdate(
				array(
					'email'      => $userInfo['email'],
					'first_name' => $userInfo['nickname'],
					'user_id'    => $userId,
					'source'     => 'kakao_login',
				)
			);
		} catch ( \Throwable $e ) {
			error_log( '[Fluent Auth Kakao] FluentCRM 등록 실패: ' . $e->getMessage() );
		}
	}

	public function renderButton(): void {
		$kakao = new KakaoAuth();
		if ( ! $kakao->isConfigured() ) {
			return;
		}

		// Google과 동일: fluent_auth/social_redirect_to 필터 적용
		$redirectTo = apply_filters( 'fluent_auth/social_redirect_to', sanitize_url( wp_unslash( $_REQUEST['redirect_to'] ?? '' ) ) );
		$url        = add_query_arg( 'fak_auth', 'kakao', wp_login_url() );
		if ( $redirectTo ) {
			$url = add_query_arg( 'redirect_to', $redirectTo, $url );
		}
		echo $this->buttonHtml( $url );
	}

	public function renderShortcode( array $atts ): string {
		if ( is_user_logged_in() ) {
			return '';
		}

		$kakao = new KakaoAuth();
		if ( ! $kakao->isConfigured() ) {
			return '';
		}

		$atts       = shortcode_atts( array( 'redirect_to' => '' ), $atts, 'fak_kakao_login' );
		$redirectTo = $atts['redirect_to'] ?: ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( $_SERVER['HTTP_HOST'] ?? '' ) . $_SERVER['REQUEST_URI'];
		$redirectTo = apply_filters( 'fluent_auth/social_redirect_to', $redirectTo );

		$loginUrl = add_query_arg(
			array(
				'fak_auth'    => 'kakao',
				'redirect_to' => $redirectTo,
			),
			wp_login_url()
		);

		return $this->buttonHtml( $loginUrl );
	}

	private function buttonHtml( string $loginUrl ): string {
		ob_start();
		?>
		<div style="text-align:center;margin:8px 0 16px;">
			<a href="<?php echo esc_url( $loginUrl ); ?>"
				style="display:inline-flex;align-items:center;justify-content:center;gap:8px;
						width:100%;padding:10px 16px;background:#FEE500;color:#000000;
						border-radius:6px;font-size:14px;font-weight:600;
						text-decoration:none;box-sizing:border-box;">
				<svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path fill-rule="evenodd" clip-rule="evenodd"
							d="M9 1.5C4.86 1.5 1.5 4.19 1.5 7.5c0 2.1 1.28 3.95 3.22 5.07L3.9 15.3a.28.28 0 0 0 .4.3l3.6-2.38C8.26 13.4 8.63 13.44 9 13.44c4.14 0 7.5-2.69 7.5-5.94S13.14 1.5 9 1.5z"
							fill="#000000"/>
				</svg>
				카카오 로그인
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	// Google과 동일한 방식: 리다이렉트 없이 wp_login_errors 필터로 에러 표시
	private function loginError( string $code ): void {
		$message = self::ERROR_MESSAGES[ $code ] ?? '카카오 로그인 중 오류가 발생했습니다. 다시 시도해주세요.';
		$error   = new \WP_Error( 'kakao_error', $message );
		add_filter(
			'wp_login_errors',
			static function () use ( $error ) {
				return $error;
			}
		);
	}

	private function isRateLimited(): bool {
		$ip   = $this->getClientIp();
		$key  = self::RATE_LIMIT_KEY . md5( $ip );
		$hits = (int) get_transient( $key );
		set_transient( $key, $hits + 1, self::RATE_LIMIT_TTL );
		return $hits >= self::RATE_LIMIT_MAX;
	}

	// REMOTE_ADDR(TCP 피어)만 기본 신뢰 — HTTP 헤더는 클라이언트가 위조 가능.
	// Cloudflare 등 리버스 프록시 뒤라면 fak/client_ip 필터로 재정의:
	// add_filter('fak/client_ip', fn() => sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR']));
	private function getClientIp(): string {
		$ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
		return (string) apply_filters( 'fak/client_ip', $ip );
	}

	public function maybeHideEmailForm(): void {
		$settings = get_option( 'fak_settings', array() );
		if ( ( $settings['hide_email_login'] ?? '' ) !== 'yes' ) {
			return;
		}
		// 카카오 미설정 상태에서 폼 숨기면 버튼도 폼도 없는 잠금 화면이 됨
		if ( ! ( new KakaoAuth() )->isConfigured() ) {
			return;
		}
		?>
		<style>
			/* WordPress 6.x: p.login-username → <p>(no class), p.login-password → div.user-pass-wrap */
			#loginform p:has(#user_login),
			#loginform p.login-username,
			#loginform .user-pass-wrap,
			#loginform p.login-password,
			#loginform .forgetmenot,
			#loginform p.submit,
			.language-switcher,
			#nav, #backtoblog { display: none !important; }
		</style>
		<?php
	}

	private static function virtualEmail( int $kakaoId ): string {
		return 'k' . substr( wp_hash( (string) $kakaoId ), 0, 24 ) . '@kakao.user';
	}
}
