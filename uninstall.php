<?php
/**
 * Brickser AI Uninstall
 *
 * Cleans up plugin-owned data when the user deletes the plugin from
 * Plugins > Delete. Does NOT run on deactivation.
 *
 * IMPORTANT — scope:
 *   - Removes ONLY data the plugin itself created (brickser_*, bkr_*,
 *     _brickser_*, brickser_ai_panic*, our user-meta, our transients).
 *   - Touches NO Bricks-owned options (bricks_global_classes,
 *     bricks_components, bricks_color_palette, bricks_theme_styles,
 *     bricks_global_settings). Per-project entries inside those are
 *     scrubbed by reset_project_styles() during a project delete; the
 *     options themselves stay intact for Bricks to keep using.
 *   - Does NOT delete WP posts the user is editing — pages they created
 *     via the plugin remain in wp_posts after uninstall, exactly like
 *     any other plugin that creates content. The tracking option
 *     (bkr_created_posts_*) is removed; the posts are theirs.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

// ── Best-effort remote deactivation ─────────────────────────────────────
// Release this site's SureCart activation slot AND clear the encrypted
// BYOK key on the worker before we wipe the local token. Done first so
// we still have the bearer to authenticate. Short timeout + fail-silent
// — a flaky network must NEVER block plugin deletion. If the call doesn't
// land, the slot can be freed via support, and the worker's encrypted
// key is inert without a token to present.
//
// Worker URL is inlined: uninstall.php runs in isolation and the
// BRICKSER_WORKER_URL constant from the main plugin file isn't defined
// here.
$brickser_token = get_option('brickser_license_token', '');
if (!empty($brickser_token) && function_exists('wp_remote_post')) {
    if (defined('BRICKSER_LOCAL_DEV') && BRICKSER_LOCAL_DEV) {
        $brickser_worker_url = 'http://localhost:8787';
    } elseif (defined('BRICKSER_STAGING') && BRICKSER_STAGING) {
        $brickser_worker_url = 'https://brickser-ai-worker-staging.brickserio.workers.dev';
    } else {
        $brickser_worker_url = 'https://brickser-ai-worker.brickserio.workers.dev';
    }
    wp_remote_post($brickser_worker_url . '/license/deactivate', [
        'timeout'  => 5,
        'blocking' => true,
        'headers'  => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $brickser_token,
        ],
        'body'     => '{}',
    ]);
}

// ── Single-value options the plugin owns (brickser_* prefix) ────────────
$options = [
    // Project + page state
    'brickser_attached_project_id',
    'brickser_current_project',
    'brickser_page_map',
    'brickser_base_page_id',
    'brickser_db_version',

    // License + auth
    'brickser_license_token',
    'brickser_license_key',
    'brickser_license_tier',
    'brickser_license_tier_label',
    'brickser_license_sites_allowed',
    'brickser_byok_provider',
    'brickser_model_tier',

    // Design system installer state
    'brickser_design_system_version',

    // Safety / panic state (added in the safety-net work — must be cleaned
    // so a fresh re-install starts with no leftover panic flag)
    'brickser_ai_panic',
    'brickser_ai_panic_log',

    // Active project pointer (bkr_ prefix is also ours)
    'bkr_active_project_id',
];

foreach ($options as $option) {
    delete_option($option);
}

// ── Per-project options (bkr_<thing>_<project_id>) ──────────────────────
// All bkr_* are plugin-owned. LIKE patterns escape the literal underscores
// so we don't accidentally match unrelated keys.
$bkr_prefixes = [
    'bkr\_frontend\_css\_',
    'bkr\_fonts\_backup\_',
    'bkr\_corner\_prefs\_',
    'bkr\_page\_css\_',
    'bkr\_created\_posts\_',
    'bkr\_project\_classes\_',
    'bkr\_project\_components\_',
];
foreach ($bkr_prefixes as $prefix) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $prefix . '%'
    ));
}

// ── Transients ──────────────────────────────────────────────────────────
delete_transient('brickser_ai_update');

// bkr_styles_<id> per-project transients — wipe both the value and
// timeout rows so we don't leave the timeout half behind as bloat.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bkr\_styles\_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_bkr\_styles\_%'");

// ── Post meta the plugin set ────────────────────────────────────────────
// _brickser_original_content is written by revert_content() so the plugin
// can restore reused pages on cleanup. Once we're uninstalling there's no
// path that reads it again — drop it to avoid leaving forever-orphan rows
// on the user's site.
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
    '_brickser_original_content'
));

