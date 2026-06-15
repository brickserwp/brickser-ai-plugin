<?php
if (!defined('ABSPATH')) exit;

trait Brickser_Ajax_Styles {

    /**
     * Stable palette IDs owned by Brickser inside bricks_color_palette.
     * Listed once so seed / save / remove / live-preview all agree on the set.
     */
    private static $brickser_palette_ids = [ 'bkrmn0', 'bkrac2', 'bkrac3', 'bkrac4' ];
    /** Legacy IDs we used before the per-slot split; stripped on every save. */
    private static $brickser_palette_legacy_ids = [ 'b1a1d0', 'brickser_ai' ];

    /**
     * Normalize an incoming style_config to v2 schema.
     * Migrates v1 (brandColors array) to v2 (palette object).
     * Strips unknown keys. Throws InvalidArgumentException on malformed input.
     *
     * @param array $cfg Incoming config (any version)
     * @return array Normalized v2 config
     * @throws InvalidArgumentException
     */
    public function normalize_style_config( array $cfg ): array {
        // v1 → v2 migration: brandColors array → palette slots
        if ( ! isset( $cfg['_schema_version'] ) && isset( $cfg['brandColors'] ) && is_array( $cfg['brandColors'] ) ) {
            $palette = [
                'brand'   => null,
                'accent2' => null,
                'accent3' => null,
                'accent4' => null,
            ];
            $slot_names = [ 'brand', 'accent2', 'accent3', 'accent4' ];
            foreach ( $cfg['brandColors'] as $i => $bc ) {
                if ( $i >= 4 || ! is_array( $bc ) || empty( $bc['base'] ) ) continue;
                $palette[ $slot_names[ $i ] ] = [
                    'base' => $bc['base'],
                    'name' => $bc['name'] ?? 'Color',
                ];
            }
            $cfg['palette'] = $palette;
            unset( $cfg['brandColors'] );
        }

        // Allowlist of known v2 keys. Anything else gets dropped.
        $allowed = [
            '_schema_version', 'mode', 'palette',
            'customTextPrimary', 'customTextSecondary',
            'customSurfacePrimary', 'customSurfaceSecondary',
            'typography', 'btnRadius', 'cardStyle', 'imageStyle',
            'baseScale', 'custom_css', 'bundle_id',
        ];
        $out = [];
        foreach ( $allowed as $k ) {
            if ( array_key_exists( $k, $cfg ) ) $out[ $k ] = $cfg[ $k ];
        }

        // Required: palette.brand
        if ( ! isset( $out['palette']['brand']['base'] ) || ! is_string( $out['palette']['brand']['base'] ) ) {
            throw new InvalidArgumentException( 'palette.brand.base is required' );
        }

        // Validate every hex in the palette
        $slots = [ 'brand', 'accent2', 'accent3', 'accent4' ];
        foreach ( $slots as $slot ) {
            $val = $out['palette'][ $slot ] ?? null;
            if ( $val === null ) continue;
            if ( ! is_array( $val ) || ! isset( $val['base'] ) ) {
                throw new InvalidArgumentException( "palette.$slot must be null or {base, name}" );
            }
            if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $val['base'] ) ) {
                throw new InvalidArgumentException( "palette.$slot.base must be a 6-digit hex color" );
            }
            $val['name'] = isset( $val['name'] ) ? (string) $val['name'] : 'Color';
            $out['palette'][ $slot ] = $val;
        }

        // Ensure all 4 slot keys exist (null if absent)
        foreach ( $slots as $slot ) {
            if ( ! array_key_exists( $slot, $out['palette'] ) ) $out['palette'][ $slot ] = null;
        }

        // Validate mode
        $out['mode'] = ( ( $out['mode'] ?? 'light' ) === 'dark' ) ? 'dark' : 'light';

        // Stamp schema version
        $out['_schema_version'] = 2;
        return $out;
    }

    /**
     * Generate frontend CSS from a normalized v2 style_config.
     * Mirrors the JS `generateFrontendCSS` but server-side so import doesn't
     * need to round-trip through the client.
     */
    public function derive_frontend_css( array $cfg ): string {
        $vars = [];

        // Brand
        $brand = $cfg['palette']['brand']['base'];
        $brand_fg = $this->best_fg_color( $brand );
        $vars['clr-brand-raw'] = $brand;
        $vars['clr-brand-fg-raw'] = $brand_fg;
        $vars['clr-brand'] = $brand;
        $vars['clr-brand-fg'] = $brand_fg;
        $vars['clr-brand-inv'] = $brand;
        $vars['clr-brand-hover'] = $this->hex_shift_lightness( $brand, -8 );
        $vars['clr-brand-inv-hover'] = $this->hex_to_rgba( $brand, 0.08 );

        // Accent slots — N=2,3,4. If null → fall back to brand variable.
        // Each filled slot also emits Tailwind-numbered shade tokens
        // (--clr-accent-N-50, -100, -300, -500, -700, -900, -950). Stop 500
        // is overridden to the user's chosen accent hex so it matches the
        // canonical accent color exactly (vs. a derived L=50 stop).
        foreach ( [ 2, 3, 4 ] as $n ) {
            $slot = $cfg['palette'][ 'accent' . $n ] ?? null;
            if ( $slot && isset( $slot['base'] ) ) {
                $a = $slot['base'];
                $afg = $this->best_fg_color( $a );
                $vars[ "clr-accent-$n-raw" ] = $a;
                $vars[ "clr-accent-$n-fg-raw" ] = $afg;
                $vars[ "clr-accent-$n" ] = $a;
                $vars[ "clr-accent-$n-fg" ] = $afg;
                $vars[ "clr-accent-$n-hover" ] = $this->hex_shift_lightness( $a, -8 );
                $shades = $this->generate_brand_shades( $a );
                $shades[3] = $a; // shade-500 = the user's accent
                foreach ( self::$accent_shade_stops as $i => $stop ) {
                    $vars[ "clr-accent-$n-$stop" ] = $shades[ $i ];
                }
            } else {
                // Fallback: every accent variable resolves to its brand equivalent.
                $vars[ "clr-accent-$n-raw" ] = 'var(--clr-brand-raw)';
                $vars[ "clr-accent-$n-fg-raw" ] = 'var(--clr-brand-fg-raw)';
                $vars[ "clr-accent-$n" ] = 'var(--clr-brand)';
                $vars[ "clr-accent-$n-fg" ] = 'var(--clr-brand-fg)';
                $vars[ "clr-accent-$n-hover" ] = 'var(--clr-brand-hover)';
            }
        }

        // Text/Surface — mode-aware defaults with custom overrides
        $is_light = $cfg['mode'] === 'light';
        $tp = $cfg['customTextPrimary']      ?? ( $is_light ? '#2D2E32' : '#F8F7F7' );
        $ts = $cfg['customTextSecondary']    ?? ( $is_light ? '#535862' : '#C4C2BF' );
        $sp = $cfg['customSurfacePrimary']   ?? ( $is_light ? '#FFFFFF' : '#0F0D0B' );
        $ss = $cfg['customSurfaceSecondary'] ?? ( $is_light ? '#F5F5F5' : '#221F1C' );
        $vars['clr-text-primary'] = $tp;
        $vars['clr-text-secondary'] = $ts;
        $vars['clr-text-tertiary'] = $sp;
        $vars['clr-bg-primary'] = $sp;
        $vars['clr-bg-secondary'] = $ss;
        $vars['clr-bg-tertiary'] = $ss;
        $vars['clr-border-color'] = $tp . '20';

        // Button radius. Map MUST cover every value JS RADIUS_OPTIONS exposes
        // (none/xs/sm/md/lg/full); missing entries silently fall through to
        // '0' and the canvas renders sharp buttons even though style_config
        // and the StyleView preview both say the picked radius. Sync with
        // src/lib/stores/style.js RADIUS_OPTIONS.
        $radius_map = [
            'none' => '0',
            'xs'   => 'var(--radius-xs)',
            'sm'   => 'var(--radius-sm)',
            'md'   => 'var(--radius-md)',
            'lg'   => 'var(--radius-lg)',
            'full' => '9999px',
        ];
        $btn_rad = $radius_map[ $cfg['btnRadius'] ?? 'none' ] ?? '0';
        $vars['btn-radius'] = $btn_rad;

        // Build :root block
        $lines = [];
        foreach ( $vars as $k => $v ) $lines[] = "  --$k: $v";
        $css = ":root {\n" . implode( ";\n", $lines ) . ";\n}";

        // Optional html base-scale
        if ( ! empty( $cfg['baseScale'] ) && (int) $cfg['baseScale'] !== 10 ) {
            $css .= "\n\nhtml { font-size: " . (int) $cfg['baseScale'] . "px; }";
        }

        // .e-heading typography
        if ( ! empty( $cfg['typography']['heading'] ) ) {
            $safe = preg_replace( '/[;:{}()*\\\\<>"\']/', '', $cfg['typography']['heading'] );
            $weight = (int) ( $cfg['typography']['headingWeight'] ?? 600 );
            $css .= "\n\n.e-heading.e-heading { font-family: '$safe', sans-serif; font-weight: $weight; }";
        }

        // Sanitized custom CSS
        if ( ! empty( $cfg['custom_css'] ) ) {
            $cc = wp_strip_all_tags( $cfg['custom_css'] );
            $cc = str_ireplace( '</style', '', $cc );
            $cc = preg_replace( '/@import\b[^;]*/i', '/* @import blocked */', $cc );
            $cc = preg_replace( '/@font-face\s*\{[^}]*\}/i', '/* @font-face blocked */', $cc );
            $cc = preg_replace( '/expression\s*\(/i', '/* expression blocked */(', $cc );
            $cc = preg_replace( '/javascript\s*:/i', '/* blocked */:', $cc );
            $cc = preg_replace( '/url\s*\(\s*[\'"]?\s*data\s*:/i', 'url(/* data: blocked */', $cc );
            $cc = preg_replace( '/url\s*\(\s*[\'"]?\s*https?:/i', 'url(/* external blocked */', $cc );
            $cc = preg_replace( '/behavior\s*:/i', '/* behavior blocked */:', $cc );
            $cc = preg_replace( '/-moz-binding\s*:/i', '/* binding blocked */:', $cc );
            $css .= "\n\n" . $cc;
        }

        return $css;
    }

    /**
     * v2 atomic save: writes style_config (canonical) and all derived options
     * (CSS, palette, fonts, corners) in one request. Replaces the legacy
     * save_all_styles + brickser_project_save dual-write that could drift.
     */
    public function save_style() {
        check_ajax_referer( 'brickser_ai', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Unauthorized' );

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9\-]{36}$/i', $project_id ) ) wp_send_json_error( 'Invalid project_id' );

        $raw = wp_unslash( $_POST['style_config'] ?? '' );
        $cfg = json_decode( $raw, true );
        if ( ! is_array( $cfg ) ) wp_send_json_error( 'Invalid style_config json' );

        try {
            $cfg = $this->normalize_style_config( $cfg );
        } catch ( InvalidArgumentException $e ) {
            wp_send_json_error( $e->getMessage() );
        }

        // Canonical: write style_config into project JSON. Abort on failure.
        $project_json = get_option( 'brickser_current_project', '' );
        $project = $project_json ? json_decode( $project_json, true ) : null;
        if ( ! is_array( $project ) || ( $project['id'] ?? '' ) !== $project_id ) {
            wp_send_json_error( 'Project not found or id mismatch' );
        }
        $project['style_config'] = $cfg;
        $project['updated_at'] = gmdate( 'c' );
        $encoded = wp_json_encode( $project );
        if ( ! update_option( 'brickser_current_project', $encoded, false ) ) {
            // update_option returns false BOTH for genuine failure AND for "value
            // unchanged". Disambiguate by re-reading: if the stored value matches
            // what we just attempted to write, the false return was a no-op
            // (success). If it differs, a real write failure occurred.
            if ( get_option( 'brickser_current_project' ) !== $encoded ) {
                wp_send_json_error( 'Failed to write project' );
            }
        }

        $this->apply_derived_style_state( $project_id, $cfg );

        wp_send_json_success( [ 'saved' => true, 'style_config' => $cfg ] );
    }

    /**
     * Write every derived style option from a normalized v2 config:
     *   - bkr_frontend_css_<id> (CSS variable :root block + font @import)
     *   - bkr_corner_prefs_<id> (radius/card/image prefs)
     *   - bricks_color_palette (Brickser-owned palettes — main + accents)
     *   - bricks_theme_styles  (brickser_ui font-family bindings)
     *   - bkr_active_project_id  (marks this project as the styled one)
     * Plus invalidates the styles transient and purges page caches.
     *
     * Called from save_style AND project_import so an imported project's
     * styles render immediately, instead of waiting for the user to hit
     * Bricks Save. Each derived write is independent — we never roll back
     * the canonical brickser_current_project write on a derived failure.
     */
    private function apply_derived_style_state( string $project_id, array $cfg ): void {
        update_option( 'bkr_frontend_css_' . $project_id, $this->derive_frontend_css( $cfg ), false );
        update_option( 'bkr_corner_prefs_' . $project_id, wp_json_encode( [
            'btnRadius'  => $cfg['btnRadius']  ?? 'none',
            'cardStyle'  => $cfg['cardStyle']  ?? 'sharp',
            'imageStyle' => $cfg['imageStyle'] ?? 'sharp',
        ] ), false );
        $this->derive_and_write_palette( $cfg );
        $this->derive_and_write_theme_styles( $project_id, $cfg );

        update_option( 'bkr_active_project_id', $project_id, true );

        // Clear any baked button-group radius from global classes so the
        // theme-style cascade (`.bricks-button { border-radius:
        // var(--btn-radius) }`) wins again. The catalog ships `.e-btn` with
        // NO `_border.radius` precisely so it inherits from theme style and
        // tracks the live --btn-radius var; baking a literal value on save
        // (introduced briefly during the atomic-style refactor) hardcoded a
        // wrong shape that beat the var in the cascade. Per-class non-zero
        // bakes are still done on plugin DEACTIVATION (see
        // bake_corner_prefs_into_global_classes in trait-frontend.php) so
        // styles survive plugin deletion — that's the only place we want
        // them.
        $this->clear_baked_btn_radius_from_global_classes();

        delete_transient( 'bkr_styles_' . $project_id );
        $this->purge_page_caches();
    }

    /**
     * Reset `_border.radius` on button-group global classes so they fall
     * through to the theme-style cascade ({{btn-radius}} var). Idempotent;
     * skips classes that already lack the setting.
     *
     * Counterpart to bake_corner_prefs_into_global_classes — that runs once
     * on deactivation to survive plugin removal; this runs on every save to
     * keep the live editor working off the cascade.
     */
    private function clear_baked_btn_radius_from_global_classes(): void {
        $classes = get_option( 'bricks_global_classes', [] );
        if ( ! is_array( $classes ) || empty( $classes ) ) return;
        $btn_set = array_flip( [ 'e-btn', 'e-icon', 'e-form', 'pricing__menu', 'pricing__tab1', 'splide__arrow', 'bricks-swiper-button-prev', 'bricks-swiper-button-next' ] );
        $changed = false;
        foreach ( $classes as &$class ) {
            $name = $class['name'] ?? '';
            if ( ! isset( $btn_set[ $name ] ) ) continue;
            if ( isset( $class['settings']['_border']['radius'] ) ) {
                unset( $class['settings']['_border']['radius'] );
                // If _border is now empty, drop it too so the diff stays clean
                if ( empty( $class['settings']['_border'] ) ) {
                    unset( $class['settings']['_border'] );
                }
                $changed = true;
            }
        }
        unset( $class );
        if ( $changed ) {
            update_option( 'bricks_global_classes', $classes );
            update_option( 'bricks_global_classes_timestamp', time() );
        }
    }

    /**
     * Write Bricks color palettes from v2 config.
     *
     * Produces up to 4 palettes inside bricks_color_palette:
     *   - "Main"     (bkrmn0)  always: brand + 7 brand shades + brand-fg + text/bg/border
     *   - "Accent 1" (bkrac2)  only if palette.accent2 set: accent + 7 shades + accent-fg
     *   - "Accent 2" (bkrac3)  only if palette.accent3 set: same shape
     *   - "Accent 3" (bkrac4)  only if palette.accent4 set: same shape
     *
     * Any Brickser-owned palette absent from the computed set is removed from
     * bricks_color_palette — so deleting an accent in StyleView removes its
     * palette on the next save. Legacy "Brickser AI" (b1a1d0) is also stripped.
     */
    private function derive_and_write_palette( array $cfg ): void {
        $built = [];

        // Main palette (always)
        $built[] = [
            'id'     => 'bkrmn0',
            'name'   => 'Main',
            'colors' => $this->build_main_palette_colors( $cfg ),
        ];

        // Accent palettes — only present slots. Palette name matches the slot
        // number directly (accent2 → "Accent 2"), keeping the picker label and
        // the underlying CSS variable namespace (--clr-accent-2-*) aligned.
        $accent_ids = [ 2 => 'bkrac2', 3 => 'bkrac3', 4 => 'bkrac4' ];
        foreach ( $accent_ids as $n => $id ) {
            $slot = $cfg['palette'][ "accent$n" ] ?? null;
            if ( ! is_array( $slot ) || empty( $slot['base'] ) ) continue;
            $built[] = [
                'id'     => $id,
                'name'   => 'Accent ' . $n,
                'colors' => $this->build_accent_palette_colors( $slot['base'], $n ),
            ];
        }

        $this->replace_brickser_palettes( $built );
    }

    /**
     * Build the Main palette colors — the 10 semantic --clr-* values exposed in
     * the Bricks color picker. text-tertiary and bg-tertiary intentionally stay
     * out of the picker (the :root variables still exist for template authors
     * who want them via raw `var(--clr-text-tertiary)` references). `light`
     * snapshots mirror derive_frontend_css's mode-aware + custom-override logic
     * so the swatch matches what gets rendered.
     */
    private function build_main_palette_colors( array $cfg ): array {
        $brand_hex       = $cfg['palette']['brand']['base'];
        $brand_fg        = $this->best_fg_color( $brand_hex );
        $brand_hover     = $this->hex_shift_lightness( $brand_hex, -8 );
        $brand_inv_hover = $this->hex_to_rgba( $brand_hex, 0.08 );

        $is_light = ( $cfg['mode'] ?? 'light' ) === 'light';
        $tp = $cfg['customTextPrimary']      ?? ( $is_light ? '#2D2E32' : '#F8F7F7' );
        $ts = $cfg['customTextSecondary']    ?? ( $is_light ? '#535862' : '#C4C2BF' );
        $sp = $cfg['customSurfacePrimary']   ?? ( $is_light ? '#FFFFFF' : '#0F0D0B' );
        $ss = $cfg['customSurfaceSecondary'] ?? ( $is_light ? '#F5F5F5' : '#221F1C' );

        return [
            [ 'id' => 'brand',           'light' => $brand_hex,       'raw' => 'var(--clr-brand)' ],
            [ 'id' => 'brand-fg',        'light' => $brand_fg,        'raw' => 'var(--clr-brand-fg)' ],
            [ 'id' => 'brand-inv',       'light' => $brand_hex,       'raw' => 'var(--clr-brand-inv)' ],
            [ 'id' => 'brand-hover',     'light' => $brand_hover,     'raw' => 'var(--clr-brand-hover)' ],
            [ 'id' => 'brand-inv-hover', 'light' => $brand_inv_hover, 'raw' => 'var(--clr-brand-inv-hover)' ],
            [ 'id' => 'text-primary',    'light' => $tp,              'raw' => 'var(--clr-text-primary)' ],
            [ 'id' => 'text-secondary',  'light' => $ts,              'raw' => 'var(--clr-text-secondary)' ],
            [ 'id' => 'bg-primary',      'light' => $sp,              'raw' => 'var(--clr-bg-primary)' ],
            [ 'id' => 'bg-secondary',    'light' => $ss,              'raw' => 'var(--clr-bg-secondary)' ],
            [ 'id' => 'border-color',    'light' => $tp . '20',       'raw' => 'var(--clr-border-color)' ],
        ];
    }

    /**
     * Tailwind-style shade stops for the 7 generated shades. Index 3 (L=50)
     * is the canonical "true tone" — we override it with the user's chosen
     * hex so `*-500` is literally the brand accent, not a derived approximation.
     */
    private static $accent_shade_stops = [ 50, 100, 300, 500, 700, 900, 950 ];

    /**
     * Build an Accent palette: 7 uniform shade rows, Tailwind-numbered.
     *
     * No `accent` or `accent-fg` rows — every entry is the same kind of thing
     * (a shade), so import/export/diff is trivial: 7 rows, each `raw` is a CSS
     * variable ref, `light` is the resolved hex preview. The `--clr-accent-N`
     * and `--clr-accent-N-fg` CSS variables still exist on :root for templates
     * that reference them by name; they're just not pickable from the palette.
     * Shade 500 == user's chosen accent hex (canonical brand color).
     *
     * Color IDs are slot-qualified (`accent-2-500`, `accent-3-500`, …). Bare
     * shade ids like `500` would collide across accent palettes: Bricks looks
     * up the picked color by id alone in bricks_color_palette, so two palettes
     * sharing id `500` cause the wrong palette's `raw` to win at render time —
     * e.g., picking Rose from Accent 2 produces `var(--clr-accent-3-500)`.
     */
    private function build_accent_palette_colors( string $hex, int $slot_n ): array {
        $shades = $this->generate_brand_shades( $hex );
        $shades[3] = $hex; // shade-500 = the user's accent (overrides the L=50 derivation)
        $entries = [];
        foreach ( self::$accent_shade_stops as $i => $stop ) {
            $entries[] = [
                'id'    => "accent-{$slot_n}-{$stop}",
                'light' => $shades[ $i ],
                'raw'   => "var(--clr-accent-{$slot_n}-{$stop})",
            ];
        }
        return $entries;
    }

    /**
     * Replace all Brickser-owned palettes in bricks_color_palette with the
     * given set. Strips current + legacy owned IDs first so a previously
     * present "Accent 1" palette disappears when that slot is deleted, and
     * the old single "Brickser AI" palette is migrated away cleanly.
     */
    private function replace_brickser_palettes( array $new_palettes ): void {
        $palettes = get_option( 'bricks_color_palette', [] );
        if ( ! is_array( $palettes ) ) $palettes = [];

        $strip = array_flip( array_merge( self::$brickser_palette_ids, self::$brickser_palette_legacy_ids ) );
        $palettes = array_values( array_filter( $palettes, function ( $p ) use ( $strip ) {
            return ! isset( $strip[ $p['id'] ?? '' ] );
        } ) );

        foreach ( $new_palettes as $pal ) {
            $clean = [];
            foreach ( ( $pal['colors'] ?? [] ) as $c ) {
                if ( empty( $c['id'] ) || empty( $c['raw'] ) ) continue;
                $light = $c['light'] ?? '';
                if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $light ) || preg_match( '/^rgba?\(\s*[\d.,\s]+\)$/', $light ) ) {
                    $light_safe = $light;
                } else {
                    $light_safe = sanitize_text_field( $light );
                }
                $clean[] = [
                    'id'    => sanitize_text_field( $c['id'] ),
                    'light' => $light_safe,
                    'raw'   => sanitize_text_field( $c['raw'] ),
                ];
            }
            $palettes[] = [
                'id'     => sanitize_text_field( $pal['id'] ),
                'name'   => sanitize_text_field( $pal['name'] ),
                'colors' => $clean,
            ];
        }

        update_option( 'bricks_color_palette', $palettes );
    }

    /**
     * Port of JS generateBrandShades — 7 shades from HSL math, pastel → deep.
     * Kept in sync with src/lib/utils/colors.js so save-time palettes match
     * what the StyleView shows in its brand-shade popover.
     */
    private function generate_brand_shades( string $hex ): array {
        list( $h, $s, $l ) = $this->hex_to_hsl( $hex );
        $is_gray = $s < 10;
        return [
            $this->hsl_to_hex( $h, $is_gray ? $s * 0.25 : max( $s * 0.25, 4 ),  95 ),
            $this->hsl_to_hex( $h, $is_gray ? $s * 0.45 : max( $s * 0.45, 10 ), 85 ),
            $this->hsl_to_hex( $h, $is_gray ? $s * 0.75 : max( $s * 0.75, 14 ), 70 ),
            $this->hsl_to_hex( $h, $s, 50 ),
            $this->hsl_to_hex( $h, $is_gray ? $s * 1.05 : min( $s * 1.05, 90 ), 40 ),
            $this->hsl_to_hex( $h, $is_gray ? $s * 0.95 : min( $s * 0.95, 80 ), 22 ),
            $this->hsl_to_hex( $h, $is_gray ? $s * 0.85 : min( $s * 0.85, 70 ), 12 ),
        ];
    }

    private function hex_to_hsl( string $hex ): array {
        $r = hexdec( substr( $hex, 1, 2 ) ) / 255;
        $g = hexdec( substr( $hex, 3, 2 ) ) / 255;
        $b = hexdec( substr( $hex, 5, 2 ) ) / 255;
        $max = max( $r, $g, $b );
        $min = min( $r, $g, $b );
        $l = ( $max + $min ) / 2;
        $h = 0; $s = 0;
        if ( $max !== $min ) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
            if ( $max === $r ) {
                $h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
            } elseif ( $max === $g ) {
                $h = ( $b - $r ) / $d + 2;
            } else {
                $h = ( $r - $g ) / $d + 4;
            }
            $h /= 6;
        }
        return [ $h * 360, $s * 100, $l * 100 ];
    }

    private function hsl_to_hex( float $h, float $s, float $l ): string {
        $h = fmod( $h, 360 ); if ( $h < 0 ) $h += 360; $h /= 360;
        $s = max( 0, min( 100, $s ) ) / 100;
        $l = max( 0, min( 100, $l ) ) / 100;
        if ( $s == 0.0 ) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = $this->hue_to_rgb( $p, $q, $h + 1 / 3 );
            $g = $this->hue_to_rgb( $p, $q, $h );
            $b = $this->hue_to_rgb( $p, $q, $h - 1 / 3 );
        }
        return sprintf( '#%02X%02X%02X',
            (int) round( $r * 255 ),
            (int) round( $g * 255 ),
            (int) round( $b * 255 )
        );
    }

    private function hue_to_rgb( float $p, float $q, float $t ): float {
        if ( $t < 0 ) $t += 1;
        if ( $t > 1 ) $t -= 1;
        if ( $t < 1 / 6 ) return $p + ( $q - $p ) * 6 * $t;
        if ( $t < 1 / 2 ) return $q;
        if ( $t < 2 / 3 ) return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
        return $p;
    }

    private function derive_and_write_theme_styles( string $project_id, array $cfg ): void {
        $h = $cfg['typography']['heading'] ?? '';
        $b = $cfg['typography']['body']    ?? '';
        if ( $h || $b ) {
            $this->update_bricks_fonts(
                $project_id,
                $h, $b,
                (string) ( $cfg['typography']['headingWeight'] ?? '600' ),
                (string) ( $cfg['typography']['bodyWeight']    ?? '400' ),
                (string) ( $cfg['typography']['headingLineHeight'] ?? '1.2' )
            );
        }
    }

    private function best_fg_color( string $hex ): string {
        // Simple luminance test — light bg → dark text, dark bg → light text.
        // Mirrors the JS `bestFgColor` heuristic at a level adequate for derive.
        $r = hexdec( substr( $hex, 1, 2 ) );
        $g = hexdec( substr( $hex, 3, 2 ) );
        $b = hexdec( substr( $hex, 5, 2 ) );
        $lum = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
        return $lum > 0.55 ? '#1a1a1f' : '#f8f7f7';
    }

    private function hex_shift_lightness( string $hex, int $delta_pct ): string {
        $r = hexdec( substr( $hex, 1, 2 ) );
        $g = hexdec( substr( $hex, 3, 2 ) );
        $b = hexdec( substr( $hex, 5, 2 ) );
        $shift = $delta_pct * 2.55;
        $r = max( 0, min( 255, (int) round( $r + $shift ) ) );
        $g = max( 0, min( 255, (int) round( $g + $shift ) ) );
        $b = max( 0, min( 255, (int) round( $b + $shift ) ) );
        return sprintf( '#%02X%02X%02X', $r, $g, $b );
    }

    private function hex_to_rgba( string $hex, float $alpha ): string {
        $r = hexdec( substr( $hex, 1, 2 ) );
        $g = hexdec( substr( $hex, 3, 2 ) );
        $b = hexdec( substr( $hex, 5, 2 ) );
        return "rgba($r, $g, $b, " . round( $alpha, 2 ) . ')';
    }

    /**
     * @deprecated since v2. Use save_style. Kept as shim so any
     * external code that still POSTs to brickser_save_all_styles
     * doesn't break. Internally reads current style_config, applies
     * any legacy params on top, and delegates to save_style.
     */
    public function save_all_styles() {
        check_ajax_referer( 'brickser_ai', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Unauthorized' );

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9\-]{36}$/i', $project_id ) ) wp_send_json_error( 'Invalid project_id' );

        // Pull current style_config off the project so the shim doesn't wipe it.
        $project_json = get_option( 'brickser_current_project', '' );
        $project = $project_json ? json_decode( $project_json, true ) : null;
        $cfg = ( is_array( $project ) && is_array( $project['style_config'] ?? null ) )
            ? $project['style_config']
            : [];

        try {
            $cfg = $this->normalize_style_config( $cfg ?: [ 'palette' => [ 'brand' => [ 'base' => '#1A73E8', 'name' => 'Brand' ] ] ] );
        } catch ( InvalidArgumentException $e ) {
            wp_send_json_error( $e->getMessage() );
        }

        // Apply any legacy params from POST onto the config (preserve back-compat).
        if ( isset( $_POST['heading_font'] ) ) $cfg['typography']['heading'] = sanitize_text_field( wp_unslash( $_POST['heading_font'] ) );
        if ( isset( $_POST['body_font'] ) )    $cfg['typography']['body']    = sanitize_text_field( wp_unslash( $_POST['body_font'] ) );
        if ( isset( $_POST['heading_weight'] ) ) $cfg['typography']['headingWeight'] = (int) $_POST['heading_weight'];
        if ( isset( $_POST['body_weight'] ) )    $cfg['typography']['bodyWeight']    = (int) $_POST['body_weight'];
        if ( isset( $_POST['heading_line_height'] ) ) $cfg['typography']['headingLineHeight'] = sanitize_text_field( wp_unslash( $_POST['heading_line_height'] ) );

        $corner_prefs = wp_unslash( $_POST['corner_prefs'] ?? '' );
        if ( $corner_prefs && ( $cp = json_decode( $corner_prefs, true ) ) ) {
            if ( isset( $cp['btnRadius'] ) )  $cfg['btnRadius']  = sanitize_text_field( $cp['btnRadius'] );
            if ( isset( $cp['cardStyle'] ) )  $cfg['cardStyle']  = sanitize_text_field( $cp['cardStyle'] );
            if ( isset( $cp['imageStyle'] ) ) $cfg['imageStyle'] = sanitize_text_field( $cp['imageStyle'] );
        }

        // Legacy CSS POST is ignored — derived CSS from save_style is canonical.

        // Re-pack into POST so save_style takes over from here.
        $_POST['style_config'] = wp_slash( wp_json_encode( $cfg ) );
        unset( $_POST['heading_font'], $_POST['body_font'], $_POST['heading_weight'], $_POST['body_weight'], $_POST['heading_line_height'], $_POST['corner_prefs'], $_POST['css'], $_POST['palette_colors'] );

        $this->save_style();
    }

    /**
     * AJAX: Reset frontend styles for a project.
     * Only clears visual styles (CSS, fonts, palette, corners).
     * Does NOT touch classes, attachment, or created posts — those belong to detach/delete.
     */
    public function reset_frontend_styles() {
        check_ajax_referer('brickser_ai', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');

        $project_id = sanitize_text_field($_POST['project_id'] ?? '');
        if (!$project_id || !preg_match('/^[a-f0-9\-]{36}$/i', $project_id)) {
            wp_send_json_error('Invalid project_id');
        }

        // Restore fonts from backup before deleting it
        $this->clear_bricks_fonts($project_id);
        // NOTE: Do NOT remove Brickser AI palette here — it's a Bricks editor resource.
        // Palette is only removed on project detach/delete (reset_project_styles).

        // Clear style-only options (not classes, attachment, or created posts)
        delete_option('bkr_frontend_css_' . $project_id);
        delete_option('bkr_corner_prefs_' . $project_id);
        delete_option('bkr_fonts_backup_' . $project_id);
        delete_transient('bkr_styles_' . $project_id);
        $this->purge_page_caches();

        wp_send_json_success(['reset' => true]);
    }

    /**
     * Remove every Brickser-owned palette (current + legacy) from
     * bricks_color_palette. Used on plugin uninstall / project detach.
     */
    public function remove_bricks_color_palette() {
        $palettes = get_option('bricks_color_palette', []);
        if (!is_array($palettes)) return;

        $strip = array_flip( array_merge( self::$brickser_palette_ids, self::$brickser_palette_legacy_ids ) );
        $filtered = array_values(array_filter($palettes, function ($p) use ( $strip ) {
            return ! isset( $strip[ $p['id'] ?? '' ] );
        }));

        if (count($filtered) !== count($palettes)) {
            update_option('bricks_color_palette', $filtered);
        }
    }

    /**
     * Seed the default "Main" palette on plugin activation. Idempotent —
     * exits early if either the current Main palette or the legacy
     * "Brickser AI" palette is already present (the legacy entry is
     * upgraded in place on the first save via replace_brickser_palettes).
     */
    public function seed_default_palette() {
        $palettes = get_option('bricks_color_palette', []);
        if (!is_array($palettes)) $palettes = [];

        $strip = array_flip( self::$brickser_palette_legacy_ids );
        foreach ($palettes as $p) {
            $id = $p['id'] ?? '';
            if ( $id === 'bkrmn0' || isset( $strip[ $id ] ) ) return;
        }

        $palettes[] = [
            'id'     => 'bkrmn0',
            'name'   => 'Main',
            'colors' => $this->build_main_palette_colors( [
                'mode'    => 'light',
                'palette' => [ 'brand' => [ 'base' => '#1A73E8', 'name' => 'Royal Blue' ] ],
            ] ),
        ];
        update_option('bricks_color_palette', $palettes);
    }

    /**
     * Update Bricks theme styles with fonts + weights.
     * Shared by save_all_styles and register_fonts.
     */

    private function update_bricks_fonts($project_id, $heading_font, $body_font, $heading_weight = '600', $body_weight = '400', $heading_line_height = '1.2') {
        $theme_styles = get_option('bricks_theme_styles', []);
        if (!is_array($theme_styles)) $theme_styles = [];
        // Fresh Bricks install may have no theme style entries — create a default.
        if (empty($theme_styles)) {
            $theme_styles = ['default' => ['settings' => []]];
        }

        // Backup the font keys we're about to overwrite (only on first save, don't overwrite existing backup)
        $backup_key = 'bkr_fonts_backup_' . $project_id;
        if (!get_option($backup_key)) {
            $backup = [];
            $font_keys = ['heading', 'body', 'typographyHeadings', 'typographyBody'];
            foreach (['H1', 'H2', 'H3', 'H4', 'H5', 'H6'] as $h) {
                $font_keys[] = 'typographyHeading' . $h;
            }
            foreach ($theme_styles as $sk => $s) {
                $typo = $s['settings']['typography'] ?? [];
                if (!is_array($typo)) continue;
                foreach ($font_keys as $fk) {
                    if (isset($typo[$fk]) && is_array($typo[$fk])) {
                        $backup[$sk][$fk] = $typo[$fk];
                    }
                }
            }
            update_option($backup_key, $backup, false);
        }

        // Two key paths per font:
        //   heading/body → font registration (Google Fonts loading)
        //   typographyHeadings/typographyBody + per-level → CSS generation (actual rendering)
        foreach ($theme_styles as $key => &$style) {
            if (!isset($style['settings'])) $style['settings'] = [];
            if (!isset($style['settings']['typography'])) $style['settings']['typography'] = [];

            if ($heading_font) {
                if (!isset($style['settings']['typography']['heading'])) $style['settings']['typography']['heading'] = [];
                $style['settings']['typography']['heading']['font-family'] = $heading_font;

                if (!isset($style['settings']['typography']['typographyHeadings'])) $style['settings']['typography']['typographyHeadings'] = [];
                $style['settings']['typography']['typographyHeadings']['font-family'] = $heading_font;
                $style['settings']['typography']['typographyHeadings']['font-weight'] = $heading_weight;
                $style['settings']['typography']['typographyHeadings']['line-height'] = $heading_line_height;

                foreach (['H1', 'H2', 'H3', 'H4', 'H5', 'H6'] as $h) {
                    $lvl_key = 'typographyHeading' . $h;
                    if (!isset($style['settings']['typography'][$lvl_key])) $style['settings']['typography'][$lvl_key] = [];
                    $style['settings']['typography'][$lvl_key]['font-family'] = $heading_font;
                    $style['settings']['typography'][$lvl_key]['font-weight'] = $heading_weight;
                    $style['settings']['typography'][$lvl_key]['line-height'] = $heading_line_height;
                }
            }

            if ($body_font) {
                if (!isset($style['settings']['typography']['body'])) $style['settings']['typography']['body'] = [];
                $style['settings']['typography']['body']['font-family'] = $body_font;

                if (!isset($style['settings']['typography']['typographyBody'])) $style['settings']['typography']['typographyBody'] = [];
                $style['settings']['typography']['typographyBody']['font-family'] = $body_font;
                $style['settings']['typography']['typographyBody']['font-weight'] = $body_weight;
            }
        }
        unset($style);

        update_option('bricks_theme_styles', $theme_styles);
    }

}
