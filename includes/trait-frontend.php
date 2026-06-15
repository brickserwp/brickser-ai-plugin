<?php
if (!defined('ABSPATH')) exit;

trait Brickser_Frontend {

    /** @var array|null Cached frontend data per request */
    private static $frontend_cache = null;

    /**
     * Load project_id and CSS once per request.
     * Both frontend hooks share this cached result.
     */
    private function get_frontend_data() {
        if (self::$frontend_cache !== null) return self::$frontend_cache;

        $project_id = get_option('bkr_active_project_id');
        if (!$project_id) {
            self::$frontend_cache = ['project_id' => null, 'css' => ''];
            return self::$frontend_cache;
        }

        // Try transient first, fall back to option
        $css = get_transient('bkr_styles_' . $project_id);
        if ($css === false) {
            $css = get_option('bkr_frontend_css_' . $project_id, '');
            if ($css) {
                set_transient('bkr_styles_' . $project_id, $css, HOUR_IN_SECONDS);
            }
        }

        self::$frontend_cache = [
            'project_id' => $project_id,
            'css'        => $css ?: '',
        ];
        return self::$frontend_cache;
    }

    /**
     * Sanitize CSS for safe output inside <style> tags.
     * Prevents tag breakout XSS (e.g. </style><script>).
     */
    private function sanitize_css_output( $css ) {
        return str_ireplace( '</style', '', wp_strip_all_tags( $css ) );
    }

    /**
     * Get the active heading and body font names from Bricks theme styles.
     * @return array ['heading' => string, 'body' => string]
     */
    private function get_active_fonts() {
        $theme_styles = get_option('bricks_theme_styles', []);
        $heading = '';
        $body = '';

        if (is_array($theme_styles)) {
            foreach ($theme_styles as $style) {
                if (!$heading && !empty($style['settings']['typography']['typographyHeadings']['font-family'])) {
                    $heading = $style['settings']['typography']['typographyHeadings']['font-family'];
                }
                if (!$body && !empty($style['settings']['typography']['typographyBody']['font-family'])) {
                    $body = $style['settings']['typography']['typographyBody']['font-family'];
                }
                if ($heading && $body) break;
            }
        }

        return [
            'heading' => $heading ?: 'Default',
            'body'    => $body ?: 'Default',
        ];
    }

    /**
     * Register Bricks dynamic data tags for font names.
     * Usage in Bricks: {bkr_heading_font} and {bkr_body_font}
     */
    public function register_font_dynamic_tags() {
        if (!function_exists('bricks_is_builder') && !class_exists('\Bricks\Integrations\Dynamic_Data\Providers')) {
            // Fallback: use WordPress shortcodes for broader compatibility
            return;
        }

        // Register as simple shortcodes that Bricks renders in dynamic data
        add_shortcode('bkr_heading_font', function() {
            $fonts = $this->get_active_fonts();
            return esc_html($fonts['heading']);
        });

        add_shortcode('bkr_body_font', function() {
            $fonts = $this->get_active_fonts();
            return esc_html($fonts['body']);
        });
    }

    /**
     * Filter Bricks dynamic data to replace {bkr_heading_font} and {bkr_body_font}.
     */
    public function filter_bricks_dynamic_data($content) {
        if (!is_string($content)) return $content;
        if (strpos($content, '{bkr_heading_font}') === false && strpos($content, '{bkr_body_font}') === false) {
            return $content;
        }

        $fonts = $this->get_active_fonts();
        $content = str_replace('{bkr_heading_font}', esc_html($fonts['heading']), $content);
        $content = str_replace('{bkr_body_font}', esc_html($fonts['body']), $content);

        return $content;
    }

    /** Button group classes (always controlled by btnRadius setting). */
    private static $btn_classes = ['e-btn', 'e-icon', 'e-form', 'pricing__menu', 'pricing__tab1', 'splide__arrow', 'bricks-swiper-button-prev', 'bricks-swiper-button-next'];

    /** Card/container group classes. */
    private static $card_classes = [
        'e-accordion', 'e-auth', 'e-block', 'e-card', 'e-div',
        'e-filter-search', 'e-frame', 'e-header-block',
        'e-header-container', 'e-nav-block', 'e-search', 'e-sm-link', 'e-sub-menu',
        'message.error',
    ];

    /** Image group classes. */
    private static $img_classes = ['e-gallery', 'e-img', 'e-logobar', 'e-video'];

