<?php
namespace FluentAuthKakao;

defined('ABSPATH') || exit;

use FluentAuth\App\Services\AuthService;

class KakaoHandler {

    private const STATE_KEY = 'fak_oauth_state';

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

            $state = wp_generate_password(32, false);
            set_transient(self::STATE_KEY . '_' . $state, 1, 300);
            wp_redirect($kakao->getAuthorizeUrl($state));
            exit;
        }

        // Step 2: 카카오 콜백 처리
        $code  = sanitize_text_field($_GET['code']  ?? '');
        $state = sanitize_text_field($_GET['state'] ?? '');

        if (!$code || !$state) return;

        $transientKey = self::STATE_KEY . '_' . $state;
        if (!get_transient($transientKey)) {
            $this->loginError('보안 검증에 실패했습니다. 다시 시도해주세요.');
        }
        delete_transient($transientKey);

        $this->processCallback($code);
    }

    private function processCallback(string $code): void {
        $kakao = new KakaoAuth();

        $tokenData = $kakao->getToken($code);
        if (is_wp_error($tokenData)) {
            $this->loginError($tokenData->get_error_message());
        }

        $userInfo = $kakao->getUserInfo($tokenData['access_token']);
        if (is_wp_error($userInfo)) {
            $this->loginError($userInfo->get_error_message());
        }

        $existingUser = get_user_by('email', $userInfo['email']);

        if (!method_exists(AuthService::class, 'doUserAuth')) {
            $this->loginError('FluentAuth 버전이 호환되지 않습니다. 플러그인을 업데이트해주세요.');
        }

        $result = AuthService::doUserAuth([
            'email'      => $userInfo['email'],
            'first_name' => $userInfo['nickname'],
            'username'   => 'kakao_' . $userInfo['id'],
        ], 'kakao');

        if (is_wp_error($result)) {
            $this->loginError($result->get_error_message());
        }

        update_user_meta($result->ID, 'fak_kakao_id', $userInfo['id']);

        if (!$existingUser) {
            $this->registerToFluentCrm($result->ID, $userInfo);
        }

        $redirectTo = sanitize_url($_REQUEST['redirect_to'] ?? '');
        $redirect   = apply_filters('login_redirect', home_url(), $redirectTo, $result);
        wp_redirect($redirect);
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
        if (isset($_GET['login'], $_GET['fak_message']) && $_GET['login'] === 'kakao_error') {
            $message = sanitize_text_field(rawurldecode($_GET['fak_message']));
            $errors->add('kakao_error', esc_html($message));
        }
        return $errors;
    }

    private function loginError(string $message): never {
        wp_redirect(add_query_arg([
            'login'       => 'kakao_error',
            'fak_message' => rawurlencode($message),
        ], wp_login_url()));
        exit;
    }
}
