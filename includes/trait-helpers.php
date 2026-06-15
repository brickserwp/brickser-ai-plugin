<?php
if (!defined('ABSPATH')) exit;

trait Brickser_Helpers {

    /**
     * Return the ID of the project currently stored on this site.
     *
     * Single-project-per-site model: brickser_current_project is the only
     * source of truth. The legacy brickser_attached_project_id option used
     * to mirror this; new code should always read through this helper.
     * Returns empty string if no project is stored.
     */
    private function get_current_project_id() {
        $raw = get_option('brickser_current_project', '');
        if (!$raw) return '';
        $project = json_decode($raw, true);
        return is_array($project) && !empty($project['id']) ? (string) $project['id'] : '';
    }

    /**
     * Generate new unique 6-char element IDs for Bricks data.
     * Mirrors Bricks' Helpers::generate_new_element_ids().
     */
    private function generate_new_element_ids($elements) {
        if (!is_array($elements) || empty($elements)) return $elements;

        $id_map = [];

        // Build map of old ID → new ID
        foreach ($elements as $el) {
            if (!empty($el['id']) && !isset($id_map[$el['id']])) {
                $id_map[$el['id']] = $this->generate_element_id();
            }
        }

        // Replace all IDs
        foreach ($elements as &$el) {
            if (!empty($el['id']) && isset($id_map[$el['id']])) {
                $el['id'] = $id_map[$el['id']];
            }
            if (!empty($el['parent']) && isset($id_map[$el['parent']])) {
                $el['parent'] = $id_map[$el['parent']];
            }
            if (!empty($el['children']) && is_array($el['children'])) {
                $el['children'] = array_map(function($child_id) use ($id_map) {
                    return isset($id_map[$child_id]) ? $id_map[$child_id] : $child_id;
                }, $el['children']);
            }
        }

        return $elements;
    }

    private function generate_element_id() {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';
        for ($i = 0; $i < 6; $i++) {
            $id .= $chars[wp_rand(0, strlen($chars) - 1)];
        }
        return $id;
    }

    /**
     * Merge global classes into the site-wide bricks_global_classes option.
     * - New classes are added
     * - Existing classes are updated with incoming settings (e.g. stock image URLs)
     * - ALL class IDs are tracked for the project (not just new ones) so cleanup
     *   can reliably remove them even if a previous detach failed
     *
     * Returns array of ALL class IDs that belong to this project.
     */
    private function merge_global_classes($new_classes, $project_id = '') {
        if (!is_array($new_classes) || empty($new_classes)) return [];

        $existing = get_option('bricks_global_classes', []);
        if (!is_array($existing)) $existing = [];
        $existing_by_id = array_column($existing, null, 'id');

        $new_ids = [];
        foreach ($new_classes as $class) {
            if (!isset($class['id'])) continue;
            // Track only classes this project is adding (not pre-existing ones)
            if (!isset($existing_by_id[$class['id']])) {
                $new_ids[] = $class['id'];
            }
            // Always upsert: ensures fresh template settings replace stale/orphaned classes
            $existing_by_id[$class['id']] = $class;
        }
        update_option('bricks_global_classes', array_values($existing_by_id));
        // Sync timestamp/user so Bricks doesn't see stale data
        update_option('bricks_global_classes_timestamp', time());
        update_option('bricks_global_classes_user', get_current_user_id());

        // Only track classes this project actually added (not pre-existing ones)
        if ($project_id && !empty($new_ids)) {
            $key = 'bkr_project_classes_' . $project_id;
            $owned = get_option($key, []);
            if (!is_array($owned)) $owned = [];
            $owned = array_unique(array_merge($owned, $new_ids));
            update_option($key, $owned, false);
        }

        return $new_ids;
    }