    /**
     * Inject corner reset + project CSS into <head>.
     * Hooked to wp_head at priority 999 (after Bricks theme styles).
     *
     * Reads corner preferences to build a selective reset:
     * - Buttons with btnRadius=none → reset to 0
     * - Cards with cardStyle=sharp → reset to 0
     * - Images with imageStyle=sharp → reset to 0
     * Rounded groups are excluded so templates keep their own radius.
     */
    public function inject_frontend_styles() {
        // Skip builder main window (performance) — only render on frontend & builder iframe.
        // Can't use bricks_is_builder_main() because it also matches the iframe (?bricks=run).
        if ( isset( $_GET['bricks'] ) && $_GET['bricks'] !== 'run' ) {
            return;
        }

        // Read corner preferences (default: everything flat)
        $project_id = get_option('bkr_active_project_id');
        $prefs = $project_id
            ? json_decode(get_option('bkr_corner_prefs_' . $project_id, '{}'), true)
            : [];

        $btn_radius = $prefs['btnRadius'] ?? 'none';
        $card_style = $prefs['cardStyle'] ?? 'sharp';
        $image_style = $prefs['imageStyle'] ?? 'sharp';

        // Build selective reset — only flat groups get border-radius: 0
        $reset_classes = [];
        if ($btn_radius === 'none') {
            $reset_classes = array_merge($reset_classes, self::$btn_classes);
        }
        if ($card_style !== 'rounded') {
            $reset_classes = array_merge($reset_classes, self::$card_classes);
        }
        if ($image_style !== 'rounded') {
            $reset_classes = array_merge($reset_classes, self::$img_classes);
        }

        $css_reset = '';

        if ($reset_classes) {
            $selectors = array_map(fn($c) => ".{$c}.{$c}.{$c}", $reset_classes);
            $css_reset .= implode(",\n", $selectors) . " {\n  border-radius: 0 !important;\n}\n";
        }

        // Bricks accordion inner DOM (.accordion-title-wrapper / .accordion-content-wrapper)
        // isn't a direct class match — covered here so cardStyle=sharp also flattens it.
        if ($card_style !== 'rounded') {
            $css_reset .= ".e-accordion .accordion-title-wrapper,\n"
                . ".e-accordion .accordion-content-wrapper {\n  border-radius: 0 !important;\n}\n";
        }

        // Form fields follow the button group — all 6 radius options
        $radius_map = [
            'none' => '0',
            'xs'   => 'var(--radius-xs)',
            'sm'   => 'var(--radius-sm)',
            'md'   => 'var(--radius-md)',
            'lg'   => 'var(--radius-lg)',
            'full' => '9999px',
        ];
        $field_radius = $radius_map[$btn_radius] ?? '0';
        $css_reset .= ".e-form.e-form input:not([type=submit]),\n"
            . ".e-form.e-form select,\n"
            . ".e-form.e-form textarea {\n  border-radius: {$field_radius} !important;\n}\n";

        // `.e-contact` override: fires only at btnRadius=full, and its value
        // tracks the corners setting:
        //   - corners rounded → fields use --radius-sm (gentle round inside
        //     the pill context)
        //   - corners sharp   → fields go to 0 (clean rectangles inside the
        //     pill context, matches the sharp design language)
        // At every other btnRadius value the general .e-form rule already
        // produces a sensible result — no override needed.
        if ($btn_radius === 'full') {
            $contact_field_radius = ($card_style === 'rounded') ? 'var(--radius-sm)' : '0';
            $css_reset .= ".e-form.e-contact.e-contact input:not([type=submit]),\n"
                . ".e-form.e-contact.e-contact select,\n"
                . ".e-form.e-contact.e-contact textarea {\n  border-radius: {$contact_field_radius} !important;\n}\n";
        }

        // Positive button-group radius rule. The Bricks theme style covers
        // `.bricks-button` and form submit via `var(--btn-radius)`, but the
        // other utility classes in this group (`.e-icon`, swiper/splide
        // arrows, pricing tabs) have no theme-style equivalent. Without
        // this rule they render at radius 0 on the frontend even though
        // StyleView's preview shows them rounded.
        //
        // `.e-form` is excluded: the form is a transparent layout container
        // with no background/border, so a radius on it would only show up
        // as a misleading selection outline in the Bricks editor — invisible
        // on the actual frontend. It stays in the reset list above so a
        // btnRadius=none save still clears any pre-existing wrapper radius.
        // Skipped entirely for btnRadius=none because the reset already handles it.
        if ($btn_radius !== 'none') {
            $btn_radius_val = $radius_map[$btn_radius];
            $positive_classes = array_values(array_diff(self::$btn_classes, ['e-form']));
            $btn_selectors = array_map(fn($c) => ".{$c}.{$c}.{$c}", $positive_classes);
            $css_reset .= implode(",\n", $btn_selectors) . " {\n  border-radius: {$btn_radius_val} !important;\n}\n";
        }

        if ($css_reset) {
            echo "\n<style id=\"brickser-corner-reset\">\n" . $css_reset . "</style>\n";
        }

        $data = $this->get_frontend_data();
        $css = $data['css'];

        // Always output CSS — project CSS if available, otherwise defaults.
        // This is the single source of truth for --clr-* variables.
        if (!$css) {
            $css = ':root {
  --clr-brand-raw: #1A73E8;
  --clr-brand-fg-raw: #FFFFFF;
  --clr-brand: #1A73E8;
  --clr-brand-fg: #FFFFFF;
  --clr-brand-inv: #0A284E;
  --clr-brand-hover: #0E60CB;
  --clr-brand-inv-hover: rgba(26, 115, 232, 0.08);
  --clr-text-primary: #2D2E32;
  --clr-text-secondary: #535862;
  --clr-text-tertiary: #F5F5F5;
  --clr-bg-primary: #FFFFFF;
  --clr-bg-secondary: #F5F5F5;
  --clr-bg-tertiary: #EDEDEE;
  --clr-border-color: rgba(113, 124, 142, 0.2);
}

.bg-brand {
  background: var(--clr-brand-raw) !important;
  color: var(--clr-text-primary);
  --clr-text-primary: var(--clr-brand-fg-raw);
  --clr-text-secondary: color-mix(in srgb, var(--clr-brand-fg-raw) 70%, transparent);
  --clr-bg-primary: var(--clr-brand-raw);
  --clr-bg-secondary: color-mix(in srgb, var(--clr-brand-fg-raw) 8%, var(--clr-brand-raw));
  --clr-brand: var(--clr-brand-fg-raw);
  --clr-brand-fg: var(--clr-brand-raw);
  --clr-brand-inv: var(--clr-brand-fg-raw);
  --clr-brand-hover: color-mix(in srgb, var(--clr-brand-fg-raw) 85%, transparent);
  --clr-brand-inv-hover: color-mix(in srgb, var(--clr-brand-fg-raw) 12%, transparent);
  --clr-border-color: color-mix(in srgb, var(--clr-brand-fg-raw) 20%, transparent);
}';
        }

