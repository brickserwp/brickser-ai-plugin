<?php
/**
 * Brickser_Safety — bootstrap and runtime safety net.
 *
 * Goals:
 *  1. Block activation if requirements aren't met (PHP, WP, Bricks).
 *  2. Detect a fatal triggered inside our plugin during a page load and
 *     auto-deactivate on the next admin request so the site stays up
 *     (admin always reachable; broken plugin doesn't take the whole
 *     site down repeatedly).
 *  3. Provide a small `safe_invoke` helper so non-critical hooks
 *     (frontend filters that should NEVER crash a page) can be wrapped.
 *
 * Lives in its own file with no dependencies on the trait stack — that
 * way the safety layer loads even if a trait is corrupted.
 */

if (!defined('ABSPATH')) exit;

class Brickser_Safety {

    const PANIC_OPTION   = 'brickser_ai_panic';
    const PANIC_LOG_OPT  = 'brickser_ai_panic_log';
    const REQ_PHP        = '7.4';
    const REQ_WP         = '6.0';

    /**
     * Verify minimum requirements. Returns ['ok' => bool, 'reason' => string].
     * Caller decides whether to abort/skip-load or just show a notice.
     *
     * Bricks is checked but not required — we want the plugin to load on
     * sites that are still installing Bricks, just with a notice. Hard
     * blockers (PHP, WP) prevent activation entirely.
     */
    public static function check_requirements() {
        if (version_compare(PHP_VERSION, self::REQ_PHP, '<')) {
            return ['ok' => false, 'reason' => sprintf(
                'Brickser AI requires PHP %s or higher (you are on %s).',
                self::REQ_PHP, PHP_VERSION
            )];
        }
        global $wp_version;
        if (isset($wp_version) && version_compare($wp_version, self::REQ_WP, '<')) {
            return ['ok' => false, 'reason' => sprintf(
                'Brickser AI requires WordPress %s or higher (you are on %s).',
                self::REQ_WP, $wp_version
            )];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Activation gate. Runs on register_activation_hook. Aborts the
     * activation with wp_die() if requirements fail — WP shows the
     * message and rolls activation back, so the plugin stays inactive
     * and admin remains usable.
     */
    public static function on_activate() {
        $check = self::check_requirements();
        if (!$check['ok']) {
            // wp_die during activation rolls back cleanly. The user sees
            // the reason and can install the missing dependency.
            if (function_exists('wp_die')) {
                wp_die(
                    esc_html($check['reason']),
                    'Brickser AI cannot activate',
                    ['back_link' => true]
                );
            }
            return;
        }
        // Clear any prior panic flag — fresh activation = clean slate.
        delete_option(self::PANIC_OPTION);
        delete_option(self::PANIC_LOG_OPT);
    }

    /**
     * Install the panic handler. Call once near the top of plugin bootstrap.
     *
     * The shutdown callback inspects error_get_last() and, if the fatal
     * came from a file inside our plugin, sets a panic flag. The next
     * admin request reads the flag and deactivates the plugin
     * (see maybe_self_deactivate). The user sees an admin notice
     * explaining what happened.
     */
    public static function install_panic_handler() {
        register_shutdown_function([__CLASS__, 'detect_fatal']);
        if (function_exists('is_admin') && is_admin()) {
            add_action('admin_init', [__CLASS__, 'maybe_self_deactivate'], 0);
            add_action('admin_notices', [__CLASS__, 'render_panic_notice']);
        }
    }

    /**
     * Shutdown callback. If a fatal happened inside our plugin's path,
     * record it. Don't deactivate from here — that requires WP admin
     * APIs which may not be safe at shutdown. Just flag and let the
     * next admin request handle it.
     */
    public static function detect_fatal() {
        $err = error_get_last();
        if (!$err) return;
        if (!isset($err['type']) || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        if (empty($err['file']) || strpos($err['file'], 'brickser-ai-plugin') === false) return;

        // Record (best-effort — option API may be unavailable depending on
        // shutdown order). update_option silently no-ops if it fails.
        if (function_exists('update_option')) {
            update_option(self::PANIC_OPTION, 1, false);
            update_option(self::PANIC_LOG_OPT, [
                'message' => substr((string) $err['message'], 0, 500),
                'file'    => (string) $err['file'],
                'line'    => (int) $err['line'],
                'ts'      => time(),
            ], false);
        }
    }

    /**
     * Run on admin_init when the panic flag is set: deactivate the plugin
     * and clear the flag. Idempotent — if deactivation has already
     * happened the flag stays cleared.
     */
    public static function maybe_self_deactivate() {
        if (!get_option(self::PANIC_OPTION)) return;
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins(plugin_basename(BRICKSER_AI_PATH . 'brickser-ai.php'));
        delete_option(self::PANIC_OPTION);
        // Keep PANIC_LOG_OPT around so render_panic_notice can show details.
    }

    /**
     * Show an admin notice describing the auto-deactivation, then clear
     * the log so the notice doesn't show again.
     */
    public static function render_panic_notice() {
        $log = get_option(self::PANIC_LOG_OPT);
        if (!is_array($log) || empty($log['message'])) return;
        $msg  = esc_html($log['message']);
        $file = esc_html(basename((string) ($log['file'] ?? '')));
        $line = (int) ($log['line'] ?? 0);
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Brickser AI was deactivated automatically</strong> after a fatal error to keep your site online.';
        echo '<br>' . $msg . ' (' . $file . ':' . $line . ')';
        echo '<br>Please report this to support and try reactivating once you have an updated build.';
        echo '</p></div>';
        delete_option(self::PANIC_LOG_OPT);
    }

    /**
     * Run a callable inside a try/catch and a Throwable safety net. If it
     * throws, log and return $fallback. Use for non-critical hook
     * callbacks where a thrown exception would crash the page (frontend
     * filters, dynamic-data resolvers, etc.). Errors logged to the PHP
     * error_log so they show up in standard debugging.
     *
     * @template T
     * @param  callable $cb
     * @param  T        $fallback returned on exception
     * @param  mixed    ...$args  forwarded to $cb
     * @return T
     */
    public static function safe_invoke($cb, $fallback = null, ...$args) {
        try {
            return $cb(...$args);
        } catch (\Throwable $t) {
            // Cap the message to avoid log spam if a recursive call
            // re-throws on every hook tick.
            error_log('[Brickser AI] safe_invoke caught: ' . substr($t->getMessage(), 0, 200));
            return $fallback;
        }
    }
}