    /**
     * Append a freshly-created WP post to the current project's tracking list
     * (bkr_created_posts_<project_id>) so cleanup_project can delete it later.
     *
     * Project ID is read from the single-project-per-site JSON option
     * (brickser_current_project) — works even before any other AJAX has set
     * brickser_attached_project_id. No-op if no current project is stored or
     * the post is already tracked.
     *
     * Only call for posts the plugin actually created — never for reused
     * pages, since reused pages restore _brickser_original_content on cleanup
     * instead of being deleted.
     */
    private function track_created_post($post_id) {
        $post_id = intval($post_id);
        if (!$post_id) return;

        $project_id = $this->get_current_project_id();
        if (!$project_id) return;

        $key = 'bkr_created_posts_' . $project_id;
        $created = get_option($key, []);
        if (!is_array($created)) $created = [];
        if (in_array($post_id, $created, true)) return;
        $created[] = $post_id;
        update_option($key, $created, false);
    }

    /**
     * Get the correct Bricks content meta key for a post.
     * Header templates use _bricks_page_header_2, footer templates use _bricks_page_footer_2,
     * everything else uses _bricks_page_content_2.
     */
    private function get_content_meta_key($post_id) {
        $template_type = get_post_meta($post_id, '_bricks_template_type', true);
        if ($template_type === 'header') return '_bricks_page_header_2';
        if ($template_type === 'footer') return '_bricks_page_footer_2';
        return '_bricks_page_content_2';
    }

    /**
     * Find or create a Bricks template (header/footer).
     * Returns ['id' => WP post ID, 'created' => bool].
     */
    private function find_or_create_template($title, $template_type) {
        $slug = sanitize_title($title);

        // Look for existing template of this type
        $existing = get_posts([
            'post_type'   => 'bricks_template',
            'meta_key'    => '_bricks_template_type',
            'meta_value'  => $template_type,
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => 1,
        ]);

        if (!empty($existing)) {
            return ['id' => $existing[0]->ID, 'created' => false];
        }

        // Create new template
        $wp_post_id = wp_insert_post([
            'post_title'  => ucfirst($title),
            'post_name'   => $slug,
            'post_status' => 'publish',
            'post_type'   => 'bricks_template',
        ]);

        if (is_wp_error($wp_post_id)) return false;

        // Set template type and site-wide conditions
        update_post_meta($wp_post_id, '_bricks_template_type', $template_type);
        update_post_meta($wp_post_id, '_bricks_template_settings', [
            'templateConditions' => [['main' => 'any']],
        ]);

        return ['id' => $wp_post_id, 'created' => true];
    }

    /**
     * Restore Bricks font settings to their pre-Brickser state using the backup.
     * If no backup exists, falls back to removing only the keys Brickser sets.
     */
    private function clear_bricks_fonts($project_id = '') {
        $theme_styles = get_option('bricks_theme_styles', []);
        if (is_array($theme_styles) && !empty($theme_styles)) {
            // Try to restore from backup
            $backup = $project_id ? get_option('bkr_fonts_backup_' . $project_id, []) : [];

            $font_keys = ['heading', 'body', 'typographyHeadings', 'typographyBody'];
            foreach (['H1', 'H2', 'H3', 'H4', 'H5', 'H6'] as $h) {
                $font_keys[] = 'typographyHeading' . $h;
            }

            foreach ($theme_styles as $key => &$style) {
                $typo = &$style['settings']['typography'];
                if (!is_array($typo)) continue;

                $saved = $backup[$key] ?? [];

                foreach ($font_keys as $fk) {
                    // Always drop Brickser's three owned properties first. array_merge
                    // can't do this on its own: if the backup didn't have font-family,
                    // merging would keep the plugin-added font-family in place.
                    unset($typo[$fk]['font-family']);
                    unset($typo[$fk]['font-weight']);
                    if ($fk !== 'body' && $fk !== 'typographyBody') {
                        unset($typo[$fk]['line-height']);
                    }
                    // Then restore each property that existed in the backup.
                    if (isset($saved[$fk]['font-family'])) {
                        $typo[$fk]['font-family'] = $saved[$fk]['font-family'];
                    }
                    if (isset($saved[$fk]['font-weight'])) {
                        $typo[$fk]['font-weight'] = $saved[$fk]['font-weight'];
                    }
                    if (isset($saved[$fk]['line-height']) && $fk !== 'body' && $fk !== 'typographyBody') {
                        $typo[$fk]['line-height'] = $saved[$fk]['line-height'];
                    }
                }
            }
            unset($style);

            update_option('bricks_theme_styles', $theme_styles);
        }

        // Also clear font-family/weight from e-heading in bricks_global_classes
        // (section templates bake heading font into this class on insert)
        $classes = get_option('bricks_global_classes', []);
        if (is_array($classes) && !empty($classes)) {
            $changed = false;
            foreach ($classes as &$class) {
                if (($class['name'] ?? '') === 'e-heading' && isset($class['settings']['_typography'])) {
                    unset($class['settings']['_typography']['font-family']);
                    unset($class['settings']['_typography']['font-weight']);
                    $changed = true;
                }
            }
            unset($class);
            if ($changed) {
                update_option('bricks_global_classes', $classes);
            }
        }
    }

