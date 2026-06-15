<?php
if (!defined('ABSPATH')) exit;

/**
 * Design system installer.
 *
 * Ships three Bricks-native JSON files (theme style + variables + template)
 * from assets/design-system/ into the WP DB. Idempotent — re-running wipes
 * only resources we own (by ID), preserving any user customizations in
 * other namespaces.
 *
 * Storage targets (all public WP/Bricks contracts, future-safe):
 *   bricks_theme_styles                  → option (assoc array keyed by id)
 *   bricks_global_variables              → option (flat list)
 *   bricks_global_variables_categories   → option (flat list)
 *   bricks_global_classes                → option (flat list, merged by id)
 *   _bricks_page_content_2 on the plugin base page → template content lands here
 *
 * Owned-IDs strategy: we delete only records matching our manifest's
 * `owned.themeStyleId` and `owned.categoryIds`. Global classes merge by id
 * (incoming wins). User customizations in other namespaces stay untouched.
 */
trait Brickser_Ajax_Design_System {

    /**
     * AJAX: install (or re-apply) the design system bundle for this site.
     */
    public function apply_design_system() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        try {
            $stats = $this->brickser_install_design_system();
            wp_send_json_success($stats);
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX: report installed vs. shipped design system version.
     * Lets the UI decide whether to surface a "re-apply" prompt.
     */
    public function design_system_status() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $manifest        = $this->brickser_design_system_manifest();
        $shipped_version = $manifest['version'] ?? '0.0.0';
        $installed       = get_option('brickser_design_system_version', '');

        wp_send_json_success([
            'installed_version' => $installed,
            'shipped_version'   => $shipped_version,
            'update_available'  => $installed === '' ? false : version_compare($shipped_version, $installed, '>'),
            'never_installed'   => $installed === '',
        ]);
    }

    /**
     * Read and validate the bundle manifest.
     *
     * @return array Normalized manifest with version / files / owned keys.
     * @throws \RuntimeException if the manifest is missing or malformed.
     */
    public function brickser_design_system_manifest() {
        $path = BRICKSER_AI_PATH . 'assets/design-system/manifest.json';
        if (!file_exists($path)) {
            throw new \RuntimeException("Design system manifest missing at {$path}");
        }
        $raw = file_get_contents($path);
        $m   = json_decode($raw, true);
        if (!is_array($m)) {
            throw new \RuntimeException('Design system manifest is not valid JSON');
        }
        foreach (['version', 'files', 'owned'] as $k) {
            if (empty($m[$k])) throw new \RuntimeException("Manifest missing required key: {$k}");
        }
        foreach (['themeStyle', 'variables', 'template'] as $k) {
            if (empty($m['files'][$k])) throw new \RuntimeException("Manifest.files missing: {$k}");
        }
        foreach (['themeStyleId', 'categoryIds'] as $k) {
            if (!isset($m['owned'][$k])) throw new \RuntimeException("Manifest.owned missing: {$k}");
        }
        return $m;
    }

    /**
     * Apply the design system bundle. Idempotent.
     *
     * @return array Stats: { theme_styles_written, variables_written, categories_written, templates_written, version }
     * @throws \RuntimeException on missing/invalid bundle data.
     */
    public function brickser_install_design_system() {
        $manifest = $this->brickser_design_system_manifest();
        $owned    = $manifest['owned'];

        $theme_payload    = $this->brickser_load_bundle_file($manifest['files']['themeStyle']);
        $vars_payload     = $this->brickser_load_bundle_file($manifest['files']['variables']);
        $template_payload = $this->brickser_load_bundle_file($manifest['files']['template']);

        $stats = [
            'theme_styles_written' => $this->brickser_install_theme_style($theme_payload, (string) $owned['themeStyleId']),
            'categories_written'   => 0,
            'variables_written'    => 0,
            'templates_written'    => 0,
            'version'              => $manifest['version'],
        ];

        $vars_result = $this->brickser_install_variables($vars_payload, (array) $owned['categoryIds']);
        $stats['categories_written'] = $vars_result['categories'];
        $stats['variables_written']  = $vars_result['variables'];

        $template_result = $this->brickser_install_template($template_payload);
        $stats['templates_written']      = $template_result['template'];
        $stats['global_classes_written'] = $template_result['global_classes'];

        update_option('brickser_design_system_version', (string) $manifest['version'], true);

        // Refresh Bricks' Style Manager CSS file so :root variables land immediately.
        // Wrapped in method_exists() because this is a Bricks 2.2+ internal helper.
        if (method_exists('\\Bricks\\Ajax', 'generate_style_manager_css_file')) {
            \Bricks\Ajax::generate_style_manager_css_file();
        }

        return $stats;
    }

