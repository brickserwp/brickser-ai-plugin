<?php
/**
 * Plugin Name: Brickser AI
 * Description: AI build partner for Bricks Builder. Create maintainable, conversion-ready websites faster with reusable sections, clean layouts, and full creative control.
 * Version: 1.3.0
 * Author: TeamBrickser
 * Author URI: https://brickser.io
 * Requires at least: 6.0
 * Tested up to: 6.9.4
 * Requires PHP: 7.4
 * Text Domain: brickser-ai
 * License: Proprietary
 * License URI: https://brickser.io/terms-of-service
 *
 * Copyright (c) 2026 Brickser LLC. All rights reserved.
 * Privacy Policy: https://brickser.io/privacy-policy
 */

if (!defined('ABSPATH')) exit;

// Prevent fatal errors if a duplicate copy of this plugin is installed
if (defined('BRICKSER_AI_VERSION')) return;

define('BRICKSER_AI_VERSION', '1.3.0');
define('BRICKSER_AI_PATH', plugin_dir_path(__FILE__));
define('BRICKSER_AI_URL', plugin_dir_url(__FILE__));

// Environment detection: local dev → staging → production
if (defined('BRICKSER_LOCAL_DEV') && BRICKSER_LOCAL_DEV) {
    define('BRICKSER_WORKER_URL', 'http://localhost:8787');
} elseif (defined('BRICKSER_STAGING') && BRICKSER_STAGING) {
    define('BRICKSER_WORKER_URL', 'https://brickser-ai-worker-staging.brickserio.workers.dev');
} else {
    define('BRICKSER_WORKER_URL', 'https://brickser-ai-worker.brickserio.workers.dev');
}

// Safety layer FIRST — provides activation gate + panic handler. Lives in
// its own file with no trait dependencies so it loads even if a trait is
// later corrupted. Loaded before the vendor autoloader so the handler is
// armed for everything that follows.
require_once BRICKSER_AI_PATH . 'includes/class-safety.php';
register_activation_hook(__FILE__, ['Brickser_Safety', 'on_activate']);
Brickser_Safety::install_panic_handler();

// Composer autoloader (enshrined/svg-sanitize and other vendored libs).
// WRAPPED: a broken or partial vendor/ (e.g. a prod subset whose generated
// autoloader eagerly `require`s a dev file that wasn't shipped) throws a
// catchable Error here. Unwrapped, that error is fatal at plugin-LOAD time
// and takes the whole site down — front AND admin — because it fires while
// wp-settings.php is including the plugin, long before any recovery hook can
// run. We log and continue; features that need a vendored lib already
// degrade gracefully when its class is absent (e.g. SVG sanitize returns '').
if (file_exists(BRICKSER_AI_PATH . 'vendor/autoload.php')) {
    try {
        require_once BRICKSER_AI_PATH . 'vendor/autoload.php';
    } catch (\Throwable $e) {
        error_log('[Brickser AI] vendor/autoload.php failed to load (continuing without vendored libs): ' . $e->getMessage());
    }
}

// Bail if requirements aren't met. We don't fatal here — instead, we skip
// loading the trait stack so admin stays accessible. A notice tells the
// user what's missing.
$__brickser_req = Brickser_Safety::check_requirements();
if (!$__brickser_req['ok']) {
    if (is_admin()) {
        add_action('admin_notices', function () use ($__brickser_req) {
            echo '<div class="notice notice-error"><p><strong>Brickser AI</strong>: ' . esc_html($__brickser_req['reason']) . '</p></div>';
        });
    }
    return;
}

// Load trait files (explicit allowlist — no glob to prevent accidental inclusion).
// Wrapped so a corrupted trait file can't bring the site down — we log,
// stop loading, and let the panic handler deactivate on the next admin hit.
$trait_files = [
    'trait-editor.php',
    'trait-ajax-sections.php',
    'trait-ajax-projects.php',
    'trait-media-bundle.php',
    'trait-ajax-styles.php',
    'trait-ajax-design-system.php',
    'trait-ajax-license.php',
    'trait-ajax-worker.php',
    'trait-frontend.php',
    'trait-helpers.php',
    'trait-rename-migration.php',
];
try {
    foreach ($trait_files as $file) {
        require_once BRICKSER_AI_PATH . 'includes/' . $file;
    }
} catch (\Throwable $t) {
    error_log('[Brickser AI] Trait load failed, skipping plugin init: ' . $t->getMessage());
    return;
}

// Self-update from Cloudflare Worker + R2 (admin only — never slow down the frontend).
// Wrapped: a failure here must not white-screen wp-admin.
if (is_admin()) {
    try {
        require_once BRICKSER_AI_PATH . 'includes/class-updater.php';
        new Brickser_Updater(__FILE__, BRICKSER_AI_VERSION);
    } catch (\Throwable $e) {
        error_log('[Brickser AI] Updater init failed: ' . $e->getMessage());
    }
}