    /**
     * Reset all project styles and clean up all project artifacts.
     * Single shared method — called by both cleanup_project and detach_project.
     *
     * What it does:
     * 1. Removes this project's classes (with protected prefix safeguard)
     * 2. Cleans orphan classes from ALL previous projects
     * 3. Clears all bkr_* options for this project
     * 4. Clears fonts from bricks_theme_styles (Bricks falls back to default)
     * 5. Clears active project + attachment options if they match
     * 6. Purges page caches
     *
     * @param string $project_id - Project UUID
     */
    private function reset_project_styles($project_id) {
        if (!$project_id) return;

        // 1. Remove this project's classes.
        //
        // Tracking option is the primary source — but it under-reports. The
        // merge_global_classes() guard only records IDs that DIDN'T already
        // exist in bricks_global_classes at merge time, so any class that
        // pre-existed (left over from a prior project, manually present in
        // Bricks, or re-introduced after a partial detach) goes untracked.
        // cleanup_orphan_global_classes() can't catch them either because
        // it skips the currently-attached project — which is exactly the
        // project being deleted here. Result: untracked classes leak.
        //
        // Recover the authoritative set by walking the project's stored
        // pages[].global_classes — that's the JSON we ourselves wrote at
        // materialize/import time, so every class actually used by the
        // project appears there regardless of whether tracking caught it.
        $class_ids = get_option('bkr_project_classes_' . $project_id, []);
        if (!is_array($class_ids)) $class_ids = [];

        $stored = get_option('brickser_current_project', '');
        if ($stored) {
            $project = json_decode($stored, true);
            if (is_array($project) && ($project['id'] ?? '') === $project_id) {
                foreach (($project['pages'] ?? []) as $page) {
                    foreach (($page['global_classes'] ?? []) as $class) {
                        if (!empty($class['id'])) $class_ids[] = $class['id'];
                    }
                }
            }
        }

        $class_ids = array_values(array_unique($class_ids));
        if (!empty($class_ids)) {
            $this->remove_project_classes($class_ids, $project_id);
        }

        // 1b. Remove this project's tracked component definitions
        $component_ids = get_option('bkr_project_components_' . $project_id, []);
        if (!empty($component_ids)) {
            $this->remove_project_components($component_ids);
        }

        // 2. Clean orphan classes from ALL past projects (not just this one)
        $this->cleanup_orphan_global_classes();

        // 3. Restore original fonts + remove color palette (before deleting backup)
        $this->clear_bricks_fonts($project_id);
        $this->remove_bricks_color_palette();

        // 4. Clear all project-specific options
        delete_option('bkr_frontend_css_' . $project_id);
        delete_option('bkr_corner_prefs_' . $project_id);
        delete_option('bkr_fonts_backup_' . $project_id);
        delete_option('bkr_project_classes_' . $project_id);
        delete_option('bkr_project_components_' . $project_id);
        delete_option('bkr_created_posts_' . $project_id);
        delete_transient('bkr_styles_' . $project_id);

        // 5. Clear active project if it matches
        if (get_option('bkr_active_project_id') === $project_id) {
            delete_option('bkr_active_project_id');
        }

        // Drop the page map when the project being reset is the current one.
        // Also drop the legacy brickser_attached_project_id option — we now
        // derive the current project ID from brickser_current_project.
        if ($this->get_current_project_id() === $project_id) {
            delete_option('brickser_page_map');
        }
        delete_option('brickser_attached_project_id');

        // 6. Purge page caches
        $this->purge_page_caches();
    }