    /**
     * Install/refresh DEFINITIONS ONLY — owned theme style + variables + deprecation
     * aliases. Deliberately does NOT write the base-page template (so a customized
     * base page is never overwritten on auto-update). Used by the rename migration.
     *
     * @return array stats (no 'templates_written' key — proof the template is skipped)
     */
    public function brickser_install_definitions(): array {
        $manifest      = $this->brickser_design_system_manifest();
        $theme_payload = $this->brickser_load_bundle_file($manifest['files']['themeStyle']);
        $vars_payload  = $this->brickser_load_bundle_file($manifest['files']['variables']);
        $owned_cats    = (array) $manifest['owned']['categoryIds'];

        // Protected = existing variable names NOT in an owned category (user/other vars).
        $existing_vars = get_option('bricks_global_variables', []);
        if (!is_array($existing_vars)) $existing_vars = [];
        $owned_flip = array_flip($owned_cats);
        $protected = [];
        foreach ($existing_vars as $v) {
            if (!isset($owned_flip[$v['category'] ?? ''])) $protected[] = $v['name'] ?? '';
        }

        $dep_cat_id = 'bkrdeprecated';
        $aliases = $this->brickser_build_alias_variables($this->brickser_load_renames(), $dep_cat_id, $protected);
        if ($aliases) {
            $vars_payload['categories'][] = ['id' => $dep_cat_id, 'name' => 'Deprecated'];
            $vars_payload['variables']    = array_merge($vars_payload['variables'], $aliases);
            $owned_cats[] = $dep_cat_id;
        }

        $stats = [
            'theme_styles_written' => $this->brickser_install_theme_style($theme_payload, (string) $manifest['owned']['themeStyleId']),
        ];
        $vr = $this->brickser_install_variables($vars_payload, $owned_cats);
        $stats['categories_written'] = $vr['categories'];
        $stats['variables_written']  = $vr['variables'];

        if (method_exists('\\Bricks\\Ajax', 'generate_style_manager_css_file')) {
            \Bricks\Ajax::generate_style_manager_css_file();
        }
        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────
    // Internals — testable, side-effect-free apart from option/post writes.
    // ─────────────────────────────────────────────────────────────────

    /**
     * Read a file from assets/design-system/ and decode as JSON.
     *
     * Filenames come from the shipped manifest, but we strip any path components
     * via basename() so a hypothetical tampered manifest can't read outside the
     * design-system directory (defense-in-depth — the manifest itself is plugin
     * code, not user input).
     */
    private function brickser_load_bundle_file($filename) {
        $safe_name = basename((string) $filename);
        if ($safe_name === '' || $safe_name !== $filename) {
            throw new \RuntimeException("Design system file name not allowed: {$filename}");
        }
        $path = BRICKSER_AI_PATH . 'assets/design-system/' . $safe_name;
        if (!file_exists($path)) {
            throw new \RuntimeException("Design system file missing: {$safe_name}");
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Design system file is not valid JSON: {$safe_name}");
        }
        return $data;
    }

    /**
     * Install/replace the owned theme style.
     *
     * @return int Number of theme style rows written (0 or 1).
     */
    private function brickser_install_theme_style(array $payload, $owned_id) {
        if (empty($payload['settings'])) return 0;

        $styles = get_option('bricks_theme_styles', []);
        if (!is_array($styles)) $styles = [];

        // Remove our previous row by ID (preserve all other styles).
        unset($styles[$owned_id]);

        $label = isset($payload['label']) ? trim((string) $payload['label']) : 'Brickser';

        $styles[$owned_id] = [
            'label'    => $label,
            'settings' => $payload['settings'],
        ];

        update_option('bricks_theme_styles', $styles, false);
        return 1;
    }

    /**
     * Install/replace owned variable categories + their variables.
     *
     * @return array { categories: int, variables: int }
     */
    private function brickser_install_variables(array $payload, array $owned_category_ids) {
        $incoming_cats = isset($payload['categories']) && is_array($payload['categories']) ? $payload['categories'] : [];
        $incoming_vars = isset($payload['variables'])  && is_array($payload['variables'])  ? $payload['variables']  : [];

        $existing_cats = get_option('bricks_global_variables_categories', []);
        $existing_vars = get_option('bricks_global_variables', []);
        if (!is_array($existing_cats)) $existing_cats = [];
        if (!is_array($existing_vars)) $existing_vars = [];

        // Drop our previous categories + their variables. Preserve everything else.
        $existing_cats = array_values(array_filter(
            $existing_cats,
            function ($c) use ($owned_category_ids) {
                return !in_array($c['id'] ?? '', $owned_category_ids, true);
            }
        ));
        $existing_vars = array_values(array_filter(
            $existing_vars,
            function ($v) use ($owned_category_ids) {
                return !in_array($v['category'] ?? '', $owned_category_ids, true);
            }
        ));

        $merged_cats = array_merge($existing_cats, $incoming_cats);
        $merged_vars = array_merge($existing_vars, $incoming_vars);

        update_option('bricks_global_variables_categories', $merged_cats, false);
        update_option('bricks_global_variables',            $merged_vars, true);

        return [
            'categories' => count($incoming_cats),
            'variables'  => count($incoming_vars),
        ];
    }

    /**
     * Install the template into the plugin's base page (the private "Brickser AI"
     * page provisioned by Brickser_AI::get_base_page_id) and merge its global
     * classes site-wide so the page renders with the design system fully wired.
     *
     * @return array { template: int (0|1), global_classes: int merged-or-added }
     */
    private function brickser_install_template(array $payload) {
        if (!class_exists('Brickser_AI') || !method_exists('Brickser_AI', 'get_base_page_id')) {
            return ['template' => 0, 'global_classes' => 0];
        }
        $page_id = (int) \Brickser_AI::get_base_page_id();
        if (!$page_id) {
            return ['template' => 0, 'global_classes' => 0];
        }

        // Write content into the base page. _bricks_page_settings is left to
        // get_base_page_id() (which sets disableHeader/disableFooter to true).
        if (!empty($payload['content']) && is_array($payload['content'])) {
            update_post_meta($page_id, '_bricks_page_content_2', $payload['content']);
        }

        // Merge global classes into the site-wide option. Dedup by id; incoming
        // wins, so re-installs pick up updates without manual cleanup. Other
        // (user-created) classes are preserved.
        $merged_count = 0;
        if (!empty($payload['global_classes']) && is_array($payload['global_classes'])) {
            $existing = get_option('bricks_global_classes', []);
            if (!is_array($existing)) $existing = [];

            $by_id = [];
            foreach ($existing as $c) {
                if (!empty($c['id'])) $by_id[$c['id']] = $c;
            }
            foreach ($payload['global_classes'] as $c) {
                if (!empty($c['id'])) {
                    $by_id[$c['id']] = $c;
                    $merged_count++;
                }
            }
            update_option('bricks_global_classes', array_values($by_id), true);
            // Bump Bricks' classes timestamp so the editor invalidates its cache.
            update_option('bricks_global_classes_timestamp', time(), false);
        }

        return ['template' => 1, 'global_classes' => $merged_count];
    }
}
