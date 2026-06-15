<?php
if (!defined('ABSPATH')) exit;

/**
 * Customer-side token rename migration.
 *
 * Variables are referenced by NAME (var(--name)) in content, so renaming a token
 * needs aliasing + reference-rewriting. Classes are id-referenced and ride for
 * free, so they are NOT in renames.json. See the design spec.
 */
trait Brickser_Rename_Migration {

    const RENAME_BATCH = 25;
    const RENAME_SKIP_MINUTES = 5;

    /** Load the variable rename manifest. @return array<int,array{from:string,to:string,since:string}> */
    public function brickser_load_renames(): array {
        $path = BRICKSER_AI_PATH . 'assets/design-system/renames.json';
        if (!file_exists($path)) return [];
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $r) {
            if (!empty($r['from']) && !empty($r['to']) && $r['from'] !== $r['to']) {
                $out[] = [
                    'from'  => (string) $r['from'],
                    'to'    => (string) $r['to'],
                    'since' => (string) ($r['since'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Build [oldName => ['re'=>regex,'rep'=>replacement], ...].
     * Anchored, whitespace-tolerant — mirrors Bricks' updateGlobalVariableReferences.
     */
    public function brickser_build_rename_regex_map(array $renames): array {
        $map = [];
        foreach ($renames as $r) {
            $from = (string) $r['from'];
            $to   = (string) $r['to'];
            if ($from === '' || $to === '') continue;
            $map[$from] = [
                're'  => '/var\(\s*--\s*' . preg_quote($from, '/') . '\s*\)/',
                'rep' => 'var(--' . $to . ')',
            ];
        }
        return $map;
    }

    /**
     * Recursively rewrite var(--old)->var(--new) in every string within $value.
     * @return array{0:mixed,1:bool} [rewritten value, changed?]
     */
    public function brickser_rewrite_var_refs($value, array $map): array {
        $changed = false;
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                [$nv, $c] = $this->brickser_rewrite_var_refs($v, $map);
                if ($c) { $value[$k] = $nv; $changed = true; }
            }
            return [$value, $changed];
        }
        if (is_string($value)) {
            $new = $value;
            foreach ($map as $entry) {
                $new = preg_replace($entry['re'], $entry['rep'], $new);
            }
            if ($new !== $value) return [$new, true];
        }
        return [$value, false];
    }

    /**
     * Build alias variable entries: name=from, value=var(--to), in the deprecated
     * category. Skips any `from` already used by a protected (non-owned) variable
     * so we never clobber a user-created variable.
     */
    public function brickser_build_alias_variables(array $renames, string $dep_cat_id, array $protected_names): array {
        $protected = array_flip($protected_names);
        $out = [];
        foreach ($renames as $r) {
            $from = (string) $r['from'];
            $to   = (string) $r['to'];
            if ($from === '' || $to === '' || isset($protected[$from])) continue;
            $out[] = [
                'id'       => 'bkrdep_' . substr(md5($from), 0, 8),
                'name'     => $from,
                'value'    => 'var(--' . $to . ')',
                'category' => $dep_cat_id,
            ];
        }
        return $out;
    }

    public function brickser_rename_pair_key(array $r): string {
        return ((string) $r['from']) . '>' . ((string) $r['to']);
    }

    /** Renames present in the manifest but not yet recorded as applied on this site. */
    public function brickser_renames_pending(): array {
        $applied = get_option('bkr_renames_applied', []);
        if (!is_array($applied)) $applied = [];
        $applied = array_flip($applied);
        $out = [];
        foreach ($this->brickser_load_renames() as $r) {
            if (!isset($applied[$this->brickser_rename_pair_key($r)])) $out[] = $r;
        }
        return $out;
    }

    public function brickser_mark_renames_applied(array $renames): void {
        $applied = get_option('bkr_renames_applied', []);
        if (!is_array($applied)) $applied = [];
        foreach ($renames as $r) {
            $key = $this->brickser_rename_pair_key($r);
            if (!in_array($key, $applied, true)) $applied[] = $key;
        }
        update_option('bkr_renames_applied', $applied, false);
    }

    /** Rewrite var-refs in the three singleton options (idempotent). */
    public function brickser_sweep_singletons(array $map): void {
        foreach (['bricks_global_classes', 'bricks_theme_styles', 'brickser_current_project'] as $opt) {
            $val = get_option($opt, null);
            if (!is_array($val)) continue;
            [$new, $changed] = $this->brickser_rewrite_var_refs($val, $map);
            if ($changed) update_option($opt, $new, false);
        }
    }

    /**
     * Rewrite one batch of posts holding _bricks_page_content_2, ordered by ID > cursor.
     * Skips posts modified within $skip_minutes (edit-race guard) to avoid fighting an
     * active editor. NOTE: the cursor advances past skipped posts too (anti-stall — a
     * perpetually-edited page must never block the sweep from completing), so a skipped
     * post is NOT re-swept within this migration. It keeps its old var(--name), which
     * still renders correctly via the permanent deprecation alias. Full content rewrite
     * of such pages is deferred (acceptable while aliases exist).
     *
     * @return array{cursor:int,done:bool} new cursor and whether the table is drained
     */
    public function brickser_sweep_pages(array $map, int $cursor, int $batch, int $skip_minutes): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_modified_gmt
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bricks_page_content_2'
              WHERE p.ID > %d
              ORDER BY p.ID ASC
              LIMIT %d",
            $cursor, $batch
        ));

        if (empty($rows)) return ['cursor' => $cursor, 'done' => true];

        $cutoff = time() - ($skip_minutes * 60);
        $new_cursor = $cursor;
        foreach ($rows as $row) {
            $new_cursor = (int) $row->ID;
            // Edit-race guard: skip recently-modified posts. Cursor still advances past
            // them (anti-stall); the alias keeps their old var(--name) rendering.
            if (strtotime($row->post_modified_gmt . ' UTC') > $cutoff) continue;

            $content = get_post_meta((int) $row->ID, '_bricks_page_content_2', true);
            if (!is_array($content)) continue;                 // decode-guard: never corrupt
            [$new, $changed] = $this->brickser_rewrite_var_refs($content, $map);
            if ($changed) update_post_meta((int) $row->ID, '_bricks_page_content_2', $new);
        }

        // Fewer rows than the batch limit => we reached the end of the table.
        $done = count($rows) < $batch;
        return ['cursor' => $new_cursor, 'done' => $done];
    }