        echo "<style id=\"brickser-project-styles\">\n" . $this->sanitize_css_output( $css ) . "\n</style>\n";
    }

    /** Map btnRadius key → absolute pixel value for baking into global classes. */
    private static $radius_px_map = [
        'none' => '0',
        'xs'   => '4px',
        'sm'   => '6px',
        'md'   => '8px',
        'lg'   => '10px',
        'full' => '9999px',
    ];

    /**
     * Bake styles into Bricks global classes on deactivation so they survive deletion.
     *
     * Handles:
     * 1. Corner/radius preferences for button, card, and image groups
     * 2. e-heading typography (font-family + font-weight)
     * 3. e-form border-radius for all radius values
     */
    public function bake_corner_prefs_into_global_classes() {
        $project_id = get_option('bkr_active_project_id');
        if (!$project_id) return;

        $prefs = json_decode(get_option('bkr_corner_prefs_' . $project_id, '{}'), true);
        if (!is_array($prefs)) $prefs = [];

        $btn_radius  = $prefs['btnRadius']   ?? 'none';
        $card_style  = $prefs['cardStyle']   ?? 'sharp';
        $image_style = $prefs['imageStyle']  ?? 'sharp';

        // Collect class names that need radius: 0
        $zero_classes = [];
        if ($card_style !== 'rounded') {
            $zero_classes = array_merge($zero_classes, self::$card_classes);
        }
        if ($image_style !== 'rounded') {
            $zero_classes = array_merge($zero_classes, self::$img_classes);
        }

        // Button group always gets explicit radius baked (any value, not just zero)
        $btn_radius_px = self::$radius_px_map[$btn_radius] ?? '0';
        $btn_set  = array_flip(self::$btn_classes);
        $zero_set = array_flip($zero_classes);

        // Get heading font for e-heading
        $fonts = $this->get_active_fonts();
        $heading_font   = $fonts['heading'] !== 'Default' ? $fonts['heading'] : '';
        $heading_weight = '';

        // Try to get heading weight from Bricks theme styles
        $theme_styles = get_option('bricks_theme_styles', []);
        if (is_array($theme_styles)) {
            foreach ($theme_styles as $style) {
                if (!empty($style['settings']['typography']['typographyHeadings']['font-weight'])) {
                    $heading_weight = $style['settings']['typography']['typographyHeadings']['font-weight'];
                    break;
                }
            }
        }

        $classes = get_option('bricks_global_classes', []);
        if (!is_array($classes) || empty($classes)) return;

        $changed = false;
        foreach ($classes as &$class) {
            $name = $class['name'] ?? '';
            if (!isset($class['settings']) || !is_array($class['settings'])) {
                $class['settings'] = [];
            }

            // Button group: bake explicit radius (any value)
            if (isset($btn_set[$name])) {
                $class['settings']['_border']['radius'] = [
                    'top'    => $btn_radius_px,
                    'right'  => $btn_radius_px,
                    'bottom' => $btn_radius_px,
                    'left'   => $btn_radius_px,
                ];
                $changed = true;
            }

            // Card/image groups: bake radius 0 for sharp
            if (isset($zero_set[$name])) {
                $class['settings']['_border']['radius'] = [
                    'top'    => '0',
                    'right'  => '0',
                    'bottom' => '0',
                    'left'   => '0',
                ];
                $changed = true;
            }

            // e-heading: bake typography
            if ($name === 'e-heading' && $heading_font) {
                if (!isset($class['settings']['_typography'])) {
                    $class['settings']['_typography'] = [];
                }
                $class['settings']['_typography']['font-family'] = $heading_font;
                if ($heading_weight) {
                    $class['settings']['_typography']['font-weight'] = $heading_weight;
                }
                $changed = true;
            }
        }
        unset($class);

        if ($changed) {
            update_option('bricks_global_classes', $classes);
            update_option('bricks_global_classes_timestamp', time());
            update_option('bricks_global_classes_user', get_current_user_id());
        }
    }
}