    /**
     * Purge page caches across popular caching plugins.
     */
    private function purge_page_caches() {
        // LiteSpeed Cache
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
    }

    /**
     * AJAX: Merge imported components into bricks_components WP option.
     * Re-signs SVG/code elements for the current site's wp_salt().
     * Deduplicates by component ID. Returns signed components for the client store.
     */
    public function merge_components() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $components = json_decode(stripslashes($_POST['components'] ?? '[]'), true);
        if (!is_array($components) || empty($components)) {
            wp_send_json_error('No components');
        }

        $existing = get_option('bricks_components', []);
        if (!is_array($existing)) $existing = [];
        $existing_by_id = array_column($existing, null, 'id');

        $merged = [];
        foreach ($components as $comp) {
            if (empty($comp['id'])) continue;

            // Sign SVG/code elements within the component
            if (!empty($comp['elements']) && is_array($comp['elements'])) {
                $comp['elements'] = $this->sign_code_elements($comp['elements']);
            }

            // Always upsert — re-sign ensures valid signatures for this site
            $existing_by_id[$comp['id']] = $comp;
            $merged[] = $comp;
        }

        update_option('bricks_components', array_values($existing_by_id));

        wp_send_json_success(['components' => $merged]);
    }

    /**
     * AJAX: Sign SVG/code elements for the current site's wp_salt().
     * Called by JS before inserting content into the Bricks canvas so the
     * editor iframe can verify signatures without "Invalid signature" errors.
     */
    public function sign_elements() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $elements = json_decode(stripslashes($_POST['elements'] ?? '[]'), true);
        if (!is_array($elements)) wp_send_json_error('Invalid data');

        $signed = $this->sign_code_elements($elements);
        wp_send_json_success(['elements' => $signed]);
    }

    /**
     * Walk elements and re-sign any svg/code elements.
     * Validates SVG safety before signing.
     */
    private function sign_code_elements($elements) {
        $user_id = get_current_user_id();
        $time = time();

        foreach ($elements as &$el) {
            $name = $el['name'] ?? '';

            if (in_array($name, ['code', 'svg'], true) && !empty($el['settings']['code'])) {
                $code = $el['settings']['code'];

                if (!$this->is_safe_svg_code($code)) continue;

                $el['settings']['signature'] = wp_hash($code);
                $el['settings']['user_id'] = $user_id;
                $el['settings']['time'] = $time;
            }
        }
        unset($el);

        return $elements;
    }

    /**
     * Validate that SVG/code content is safe to sign.
     * Rejects script tags, PHP code, and JS event handlers.
     */
    private function is_safe_svg_code($code) {
        // Decode HTML entities first to prevent bypass via &#60;script&#62; etc.
        $code = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lower = strtolower($code);

        // Block script tags
        if (preg_match('/<\s*script/i', $lower)) return false;

        // Block embed/object/iframe/foreignObject/style (XSS vectors inside SVG)
        if (preg_match('/<\s*(iframe|object|embed|foreignObject|style)\b/i', $lower)) return false;

        // Block external references via <use xlink:href="http...">
        if (preg_match('/<\s*use\b[^>]*\bhref\s*=\s*["\']?\s*https?:/i', $code)) return false;

        // Block PHP code
        if (strpos($lower, '<?php') !== false || strpos($lower, '<?=') !== false) return false;

        // Block JS event handlers (onclick, onload, onerror, etc.)
        if (preg_match('/\bon\w+\s*=/i', $code)) return false;

        // Block javascript: URIs
        if (preg_match('/javascript\s*:/i', $lower)) return false;

        // Block data: URIs in href/src (XSS vector)
        if (preg_match('/(?:href|src|xlink:href)\s*=\s*["\']?\s*data\s*:/i', $code)) return false;

        return true;
    }

    /**
     * Check if plugin was updated and run any necessary migrations.
     * Runs on admin_init so it catches both activation and auto-updates.
     */
    public function maybe_upgrade() {
        $stored_version = get_option('brickser_db_version', '0.0.0');

        if (version_compare($stored_version, BRICKSER_AI_VERSION, '>=')) {
            return; // Already up to date
        }

        // v0.2.0: Project style system (frontend injection, animations)
        // No data migration needed — new options are created on first use.
        // Style config uses deep merge in JS to fill missing fields from defaults.

        // v1.0.7: Clean orphaned global classes left by incomplete detach cycles.
        // Previous versions only tracked NEW class IDs in bkr_project_classes_*,
        // so detach couldn't remove classes that already existed. This one-time
        // cleanup removes any brickser-style classes not owned by an active project.
        if (version_compare($stored_version, '1.0.7', '<')) {
            $this->cleanup_orphan_global_classes();
        }

        // Token rename migration (aliases now + lazy background rewrite).
        $this->brickser_rename_bootstrap($stored_version);

        update_option('brickser_db_version', BRICKSER_AI_VERSION, false);
    }

    /**
     * Remove orphaned global classes from deleted projects.
     *
     * Only removes classes that appear in a bkr_project_classes_* tracking list
     * but whose project is not the current one. Classes NOT in any tracking
     * list (user-created in Bricks) are always preserved.
     */
    private function cleanup_orphan_global_classes() {
        $existing = get_option('bricks_global_classes', []);
        if (!is_array($existing) || empty($existing)) return;

        $attached_id = $this->get_current_project_id();

        // Collect class IDs from projects that are NOT currently attached (orphaned projects)
        $orphan_ids = [];
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'bkr\_project\_classes\_%'",
            ARRAY_A
        );
        foreach ($rows as $row) {
            // Extract project_id from option name
            $project_id = str_replace('bkr_project_classes_', '', $row['option_name']);
            // Skip the currently attached project — its classes are still active
            if ($project_id === $attached_id) continue;
            $ids = maybe_unserialize($row['option_value']);
            if (is_array($ids)) {
                $orphan_ids = array_merge($orphan_ids, $ids);
            }
        }

        if (empty($orphan_ids)) return;

        $orphan_set = array_flip($orphan_ids);
        $before = count($existing);

        $filtered = array_filter($existing, function($c) use ($orphan_set) {
            $name = $c['name'] ?? '';
            // Never remove system classes
            if (strncmp($name, 'e-', 2) === 0 || strncmp($name, 'u-', 2) === 0 || strncmp($name, 'ov-', 3) === 0) return true;
            if ($name === 'remove-plc-logo') return true;
            // Design-system classes (ds_*, ds__*) belong to imported templates,
            // not the project — keep even if they were tracked by an orphan.
            if (strncmp($name, 'ds_', 3) === 0) return true;
            // Remove only classes from orphaned (non-attached) projects
            $id = $c['id'] ?? '';
            return !isset($orphan_set[$id]);
        });

        $after = count($filtered);
        if ($after < $before) {
            update_option('bricks_global_classes', array_values($filtered));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Brickser] Orphan cleanup: removed ' . ($before - $after) . ' classes from detached projects');
            }
        }

        // Mirror the class sweep for component definitions: any
        // bkr_project_components_<id> tracking option for a non-attached
        // project means those components belong to a deleted project and
        // should be removed from the live bricks_components option.
        $orphan_component_ids = [];
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'bkr\_project\_components\_%'",
            ARRAY_A
        );
        foreach ($rows as $row) {
            $project_id = str_replace('bkr_project_components_', '', $row['option_name']);
            if ($project_id === $attached_id) continue;
            $ids = maybe_unserialize($row['option_value']);
            if (is_array($ids)) {
                $orphan_component_ids = array_merge($orphan_component_ids, $ids);
            }
        }
        if (!empty($orphan_component_ids)) {
            $this->remove_project_components(array_values(array_unique($orphan_component_ids)));
        }
    }
}
