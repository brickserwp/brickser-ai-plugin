<?php
if (!defined('ABSPATH')) exit;

trait Brickser_Ajax_Projects {


    /**
     * Remove classes that this project owns (tracked in bkr_project_classes_*).
     * Preserves Bricks built-in classes (e-* and u-*).
     *
     * Updates ALL Bricks global class options (timestamp, user, trash, etc.)
     * to match how Bricks internally persists changes — prevents sync conflicts.
     *
     * @param array $class_ids - Class IDs to remove
     * @param string $project_id - Project ID (for logging)
     */
    private function remove_project_classes($class_ids, $project_id) {
        if (!is_array($class_ids) || empty($class_ids)) return;

        $existing = get_option('bricks_global_classes', []);
        if (!is_array($existing)) return;

        $before_count = count($existing);
        $remove_set   = array_flip($class_ids);

        $filtered = array_filter($existing, function($c) use ($remove_set) {
            $name = $c['name'] ?? '';
            // Never remove Bricks built-in classes or Brickser system classes
            if (strncmp($name, 'e-', 2) === 0 || strncmp($name, 'u-', 2) === 0) return true;
            if ($name === 'remove-plc-logo' || strncmp($name, 'ov-', 3) === 0) return true;
            // Design-system classes (ds_*, ds__*) belong to imported templates,
            // not the project — keep even if they slipped into tracking.
            if (strncmp($name, 'ds_', 3) === 0) return true;
            return !isset($remove_set[$c['id'] ?? '']);
        });

        $filtered = array_values($filtered);
        $after_count = count($filtered);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Brickser] remove_project_classes: ' . $before_count . ' → ' . $after_count . ' (removed ' . ($before_count - $after_count) . ')');
        }

        // Update bricks_global_classes + timestamp/user (mirrors Bricks'
        // save_global_classes_in_db). Even when the filtered list is empty
        // we WRITE an empty array rather than delete_option — the option
        // belongs to Bricks, not us, and we shouldn't unilaterally remove
        // its row from wp_options. Bricks expects the key to exist.
        update_option('bricks_global_classes', $filtered);
        update_option('bricks_global_classes_timestamp', time());
        update_option('bricks_global_classes_user', get_current_user_id());

        // Also remove these class IDs from trash (so they don't get restored)
        $trash = get_option('bricks_global_classes_trash', []);
        if (is_array($trash) && !empty($trash)) {
            $trash = array_values(array_filter($trash, function($t) use ($remove_set) {
                return !isset($remove_set[$t['id'] ?? '']);
            }));
            update_option('bricks_global_classes_trash', $trash);
        }
    }

    public function update_page_map() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $page_id    = sanitize_text_field($_POST['page_id'] ?? '');
        $wp_post_id = intval($_POST['wp_post_id'] ?? 0);

        if (!$page_id || !$wp_post_id) wp_send_json_error('Missing parameters');

        $page_map = json_decode(get_option('brickser_page_map', '{}'), true);
        if (!is_array($page_map)) $page_map = [];

        $page_map[$page_id] = $wp_post_id;
        update_option('brickser_page_map', wp_json_encode($page_map), false);

        wp_send_json_success();
    }

    /**
     * Return the current project ID + page map for the JS bootstrap.
     *
     * Naming kept (brickser_get_attached_project) for compatibility with the
     * deployed plugin builds, but the data now derives from the
     * brickser_current_project JSON — single-project-per-site model. There
     * is no longer a separate attach/detach concept.
     */
    public function get_attached_project() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $project_id = $this->get_current_project_id();
        $page_map   = get_option('brickser_page_map', '{}');

        wp_send_json_success([
            'projectId' => $project_id,
            'pageMap'   => json_decode($page_map, true) ?: [],
        ]);
    }

    /**
     * Materialize a project's pages into WordPress: create posts (or reuse by
     * slug), write Bricks meta, merge global classes, and write tracking options.
     * Pure helper — no nonce/cap checks, no JSON response.
     *
     * @param string $project_id  Project UUID
     * @param array  $pages       Array of page objects (id, page_name, bricks_content, global_classes)
     * @return array { pageMap: array<page_id, wp_post_id>, createdPosts: int[] }
     */
    private function materialize_project_pages($project_id, $pages) {
        if (!is_array($pages)) $pages = [];

        $page_map        = [];
        $created_posts   = [];
        $all_new_classes = [];

        // Pre-process pages: collect slugs for batch lookup
        $slug_map        = [];
        $processed_pages = [];
        foreach ($pages as $page) {
            $page_id     = sanitize_text_field($page['id'] ?? '');
            $title       = sanitize_text_field($page['page_name'] ?? 'Untitled');
            $slug        = sanitize_title($title);
            $content     = is_array($page['bricks_content'] ?? null) ? $page['bricks_content'] : [];
            $title_lower = strtolower($title);

            if (!$page_id) continue;

            $processed_pages[] = compact('page_id', 'title', 'slug', 'content', 'title_lower');

            if (is_array($page['global_classes'] ?? null) && !empty($page['global_classes'])) {
                $all_new_classes = array_merge($all_new_classes, $page['global_classes']);
            }

            if ($title_lower !== 'header' && $title_lower !== 'footer') {
                $slug_map[$slug] = true;
            }
        }

        $existing_by_slug = [];
        if (!empty($slug_map)) {
            $existing_pages = get_posts([
                'post_type'     => 'page',
                'post_name__in' => array_keys($slug_map),
                'post_status'   => ['publish', 'draft', 'private'],
                'numberposts'   => count($slug_map),
                'fields'        => 'ids',
            ]);
            foreach ($existing_pages as $pid) {
                $existing_by_slug[get_post_field('post_name', $pid)] = $pid;
            }
        }

        foreach ($processed_pages as $p) {
            $signed_content = $this->sign_code_elements($p['content']);

            // Header/footer → Bricks template
            if ($p['title_lower'] === 'header' || $p['title_lower'] === 'footer') {
                $tpl = $this->find_or_create_template($p['title'], $p['title_lower']);
                if (!$tpl) continue;

                $wp_post_id = $tpl['id'];
                $meta_key   = $this->get_content_meta_key($wp_post_id);

                if (!$tpl['created']) {
                    $orig = get_post_meta($wp_post_id, $meta_key, true);
                    if ($orig) update_post_meta($wp_post_id, '_brickser_original_content', $orig);
                }

                update_post_meta($wp_post_id, $meta_key, wp_slash($signed_content));
                update_post_meta($wp_post_id, '_bricks_editor_mode', 'bricks');

                $page_map[$p['page_id']] = $wp_post_id;
                if ($tpl['created']) $created_posts[] = $wp_post_id;
                continue;
            }

            // Regular page
            if (isset($existing_by_slug[$p['slug']])) {
                $wp_post_id = $existing_by_slug[$p['slug']];
                $orig = get_post_meta($wp_post_id, '_bricks_page_content_2', true);
                if ($orig) update_post_meta($wp_post_id, '_brickser_original_content', $orig);
            } else {
                $wp_post_id = wp_insert_post([
                    'post_title'  => $p['title'],
                    'post_name'   => $p['slug'],
                    'post_status' => 'publish',
                    'post_type'   => 'page',
                ]);
                if (is_wp_error($wp_post_id)) continue;
                $created_posts[] = $wp_post_id;
            }

            update_post_meta($wp_post_id, '_bricks_page_content_2', wp_slash($signed_content));
            update_post_meta($wp_post_id, '_bricks_editor_mode', 'bricks');

            $page_map[$p['page_id']] = $wp_post_id;
        }

        $this->merge_global_classes($all_new_classes, $project_id);

        // Single-project-per-site: brickser_current_project.id IS the active
        // project ID. We no longer mirror it into brickser_attached_project_id.
        update_option('brickser_page_map', wp_json_encode($page_map), false);
        update_option('bkr_created_posts_' . $project_id, $created_posts, false);

        return ['pageMap' => $page_map, 'createdPosts' => $created_posts];
    }

    /**
     * Upsert component definitions into bricks_components, re-sign their
     * SVG/code elements for this site's wp_salt, and track which ids this
     * project introduced so cleanup can remove them later.
     *
     * Pure helper — no nonce/cap checks. Called from project_import().
     */
    private function merge_project_components($project_id, $components) {
        if (!is_array($components) || empty($components)) {
            update_option('bkr_project_components_' . $project_id, [], false);
            return;
        }

        $existing = get_option('bricks_components', []);
        if (!is_array($existing)) $existing = [];
        $existing_by_id = array_column($existing, null, 'id');

        $owned_ids = [];
        foreach ($components as $comp) {
            if (empty($comp['id']) || !is_string($comp['id'])) continue;
            if (!empty($comp['elements']) && is_array($comp['elements'])) {
                $comp['elements'] = $this->sign_code_elements($comp['elements']);
            }
            $existing_by_id[$comp['id']] = $comp;
            $owned_ids[] = $comp['id'];
        }

        update_option('bricks_components', array_values($existing_by_id));
        update_option('bkr_project_components_' . $project_id, array_values(array_unique($owned_ids)), false);
    }

    /**
     * Remove the listed component IDs from bricks_components. Mirrors
     * remove_project_classes(): keeps anything not in $component_ids so
     * components from other projects (or built-in/system ones) survive.
     */
    private function remove_project_components($component_ids) {
        if (!is_array($component_ids) || empty($component_ids)) return;

        $existing = get_option('bricks_components', []);
        if (!is_array($existing)) return;

        $remove_set = array_flip($component_ids);
        $filtered = array_values(array_filter($existing, function($c) use ($remove_set) {
            return !isset($remove_set[$c['id'] ?? '']);
        }));

        // bricks_components is Bricks-owned. Always write — never delete
        // the option, even when the filtered list ends up empty.
        update_option('bricks_components', $filtered);
    }

    /**
     * Permanently delete WP pages/templates and remove orphaned global classes for a project.
     * Self-sufficient: uses bkr_created_posts_ as the authority for what to delete.
     * Works regardless of whether the project is currently attached.
     */
    public function cleanup_project() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('delete_posts')) wp_send_json_error('Unauthorized');

        $project_id = sanitize_text_field($_POST['project_id'] ?? '');
        if ($project_id && !preg_match('/^[a-f0-9\-]{36}$/i', $project_id)) $project_id = '';

        // Pages this project actually created (source of truth for what to delete)
        $created_posts = $project_id ? get_option('bkr_created_posts_' . $project_id, []) : [];
        if (!is_array($created_posts)) $created_posts = [];
        $created_set = array_flip($created_posts);

        $deleted = 0;
        foreach ($created_posts as $pid) {
            $pid = intval($pid);
            if (!$pid || !current_user_can('delete_post', $pid)) continue;
            if (wp_delete_post($pid, true)) $deleted++;
        }

        // Restore original content for reused pages (not created by this project)
        $wp_post_ids = json_decode(stripslashes($_POST['wp_post_ids'] ?? '[]'), true);
        if (is_array($wp_post_ids)) {
            foreach ($wp_post_ids as $pid) {
                $pid = intval($pid);
                if (!$pid || isset($created_set[$pid]) || !get_post($pid)) continue;

                $orig = get_post_meta($pid, '_brickser_original_content', true);
                if ($orig) {
                    $meta_key = $this->get_content_meta_key($pid);
                    update_post_meta($pid, $meta_key, wp_slash($orig));
                    delete_post_meta($pid, '_brickser_original_content');
                }
            }
        }

        // Reset all project styles + clean orphan classes from all projects
        $this->reset_project_styles($project_id);

        wp_send_json_success(['deletedCount' => $deleted]);
    }

    // =========================================================================
    // Single-project-per-site model (WP-backed)
    //
    // Storage: one wp_option row `brickser_current_project` holding the full
    // project JSON (metadata + sitemap + style_config + pages). One project per
    // site; sharing across sites is via JSON import/export, not cloud sync.
    //
    // Shape (authoritative):
    // {
    //   "id": "<uuid>",
    //   "name": "...", "prompt": "...", "business": "...", "language": "...",
    //   "sitemap": [...], "style_config": {...},
    //   "pages": [ { "id": "<uuid>", "page_name": "...", "sort_order": 0,
    //               "status": "published", "bricks_content": [...],
    //               "global_classes": [...], "section_count": 0 } ],
    //   "created_at": "<iso8601>", "updated_at": "<iso8601>"
    // }
    // =========================================================================

    private function validate_project_shape($data) {
        if (!is_array($data)) return false;
        if (empty($data['id']) || !is_string($data['id'])) return false;
        if (!preg_match('/^[a-f0-9\-]{36}$/i', $data['id'])) return false;
        if (!isset($data['pages']) || !is_array($data['pages'])) return false;
        return true;
    }

    /**
     * Return the current project JSON (or null if none).
     */
    public function project_get() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $raw = get_option('brickser_current_project', '');
        if (!$raw) {
            wp_send_json_success(['project' => null]);
        }

        $project = json_decode($raw, true);
        wp_send_json_success(['project' => is_array($project) ? $project : null]);
    }

    /**
     * Create or replace the current project. Body param `project` is a JSON
     * string matching the shape above. Fully replaces whatever was stored.
     */
    public function project_save() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $raw = stripslashes($_POST['project'] ?? '');
        $project = json_decode($raw, true);
        if (!$this->validate_project_shape($project)) {
            wp_send_json_error('Invalid project payload');
        }

        $now = gmdate('c');
        if (empty($project['created_at'])) $project['created_at'] = $now;
        $project['updated_at'] = $now;

        update_option('brickser_current_project', wp_json_encode($project), false);

        wp_send_json_success(['project' => $project]);
    }

    /**
     * Surgical page-content autosave. Reads the project, updates ONLY the
     * matching pages[i] entry, writes back. Never touches style_config.
     *
     * Fires from the Bricks Save XHR hook in parallel with brickser_save_style.
     * The legacy autosave path (a full saveCurrentProject from the client) had a
     * read-modify-write race against save_style on `brickser_current_project`:
     * if its client-side read happened before save_style's write but its server
     * write happened after, the autosave's stale `style_config` snapshot
     * overwrote the freshly persisted palette while save_style's CSS write
     * stuck — producing a project palette / frontend CSS mismatch (e.g. UI
     * shows Soft Rose, canvas renders slate). Doing the read-modify-write
     * server-side, scoped to pages only, eliminates that window.
     */
    public function autosave_page_content() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $wp_post_id = (int) ($_POST['wp_post_id'] ?? 0);
        if ($wp_post_id <= 0) wp_send_json_error('Missing wp_post_id');

        $content_raw = wp_unslash($_POST['bricks_content'] ?? '[]');
        $bricks_content = json_decode($content_raw, true);
        if (!is_array($bricks_content)) wp_send_json_error('Invalid bricks_content');

        $classes_raw = wp_unslash($_POST['global_classes'] ?? '[]');
        $global_classes = json_decode($classes_raw, true);
        if (!is_array($global_classes)) $global_classes = [];

        $project_json = get_option('brickser_current_project', '');
        $project = $project_json ? json_decode($project_json, true) : null;
        if (!is_array($project) || !isset($project['pages']) || !is_array($project['pages'])) {
            wp_send_json_error('No current project');
        }

        $idx = -1;
        foreach ($project['pages'] as $i => $p) {
            if ((int)($p['wp_post_id'] ?? 0) === $wp_post_id) { $idx = $i; break; }
        }
        if ($idx < 0) wp_send_json_error('Page not found for post');

        $section_count = 0;
        foreach ($bricks_content as $el) {
            if (is_array($el) && ($el['name'] ?? '') === 'section') $section_count++;
        }

        $project['pages'][$idx]['bricks_content'] = $bricks_content;
        $project['pages'][$idx]['global_classes'] = $global_classes;
        $project['pages'][$idx]['status']         = 'done';
        $project['pages'][$idx]['section_count']  = $section_count;

        $page_name = $project['pages'][$idx]['page_name'] ?? null;
        if ($page_name && isset($project['sitemap']) && is_array($project['sitemap'])) {
            foreach ($project['sitemap'] as &$s) {
                if (($s['name'] ?? '') === $page_name) $s['sections'] = $section_count;
            }
            unset($s);
        }

        $project['updated_at'] = gmdate('c');
        update_option('brickser_current_project', wp_json_encode($project), false);

        wp_send_json_success(['saved' => true, 'sections' => $section_count]);
    }

    /**
     * Delete the current project row. Does NOT touch attached WP pages, Bricks
     * classes, or templates — detach_project / cleanup_project own that. Call
     * those first if the project is currently attached.
     */
    public function project_delete() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        delete_option('brickser_current_project');
        wp_send_json_success();
    }

    /**
     * Import a project from a JSON string. Stores the project, materializes
     * pages/classes into WordPress, and writes tracking options.
     * Refuses to clobber an existing project unless force=1 is passed.
     */
    public function project_import() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $force = !empty($_POST['force']);

        $raw = stripslashes($_POST['project'] ?? '');
        $project = json_decode($raw, true);
        if (!$this->validate_project_shape($project)) {
            wp_send_json_error('Invalid project payload');
        }

        $res = $this->import_project_payload($project, $force);
        if (!$res['success']) {
            wp_send_json_error(['code' => $res['code'] ?? null, 'message' => $res['message']]);
        }
        wp_send_json_success($res);
    }

    /**
     * Shared (non-HTTP) import pipeline used by BOTH the JSON import endpoint
     * (project_import) and the zip import core (import_zip_core). Takes an
     * already-validated $project array, runs refuse-if-exists / force-cleanup /
     * cross-site URL rewrite / materialize / merge / wp_post_id refresh / save /
     * derive-style, and RETURNS a result array instead of echoing JSON.
     *
     * @return array{success:bool,message:string,code?:string,project?:array,pageMap?:array,urlRewrites?:int}
     */
    public function import_project_payload(array $project, bool $force): array {
        $existing = get_option('brickser_current_project', '');
        if ($existing && !$force) {
            return ['success' => false, 'code' => 'exists', 'message' => 'A project already exists. Pass force=1 to overwrite.'];
        }

        // Force-clean the previous project's WP pages + classes before re-import.
        if ($existing && $force) {
            $prev = json_decode($existing, true);
            $prev_id = is_array($prev) ? ($prev['id'] ?? '') : '';
            if ($prev_id) {
                $created = get_option('bkr_created_posts_' . $prev_id, []);
                if (is_array($created)) {
                    foreach ($created as $pid) {
                        $pid = intval($pid);
                        if ($pid && get_post($pid)) wp_delete_post($pid, true);
                    }
                }
                $owned_classes = get_option('bkr_project_classes_' . $prev_id, []);
                if (is_array($owned_classes) && !empty($owned_classes)) {
                    $this->remove_project_classes($owned_classes, $prev_id);
                }
                $owned_components = get_option('bkr_project_components_' . $prev_id, []);
                if (is_array($owned_components) && !empty($owned_components)) {
                    $this->remove_project_components($owned_components);
                }
                // Reclaim media the previous project sideloaded from a bundle import
                // (tracked in bkr_project_media_<id>) when the INCOMING project no
                // longer references it. The reference check is what protects a
                // same-site re-import, whose URLs still point at those attachments.
                // Only bundle-sideloaded IDs are tracked, so user uploads and
                // pre-existing library items are never touched.
                $tracked_media = get_option('bkr_project_media_' . $prev_id, []);
                if (is_array($tracked_media) && !empty($tracked_media)) {
                    $referenced = $this->project_referenced_attachment_ids($project);
                    $kept = [];
                    foreach ($tracked_media as $att_id) {
                        $att_id = (int) $att_id;
                        if (!$att_id) continue;
                        if (in_array($att_id, $referenced, true)) { $kept[] = $att_id; continue; }
                        wp_delete_attachment($att_id, true);
                    }
                    if (!empty($kept)) {
                        update_option('bkr_project_media_' . $prev_id, array_values(array_unique($kept)), false);
                    } else {
                        delete_option('bkr_project_media_' . $prev_id);
                    }
                }
                delete_option('bkr_created_posts_' . $prev_id);
                delete_option('bkr_project_classes_' . $prev_id);
                delete_option('bkr_project_components_' . $prev_id);
                // Previous project's derived style state — without this,
                // bkr_frontend_css_<old> and bkr_corner_prefs_<old> rows
                // accumulate forever across re-imports. The new project
                // will write its own derived options just below.
                delete_option('bkr_frontend_css_' . $prev_id);
                delete_option('bkr_corner_prefs_' . $prev_id);
                delete_transient('bkr_styles_' . $prev_id);
            }
        }

        // Cross-site URL rewriting. If the export came from a different WP
        // install, every internal absolute URL (e.g. uploaded images at
        // https://source-site.local/wp-content/uploads/...) still points at
        // the source. Rewrite the host so links target THIS site instead.
        // Off-host URLs (CDN, third-party assets) are untouched. The actual
        // media files still need to be copied separately — this only fixes
        // the URL pattern, but it eliminates the "every link goes to the
        // wrong domain" failure mode that otherwise dominates the bug list.
        $source_url     = isset($project['exported_from']) ? (string) $project['exported_from'] : '';
        $target_url     = home_url();
        $rewrites       = 0;
        if ($source_url && $source_url !== $target_url) {
            $rewrites = $this->rewrite_site_urls_in_project($project, $source_url, $target_url);
        }

        $now = gmdate('c');
        $project['created_at'] = $now;
        $project['updated_at'] = $now;

        $result = $this->materialize_project_pages($project['id'], $project['pages']);

        // Persist component definitions into bricks_components so page
        // elements with `cid` actually render. Mirrors merge_components():
        // re-sign SVG/code elements for this site's wp_salt, dedupe by id,
        // then track owned ids in bkr_project_components_<id> for cleanup
        // symmetry with global classes.
        $this->merge_project_components($project['id'], $project['components'] ?? []);

        // Refresh wp_post_id on each page from the freshly-minted page_map.
        // Source-site IDs (1351, 1352, …) came along in the JSON; without
        // this the saved project JSON still carries them and the "Open in
        // Bricks" button hits a non-existent post → Bricks redirects with
        // ?bricks_notice=error_post_type. The page_map is the source of
        // truth for IDs on this site; mirror it back into the JSON before
        // we save the option for any consumer that reads pages[].wp_post_id
        // directly.
        if (is_array($project['pages'] ?? null)) {
            foreach ($project['pages'] as &$page) {
                $page_id = $page['id'] ?? '';
                if ($page_id && isset($result['pageMap'][$page_id])) {
                    $page['wp_post_id'] = $result['pageMap'][$page_id];
                }
            }
            unset($page);
        }

        update_option('brickser_current_project', wp_json_encode($project), false);

        // Derive every dependent style option (bricks_color_palette,
        // bkr_frontend_css_<id>, bkr_corner_prefs_<id>, bricks_theme_styles)
        // from the imported style_config. Without this, the frontend renders
        // with stale/empty CSS and the Bricks color picker shows the previous
        // project's palette until the user opens StyleView and hits Save.
        if (is_array($project['style_config'] ?? null)) {
            try {
                $cfg = $this->normalize_style_config($project['style_config']);
                $this->apply_derived_style_state($project['id'], $cfg);
            } catch (InvalidArgumentException $e) {
                // Malformed style_config: leave derived state alone rather
                // than fail the entire import. User can fix styles via the
                // StyleView reset button afterwards.
            }
        }

        return [
            'success'      => true,
            'project'      => $project,
            'pageMap'      => $result['pageMap'],
            'urlRewrites'  => $rewrites,
            'message'      => sprintf('%d page(s) imported.', count($result['pageMap'])),
        ];
    }

    /**
     * Walk the project payload and replace every occurrence of the source
     * site URL with the target site URL. Mutates $project in place.
     *
     * Implementation: array_walk_recursive over leaf strings. Avoids the
     * JSON-escape trap — wp_json_encode escapes / as \/ by default, so a
     * naïve str_replace on the serialized form would miss every match.
     * Scoped to exact-host matches so off-host URLs (CDN, third-party) are
     * never touched. Returns the number of replacements done.
     */
    private function rewrite_site_urls_in_project(array &$project, $source_url, $target_url) {
        $needle = rtrim((string) $source_url, '/');
        $repl   = rtrim((string) $target_url, '/');
        if ($needle === '' || $needle === $repl) return 0;
        $count = 0;
        array_walk_recursive($project, function (&$value) use ($needle, $repl, &$count) {
            if (!is_string($value) || strpos($value, $needle) === false) return;
            $hit = 0;
            $value = str_replace($needle, $repl, $value, $hit);
            $count += $hit;
        });
        return $count;
    }

    /**
     * Assemble a portable project JSON by merging the stored project metadata
     * with live WordPress state (per-page bricks_content from post meta,
     * project-owned classes from bricks_global_classes filtered by
     * bkr_project_classes_<id>).
     *
     * Returns null if there is no current project.
     */
    private function assemble_project_from_wp() {
        $raw = get_option('brickser_current_project', '');
        if (!$raw) return null;
        $project = json_decode($raw, true);
        if (!is_array($project) || empty($project['id'])) return null;

        $project_id = $project['id'];

        // Map page_id → wp_post_id from the page map (set during import)
        $page_map_json = get_option('brickser_page_map', '{}');
        $page_map      = json_decode($page_map_json, true);
        if (!is_array($page_map)) $page_map = [];

        // Project-owned class IDs (set during import via merge_global_classes)
        $owned_class_ids = get_option('bkr_project_classes_' . $project_id, []);
        if (!is_array($owned_class_ids)) $owned_class_ids = [];
        $owned_set = array_flip($owned_class_ids);

        // Index all bricks_global_classes by id. We export every class actually
        // referenced by a page (or by a component used on a page), NOT just
        // project-owned classes. Catalog-installed classes (e.g. `e-frame`,
        // `e-avatar`, section-specific styles like `pricing-04__cont`) are
        // not "owned" by the project but pages reference them and the import
        // target may not have them — without including them, pages render
        // unstyled on the target. merge_global_classes still only tracks NEW
        // IDs as project-owned, so cleanup symmetry is preserved.
        $all_classes = get_option('bricks_global_classes', []);
        if (!is_array($all_classes)) $all_classes = [];
        $classes_by_id = [];
        foreach ($all_classes as $c) {
            if (is_array($c) && isset($c['id']) && is_string($c['id'])) {
                $classes_by_id[$c['id']] = $c;
            }
        }

        // Index live components by id so collect_class_refs can follow `cid`
        // references on a page into the component definition's elements. Without
        // this, classes that live ONLY inside a reusable component (e.g. an
        // e-btn class used by the global Button component) are filtered out of
        // pages[].global_classes and the import target gets unstyled instances.
        $all_components = get_option('bricks_components', []);
        if (!is_array($all_components)) $all_components = [];
        $components_by_cid = [];
        foreach ($all_components as $c) {
            if (is_array($c) && isset($c['id']) && is_string($c['id'])) {
                $components_by_cid[$c['id']] = $c;
            }
        }

        // Rebuild each page's bricks_content from live post meta
        $pages = is_array($project['pages'] ?? null) ? $project['pages'] : [];
        foreach ($pages as $i => $page) {
            $page_id = $page['id'] ?? '';
            $wp_post_id = isset($page_map[$page_id]) ? intval($page_map[$page_id]) : 0;
            if (!$wp_post_id) continue;

            $meta_key = $this->get_content_meta_key($wp_post_id);
            $live = get_post_meta($wp_post_id, $meta_key, true);
            if (is_array($live)) {
                $pages[$i]['bricks_content'] = $live;
            }
            $pages[$i]['wp_post_id'] = $wp_post_id;

            // Per-page global_classes = every class actually referenced by
            // this page's bricks_content (including classes pulled in via
            // component cid references). We resolve refs against the full
            // bricks_global_classes table — not the project-owned subset —
            // because catalog-installed classes (e-frame, ov-center, etc.)
            // are required for rendering but are not project-owned.
            $referenced = $this->collect_class_refs($live, $components_by_cid);
            $page_classes = [];
            foreach ($referenced as $id => $_v) {
                if (isset($classes_by_id[$id])) {
                    $page_classes[] = $classes_by_id[$id];
                }
            }
            $pages[$i]['global_classes'] = $page_classes;
        }

        $project['pages'] = $pages;

        // Bundle component definitions (bricks_components) referenced by any
        // page's `cid`. The page bricks_content has elements like
        // {name: "component", cid: "btmwst"} that are inert without the
        // matching def in bricks_components — without this, the editor
        // shows empty/broken instances on the import target.
        $referenced_cids = [];
        foreach ($pages as $page) {
            foreach ($this->collect_component_refs($page['bricks_content'] ?? []) as $cid => $_) {
                $referenced_cids[$cid] = true;
            }
        }
        // $all_components already loaded above for components_by_cid indexing.
        $project['components'] = array_values(array_filter($all_components, function($c) use ($referenced_cids) {
            return isset($c['id']) && isset($referenced_cids[$c['id']]);
        }));

        // Stamp the source site URL so cross-site imports can rewrite links
        // back to the target's home_url(). Off-host URLs (CDN, third-party)
        // are unaffected.
        $project['exported_from'] = home_url();
        $project['exported_at']   = gmdate('c');

        return $project;
    }

    /**
     * Walk a Bricks element tree and collect every component ID referenced
     * via element `cid`. Returns a set keyed by component ID. Component
     * instances on a page only render correctly when the matching def is
     * present in bricks_components on the target site.
     */
    private function collect_component_refs($elements) {
        $set = [];
        if (!is_array($elements)) return $set;
        foreach ($elements as $el) {
            if (!is_array($el)) continue;
            $cid = $el['cid'] ?? null;
            if (is_string($cid) && $cid !== '') $set[$cid] = true;
        }
        return $set;
    }

    /**
     * Walk a Bricks element tree and collect every class ID referenced via
     * settings._cssGlobalClasses. Returns a set keyed by class ID.
     *
     * Component instances (elements with a `cid` pointing into the
     * bricks_components definitions) own their classes inside the component
     * definition, not on the instance. Pass $components_by_cid to also pull
     * in classes referenced by any cid'd component this page uses —
     * otherwise the per-page export filter strips those classes out and the
     * import target renders the component unstyled.
     */
    private function collect_class_refs($elements, array $components_by_cid = []) {
        $set = [];
        if (!is_array($elements)) return $set;
        foreach ($elements as $el) {
            if (!is_array($el)) continue;
            $refs = $el['settings']['_cssGlobalClasses'] ?? [];
            if (is_array($refs)) {
                foreach ($refs as $id) {
                    if (is_string($id) && $id !== '') $set[$id] = true;
                }
            }
            // Follow component instance into its definition's elements.
            $cid = $el['cid'] ?? null;
            if (is_string($cid) && $cid !== '' && isset($components_by_cid[$cid])) {
                $comp = $components_by_cid[$cid];
                $comp_elements = is_array($comp['elements'] ?? null) ? $comp['elements'] : [];
                foreach ($this->collect_class_refs($comp_elements, $components_by_cid) as $id => $_v) {
                    $set[$id] = true;
                }
            }
        }
        return $set;
    }

    /**
     * Return the current project as portable JSON, with per-page bricks_content
     * rebuilt from live WordPress state.
     */
    public function project_export() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $project = $this->assemble_project_from_wp();
        if (!$project) wp_send_json_error('No project to export');

        wp_send_json_success(['project' => $project]);
    }
}