// Show admin notice if Bricks Builder theme is not active
add_action('after_setup_theme', function () {
    if (defined('BRICKS_VERSION') || !is_admin()) return;
    add_action('admin_notices', function () {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Brickser AI</strong> requires the <a href="https://bricksbuilder.io/" target="_blank">Bricks Builder</a> theme to be installed and active.';
        echo '</p></div>';
    });
});

class Brickser_AI {
    use Brickser_Editor;
    use Brickser_Ajax_Sections;
    use Brickser_Ajax_Projects;
    use Brickser_Media_Bundle;
    use Brickser_Ajax_Styles;
    use Brickser_Ajax_Design_System;
    use Brickser_Ajax_License;
    use Brickser_Ajax_Worker;
    use Brickser_Frontend;
    use Brickser_Helpers;
    use Brickser_Rename_Migration;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'init']);
        // AJAX handlers must be registered outside the editor check
        // because admin-ajax.php requests don't have ?bricks=run
        add_action('wp_ajax_brickser_insert_sections', [$this, 'insert_sections']);
        add_action('wp_ajax_brickser_revert_content', [$this, 'revert_content']);
        add_action('wp_ajax_brickser_create_page', [$this, 'create_page']);
        add_action('wp_ajax_brickser_trash_page', [$this, 'trash_page']);
        add_action('wp_ajax_brickser_list_pages', [$this, 'list_pages']);
        add_action('wp_ajax_brickser_get_attached_project', [$this, 'get_attached_project']);
        add_action('wp_ajax_brickser_check_pages', [$this, 'check_pages']);
        add_action('wp_ajax_brickser_update_page_map', [$this, 'update_page_map']);
        add_action('wp_ajax_brickser_cleanup_project', [$this, 'cleanup_project']);

        // Single-project-per-site (WP-backed)
        add_action('wp_ajax_brickser_project_get',    [$this, 'project_get']);
        add_action('wp_ajax_brickser_project_save',   [$this, 'project_save']);
        add_action('wp_ajax_brickser_project_delete', [$this, 'project_delete']);
        add_action('wp_ajax_brickser_project_import', [$this, 'project_import']);
        add_action('wp_ajax_brickser_project_export', [$this, 'project_export']);
        add_action('wp_ajax_brickser_project_export_zip', [$this, 'project_export_zip']);
        add_action('wp_ajax_brickser_project_import_zip', [$this, 'project_import_zip']);
        // Surgical page autosave (Bricks Save hook) — must NOT round-trip style_config,
        // otherwise it races with brickser_save_style on the same option row and
        // clobbers fresh palette writes with a stale client snapshot.
        add_action('wp_ajax_brickser_autosave_page_content', [$this, 'autosave_page_content']);

        // Component import: merge into bricks_components + re-sign SVG/code
        add_action('wp_ajax_brickser_merge_components', [$this, 'merge_components']);

        // Sign SVG/code elements for the current site before canvas insertion
        add_action('wp_ajax_brickser_sign_elements', [$this, 'sign_elements']);

        // Style system AJAX handlers
        add_action('wp_ajax_brickser_save_all_styles', [$this, 'save_all_styles']);
        add_action('wp_ajax_brickser_save_style',         [$this, 'save_style']);
        add_action('wp_ajax_brickser_reset_frontend_styles', [$this, 'reset_frontend_styles']);

        // Design system installer (theme style + variables + template bundle)
        add_action('wp_ajax_brickser_apply_design_system',  [$this, 'apply_design_system']);
        add_action('wp_ajax_brickser_design_system_status', [$this, 'design_system_status']);

        // License (SureCart) AJAX handlers
        add_action('wp_ajax_brickser_license_activate',   [$this, 'license_activate']);
        add_action('wp_ajax_brickser_license_deactivate', [$this, 'license_deactivate']);
        add_action('wp_ajax_brickser_license_status',     [$this, 'license_status']);
        add_action('wp_ajax_brickser_license_save_byok',  [$this, 'license_save_byok']);
        add_action('wp_ajax_brickser_license_test_byok',  [$this, 'license_test_byok']);
        add_action('wp_ajax_brickser_license_delete_byok',[$this, 'license_delete_byok']);

        // Model tier picker (Default / Pro) — per-site option
        add_action('wp_ajax_brickser_get_model_tier',     [$this, 'get_model_tier']);
        add_action('wp_ajax_brickser_save_model_tier',    [$this, 'save_model_tier']);

        // Server-side proxy for worker AI calls (keeps license token off the browser)
        add_action('wp_ajax_brickser_worker_proxy',       [$this, 'worker_proxy']);

        // Token rename migration cron handler (self-rescheduling batch sweep)
        add_action('bkr_rename_sweep', [$this, 'brickser_run_rename_sweep']);

        // Frontend style injection (only when Bricks theme is active)
        add_action('after_setup_theme', function() {
            if (!defined('BRICKS_VERSION')) return;

            // Enable Bricks query filters so filter-search, filter-radio etc. are registered
            $bricks_settings = get_option('bricks_global_settings', []);
            if (empty($bricks_settings['enableQueryFilters'])) {
                $bricks_settings['enableQueryFilters'] = true;
                update_option('bricks_global_settings', $bricks_settings);
            }

            // wp_head injection wrapped — a thrown exception here would
            // otherwise break the frontend layout entirely. safe_invoke
            // logs and silently swallows so the page still renders.
            add_action('wp_head', function() {
                Brickser_Safety::safe_invoke([$this, 'inject_frontend_styles'], null);
            }, 999);

            // Dynamic font name tags
            add_action('init', [$this, 'register_font_dynamic_tags']);

            // Bricks dynamic-data filters run on EVERY rendered tag. If our
            // callback throws (e.g. unexpected input shape from another
            // filter in the chain), the page dies. safe_invoke returns the
            // original $content on any exception so the chain continues.
            $dynamic_filter = function($content) {
                return Brickser_Safety::safe_invoke([$this, 'filter_bricks_dynamic_data'], $content, $content);
            };
            add_filter('bricks/dynamic_data/render_content', $dynamic_filter, 10, 1);
            add_filter('bricks/dynamic_data/render_tag',     $dynamic_filter, 10, 1);
            add_filter('bricks/frontend/render_data',        $dynamic_filter, 10, 1);
        });

        // Ensure the base Brickser AI page always exists (recreate if trashed/deleted)
        // Skip during AJAX requests — saves 2 DB queries per AJAX call
        if (!wp_doing_ajax()) {
            add_action('admin_init', [__CLASS__, 'get_base_page_id']);
        }

        // Run upgrade checks on admin_init (handles plugin updates)
        add_action('admin_init', [$this, 'maybe_upgrade']);

        // Ensure Bricks color palette exists (covers existing installs that never hit activation hook)
        if (!wp_doing_ajax()) {
            add_action('admin_init', [$this, 'seed_default_palette']);
        }

        // Admin bar shortcut — link to Bricks editor on the base page
        add_action('admin_bar_menu', [$this, 'add_admin_bar_link'], 80);
    }

    /**
     * Add sparkle icon link to the WordPress admin bar.
     */
    public function add_admin_bar_link($wp_admin_bar) {
        if (!current_user_can('edit_posts')) return;

        $page_id = self::get_base_page_id();
        if (!$page_id) return;

        $url = add_query_arg('bricks', 'run', get_permalink($page_id));

        $icon = '<svg viewBox="0 0 200 200" fill="none" width="20" height="20" style="vertical-align:middle;margin-top:-4px">'
            . '<defs><linearGradient id="bkr-ab-g" x1="0%" y1="0%" x2="100%" y2="100%">'
            . '<stop offset="0%" stop-color="#155dfc"/><stop offset="100%" stop-color="#93b4ff"/>'
            . '</linearGradient></defs>'
            . '<path d="M190.111 83.304L163.877 68.971C149.984 61.383 138.61 50.013 130.993 36.108L116.619 9.859C113.283 3.773 106.917 0 99.998 0C93.079 0 86.709 3.781 83.381 9.859L69.007 36.108C61.39 50.013 50.02 61.383 36.123 68.971L9.893 83.304C3.791 86.633 0 93.035 0 100C0 106.965 3.791 113.367 9.889 116.697L36.127 131.029C50.02 138.617 61.39 149.987 69.007 163.892L83.381 190.141C86.709 196.219 93.075 200 99.994 200C106.913 200 113.283 196.227 116.619 190.141L130.993 163.892C138.61 149.987 149.98 138.617 163.877 131.029L190.107 116.697C196.209 113.367 200 106.965 200 100C200 93.035 196.209 86.633 190.111 83.304Z" fill="url(#bkr-ab-g)"/>'
            . '</svg>';

        $wp_admin_bar->add_node([
            'id'    => 'brickser-ai',
            'title' => $icon,
            'href'  => $url,
            'meta'  => ['title' => 'Open Brickser AI'],
        ]);
    }
}

// On activation: create base page + seed Bricks color palette.
// Wrapped in try/catch so a downstream failure (Bricks missing, DB hiccup, etc.)
// never fatals the WP install — the plugin can be re-activated after the fix.
register_activation_hook(__FILE__, function () {
    try {
        Brickser_AI::get_base_page_id();
        Brickser_AI::get_instance()->seed_default_palette();
    } catch (\Throwable $e) {
        error_log('[Brickser AI] Activation hook failed: ' . $e->getMessage());
    }
});

// On deactivation: bake corner/radius prefs into Bricks global classes so styles survive deletion.
// Palette is intentionally kept — it lives in bricks_color_palette and belongs to the site now.
register_deactivation_hook(__FILE__, function () {
    try {
        Brickser_AI::get_instance()->bake_corner_prefs_into_global_classes();
    } catch (\Throwable $e) {
        error_log('[Brickser AI] Deactivation hook failed: ' . $e->getMessage());
    }
});

Brickser_AI::get_instance();
