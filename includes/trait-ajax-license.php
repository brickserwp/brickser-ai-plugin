<?php
/**
 * License activation/deactivation AJAX handlers.
 * Proxies to the Cloudflare Worker's /license/* endpoints and persists
 * the session token + key in WordPress options.
 */

if (!defined('ABSPATH')) exit;

trait Brickser_Ajax_License {

    /** Verify the AJAX nonce used by the editor sidebar. */
    private function license_verify_nonce() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }
    }

    /** POST /license/activate — validate + activate against SureCart. */
    public function license_activate() {
        $this->license_verify_nonce();

        $key = sanitize_text_field($_POST['key'] ?? '');
        if (empty($key)) {
            wp_send_json_error(['message' => 'License key required'], 400);
        }

        $site_url = home_url();

        $resp = wp_remote_post(BRICKSER_WORKER_URL . '/license/activate', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'key'      => $key,
                'site_url' => $site_url,
            ]),
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200 || empty($body['token'])) {
            wp_send_json_error([
                'message' => $body['error'] ?? 'Activation failed',
            ], $code ?: 500);
        }

        update_option('brickser_license_token', $body['token'], false);
        update_option('brickser_license_key',   $body['license_key'], false);
        update_option('brickser_license_tier',  $body['tier'], false);

        // Optional plan metadata from the worker — keeps the plugin tier-agnostic
        // (new SureCart products require zero plugin changes when the worker
        // returns these fields). Falls back gracefully if absent.
        if (isset($body['tier_label']) && is_string($body['tier_label'])) {
            update_option('brickser_license_tier_label', sanitize_text_field($body['tier_label']), false);
        } else {
            delete_option('brickser_license_tier_label');
        }
        if (isset($body['sites_allowed'])) {
            update_option('brickser_license_sites_allowed', (int) $body['sites_allowed'], false);
        } else {
            delete_option('brickser_license_sites_allowed');
        }

        wp_send_json_success([
            'tier'               => $body['tier'],
            'tier_label'         => $body['tier_label']    ?? null,
            'sites_allowed'      => $body['sites_allowed'] ?? null,
            'license_key_masked' => self::mask_license_key($body['license_key']),
            'site_url'           => $body['site_url'],
        ]);
    }

    /** POST /license/deactivate — delete SureCart activation + clear local options. */
    public function license_deactivate() {
        $this->license_verify_nonce();

        $token = get_option('brickser_license_token', '');
        if (!empty($token)) {
            wp_remote_post(BRICKSER_WORKER_URL . '/license/deactivate', [
                'timeout' => 15,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'body' => '{}',
            ]);
        }

        delete_option('brickser_license_token');
        delete_option('brickser_license_key');
        delete_option('brickser_license_tier');
        delete_option('brickser_license_tier_label');
        delete_option('brickser_license_sites_allowed');
        delete_option('brickser_byok_provider');

        wp_send_json_success(['ok' => true]);
    }

    /** GET /license/status — passthrough for the editor UI. */
    public function license_status() {
        $this->license_verify_nonce();

        $token = get_option('brickser_license_token', '');
        if (empty($token)) {
            wp_send_json_success(['active' => false]);
        }

        $resp = wp_remote_get(BRICKSER_WORKER_URL . '/license/status', [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code === 401) {
            // Token no longer valid — clear locally (including BYOK, since the
            // worker drops the BYOK record together with the license).
            delete_option('brickser_license_token');
            delete_option('brickser_license_key');
            delete_option('brickser_license_tier');
            delete_option('brickser_license_tier_label');
            delete_option('brickser_license_sites_allowed');
            delete_option('brickser_byok_provider');
            wp_send_json_success(['active' => false]);
        }

        if ($code !== 200) {
            wp_send_json_error(['message' => $body['error'] ?? 'Status check failed'], $code ?: 500);
        }

        wp_send_json_success(array_merge(['active' => true], $body));
    }

    /** POST /license/byok-test — test provider API key without saving. */
    public function license_test_byok() {
        $this->license_verify_nonce();

        $token = get_option('brickser_license_token', '');
        if (empty($token)) {
            wp_send_json_error(['message' => 'License not activated'], 403);
        }

        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $api_key  = sanitize_text_field($_POST['api_key'] ?? '');
        if (empty($provider) || empty($api_key)) {
            wp_send_json_error(['message' => 'provider and api_key required'], 400);
        }

        $resp = wp_remote_post(BRICKSER_WORKER_URL . '/license/byok-test', [
            'timeout' => 20,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => wp_json_encode([
                'provider' => $provider,
                'key'      => $api_key,
            ]),
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200) {
            wp_send_json_error([
                'message' => $body['error'] ?? 'Test failed',
            ], $code ?: 500);
        }

        wp_send_json_success($body);
    }

    /** POST /license/byok-key — save provider API key keyed by license. */
    public function license_save_byok() {
        $this->license_verify_nonce();

        $token = get_option('brickser_license_token', '');
        if (empty($token)) {
            wp_send_json_error(['message' => 'License not activated'], 403);
        }

        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $api_key  = sanitize_text_field($_POST['api_key'] ?? '');
        if (empty($provider) || empty($api_key)) {
            wp_send_json_error(['message' => 'provider and api_key required'], 400);
        }

        $resp = wp_remote_post(BRICKSER_WORKER_URL . '/license/byok-key', [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => wp_json_encode([
                'provider' => $provider,
                'key'      => $api_key,
            ]),
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200) {
            wp_send_json_error(['message' => $body['error'] ?? 'Save failed'], $code ?: 500);
        }

        update_option('brickser_byok_provider', $provider, false);

        wp_send_json_success($body);
    }

    /**
     * GET — return the current model tier ('default' | 'pro').
     * Per-site option, not user meta — matches byokProvider.
     * Open to any user that can use the editor sidebar; no manage_options
     * required since it's a UI preference, not a security boundary.
     */
    public function get_model_tier() {
        check_ajax_referer('brickser_ai', 'nonce');
        $tier = get_option('brickser_model_tier', 'default');
        // 'pro' is the legacy name from v1.3.0 — normalize on read.
        if ($tier === 'pro') $tier = 'thinking';
        if ($tier !== 'default' && $tier !== 'thinking') $tier = 'default';
        wp_send_json_success(['modelTier' => $tier]);
    }

    /**
     * POST — save the model tier. Requires edit_posts (same gate as the
     * sidebar's other AJAX endpoints) so subscribers can't flip it.
     * Accepts the canonical 'thinking' as well as the legacy 'pro' alias
     * (in case an old plugin build is still posting it). Rejects anything
     * else so a bad client can't corrupt the option.
     */
    public function save_model_tier() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }
        $tier = sanitize_text_field($_POST['modelTier'] ?? '');
        if ($tier === 'pro') $tier = 'thinking'; // legacy alias
        if ($tier !== 'default' && $tier !== 'thinking') {
            wp_send_json_error(['message' => "modelTier must be 'default' or 'thinking'"], 400);
        }
        update_option('brickser_model_tier', $tier, false);
        wp_send_json_success(['modelTier' => $tier]);
    }

    /** DELETE /license/byok-key — remove saved provider key. */
    public function license_delete_byok() {
        $this->license_verify_nonce();

        $token = get_option('brickser_license_token', '');
        if (empty($token)) {
            wp_send_json_error(['message' => 'License not activated'], 403);
        }

        $resp = wp_remote_request(BRICKSER_WORKER_URL . '/license/byok-key', [
            'method'  => 'DELETE',
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200) {
            wp_send_json_error(['message' => $body['error'] ?? 'Delete failed'], $code ?: 500);
        }

        delete_option('brickser_byok_provider');

        wp_send_json_success($body ?: []);
    }
}
