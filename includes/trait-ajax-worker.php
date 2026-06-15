<?php
/**
 * Server-side proxy for Cloudflare Worker AI calls.
 *
 * The plugin used to call the worker directly from the browser using a bearer
 * token shipped via wp_localize_script. That exposed the long-lived license
 * token to anyone who could open DevTools on the Bricks editor page. This
 * trait keeps the token server-side: the browser POSTs to admin-ajax.php,
 * we attach the bearer header here, and forward the response unchanged.
 */

if (!defined('ABSPATH')) exit;

trait Brickser_Ajax_Worker {

    /**
     * Allowlist of worker path prefixes the proxy is willing to forward.
     * Everything else (including arbitrary URLs) is rejected.
     */
    private function worker_proxy_allowed_prefixes() {
        return [
            '/api/',
            '/v1/',          // legacy AI endpoints (if any)
            '/sections/',
            '/components/',
            '/intent/',
            '/translate/',
            '/rewrite/',
            '/swap-fill/',
            '/content/',
            '/classify/',
            '/generate/',
            '/style/',
        ];
    }

    /**
     * POST wp-admin/admin-ajax.php?action=brickser_worker_proxy
     * Body: { nonce, endpoint, payload } where payload is a JSON string.
     */
    public function worker_proxy() {
        check_ajax_referer('brickser_ai', 'nonce');

        // Anyone allowed to edit pages can use AI features; license management
        // (activate / save BYOK / delete BYOK) is gated separately to admins.
        if (!current_user_can('edit_pages')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $token = get_option('brickser_license_token', '');
        if (empty($token)) {
            wp_send_json_error(['message' => 'License not activated'], 401);
        }

        $endpoint = isset($_POST['endpoint']) ? (string) $_POST['endpoint'] : '';

        // Strip query string and any path traversal before validation, then
        // re-attach a sanitized query string so we don't smuggle absolute URLs.
        $parts = parse_url($endpoint);
        if ($parts === false || !empty($parts['scheme']) || !empty($parts['host'])) {
            wp_send_json_error(['message' => 'Invalid endpoint'], 400);
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        if (empty($path) || strpos($path, '..') !== false || $path[0] !== '/') {
            wp_send_json_error(['message' => 'Invalid endpoint'], 400);
        }

        // Allowlist check — only specific worker paths are forwardable.
        $allowed = false;
        foreach ($this->worker_proxy_allowed_prefixes() as $prefix) {
            if (strpos($path, $prefix) === 0) { $allowed = true; break; }
        }
        if (!$allowed) {
            wp_send_json_error(['message' => 'Forbidden endpoint'], 403);
        }

        $url = BRICKSER_WORKER_URL . $path;
        if (!empty($parts['query'])) {
            $url .= '?' . $parts['query'];
        }

        // Cap payload size so a compromised browser can't fan out giant requests.
        $payload = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '{}';
        if (strlen($payload) > 200000) {
            wp_send_json_error(['message' => 'Payload too large'], 413);
        }
        // Validate JSON shape so we don't forward garbage and waste a worker round-trip.
        if (json_decode($payload, true) === null && $payload !== 'null') {
            wp_send_json_error(['message' => 'Invalid JSON payload'], 400);
        }

        // 120s ceiling: sitemap-mode requests fan out into N parallel AI calls
        // (one per page) on the worker side, and a slow provider tail can push
        // the worker run past 60s. Higher timeout surfaces the real slowness
        // instead of a useless 502 to the editor.
        $resp = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => $payload,
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        // If the worker says the token is dead, eagerly clear local state so the
        // editor flips to the wizard on next load — same teardown as license_status.
        if ($code === 401) {
            delete_option('brickser_license_token');
            delete_option('brickser_license_key');
            delete_option('brickser_license_tier');
            delete_option('brickser_byok_provider');
        }

        // Forward the worker response verbatim with the same status code so
        // existing client error-handling (529 retry, 402 BYOK, etc.) keeps working.
        // ['response' => null] is load-bearing: by default _ajax_wp_die_handler
        // calls status_header($args['response'] ?? 200) on the way out, which
        // silently downgrades our explicit 402/403/etc. to 200 and breaks the
        // plugin's status-based error branching.
        status_header($code ?: 502);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
        wp_die('', '', ['response' => null]);
    }
}
