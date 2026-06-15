<?php
/**
 * Cloudflare Worker-based plugin updater.
 *
 * Checks the Worker /api/plugin/update endpoint for new releases and hooks
 * into WordPress's native update system. The zip is served from R2 via a
 * time-limited HMAC-signed URL — no GitHub repo required.
 *
 * Follows WordPress best practices:
 * - Only runs in admin context (loaded conditionally in brickser-ai.php)
 * - Respects force-check=1 to bypass cache
 * - Short timeout (5s) to never block page loads
 * - Caches results for 4h, failures for 15min
 * - All methods wrapped in try/catch — never crashes the site
 */

if (!defined('ABSPATH')) exit;

class Brickser_Updater {

    private const CACHE_KEY = 'brickser_ai_update';
    private const CACHE_TTL = 14400; // 4 hours
    private const FAIL_TTL  = 900;   // 15 minutes

    private string $plugin_file;
    private string $plugin_slug;
    private string $current_version;
    private string $update_url;

    public function __construct(string $plugin_file, string $current_version) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename($plugin_file);
        $this->current_version = $current_version;
        $this->update_url      = BRICKSER_WORKER_URL . '/api/plugin/update';

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);

        // "Check for updates" link in the plugin's meta row (next to
        // "View details") + the admin-post handler that backs it. The
        // handler clears our 4h release-cache transient AND WP's
        // update_plugins site transient so the next page load re-runs
        // check_update() with fresh data.
        //
        // We use plugin_row_meta (not plugin_action_links) because that's
        // the row that already contains "View details" — append makes the
        // link land naturally after it.
        add_filter('plugin_row_meta',                   [$this, 'add_check_update_meta'], 10, 2);
        add_action('admin_post_brickser_ai_check_update', [$this, 'handle_check_update']);
        add_action('admin_notices',                       [$this, 'maybe_render_check_notice']);
    }

    /**
     * Append a "Check for updates" link to our plugin's meta row, so it
     * shows up after "View details" instead of next to "Deactivate".
     *
     * Filter is global (fires for every plugin row); we early-return
     * when $file isn't ours.
     */
    public function add_check_update_meta($links, $file) {
        if (!is_array($links)) $links = [];
        if ($file !== $this->plugin_slug) return $links;
        if (!current_user_can('update_plugins')) return $links;

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=brickser_ai_check_update'),
            'brickser_ai_check_update'
        );
        $links[] = '<a href="' . esc_url($url) . '">Check for updates</a>';
        return $links;
    }

    /**
     * Force a fresh release lookup, then bounce back to the plugins page.
     * Both transients are cleared so check_update() re-runs with no cache.
     */
    public function handle_check_update() {
        if (!current_user_can('update_plugins')) wp_die('Unauthorized', 403);
        check_admin_referer('brickser_ai_check_update');

        delete_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_safe_redirect(add_query_arg(
            ['brickser_update_check' => '1'],
            admin_url('plugins.php')
        ));
        exit;
    }

    /**
     * Show a one-shot success notice on the Plugins screen after a manual
     * "Check for updates" round-trip. WP itself will surface the
     * "Update available" line if there's a newer version; we just confirm
     * the check actually ran.
     */
    public function maybe_render_check_notice() {
        if (empty($_GET['brickser_update_check']) || $_GET['brickser_update_check'] !== '1') return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id !== 'plugins') return;
        echo '<div class="notice notice-success is-dismissible"><p><strong>Brickser AI:</strong> Checked for updates. If a newer version is available, the "Update available" message will show on the row above.</p></div>';
    }

    /**
     * Tell WordPress when a new version is available.
     *
     * Always registers the plugin in no_update (at minimum) so WordPress
     * shows "View details" and "Enable auto-updates" even when no update
     * is available or the Worker is unreachable.
     */
    public function check_update($transient) {
        try {
            if (!is_object($transient)) $transient = new \stdClass();

            // Read version from disk during upgrades (in-memory version is stale)
            $current = $this->current_version;
            if (function_exists('get_plugin_data')) {
                $file = WP_PLUGIN_DIR . '/' . $this->plugin_slug;
                if (file_exists($file)) {
                    $data = get_plugin_data($file, false, false);
                    if (!empty($data['Version'])) {
                        $current = $data['Version'];
                    }
                }
            }

            $plugin_data = (object) [
                'slug'        => 'brickser-ai-plugin',
                'plugin'      => $this->plugin_slug,
                'new_version' => $current,
                'url'         => 'https://brickser.io',
                'package'     => '',
                'icons'       => $this->get_icons(),
                'banners'     => $this->get_banners(),
            ];

            if (!empty($transient->checked)) {
                $release = $this->get_release_data();

                if ($release && !empty($release['version'])) {
                    $new_version = $release['version'];
                    $plugin_data->new_version = $new_version;
                    $plugin_data->package     = $release['download_url'] ?? '';

                    if (version_compare($current, $new_version, '<') && !empty($plugin_data->package)) {
                        $transient->response[$this->plugin_slug] = $plugin_data;
                        return $transient;
                    }
                }
            }

            if (!isset($transient->no_update)) $transient->no_update = [];
            $transient->no_update[$this->plugin_slug] = $plugin_data;
        } catch (\Throwable $e) {
            // Never crash the site over an update check
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View Details" modal.
     */
    public function plugin_info($result, $action, $args) {
        try {
            if ($action !== 'plugin_information') return $result;
            $valid_slugs = ['brickser-ai-plugin', 'brickser-ai'];
            if (!isset($args->slug) || !in_array($args->slug, $valid_slugs, true)) return $result;

            $release = $this->get_release_data();
            $version = $this->current_version;
            $download_link = null;

            if ($release && !empty($release['version'])) {
                $version       = $release['version'];
                $download_link = $release['download_url'] ?? null;
            }

            $changelog = '<p>For the full changelog, visit <a href="https://brickser.io/changelog" target="_blank">brickser.io/changelog</a>.</p>';

            return (object) [
                'name'          => 'Brickser AI',
                'slug'          => 'brickser-ai-plugin',
                'version'       => $version,
                'author'        => '<a href="https://brickser.io">TeamBrickser</a>',
                'homepage'      => 'https://brickser.io',
                'requires'      => '6.0',
                'tested'        => '6.9.4',
                'requires_php'  => '7.4',
                'download_link' => $download_link,
                'sections'      => [
                    'description' => '<p>Brickser AI is your AI build agent for Bricks Builder. Describe your project, and it builds a complete website structure with a sitemap, pages, and conversion-focused layouts using reusable sections and components.</p>'
                        . '<p>No random AI slop. Brickser AI assembles websites from structured, reusable UI patterns so your pages stay clean, consistent, high-converting, and easy to maintain inside Bricks.</p>'
                        . '<p>Customize the entire look and feel with a CSS-variable-based design system. Adjust colors, fonts, spacing, and style without frameworks, dependencies, or custom code.</p>'
                        . '<p>Refine copy with AI, improve page flow, shuffle sections, add pages, and evolve your sitemap anytime. Brickser AI saves your changes automatically so your project stays organized.</p>'
                        . '<p>Ship Bricks websites faster while keeping full control. Brickser AI helps you build conversion-ready sites that are scalable, portable, and built for real client work.</p>'
                        . '<h4>Key Features</h4>'
                        . '<ul><li>AI-powered full website generation</li><li>Instant section swap</li><li>AI text rewrite and tuning</li><li>Simple CSS variables design system</li><li>Native Bricks, zero dependencies</li><li>Expandable sitemap</li><li>Share projects across any Bricks site</li><li>Works with Bricks\' own settings</li></ul>',
                    'changelog'   => $changelog,
                ],
                'banners'       => $this->get_banners(),
                'icons'         => $this->get_icons(),
            ];
        } catch (\Throwable $e) {
            return $result;
        }
    }

    /**
     * Rename the extracted source folder to 'brickser-ai-plugin' BEFORE
     * WordPress moves it into wp-content/plugins/. This prevents WP from
     * appending '-1', '-2' etc. when the folder name doesn't match.
     *
     * Fires on 'upgrader_source_selection'.
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        try {
            if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
                return $source;
            }

            global $wp_filesystem;
            if (!$wp_filesystem) return $source;

            $expected = trailingslashit($remote_source) . 'brickser-ai-plugin/';

            if (trailingslashit($source) !== $expected) {
                $wp_filesystem->move(trailingslashit($source), $expected);
                return $expected;
            }
        } catch (\Throwable $e) {
            // Fall through — after_install will try again
        }

        return $source;
    }

    /**
     * Post-install: ensure correct directory name and reactivate the plugin.
     */
    public function after_install($response, $hook_extra, $result) {
        try {
            if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
                return $result;
            }

            global $wp_filesystem;
            $proper_dir    = trailingslashit(WP_PLUGIN_DIR) . 'brickser-ai-plugin/';
            $installed_dir = trailingslashit($result['destination']);

            // Safety net: if source_selection didn't fire or didn't fix it
            if ($installed_dir !== $proper_dir && $wp_filesystem) {
                if ($wp_filesystem->exists($proper_dir)) {
                    $wp_filesystem->delete($proper_dir, true);
                }
                $wp_filesystem->move($installed_dir, $proper_dir);
                $result['destination'] = $proper_dir;
            }

            activate_plugin($this->plugin_slug);

            remove_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
            delete_transient(self::CACHE_KEY);
            delete_site_transient('update_plugins');
        } catch (\Throwable $e) {
            // Don't break the install process
        }

        return $result;
    }

    /**
     * Fetch release data from the Worker with caching.
     * Returns array with keys: version, tag_name, changelog, download_url
     */
    private function get_release_data(): ?array {
        $force = isset($_GET['force-check']) && $_GET['force-check'] === '1';

        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached) && !empty($cached['version'])) return $cached;
            if ($cached === 'empty') return null;
        }

        $response = wp_remote_get($this->update_url, [
            'timeout' => 5,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'BrickserAI/' . $this->current_version,
            ],
        ]);

        if (is_wp_error($response)) {
            set_transient(self::CACHE_KEY, 'empty', self::FAIL_TTL);
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            set_transient(self::CACHE_KEY, 'empty', self::FAIL_TTL);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['version'])) {
            set_transient(self::CACHE_KEY, 'empty', self::FAIL_TTL);
            return null;
        }

        $release = [
            'version'      => $data['version'],
            'tag_name'     => $data['tag_name'] ?? 'v' . $data['version'],
            'changelog'    => $data['changelog'] ?? '',
            'download_url' => $data['download_url'] ?? '',
        ];

        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    private function get_icons(): array {
        return [
            '1x' => 'https://cdn.brickser.io/plugin/icon-128x128.png',
            '2x' => 'https://cdn.brickser.io/plugin/icon-256x256.png',
        ];
    }

    private function get_banners(): array {
        return [
            'low'  => 'https://cdn.brickser.io/plugin/banner-772x250.png',
            'high' => 'https://cdn.brickser.io/plugin/banner-1544x500.png',
        ];
    }

    private function parse_changelog(string $body): string {
        if (empty($body)) return '<p>See <a href="https://brickser.io/changelog">brickser.io/changelog</a> for release details.</p>';

        $html = esc_html($body);
        $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html);
        $html = nl2br($html);

        return $html;
    }
}
