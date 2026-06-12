<?php
namespace FluentAuthKakao;

defined('ABSPATH') || exit;

use FluentAuth\App\Services\AuthService;

class KakaoHandler {

    private const STATE_KEY    = 'fak_oauth_state';
    private const RATE_LIMIT_KEY = 'fak_rl_';
    private const RATE_LIMIT_MAX = 5;   // 분당 최대 인증 요청 수
    private const RATE_LIMIT_TTL = 60;  // 초

    private const ERROR_MESSAGES = [
        'csrf_fail'     => '보안 검증에 실패했습니다. 다시 시도해주세요.',
        'token_fail'    => '카카오 인증에 실패했습니다. 다시 시도해주세요.',
        'userinfo_fail' => '카카오 사용자 정보를 가져올 수 없습니다. 다시 시도해주세요.',
        'compat_fail'   => 'FluentAuth 버전이 호환되지 않습니다. 플러그인을 업데이트해주세요.',
        'auth_fail'     => '로그인 처리 중 오류가 발생했습니다. 다시 시도해주세요.',
    ];

    public function register(): void {
        add_action('login_init',   [$this, 'handleLoginInit']);
        add_action('login_form',   [$this, 'renderButton']);
        add_filter('wp_login_errors', [$this, 'addLoginError']);
    }

