<?php
namespace FluentAuthKakao;

defined('ABSPATH') || exit;

class KakaoHandler {

    private const STATE_KEY = 'fak_oauth_state';

    public function register(): void {
        add_action('login_init', [$this, 'handleLoginInit']);
        add_action('login_form',  [$this, 'renderButton']);
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
            set_transient(self::STATE_KEY . '_' . $state, 1, 300); // 5분 유효
            wp_redirect($kakao->getAuthorizeUrl($state));
            exit;
        }

        // Step 2: 카카오 콜백 처리
        $code  = sanitize_text_field($_GET['code']  ?? '');
        $state = sanitize_text_field($_GET['state'] ?? '');

        if (!$code || !$state) return;

        // state 검증 (CSRF)
        $transientKey = self::STATE_KEY . '_' . $state;
        if (!get_transient($transientKey)) return;
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

        $wpUser = get_user_by('email', $userInfo['email']);

        if ($wpUser) {
            // 기존 계정 연결
            update_user_meta($wpUser->ID, 'fak_kakao_id', $userInfo['id']);
        } else {
            // 신규 계정 생성
            $userId = wp_insert_user([
                'user_login'   => 'kakao_' . $userInfo['id'],
                'user_email'   => $userInfo['email'],
                'display_name' => $userInfo['nickname'],
                'user_pass'    => wp_generate_password(),
                'role'         => 'subscriber',
            ]);

            if (is_wp_error($userId)) {
                $this->loginError('계정 생성에 실패했습니다.');
            }

            update_user_meta($userId, 'fak_kakao_id', $userInfo['id']);
            $this->registerToFluentCrm($userId, $userInfo);
            $wpUser = get_user_by('ID', $userId);
        }

        // 로그인 처리
        wp_set_current_user($wpUser->ID);
        wp_set_auth_cookie($wpUser->ID, true);
        do_action('wp_login', $wpUser->user_login, $wpUser);

        $redirect = apply_filters('login_redirect', admin_url(), '', $wpUser);
        wp_redirect($redirect);
        exit;
    }

    private function registerToFluentCrm(int $userId, array $userInfo): void {
        if (!defined('FLUENTCRM')) return;

        $contactData = [
            'email'      => $userInfo['email'],
            'first_name' => $userInfo['nickname'],
            'source'     => 'kakao_login',
        ];

        $contact = \FluentCrmApi('contacts')->getContact($userInfo['email']);
        if (!$contact) {
            \FluentCrmApi('contacts')->createOrUpdate($contactData);
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

    private function loginError(string $message): never {
        wp_redirect(add_query_arg('login', 'kakao_error', wp_login_url()));
        exit;
    }
}
