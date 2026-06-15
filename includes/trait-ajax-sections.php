<?php
if (!defined('ABSPATH')) exit;

trait Brickser_Ajax_Sections {

    public function insert_sections() {
        check_ajax_referer('brickser_ai', 'nonce');

        $post_id        = intval($_POST['post_id'] ?? 0);
        $new_content    = json_decode(stripslashes($_POST['content'] ?? '[]'), true);
        $new_classes    = json_decode(stripslashes($_POST['globalClasses'] ?? '[]'), true);
        $project_id     = sanitize_text_field($_POST['project_id'] ?? '');
        if (!$post_id || !is_array($new_content)) {
            wp_send_json_error('Invalid data');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Unauthorized');
        }

        // Use correct meta key for templates (header/footer) vs regular pages
        $meta_key = $this->get_content_meta_key($post_id);

        // Store originals BEFORE modifying (so JS can revert on Discard)
        $original_content = get_post_meta($post_id, $meta_key, true);
        $original_content = is_array($original_content) ? $original_content : [];
        $original_classes = get_option('bricks_global_classes', []);
        if (!is_array($original_classes)) $original_classes = [];

        // Sign SVG/code elements so Bricks accepts them without "Invalid signature"
        $new_content = $this->sign_code_elements($new_content);

        // Merge elements: existing + new sections appended
        $merged = array_merge($original_content, $new_content);
        update_post_meta($post_id, $meta_key, wp_slash($merged));

        // Mark page as Bricks-edited
        update_post_meta($post_id, '_bricks_editor_mode', 'bricks');

        $this->merge_global_classes($new_classes, $project_id);

        wp_send_json_success([
            'count'           => count($new_content),
            'total'           => count($merged),
            'originalContent' => $original_content,
            'originalClasses' => $original_classes,
        ]);
    }

    public function create_page() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $title = sanitize_text_field($_POST['title'] ?? '');
        if (!$title) wp_send_json_error('No title');

        $template_type = sanitize_text_field($_POST['template_type'] ?? '');
        $slug = sanitize_title($_POST['slug'] ?? $title);

        // Header/footer → create as Bricks template
        if ($template_type === 'header' || $template_type === 'footer') {
            $tpl = $this->find_or_create_template($title, $template_type);
            if (!$tpl) wp_send_json_error('Template creation failed');

            $post_id = $tpl['id'];
            update_post_meta($post_id, '_bricks_editor_mode', 'bricks');
            // Track only newly created templates so project cleanup can delete
            // them later. Reused (existing) templates are left alone.
            if (!empty($tpl['created'])) {
                $this->track_created_post($post_id);
            }
            $edit_url = add_query_arg('bricks', 'run', get_permalink($post_id));
            wp_send_json_success(['postId' => $post_id, 'editUrl' => $edit_url, 'slug' => $slug]);
            return;
        }

        $post_parent = intval($_POST['post_parent'] ?? 0);

        $post_id = wp_insert_post([
            'post_title'  => $title,
            'post_name'   => $slug,
            'post_status' => 'publish',
            'post_type'   => 'page',
            'post_parent' => $post_parent,
        ]);
        if (is_wp_error($post_id)) wp_send_json_error($post_id->get_error_message());

        $actual_slug = get_post_field('post_name', $post_id);
        update_post_meta($post_id, '_bricks_editor_mode', 'bricks');
        // Track this newly-created post so cleanup_project can delete it later.
        $this->track_created_post($post_id);
        $edit_url = add_query_arg('bricks', 'run', get_permalink($post_id));

        wp_send_json_success(['postId' => $post_id, 'editUrl' => $edit_url, 'slug' => $actual_slug]);
    }

    public function trash_page() {
        check_ajax_referer('brickser_ai', 'nonce');

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('No post ID');

        if (!current_user_can('delete_post', $post_id)) wp_send_json_error('Unauthorized');

        wp_trash_post($post_id);
        wp_send_json_success();
    }

    public function check_pages() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $ids_raw = $_POST['post_ids'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

        if (empty($ids)) {
            wp_send_json_success(['existing' => [], 'missing' => []]);
        }

        $found = get_posts([
            'post_type'   => ['page', 'bricks_template'],
            'post_status' => ['publish', 'draft', 'private'],
            'post__in'    => $ids,
            'numberposts' => count($ids),
            'fields'      => 'ids',
        ]);

        $found_set = array_flip($found);
        $missing = array_values(array_filter($ids, fn($id) => !isset($found_set[$id])));

        wp_send_json_success([
            'existing' => $found,
            'missing'  => $missing,
        ]);
    }

    public function list_pages() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ID, post_title, post_name, post_status
             FROM {$wpdb->posts}
             WHERE post_type IN ('page', 'bricks_template')
               AND post_status IN ('publish', 'draft', 'private')
             ORDER BY post_title ASC
             LIMIT 200",
            ARRAY_A
        );

        $result = array_map(function($r) {
            return [
                'id'      => (int) $r['ID'],
                'title'   => $r['post_title'],
                'slug'    => $r['post_name'],
                'status'  => $r['post_status'],
                'editUrl' => add_query_arg('bricks', 'run', get_permalink((int) $r['ID'])),
            ];
        }, $rows ?: []);

        wp_send_json_success($result);
    }

    public function revert_content() {
        check_ajax_referer('brickser_ai', 'nonce');

        $post_id    = intval($_POST['post_id'] ?? 0);
        $content    = json_decode(stripslashes($_POST['content'] ?? '[]'), true);
        $classes    = json_decode(stripslashes($_POST['globalClasses'] ?? '[]'), true);
        $project_id = sanitize_text_field($_POST['project_id'] ?? '');
        if (!$post_id) {
            wp_send_json_error('Invalid data');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Unauthorized');
        }

        $content = is_array($content) ? $content : [];

        // Generate new unique element IDs (same as Bricks' own duplicate)
        $content = $this->generate_new_element_ids($content);

        // Sign SVG/code elements so Bricks accepts them without "Invalid signature"
        $content = $this->sign_code_elements($content);

        // Use correct meta key for templates (header/footer) vs regular pages
        $meta_key = $this->get_content_meta_key($post_id);
        update_post_meta($post_id, $meta_key, wp_slash($content));
        update_post_meta($post_id, '_bricks_editor_mode', 'bricks');

        // Fall back to the current project if caller didn't specify.
        if (!$project_id) {
            $project_id = $this->get_current_project_id();
        }
        $this->merge_global_classes($classes, $project_id);

        wp_send_json_success();
    }
}
