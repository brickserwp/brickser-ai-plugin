<?php
if (!defined('ABSPATH')) exit;

trait Brickser_Editor {

    /**
     * Returns a masked representation of the license key safe to expose to JS.
     * Plugin convention: first 7 chars + ··· + last 4 chars (mirrors the
     * General tab display the user already sees).
     */
    private static function mask_license_key($key) {
        if (empty($key) || strlen($key) < 12) return '';
        return substr($key, 0, 7) . '····' . substr($key, -4);
    }

    private function is_bricks_editor() {
        // Check if Bricks is active
        if (!defined('BRICKS_VERSION')) return false;

        // Check if we're in the builder
        if (function_exists('bricks_is_builder_main')) {
            return bricks_is_builder_main();
        }

        // Fallback check
        return isset($_GET['bricks']) && $_GET['bricks'] === 'run';
    }

    public function init() {
        // Only load in Bricks editor
        if (!$this->is_bricks_editor()) return;

        // Handle deferred page trashing (redirected here after deleting the current page)
        if (!empty($_GET['brickser_trash'])) {
            if (!wp_verify_nonce(sanitize_text_field($_GET['_brickser_nonce'] ?? ''), 'brickser_ai')) {
                return;
            }
            $trash_id = intval($_GET['brickser_trash']);
            if ($trash_id && $trash_id !== self::get_base_page_id() && current_user_can('delete_post', $trash_id)) {
                wp_trash_post($trash_id);
            }
        }

        add_action('wp_head', [$this, 'inject_early_overlay'], 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_editor_assets']);
    }

    /**
     * Get or create the base "Brickser AI" page.
     * Returns the WP post ID. Creates the page if it doesn't exist or was trashed.
     */
    public static function get_base_page_id() {
        $page_id = get_option('brickser_base_page_id');

        // Verify it still exists and is not trashed
        if ($page_id) {
            $status = get_post_status($page_id);
            if ($status && $status !== 'trash') {
                // Ensure header/footer stay disabled (survives deactivation/reactivation).
                // Bricks reads these specific keys — see Bricks themes/bricks/includes/database.php:1164
                // where `is_template_disabled()` derives `"{$type}Disabled"`. Using `disableHeader`
                // or any other key name silently does nothing.
                $settings = get_post_meta($page_id, '_bricks_page_settings', true);
                if (!is_array($settings)) $settings = [];
                if (empty($settings['headerDisabled']) || empty($settings['footerDisabled'])) {
                    $settings['headerDisabled'] = true;
                    $settings['footerDisabled'] = true;
                    unset($settings['disableHeader'], $settings['disableFooter']);
                    update_post_meta($page_id, '_bricks_page_settings', $settings);
                }
                return (int) $page_id;
            }
        }

        // Option missing or page trashed — look for an existing "Brickser AI" page.
        // This handles reactivation: the page still exists but the option was lost.
        $existing = get_posts([
            'post_type'   => 'page',
            'post_status' => ['publish', 'private', 'draft'],
            'name'        => 'brickser-ai',
            'numberposts' => 1,
        ]);

        if (!empty($existing)) {
            $page_id = $existing[0]->ID;
            update_option('brickser_base_page_id', $page_id, false);
            update_post_meta($page_id, '_bricks_editor_mode', 'bricks');
            update_post_meta($page_id, '_bricks_page_settings', ['headerDisabled' => true, 'footerDisabled' => true]);
            return (int) $page_id;
        }

        // No existing page found — create a new one
        $page_id = wp_insert_post([
            'post_title'  => 'Brickser AI',
            'post_name'   => 'brickser-ai',
            'post_status' => 'private',
            'post_type'   => 'page',
            'post_content' => '',
        ]);

        if (is_wp_error($page_id)) {
            return 0;
        }

        update_option('brickser_base_page_id', $page_id, false);
        update_post_meta($page_id, '_bricks_editor_mode', 'bricks');
        update_post_meta($page_id, '_bricks_page_settings', ['headerDisabled' => true, 'footerDisabled' => true]);

        return (int) $page_id;
    }

    /**
     * Inject an inline script in <head> that checks sessionStorage for
     * generate/translate intents and shows an overlay on the Bricks canvas area
     * as soon as the iframe element appears (before its content loads).
     */
    public function inject_early_overlay() {
        ?>
        <script>
        (function(){
            try {
                var g = sessionStorage.getItem('brickser-generate-intent');
                var t = sessionStorage.getItem('brickser-translate-intent');
                if (!g && !t) return;
                var s = document.createElement('style');
                s.id = 'brickser-canvas-overlay-style';
                s.textContent = '.bo-overlay{position:absolute;inset:0;z-index:99999;background:#fafafa;display:flex;flex-direction:column;opacity:0;transition:opacity .5s ease;pointer-events:all;overflow:hidden}.bo-overlay.bo-visible{opacity:1}.bo-skeleton{flex:1;position:relative;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column}.bo-skeleton::after{content:"";position:absolute;inset:0;background:linear-gradient(105deg,transparent 40%,rgba(255,255,255,0.7) 48%,rgba(255,255,255,0.9) 50%,rgba(255,255,255,0.7) 52%,transparent 60%);background-size:200% 100%;animation:bo-sweep 2.2s ease-in-out infinite;z-index:1;pointer-events:none}@keyframes bo-sweep{0%{background-position:120% 0}100%{background-position:-120% 0}}.bo-b{border-radius:6px;background:#ebebef}.bo-b-d{border-radius:6px;background:#dddde2}.bo-b-l{border-radius:6px;background:#f0f0f3}.bo-skeleton>*{opacity:0;animation:bo-section-in .5s ease forwards}.bo-skeleton>*:nth-child(1){animation-delay:0s}.bo-skeleton>*:nth-child(2){animation-delay:.08s}.bo-skeleton>*:nth-child(3){animation-delay:.16s}.bo-skeleton>*:nth-child(4){animation-delay:.3s}.bo-skeleton>*:nth-child(5){animation-delay:.45s}.bo-skeleton>*:nth-child(6){animation-delay:.55s}.bo-skeleton>*:nth-child(7){animation-delay:.65s}@keyframes bo-section-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}.bo-nav{display:flex;align-items:center;padding:18px 5%;gap:24px;border-bottom:1px solid #f0f0f2;flex-shrink:0}.bo-nav-logo{width:100px;height:14px;border-radius:4px;background:#dddde2}.bo-nav-gap{flex:1}.bo-nav-link{width:52px;height:10px}.bo-nav-btn{width:80px;height:32px;border-radius:16px}.bo-hero{display:flex;flex-direction:column;align-items:center;text-align:center;padding:64px 8% 48px;gap:16px;flex-shrink:0}.bo-hero-badge{width:120px;height:26px;border-radius:13px}.bo-hero-h1{width:65%;max-width:460px;height:28px;border-radius:8px}.bo-hero-h1b{width:45%;max-width:320px;height:28px;border-radius:8px}.bo-hero-p{width:55%;max-width:400px;height:12px;margin-top:4px}.bo-hero-p2{width:40%;max-width:280px;height:12px}.bo-hero-cta{display:flex;gap:12px;margin-top:12px}.bo-hero-btn{width:130px;height:44px;border-radius:22px}.bo-hero-btn-ghost{width:120px;height:44px;border-radius:22px;background:transparent;border:2px solid #e8e8ec}.bo-trust{display:flex;align-items:center;justify-content:center;gap:32px;padding:28px 8%;border-top:1px solid #f2f2f4;border-bottom:1px solid #f2f2f4;flex-shrink:0}.bo-trust-label{width:80px;height:9px;flex-shrink:0}.bo-trust-logo{width:64px;height:18px;border-radius:4px}.bo-features{display:flex;flex-direction:column;align-items:center;padding:56px 8% 48px;gap:36px;flex-shrink:0}.bo-feat-header{display:flex;flex-direction:column;align-items:center;gap:12px;margin-bottom:8px}.bo-feat-header-tag{width:70px;height:10px}.bo-feat-header-h{width:280px;max-width:60%;height:22px;border-radius:6px}.bo-feat-header-p{width:340px;max-width:70%;height:10px}.bo-feat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;width:100%}.bo-feat-card{display:flex;flex-direction:column;gap:12px;padding:28px 20px;background:#fff;border-radius:12px;border:1px solid #f0f0f2}.bo-feat-icon{width:40px;height:40px;border-radius:10px}.bo-feat-title{width:70%;height:13px;border-radius:4px}.bo-feat-desc{width:90%;height:9px}.bo-feat-desc2{width:65%;height:9px}.bo-testimonial{display:flex;flex-direction:column;align-items:center;gap:20px;padding:48px 12%;background:#f5f5f8;flex-shrink:0}.bo-testi-stars{display:flex;gap:4px}.bo-testi-star{width:16px;height:16px;border-radius:3px}.bo-testi-quote{width:80%;max-width:420px;height:14px;border-radius:6px}.bo-testi-quote2{width:60%;max-width:320px;height:14px;border-radius:6px}.bo-testi-author{display:flex;align-items:center;gap:10px;margin-top:4px}.bo-testi-avatar{width:36px;height:36px;border-radius:50%}.bo-testi-name{width:80px;height:10px;border-radius:4px}.bo-testi-role{width:60px;height:8px}.bo-cta{display:flex;flex-direction:column;align-items:center;gap:14px;padding:52px 8%;flex-shrink:0}.bo-cta-h{width:300px;max-width:55%;height:22px;border-radius:6px}.bo-cta-p{width:260px;max-width:50%;height:10px}.bo-cta-btn{width:150px;height:46px;border-radius:23px;margin-top:6px}.bo-footer{display:flex;align-items:center;padding:20px 5%;gap:20px;border-top:1px solid #f0f0f2;flex-shrink:0;margin-top:auto}.bo-footer-logo{width:72px;height:11px;border-radius:3px}.bo-footer-gap{flex:1}.bo-footer-link{width:40px;height:8px}.bo-pill-wrap{position:absolute;left:50%;bottom:28px;transform:translateX(-50%);z-index:100001;padding:1.5px;border-radius:50px;background:linear-gradient(135deg,#155dfc,#a78bfa,#6b8cff,#155dfc);background-size:300% 300%;animation:bo-borderglow 3s ease-in-out infinite;pointer-events:none;transition:all .4s cubic-bezier(.4,0,.2,1)}.bo-pill-inner{gap:8px;padding:7px 14px 7px 10px;background:#fff;border-radius:50px;display:flex;align-items:center;box-shadow:0 4px 20px rgba(0,0,0,0.06)}@keyframes bo-borderglow{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}.bo-icon{position:relative;width:18px;height:18px;flex-shrink:0}.bo-icon svg{position:absolute;inset:0;width:18px;height:18px;opacity:0;animation:bo-morph 3s ease-in-out infinite;filter:drop-shadow(0 1px 4px rgba(21,93,252,0.18))}.bo-icon svg:nth-child(1){animation-delay:0s}.bo-icon svg:nth-child(2){animation-delay:1s}.bo-icon svg:nth-child(3){animation-delay:2s}@keyframes bo-morph{0%{opacity:0;transform:scale(.6) rotate(-20deg)}5%{opacity:1;transform:scale(1) rotate(0)}28%{opacity:1;transform:scale(1.05) rotate(3deg)}38%{opacity:0;transform:scale(.6) rotate(20deg)}100%{opacity:0}}.bo-status{font-size:12.5px;font-weight:600;letter-spacing:-0.2px;line-height:1.2;background:linear-gradient(90deg,#155dfc,#6b8cff 40%,#a78bfa 55%,#155dfc);background-size:300% 100%;-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;animation:bo-txt 3s ease-in-out infinite;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;white-space:nowrap}@keyframes bo-txt{0%{background-position:100% 0}100%{background-position:-100% 0}}.bo-pill-text{display:flex;flex-direction:column}';
                document.head.appendChild(s);
                var isTranslate = !!t;
                var statusText = isTranslate ? 'Translating your page...' : 'Designing your page...';
                var iv = setInterval(function(){
                    var iframe = document.querySelector('#bricks-builder-iframe');
                    if (!iframe || !iframe.parentElement) return;
                    clearInterval(iv);
                    if (document.getElementById('brickser-canvas-overlay')) return;
                    iframe.parentElement.style.position = 'relative';
                    var o = document.createElement('div');
                    o.id = 'brickser-canvas-overlay';
                    o.className = 'bo-overlay bo-visible';
                    o.innerHTML = '<div class="bo-skeleton"><div class="bo-nav"><div class="bo-nav-logo"></div><div class="bo-nav-gap"></div><div class="bo-b bo-nav-link"></div><div class="bo-b bo-nav-link"></div><div class="bo-b bo-nav-link"></div><div class="bo-b bo-nav-link"></div><div class="bo-b bo-nav-btn"></div></div><div class="bo-hero"><div class="bo-b-l bo-hero-badge"></div><div class="bo-b-d bo-hero-h1"></div><div class="bo-b-d bo-hero-h1b"></div><div class="bo-b bo-hero-p"></div><div class="bo-b bo-hero-p2"></div><div class="bo-hero-cta"><div class="bo-b bo-hero-btn"></div><div class="bo-hero-btn-ghost"></div></div></div><div class="bo-trust"><div class="bo-b-l bo-trust-label"></div><div class="bo-b-l bo-trust-logo"></div><div class="bo-b-l bo-trust-logo"></div><div class="bo-b-l bo-trust-logo"></div><div class="bo-b-l bo-trust-logo"></div><div class="bo-b-l bo-trust-logo"></div></div><div class="bo-features"><div class="bo-feat-header"><div class="bo-b-l bo-feat-header-tag"></div><div class="bo-b-d bo-feat-header-h"></div><div class="bo-b bo-feat-header-p"></div></div><div class="bo-feat-grid"><div class="bo-feat-card"><div class="bo-b bo-feat-icon"></div><div class="bo-b-d bo-feat-title"></div><div class="bo-b bo-feat-desc"></div><div class="bo-b bo-feat-desc2"></div></div><div class="bo-feat-card"><div class="bo-b bo-feat-icon"></div><div class="bo-b-d bo-feat-title"></div><div class="bo-b bo-feat-desc"></div><div class="bo-b bo-feat-desc2"></div></div><div class="bo-feat-card"><div class="bo-b bo-feat-icon"></div><div class="bo-b-d bo-feat-title"></div><div class="bo-b bo-feat-desc"></div><div class="bo-b bo-feat-desc2"></div></div></div></div><div class="bo-testimonial"><div class="bo-testi-stars"><div class="bo-b bo-testi-star"></div><div class="bo-b bo-testi-star"></div><div class="bo-b bo-testi-star"></div><div class="bo-b bo-testi-star"></div><div class="bo-b bo-testi-star"></div></div><div class="bo-b-d bo-testi-quote"></div><div class="bo-b-d bo-testi-quote2"></div><div class="bo-testi-author"><div class="bo-b bo-testi-avatar"></div><div style="display:flex;flex-direction:column;gap:5px"><div class="bo-b-d bo-testi-name"></div><div class="bo-b bo-testi-role"></div></div></div></div><div class="bo-cta"><div class="bo-b-d bo-cta-h"></div><div class="bo-b bo-cta-p"></div><div class="bo-b bo-cta-btn"></div></div><div class="bo-footer"><div class="bo-b-d bo-footer-logo"></div><div class="bo-footer-gap"></div><div class="bo-b-l bo-footer-link"></div><div class="bo-b-l bo-footer-link"></div><div class="bo-b-l bo-footer-link"></div></div></div>';
                    iframe.parentElement.appendChild(o);
                    var p = document.createElement('div');
                    p.id = 'brickser-canvas-pill';
                    p.className = 'bo-pill-wrap';
                    p.innerHTML = '<div class="bo-pill-inner"><div class="bo-icon" id="bo-icon"><svg viewBox="0 0 200 200" fill="none"><defs><linearGradient id="bo-g1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#155dfc"/><stop offset="100%" stop-color="#93b4ff"/></linearGradient></defs><path id="bo-sp1" d="M190.111 83.304L163.877 68.971C149.984 61.383 138.61 50.013 130.993 36.108L116.619 9.859C113.283 3.773 106.917 0 99.998 0C93.079 0 86.709 3.781 83.381 9.859L69.007 36.108C61.39 50.013 50.02 61.383 36.123 68.971L9.893 83.304C3.791 86.633 0 93.035 0 100C0 106.965 3.791 113.367 9.889 116.697L36.127 131.029C50.02 138.617 61.39 149.987 69.007 163.892L83.381 190.141C86.709 196.219 93.075 200 99.994 200C106.913 200 113.283 196.227 116.619 190.141L130.993 163.892C138.61 149.987 149.98 138.617 163.877 131.029L190.107 116.697C196.209 113.367 200 106.965 200 100C200 93.035 196.209 86.633 190.111 83.304Z" fill="url(#bo-g1)"/></svg><svg viewBox="0 0 200 200" fill="none"><defs><linearGradient id="bo-g2" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#155dfc"/><stop offset="100%" stop-color="#93b4ff"/></linearGradient></defs><path d="M127.14 200C99.994 200 99.994 167.423 72.849 167.423C41.605 167.423 0 158.386 0 127.133C0 99.989 32.568 99.989 32.568 72.845C32.568 41.614 41.605 0 72.86 0C100.006 0 100.006 32.577 127.151 32.577C158.384 32.577 200 41.614 200 72.868C200 100.012 167.421 100.012 167.421 127.156C167.409 158.444 158.384 200 127.14 200Z" fill="url(#bo-g2)"/></svg><svg viewBox="0 0 200 200" fill="none"><defs><linearGradient id="bo-g3" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#155dfc"/><stop offset="100%" stop-color="#93b4ff"/></linearGradient></defs><circle cx="100" cy="100" r="100" fill="url(#bo-g3)"/></svg></div><div class="bo-pill-text"><span class="bo-status" id="bo-txt">' + statusText + '</span></div></div>';
                    iframe.parentElement.appendChild(p);
                }, 50);
                setTimeout(function(){
                    clearInterval(iv);
                    // Safety: hide overlay + clear intents ONLY if JS app never took over
                    // Svelte app signals takeover by clearing the sessionStorage intents itself
                    if (sessionStorage.getItem('brickser-generate-intent') || sessionStorage.getItem('brickser-translate-intent')) {
                        var ov = document.getElementById('brickser-canvas-overlay');
                        if (ov) { ov.style.opacity = '0'; setTimeout(function(){ ov.remove(); }, 600); }
                        var pi = document.getElementById('brickser-canvas-pill');
                        if (pi) { pi.style.opacity = '0'; setTimeout(function(){ pi.remove(); }, 600); }
                        sessionStorage.removeItem('brickser-generate-intent');
                        sessionStorage.removeItem('brickser-translate-intent');
                    }
                }, 30000);
            } catch(e){}
        })();
        </script>
        <?php
    }

    public function enqueue_editor_assets() {
        // Prefetch chatbox image options so they're cached when the picker opens
        echo '<link rel="prefetch" href="' . esc_url(BRICKSER_AI_URL . 'assets/images/wireframe.avif') . '" as="image" type="image/avif">';
        echo '<link rel="prefetch" href="' . esc_url(BRICKSER_AI_URL . 'assets/images/stock-img.avif') . '" as="image" type="image/avif">';

        $js_path = BRICKSER_AI_PATH . 'assets/js/editor.js';
        $asset_ver = BRICKSER_AI_VERSION . '.' . (file_exists($js_path) ? filemtime($js_path) : '0');

        wp_enqueue_style(
            'brickser-ai-editor',
            BRICKSER_AI_URL . 'assets/css/editor.css',
            [],
            $asset_ver
        );

        wp_enqueue_script(
            'brickser-ai-editor',
            BRICKSER_AI_URL . 'assets/js/editor.js',
            [],
            $asset_ver,
            true
        );

        $base_page_id = self::get_base_page_id();
        $current_post_id = get_the_ID();

        // Editors get just the boolean — only admins see masked key, tier, BYOK
        // provider. The raw license key and worker bearer token are NEVER
        // exposed to JS in either case (server-side only).
        $is_admin = current_user_can('manage_options');
        $localized = [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'pluginUrl'         => BRICKSER_AI_URL,
            'postId'            => $current_post_id,
            'postType'          => get_post_type($current_post_id),
            'pageSlug'          => get_post_field('post_name', $current_post_id),
            'apiUrl'            => 'https://brickser.io/api',
            'workerUrl'         => BRICKSER_WORKER_URL,
            'version'           => BRICKSER_AI_VERSION,
            'nonce'             => wp_create_nonce('brickser_ai'),
            'basePageId'        => $base_page_id,
            'basePageUrl'       => add_query_arg('bricks', 'run', get_permalink($base_page_id)),
            'attachedProjectId' => $this->get_current_project_id(),
            'isDev'             => defined('BRICKSER_LOCAL_DEV') && BRICKSER_LOCAL_DEV,
            'fonts'             => $this->get_active_fonts(),
            'siteName'          => get_bloginfo('name'),
            'userName'          => wp_get_current_user()->display_name,
            'isAdmin'           => $is_admin,
            'hasLicense'        => !empty(get_option('brickser_license_token', '')),
            'modelTier'         => (function() {
                // 'pro' is the legacy name from v1.3.0 — normalize to 'thinking'
                // so the JS store and worker request body always see one canonical value.
                $t = get_option('brickser_model_tier', 'default');
                if ($t === 'pro' || $t === 'thinking') return 'thinking';
                return 'default';
            })(),
        ];
        if ($is_admin) {
            $localized['licenseKeyMasked']     = self::mask_license_key(get_option('brickser_license_key', ''));
            $localized['licenseTier']          = get_option('brickser_license_tier', '');
            $localized['licenseTierLabel']     = get_option('brickser_license_tier_label', '');
            $localized['licenseSitesAllowed']  = (int) get_option('brickser_license_sites_allowed', 0);
            $localized['byokProvider']         = get_option('brickser_byok_provider', '');
        }
        wp_localize_script('brickser-ai-editor', 'brickserAI', $localized);
    }
}
