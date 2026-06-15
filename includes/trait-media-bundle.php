<?php
/**
 * Project media bundling: collect same-site uploaded assets into a zip on
 * export, and sideload + remap them into the target media library on import.
 *
 * Lives in its own trait because trait-ajax-projects.php is already large.
 * See docs/superpowers/specs/2026-05-31-media-bundle-import-export-design.md
 */
trait Brickser_Media_Bundle {

    /** File extensions we bundle. SVG is handled specially (sanitized). */
    private static $bundle_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    /**
     * Scan the project for absolute URLs under THIS site's uploads dir whose
     * extension is bundle-able. Returns a deduped list:
     *   [ ['url' => abs_url, 'id' => int|null, 'path' => abs_fs_path|null], ... ]
     * Off-host URLs (stock/worker/CDN) are ignored.
     */
    public function collect_local_media_refs(array $project): array {
        $upload  = wp_upload_dir();
        $baseurl = rtrim($upload['baseurl'], '/');
        $basedir = rtrim($upload['basedir'], '/');
        if ($baseurl === '') return [];

        $found = []; // url => ['url'=>, 'id'=>, 'path'=>]

        // Manual recursion so we can read a sibling 'id' next to a 'url'.
        $walk = function ($node) use (&$walk, &$found, $baseurl, $basedir) {
            if (!is_array($node)) return;
            // Object with a url leaf
            if (isset($node['url']) && is_string($node['url'])) {
                $url = $node['url'];
                if ($this->is_bundleable_upload_url($url, $baseurl)) {
                    if (!isset($found[$url])) {
                        $rel  = ltrim(substr($url, strlen($baseurl)), '/');
                        $path = $basedir . '/' . $rel;
                        $id   = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null;
                        $found[$url] = ['url' => $url, 'id' => $id, 'path' => file_exists($path) ? $path : null];
                    }
                }
            }
            // The {url} object is handled above. This loop handles bare-string upload URLs
            // in other keys (e.g. CSS background, srcset, inline HTML) and recurses into
            // nested arrays. The $k !== 'url' guard avoids re-processing the already-handled
            // url key when the object pattern above matched.
            foreach ($node as $k => $v) {
                if (is_string($v) && $k !== 'url' && $this->is_bundleable_upload_url($v, $baseurl)) {
                    if (!isset($found[$v])) {
                        $rel  = ltrim(substr($v, strlen($baseurl)), '/');
                        $path = $basedir . '/' . $rel;
                        $found[$v] = ['url' => $v, 'id' => null, 'path' => file_exists($path) ? $path : null];
                    }
                } elseif (is_array($v)) {
                    $walk($v);
                }
            }
        };

        $walk($project);
        return array_values($found);
    }

    private function is_bundleable_upload_url(string $url, string $baseurl): bool {
        if (strpos($url, $baseurl . '/') !== 0) return false;
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        return in_array($ext, self::$bundle_exts, true);
    }

    /**
     * Build a portable zip for $project: project.json + manifest.json +
     * media/<uploads-relative-path> for each resolvable local upload.
     * Unresolvable refs are listed in manifest.json under "skipped".
     * Returns the temp zip path, or null on failure.
     */
    public function build_export_zip(array $project): ?string {
        $refs    = $this->collect_local_media_refs($project);
        $upload  = wp_upload_dir();
        $basedir = rtrim($upload['basedir'], '/');

        // wp_tempnam creates a 0-byte file; use that path directly so there is no orphan.
        $tmp = wp_tempnam('brickser-export');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return null;
        }

        $manifest = ['files' => [], 'skipped' => []];
        foreach ($refs as $ref) {
            if (!$ref['path'] || !file_exists($ref['path'])) {
                $manifest['skipped'][] = $ref['url'];
                continue;
            }
            $rel = ltrim(substr($ref['path'], strlen($basedir)), '/'); // 2026/05/x.png
            $zip->addFile($ref['path'], 'media/' . $rel);
            $manifest['files'][] = ['url' => $ref['url'], 'zip_path' => 'media/' . $rel];
        }