    /** WP-cron handler. Processes one batch; re-arms itself until done. */
    public function brickser_run_rename_sweep(): void {
        $state = get_option('bkr_rename_migration', null);
        if (!is_array($state) || ($state['status'] ?? '') !== 'pending') return;

        $map = $this->brickser_build_rename_regex_map($this->brickser_load_renames());
        if (empty($map)) { $state['status'] = 'done'; update_option('bkr_rename_migration', $state, false); return; }

        if (empty($state['singletons_done'])) {
            $this->brickser_sweep_singletons($map);
            $state['singletons_done'] = true;
        }

        $res = $this->brickser_sweep_pages($map, (int) ($state['cursor'] ?? 0), self::RENAME_BATCH, self::RENAME_SKIP_MINUTES);
        $state['cursor'] = $res['cursor'];

        if ($res['done']) {
            $state['status'] = 'done';
            update_option('bkr_rename_migration', $state, false);
        } else {
            update_option('bkr_rename_migration', $state, false);
            if (!wp_next_scheduled('bkr_rename_sweep')) {
                wp_schedule_single_event(time() + 60, 'bkr_rename_sweep');
            }
        }
    }

    /**
     * Called from maybe_upgrade(). $stored_version is the pre-upgrade db version.
     * - Fresh install ('0.0.0'): mark all manifest pairs applied, no sweep (the
     *   onboarding installer ships new names; nothing old exists).
     * - Existing user: install definitions + aliases, mark applied, queue the sweep.
     */
    public function brickser_rename_bootstrap(string $stored_version): void {
        $pending = $this->brickser_renames_pending();
        if (empty($pending)) return;

        if ($stored_version === '0.0.0') {
            $this->brickser_mark_renames_applied($pending);
            return;
        }

        $this->brickser_install_definitions();              // defs + aliases (no base page)
        $this->brickser_mark_renames_applied($pending);
        update_option('bkr_rename_migration', [
            'status' => 'pending', 'cursor' => 0, 'singletons_done' => false,
        ], false);
        if (!wp_next_scheduled('bkr_rename_sweep')) {
            wp_schedule_single_event(time() + 60, 'bkr_rename_sweep');
        }
    }
}