    public function handleLoginInit(): void {
        $auth = sanitize_text_field($_GET['fak_auth'] ?? '');

        // Step 1: 카카오 인증 페이지로 리다이렉트
        if ($auth === 'kakao') {
            $kakao = new KakaoAuth();
            if (!$kakao->isConfigured()) {
                wp_die('카카오 로그인 설정이 완료되지 않았습니다. 관리자에게 문의하세요.');
            }

            $this->checkRateLimit();

            $state = wp_generate_password(32, false);
            set_transient(self::STATE_KEY . '_' . $state, 1, 300);
            // 로그인 CSRF 방지: state를 HttpOnly 쿠키에도 바인딩
            setcookie(self::STATE_KEY, $state, [
                'expires'  => time() + 300,
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            wp_redirect($kakao->getAuthorizeUrl($state));
            exit;
        }

        // Step 2: 카카오 콜백 처리
        $code  = sanitize_text_field($_GET['code']  ?? '');
        $state = sanitize_text_field($_GET['state'] ?? '');

        if (!$code || !$state) return;

        // 쿠키 바인딩 검증 (공격자 code+state를 피해자에게 전달하는 CSRF 차단)
        $cookieState = sanitize_text_field($_COOKIE[self::STATE_KEY] ?? '');
        if (!$cookieState || !hash_equals($cookieState, $state)) {
            $this->loginError('csrf_fail');
        }

        $transientKey = self::STATE_KEY . '_' . $state;
        if (!get_transient($transientKey)) {
            $this->loginError('csrf_fail');
        }
        delete_transient($transientKey);
        setcookie(self::STATE_KEY, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

        $this->processCallback($code);
    }

    private function processCallback(string $code): void {
        $kakao = new KakaoAuth();

        $tokenData = $kakao->getToken($code);
        if (is_wp_error($tokenData)) {
            $this->loginError('token_fail');
        }

        $userInfo = $kakao->getUserInfo($tokenData['access_token']);
        if (is_wp_error($userInfo)) {
            $this->loginError('userinfo_fail');
        }

        if (!method_exists(AuthService::class, 'doUserAuth')) {
            $this->loginError('compat_fail');
        }

        // 1) 카카오 ID로 먼저 조회 — 가장 신뢰도 높음
        $kakaoUsers = get_users([
            'meta_key'   => 'fak_kakao_id',
            'meta_value' => $userInfo['id'],
            'number'     => 1,
        ]);

        if (!empty($kakaoUsers)) {
            // 이미 연결된 계정 → WP 이메일 사용 (카카오 이메일 신뢰 불필요)
            $email = $kakaoUsers[0]->user_email;
        } elseif ($userInfo['email_verified']) {
            // 카카오가 검증한 이메일만 기존 계정 연결에 사용 (미검증 이메일 = 계정 탈취 위험)
            $email = $userInfo['email'];
        } else {
            // 미검증/미제공 이메일 → 해시 기반 가상 이메일 (카카오 ID 역추적 불가)
            $email = self::virtualEmail($userInfo['id']);
        }

        $isNewUser = empty($kakaoUsers) && !get_user_by('email', $email);

        // 사이트 가입 허용 여부와 관계없이 카카오 로그인은 계정 생성 허용
        add_filter('fluent_auth/signup_enabled', '__return_true');
        $result = AuthService::doUserAuth([
            'email'      => $email,
            'first_name' => $userInfo['nickname'],
            'username'   => 'kakao_' . $userInfo['id'],
        ], 'kakao');
        remove_filter('fluent_auth/signup_enabled', '__return_true');

        if (is_wp_error($result)) {
            $this->loginError('auth_fail');
        }

        update_user_meta($result->ID, 'fak_kakao_id', $userInfo['id']);

        if ($isNewUser) {
            $this->registerToFluentCrm($result->ID, $userInfo);
        }

        $redirectTo = sanitize_url($_REQUEST['redirect_to'] ?? '');
        $redirect   = apply_filters('login_redirect', $redirectTo ?: admin_url(), $redirectTo, $result);
        wp_safe_redirect($redirect);
        exit;
    }

    private function registerToFluentCrm(int $userId, array $userInfo): void {
        if (!defined('FLUENTCRM')) return;

        $contact = \FluentCrmApi('contacts')->getContact($userInfo['email']);
        if (!$contact) {
            \FluentCrmApi('contacts')->createOrUpdate([
                'email'      => $userInfo['email'],
                'first_name' => $userInfo['nickname'],
                'source'     => 'kakao_login',
            ]);
        }
    }

    public function renderButton(): void {
        $kakao = new KakaoAuth();
        if (!$kakao->isConfigured()) return;

        $loginUrl = add_query_arg('fak_auth', 'kakao', wp_login_url());
        ?>
        <div style="text-align:center;margin:8px 0 16px;">
            <a href="<?php echo esc_url($loginUrl); ?>"
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
    }

    public function addLoginError(\WP_Error $errors): \WP_Error {
        if (isset($_GET['login'], $_GET['fak_error']) && $_GET['login'] === 'kakao_error') {
            $code    = sanitize_key($_GET['fak_error']);
            $message = self::ERROR_MESSAGES[$code] ?? '카카오 로그인 중 오류가 발생했습니다. 다시 시도해주세요.';
            $errors->add('kakao_error', $message);
        }
        return $errors;
    }

    private function loginError(string $code): never {
        wp_redirect(add_query_arg([
            'login'     => 'kakao_error',
            'fak_error' => $code,
        ], wp_login_url()));
        exit;
    }

    // IP당 분당 RATE_LIMIT_MAX회 초과 시 429 차단 (transient DoS 방지)
    private function checkRateLimit(): void {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = self::RATE_LIMIT_KEY . md5($ip);
        $hits = (int) get_transient($key);
        if ($hits >= self::RATE_LIMIT_MAX) {
            wp_die(
                '잠시 후 다시 시도해주세요. (1분에 최대 ' . self::RATE_LIMIT_MAX . '회)',
                '요청 제한',
                ['response' => 429]
            );
        }
        set_transient($key, $hits + 1, self::RATE_LIMIT_TTL);
    }

    // 카카오 ID → 해시 기반 가상 이메일 (ID 역추적 불가, 사이트별 고유값)
    private static function virtualEmail(int $kakaoId): string {
        return 'k' . substr(wp_hash((string) $kakaoId), 0, 24) . '@kakao.user';
    }
}