        $zip->addFromString('project.json', wp_json_encode($project));
        $zip->addFromString('manifest.json', wp_json_encode($manifest));
        $zip->close();
        return $tmp;
    }

    /** GET/POST brickser_project_export_zip — streams the zip as a download. */
    public function project_export_zip() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_die('Unauthorized', 403);

        if (!class_exists('ZipArchive')) wp_die('ZIP support unavailable on this server', 500);

        $project = $this->assemble_project_from_wp();
        if (!$project) wp_die('No project to export', 404);

        $zip_path = $this->build_export_zip($project);
        if (!$zip_path) wp_die('Failed to build export', 500);

        $slug = sanitize_title($project['name'] ?? 'export') ?: 'export';
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="brickser-project-' . $slug . '.zip"');
        header('Content-Length: ' . filesize($zip_path));
        while (ob_get_level()) ob_end_clean();
        readfile($zip_path);
        @unlink($zip_path);
        exit;
    }

    public function sanitize_svg(string $svg): string {
        if (!class_exists('enshrined\\svgSanitize\\Sanitizer')) {
            // Defensive: vendor missing — never sideload an unsanitized SVG.
            return '';
        }
        $sanitizer = new \enshrined\svgSanitize\Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $clean = $sanitizer->sanitize($svg);
        return is_string($clean) ? $clean : '';
    }

    /**
     * Extract media/ from an opened zip dir, sanitize SVGs, sideload each file
     * into the media library, and return the remap table:
     *   source_url => ['url' => new_url, 'id' => new_id].
     * Deduped by source url. Per-file failures are logged + skipped.
     */
    public function sideload_bundled_media(string $extract_dir, array $manifest): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $map = [];
        foreach (($manifest['files'] ?? []) as $entry) {
            $src_url  = $entry['url'] ?? '';
            $zip_path = $entry['zip_path'] ?? '';
            if (!$src_url || !$zip_path) continue;
            if (isset($map[$src_url])) continue; // dedupe

            $abs = $extract_dir . '/' . $zip_path;
            if (!file_exists($abs)) continue;

            $is_svg = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) === 'svg';
            if ($is_svg) {
                $clean = $this->sanitize_svg((string) file_get_contents($abs));
                if ($clean === '') continue; // refuse empty/failed sanitize
                file_put_contents($abs, $clean);
            }

            // Allow SVG mime for the duration of this sideload.
            $allow_svg = function ($mimes) { $mimes['svg'] = 'image/svg+xml'; return $mimes; };
            if ($is_svg) add_filter('upload_mimes', $allow_svg);

            $file_array = ['name' => basename($abs), 'tmp_name' => $abs];
            // media_handle_sideload moves tmp_name; copy so the original survives reuse.
            $copy = $abs . '.upload';
            copy($abs, $copy);
            $file_array['tmp_name'] = $copy;

            $new_id = media_handle_sideload($file_array, 0);
            if ($is_svg) remove_filter('upload_mimes', $allow_svg);

            if (is_wp_error($new_id)) { @unlink($copy); continue; }
            $map[$src_url] = ['url' => wp_get_attachment_url($new_id), 'id' => (int) $new_id];
        }
        return $map;
    }

    /**
     * Rewrite every reference to a bundled source URL to its new target URL.
     * $map: source_url => ['url' => new_url, 'id' => new_attachment_id].
     * - Object with {url} matching a source: replace url, and sibling id if present.
     * - Any string leaf containing a source url: str_replace (covers CSS url(...),
     *   inline html, srcset, etc.).
     * Returns the number of replacements.
     */
    public function remap_media_refs(array &$project, array $map): int {
        if (empty($map)) return 0;
        $count = 0;

        $walk = function (&$node) use (&$walk, &$count, $map) {
            if (!is_array($node)) return;

            // {id,url} object: exact-match url replacement + sibling id.
            if (isset($node['url']) && is_string($node['url']) && isset($map[$node['url']])) {
                $entry = $map[$node['url']];
                $node['url'] = $entry['url'];
                if (array_key_exists('id', $node) && !empty($entry['id'])) {
                    $node['id'] = $entry['id'];
                }
                $count++;
            }

            foreach ($node as $k => &$v) {
                if (is_array($v)) {
                    $walk($v);
                } elseif (is_string($v) && $k !== 'url') {
                    foreach ($map as $src => $entry) {
                        if (strpos($v, $src) !== false) {
                            $hit = 0;
                            $v = str_replace($src, $entry['url'], $v, $hit);
                            $count += $hit;
                        }
                    }
                }
            }
            unset($v);
        };

        $walk($project);
        return $count;
    }

    /**
     * Import a project from a .zip on disk. Validates, sideloads bundled media,
     * remaps refs, then hands off to the shared import pipeline
     * (import_project_payload). $force overwrites an existing project.
     * Returns ['success' => bool, 'message' => string, 'remapped' => int].
     */
    public function import_zip_core(string $zip_path, bool $force): array {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'ZipArchive unavailable'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return ['success' => false, 'message' => 'Could not open zip'];
        }

        $project_json = $zip->getFromName('project.json');
        $manifest_raw = $zip->getFromName('manifest.json');
        if ($project_json === false) {
            $zip->close();
            return ['success' => false, 'message' => 'project.json missing from package'];
        }
        $project  = json_decode($project_json, true);
        $manifest = $manifest_raw !== false ? (json_decode($manifest_raw, true) ?: []) : [];
        if (!$this->validate_project_shape($project)) {
            $zip->close();
            return ['success' => false, 'message' => 'Invalid project payload'];
        }

        // Exists guard: check BEFORE any extraction/sideload so we never orphan media.
        $existing = get_option('brickser_current_project', '');
        if ($existing && !$force) {
            $zip->close();
            return ['success' => false, 'code' => 'exists', 'message' => 'A project already exists. Pass force=1 to overwrite.'];
        }

        // Same-site re-import: files already exist, skip sideload entirely.
        $same_site = !empty($project['exported_from']) && $project['exported_from'] === home_url();

        $remapped = 0;
        $sideloaded_ids = [];
        if (!$same_site && !empty($manifest['files'])) {
            // Zip-bomb guard: reject packages that are too large or have too many entries.
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat !== false) $total += (int) $stat['size'];
            }
            $MAX_UNCOMPRESSED = 500 * 1024 * 1024; // 500 MB
            $MAX_ENTRIES      = 5000;
            if ($zip->numFiles > $MAX_ENTRIES || $total > $MAX_UNCOMPRESSED) {
                $zip->close();
                return ['success' => false, 'message' => 'Package too large or has too many files'];
            }

            // wp_tempnam creates a 0-byte file; unlink it so we can use the name as a directory.
            $base = wp_tempnam('brickser-import');
            @unlink($base);
            $extract_dir = $base . '.d';
            wp_mkdir_p($extract_dir);

            // Zip-slip mitigation: extract ONLY manifest-listed media paths; reject traversal.
            foreach (($manifest['files'] ?? []) as $entry) {
                $zp = $entry['zip_path'] ?? '';
                // Only extract trusted, manifest-listed media paths; reject traversal.
                if ($zp === '' || strpos($zp, 'media/') !== 0 || strpos($zp, '..') !== false) continue;
                $contents = $zip->getFromName($zp);
                if ($contents === false) continue;
                $dest = $extract_dir . '/' . $zp;
                wp_mkdir_p(dirname($dest));
                // Confinement: ensure the resolved dir is inside the extract dir.
                $base_real = realpath($extract_dir);
                $dir_real  = realpath(dirname($dest));
                if ($base_real === false || $dir_real === false || strpos($dir_real, $base_real) !== 0) continue;
                file_put_contents($dest, $contents);
            }

            $zip->close();

            $map = $this->sideload_bundled_media($extract_dir, $manifest);
            $remapped = $this->remap_media_refs($project, $map);
            foreach ($map as $entry) {
                if (!empty($entry['id'])) $sideloaded_ids[] = (int) $entry['id'];
            }
            $this->rrmdir($extract_dir);
        } else {
            $zip->close();
        }

        // Hand off to the shared import pipeline so the materialize/merge/derive
        // logic runs identically to the JSON path (one import pipeline). This also
        // reclaims the PREVIOUS project's orphaned bundle media on force overwrite.
        $stored = $this->import_project_payload($project, $force);
        if (!$stored['success']) return $stored;

        // Track the attachments we just sideloaded as bundle-owned media for THIS
        // project, so a future force re-import can reclaim them when orphaned.
        // User uploads are never tracked here, so reclaim never touches them.
        if (!empty($sideloaded_ids) && !empty($project['id'])) {
            $key = 'bkr_project_media_' . $project['id'];
            $existing_tracked = get_option($key, []);
            if (!is_array($existing_tracked)) $existing_tracked = [];
            update_option($key, array_values(array_unique(array_merge($existing_tracked, $sideloaded_ids))), false);
        }

        return ['success' => true, 'message' => $stored['message'], 'remapped' => $remapped, 'project' => $stored['project'] ?? null];
    }

    /**
     * Collect the attachment IDs a project references — by explicit sibling id and
     * by resolving each same-host upload URL to its attachment. Used by the force
     * re-import reclaim path to decide which previously sideloaded media is now
     * orphaned (safe to delete) vs. still in use (must be kept).
     */
    public function project_referenced_attachment_ids(array $project): array {
        $ids = [];
        foreach ($this->collect_local_media_refs($project) as $ref) {
            if (!empty($ref['id'])) $ids[(int) $ref['id']] = true;
            $by_url = attachment_url_to_postid($ref['url']);
            if ($by_url) $ids[(int) $by_url] = true;
        }
        return array_keys($ids);
    }

    private function rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** POST brickser_project_import_zip — multipart upload of a .zip package. */
    public function project_import_zip() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        if (empty($_FILES['package']['tmp_name']) || !is_uploaded_file($_FILES['package']['tmp_name'])) {
            wp_send_json_error('No package uploaded');
        }
        $force = !empty($_POST['force']);
        $res = $this->import_zip_core($_FILES['package']['tmp_name'], $force);
        if (!$res['success']) {
            wp_send_json_error(['code' => $res['code'] ?? null, 'message' => $res['message']]);
        }
        wp_send_json_success($res);
    }
}
