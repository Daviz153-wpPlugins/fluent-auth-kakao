<?php
namespace FluentAuthKakao;

defined('ABSPATH') || exit;

class Settings {

    private const OPTION_KEY = 'fak_settings';

    public function register(): void {
        add_action('rest_api_init',         [$this, 'registerRestRoutes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScript']);
    }

    public function registerRestRoutes(): void {
        register_rest_route('fak/v1', '/settings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'restGetSettings'],
                'permission_callback' => fn() => current_user_can('manage_options'),
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'restSaveSettings'],
                'permission_callback' => fn() => current_user_can('manage_options'),
            ],
        ]);
    }

    public function restGetSettings(): array {
        $s = get_option(self::OPTION_KEY, []);
        return [
            'rest_api_key'     => $s['rest_api_key']     ?? '',
            'client_secret'    => $s['client_secret']    ?? '',
            'hide_email_login' => $s['hide_email_login'] ?? 'no',
            'redirect_uri'     => wp_login_url(),
        ];
    }

    public function restSaveSettings(\WP_REST_Request $request): array {
        $data = $this->sanitize((array) $request->get_json_params());
        update_option(self::OPTION_KEY, $data, false);
        return ['message' => '카카오 로그인 설정이 저장되었습니다.'];
    }

    private function sanitize(array $input): array {
        return [
            'rest_api_key'     => sanitize_text_field($input['rest_api_key']     ?? ''),
            'client_secret'    => sanitize_text_field($input['client_secret']    ?? ''),
            'hide_email_login' => ($input['hide_email_login'] ?? '') === 'yes' ? 'yes' : 'no',
        ];
    }

    public function enqueueAdminScript(string $hook): void {
        if ($hook !== 'toplevel_page_fluent-auth') return;

        wp_enqueue_script('fak-admin', FAK_URL . 'assets/js/admin.js', [], FAK_VERSION, true);
        wp_localize_script('fak-admin', 'fakAdmin', [
            'restUrl'     => rest_url('fak/v1/settings'),
            'nonce'       => wp_create_nonce('wp_rest'),
            'redirectUri' => wp_login_url(),
        ]);
    }
}
