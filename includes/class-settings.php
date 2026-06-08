<?php
namespace FluentAuthKakao;

defined('ABSPATH') || exit;

class Settings {

    private const OPTION_KEY = 'fak_settings';
    private const MENU_SLUG  = 'fak-settings';

    public function register(): void {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenuPage(): void {
        add_options_page(
            '카카오 로그인 설정',
            '카카오 로그인',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function registerSettings(): void {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize'],
        ]);
    }

    public function sanitize(array $input): array {
        return [
            'rest_api_key'  => sanitize_text_field($input['rest_api_key'] ?? ''),
            'client_secret' => sanitize_text_field($input['client_secret'] ?? ''),
        ];
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;

        $settings    = get_option(self::OPTION_KEY, []);
        $apiKey      = esc_attr($settings['rest_api_key'] ?? '');
        $secretKey   = esc_attr($settings['client_secret'] ?? '');
        $redirectUri = esc_url(wp_login_url());
        ?>
        <div class="wrap">
            <h1>카카오 로그인 설정</h1>

            <div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;
                        max-width:600px;margin:12px 0;border-radius:4px;">
                <h3 style="margin-top:0;">카카오 개발자 콘솔 설정</h3>
                <ol style="line-height:2;">
                    <li><a href="https://developers.kakao.com" target="_blank">developers.kakao.com</a>에서 앱 생성</li>
                    <li>카카오 로그인 활성화</li>
                    <li>리다이렉트 URI 등록:
                        <code style="background:#f0f0f1;padding:2px 6px;border-radius:3px;">
                            <?php echo $redirectUri; ?>
                        </code>
                    </li>
                    <li>동의 항목: 프로필 닉네임(필수), 이메일(선택)</li>
                    <li>보안 &gt; Client Secret 발급 (권장)</li>
                </ol>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="fak_rest_api_key">REST API 키</label></th>
                        <td>
                            <input type="text" id="fak_rest_api_key"
                                   name="<?php echo self::OPTION_KEY; ?>[rest_api_key]"
                                   value="<?php echo $apiKey; ?>"
                                   class="regular-text" required>
                            <p class="description">카카오 앱 &gt; 앱 키 &gt; REST API 키</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fak_client_secret">Client Secret</label></th>
                        <td>
                            <input type="password" id="fak_client_secret"
                                   name="<?php echo self::OPTION_KEY; ?>[client_secret]"
                                   value="<?php echo $secretKey; ?>"
                                   class="regular-text">
                            <p class="description">카카오 앱 &gt; 보안 &gt; Client Secret (권장)</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('설정 저장'); ?>
            </form>
        </div>
        <?php
    }
}
