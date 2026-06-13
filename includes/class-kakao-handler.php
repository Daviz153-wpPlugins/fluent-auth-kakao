<?php
namespace FluentAuthKakao;

defined('ABSPATH') || exit;

use FluentAuth\App\Services\AuthService;

class KakaoHandler {

    private const STATE_KEY      = 'fak_oauth_state';
    private const RATE_LIMIT_KEY = 'fak_rl_';
    private const RATE_LIMIT_MAX = 10;
    private const RATE_LIMIT_TTL = 60;

    private const ERROR_MESSAGES = [
        'csrf_fail'       => '보안 검증에 실패했습니다. 다시 시도해주세요.',
        'token_fail'      => '카카오 인증에 실패했습니다. 다시 시도해주세요.',
        'userinfo_fail'   => '카카오 사용자 정보를 가져올 수 없습니다. 다시 시도해주세요.',
        'compat_fail'     => 'FluentAuth 버전이 호환되지 않습니다. 플러그인을 업데이트해주세요.',
        'auth_fail'       => '로그인 처리 중 오류가 발생했습니다. 다시 시도해주세요.',
        'signup_disabled' => '신규 가입이 제한되어 있습니다. 기존 계정으로 로그인하거나 관리자에게 문의하세요.',
        'rate_limit'      => '잠시 후 다시 시도해주세요. (요청이 너무 많습니다)',
    ];

    public function register(): void {
        // priority 0: FluentAuth SocialAuthHandler(priority 1)이 Google ?code&state를 가로채기 전에 실행
        add_action('login_init', [$this, 'handleLoginInit'], 0);
        add_action('login_form', [$this, 'renderButton']);
        add_action('login_head', [$this, 'maybeHideEmailForm']);
        add_shortcode('fak_kakao_login', [$this, 'renderShortcode']);
    }

    public function handleLoginInit(): void {
        $auth = sanitize_text_field($_GET['fak_auth'] ?? '');

        if ($auth === 'kakao') {
            $kakao = new KakaoAuth();
            if (!$kakao->isConfigured()) {
                wp_die('카카오 로그인 설정이 완료되지 않았습니다. 관리자에게 문의하세요.');
            }

            if ($this->isRateLimited()) {
                $this->loginError('rate_limit');
                return;
            }

            $state = wp_generate_password(32, false);
            set_transient(self::STATE_KEY . '_' . $state, 1, 300);
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

        $code  = sanitize_text_field($_GET['code']  ?? '');
        $state = sanitize_text_field($_GET['state'] ?? '');

        if (!$code || !$state) return;

        // 쿠키 없으면 Google 등 다른 소셜 콜백 — FluentAuth에 위임
        $cookieState = sanitize_text_field($_COOKIE[self::STATE_KEY] ?? '');
        if (!$cookieState) return;

        if (!hash_equals($cookieState, $state)) {
            $this->loginError('csrf_fail');
            return;
        }

        $transientKey = self::STATE_KEY . '_' . $state;
        if (!get_transient($transientKey)) {
            $this->loginError('csrf_fail');
            return;
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
            return;
        }

        $userInfo = $kakao->getUserInfo($tokenData['access_token']);
        if (is_wp_error($userInfo)) {
            $this->loginError('userinfo_fail');
            return;
        }

        if (!method_exists(AuthService::class, 'doUserAuth')) {
            $this->loginError('compat_fail');
            return;
        }

        $kakaoUsers = get_users([
            'meta_key'   => 'fak_kakao_id',
            'meta_value' => $userInfo['id'],
            'number'     => 1,
        ]);

        if (!empty($kakaoUsers)) {
            $email = $kakaoUsers[0]->user_email;
        } elseif ($userInfo['email_verified'] && $userInfo['email']) {
            $email = $userInfo['email'];
        } else {
            $email = self::virtualEmail($userInfo['id']);
        }

        $isNewUser = empty($kakaoUsers) && !get_user_by('email', $email);

        $result = AuthService::doUserAuth([
            'email'      => $email,
            'first_name' => $userInfo['nickname'],
            'username'   => 'kakao_' . $userInfo['id'],
        ], 'kakao');

        if (is_wp_error($result)) {
            $errCode = $result->get_error_code() === 'signup_disabled' ? 'signup_disabled' : 'auth_fail';
            $this->loginError($errCode);
            return;
        }

        update_user_meta($result->ID, 'fak_kakao_id', $userInfo['id']);

        // FluentCRM 등록: 실제 이메일이 있는 신규 사용자만 (빈 이메일 → createOrUpdate 예외 방지)
        if ($isNewUser && $userInfo['email']) {
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

        echo $this->buttonHtml(add_query_arg('fak_auth', 'kakao', wp_login_url()));
    }

    public function renderShortcode(array $atts): string {
        if (is_user_logged_in()) return '';

        $kakao = new KakaoAuth();
        if (!$kakao->isConfigured()) return '';

        $atts       = shortcode_atts(['redirect_to' => ''], $atts, 'fak_kakao_login');
        $redirectTo = $atts['redirect_to'] ?: (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        $loginUrl = add_query_arg([
            'fak_auth'    => 'kakao',
            'redirect_to' => $redirectTo,
        ], wp_login_url());

        return $this->buttonHtml($loginUrl);
    }

    private function buttonHtml(string $loginUrl): string {
        ob_start();
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
        return ob_get_clean();
    }

    // Google과 동일한 방식: 리다이렉트 없이 wp_login_errors 필터로 에러 표시
    private function loginError(string $code): void {
        $message = self::ERROR_MESSAGES[$code] ?? '카카오 로그인 중 오류가 발생했습니다. 다시 시도해주세요.';
        $error   = new \WP_Error('kakao_error', $message);
        add_filter('wp_login_errors', static function () use ($error) {
            return $error;
        });
    }

    // CF-Connecting-IP → X-Forwarded-For → REMOTE_ADDR 순으로 클라이언트 IP 판별
    private function isRateLimited(): bool {
        $ip = sanitize_text_field(
            $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR'])
                ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]
                : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))
        );
        $ip   = trim($ip);
        $key  = self::RATE_LIMIT_KEY . md5($ip);
        $hits = (int) get_transient($key);
        set_transient($key, $hits + 1, self::RATE_LIMIT_TTL);
        return $hits >= self::RATE_LIMIT_MAX;
    }

    public function maybeHideEmailForm(): void {
        $settings = get_option('fak_settings', []);
        if (($settings['hide_email_login'] ?? '') !== 'yes') return;
        // 카카오 미설정 상태에서 폼 숨기면 버튼도 폼도 없는 잠금 화면이 됨
        if (!(new KakaoAuth())->isConfigured()) return;
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

    private static function virtualEmail(int $kakaoId): string {
        return 'k' . substr(wp_hash((string) $kakaoId), 0, 24) . '@kakao.user';
    }
}
