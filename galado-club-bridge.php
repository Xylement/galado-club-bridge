<?php
/**
 * Plugin Name: GALADO Club Bridge
 * Description: Connects galado.com.my accounts to GALADO Club — adds a "GALADO Club" tab in My Account, signs members into club.galado.com.my (SSO), and mirrors Club tiers to user meta.
 * Version: 0.7.1
 * Author: GALADO
 *
 * Deploy checklist (wp-config.php):
 *   define('GALADO_CLUB_URL', 'https://club.galado.com.my');
 *   define('GALADO_CLUB_SSO_SECRET', '<same value as WP_SSO_SECRET in the Club .env>');
 *   define('GALADO_CLUB_BRIDGE_SECRET', '<same value as BRIDGE_SHARED_SECRET in the Club .env>');
 * Then: activate plugin, visit Settings > Permalinks once (flush), and create the
 * WooCommerce webhook (topic "Order updated", delivery URL
 * https://club.galado.com.my/webhooks/woo/order, secret = WOO_WEBHOOK_SECRET).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Galado_Club_Bridge {

    const ENDPOINT = 'galado-club';
    const VERSION  = '0.7.1';
    const WELCOME_AMOUNT = 10;   // RM off a referred new customer's first order
    const WELCOME_MIN    = 30;   // min cart subtotal (RM) before the welcome discount applies

    public static function init() {
        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'render_tab']);
        // On-site activation (post-payment + account only — never before checkout):
        add_action('woocommerce_account_dashboard', [__CLASS__, 'dashboard_card']);
        add_action('woocommerce_thankyou', [__CLASS__, 'thankyou_block']);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
        add_action('transition_comment_status', [__CLASS__, 'on_comment_transition'], 10, 3);
        add_action('comment_post', [__CLASS__, 'on_comment_post'], 10, 2);
        // Referral: capture ?ref= into a 30-day cookie, then stamp it onto the order at checkout.
        add_action('wp_footer', [__CLASS__, 'ref_cookie_script']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'capture_referral'], 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'capture_referral'], 10, 1);
        // Referral: RM10 off a referred NEW customer's first order.
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'referral_welcome_discount']);
        register_activation_hook(__FILE__, 'flush_rewrite_rules');
        register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
    }

    private static function club_url() {
        return defined('GALADO_CLUB_URL') ? rtrim(GALADO_CLUB_URL, '/') : 'https://club.galado.com.my';
    }

    private static function sso_secret() {
        return defined('GALADO_CLUB_SSO_SECRET') ? GALADO_CLUB_SSO_SECRET : '';
    }

    private static function bridge_secret() {
        return defined('GALADO_CLUB_BRIDGE_SECRET') ? GALADO_CLUB_BRIDGE_SECRET : '';
    }

    /** Shared permission check for all server-to-server bridge routes. */
    public static function bridge_auth(WP_REST_Request $request) {
        $secret = self::bridge_secret();
        return '' !== $secret && hash_equals($secret, (string) $request->get_header('x-club-bridge-secret'));
    }

    /** Review moderated from pending → approved. */
    public static function on_comment_transition($new_status, $old_status, $comment) {
        if ('approved' === $new_status && 'approved' !== $old_status) {
            self::maybe_credit_review($comment);
        }
    }

    /** New review that is auto-approved on submission (owner/admin) — no status transition fires. */
    public static function on_comment_post($comment_id, $approved) {
        if (1 === (int) $approved) {
            self::maybe_credit_review(get_comment($comment_id));
        }
    }

    /**
     * Approved, verified-purchase product review → tell the Club to credit G-Coins.
     * Fire-and-forget; the Club enforces idempotency + one reward per product per member.
     */
    public static function maybe_credit_review($comment) {
        if (!$comment) {
            return;
        }
        $product_id = (int) $comment->comment_post_ID;
        if ('product' !== get_post_type($product_id)) {
            return; // product reviews only
        }
        $user_id = (int) $comment->user_id;
        $email = strtolower(trim((string) $comment->comment_author_email));
        if (!$email && $user_id) {
            $u = get_userdata($user_id);
            $email = $u ? strtolower($u->user_email) : '';
        }
        if (!$email) {
            return;
        }
        // Verified purchase only.
        if (!function_exists('wc_customer_bought_product') || !wc_customer_bought_product($email, $user_id, $product_id)) {
            return;
        }
        wp_remote_post(self::club_url() . '/webhooks/review', [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => ['content-type' => 'application/json', 'x-club-bridge-secret' => self::bridge_secret()],
            'body'     => wp_json_encode([
                'email'      => $email,
                'product_id' => $product_id,
                'comment_id' => (int) $comment->comment_ID,
                'rating'     => (int) get_comment_meta($comment->comment_ID, 'rating', true),
            ]),
        ]);
    }

    /**
     * Referral capture — client side. Outputs a tiny script on every front-end page that,
     * when the URL carries ?ref=CODE, stores it in a 30-day first-party cookie. It reads the
     * live URL in the browser, so it works even on fully cached pages.
     */
    public static function ref_cookie_script() {
        if (is_admin()) {
            return;
        }
        ?>
<script>(function(){try{var r=new URLSearchParams(location.search).get('ref');if(!r)return;r=r.replace(/[^A-Za-z0-9]/g,'').slice(0,12).toUpperCase();if(!r)return;var e=new Date(Date.now()+2592e6).toUTCString();document.cookie='galado_ref='+r+'; expires='+e+'; path=/; SameSite=Lax';}catch(e){}})();</script>
<?php
    }

    /**
     * Referral capture — server side. At checkout, copy the galado_ref cookie onto the order
     * as PUBLIC meta (no underscore) so it rides the WooCommerce order webhook to the Club,
     * which credits the referrer. Fires for both classic and block (Store API) checkout.
     */
    public static function capture_referral($order) {
        if (!$order || empty($_COOKIE['galado_ref'])) {
            return;
        }
        $code = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_COOKIE['galado_ref'])), 0, 12);
        if ('' !== $code) {
            $order->update_meta_data('galado_ref', strtoupper($code));
        }
    }

    /**
     * RM10 welcome discount for a referred NEW customer: galado_ref cookie present AND no
     * prior paid orders. Applied as a negative cart fee so it shows on cart + checkout and
     * flows into the order total (so the referrer's 10% is on what the friend actually paid).
     * Existing customers never get it; a min subtotal protects margin.
     */
    public static function referral_welcome_discount($cart) {
        if ((is_admin() && !defined('DOING_AJAX')) || !function_exists('WC')) {
            return;
        }
        if (empty($_COOKIE['galado_ref'])) {
            return;
        }
        if ((float) $cart->get_subtotal() < self::WELCOME_MIN) {
            return;
        }
        if (self::is_existing_customer()) {
            return;
        }
        $cart->add_fee(__('Referral welcome — RM10 off your first order', 'galado-club'), -1 * self::WELCOME_AMOUNT, false);
    }

    /** True if this shopper has a prior paid order (logged-in, or guest matched by billing email). */
    private static function is_existing_customer() {
        if (is_user_logged_in()) {
            return (int) wc_get_customer_order_count(get_current_user_id()) > 0;
        }
        $email = (WC()->customer) ? WC()->customer->get_billing_email() : '';
        if (!$email) {
            return false; // unknown guest → treat as new (re-checked once they enter an email at checkout)
        }
        $orders = wc_get_orders([
            'billing_email' => $email,
            'status'        => ['wc-completed', 'wc-processing'],
            'limit'         => 1,
            'return'        => 'ids',
        ]);
        return !empty($orders);
    }

    /** Scene sky colours — keep in sync with web/src/lib/sceneFx.ts SCENE_FX (top, bottom). */
    private static function scene_colors($slug) {
        $m = [
            'scene-pastel-dream'   => ['#ffc9e6', '#c6ccff'],
            'scene-sunset-glow'    => ['#ffb15e', '#7d5a9c'],
            'scene-starry-night'   => ['#241f48', '#5b4b8a'],
            'scene-ocean-breeze'   => ['#bfe9ff', '#7fd1e8'],
            'scene-galado-coral'   => ['#ffe0d2', '#ff5e4d'],
            'scene-kl-skyline'     => ['#1b2147', '#c7b2c2'],
            'scene-penang-kek'     => ['#5a3f6e', '#ffc488'],
            'scene-blossom-garden' => ['#ffbcd6', '#e6dafb'],
        ];
        return isset($m[$slug]) ? $m[$slug] : null;
    }

    /**
     * Avatar circle mirroring the Club home (Dashboard.tsx / AvatarPortrait.tsx):
     * custom photo -> the member standing in their equipped Scenery (transparent chibi
     * over the scene sky) -> plain studio portrait. Images are served by the Club origin.
     */
    private static function avatar_html(array $summary, $size = 96) {
        $base = isset($summary['avatarBase']) && 'boy' === $summary['avatarBase'] ? 'boy' : 'girl';
        $sz   = (int) $size;
        $ring = 'border-radius:50%;border:4px solid #ffd9cf;object-fit:cover;object-position:top;flex:none;';

        // 1) Custom uploaded photo wins (prefix root-relative paths — this renders on galado.com.my).
        $custom = isset($summary['customPhoto']) ? trim((string) $summary['customPhoto']) : '';
        if ($custom !== '') {
            $url = preg_match('#^https?://#i', $custom) ? $custom : self::club_url() . '/' . ltrim($custom, '/');
            return '<img src="' . esc_url($url) . '" alt="Your Club avatar" width="' . $sz . '" height="' . $sz . '" style="' . $ring . '" />';
        }

        // Equipped outfit (only those with a baked 2D portrait) + scene.
        $portrait_outfits = ['outfit-cozy-hoodie-set', 'outfit-summer-tee-shorts', 'outfit-futuristic', 'outfit-formal', 'outfit-baju-raya'];
        $outfit = '';
        $scene  = '';
        if (!empty($summary['equipped']) && is_array($summary['equipped'])) {
            foreach ($summary['equipped'] as $eq) {
                if (!isset($eq['slot'], $eq['slug'])) {
                    continue;
                }
                if ('outfit' === $eq['slot'] && in_array($eq['slug'], $portrait_outfits, true)) {
                    $outfit = $eq['slug'];
                } elseif ('scene' === $eq['slot']) {
                    $scene = $eq['slug'];
                }
            }
        }
        $stem   = '/avatar-' . $base . ($outfit ? '-' . $outfit : '');
        $colors = self::scene_colors($scene);

        // 2) Scenery equipped → transparent chibi over the scene sky. The gradient goes on the
        //    <img> ITSELF (+ !important): the store theme forces a white <img> background that
        //    hides a wrapper div's gradient, and inline !important beats even a themed !important.
        if ($colors) {
            $cut = self::club_url() . $stem . '-cut.png';
            return '<img src="' . esc_url($cut) . '" alt="Your Club avatar" width="' . $sz . '" height="' . $sz . '" '
                . 'style="border-radius:50%;border:4px solid #ffd9cf;object-fit:cover;object-position:top center;flex:none;'
                . 'background:linear-gradient(170deg,' . esc_attr($colors[0]) . ',' . esc_attr($colors[1]) . ') !important;" />';
        }

        // 3) Plain studio portrait.
        return '<img src="' . esc_url(self::club_url() . $stem . '.png') . '" alt="Your Club avatar" width="' . $sz . '" height="' . $sz . '" style="' . $ring . '" />';
    }

    /** Tier badge pill — mirrors the Club's .tier-chip gradients (silver/gold/diamond/black). */
    private static function tier_pill($tier) {
        $grad = [
            'silver'  => 'linear-gradient(135deg,#b9c4d0,#9aa7b5)',
            'gold'    => 'linear-gradient(135deg,#f4c976,#e9a93d)',
            'diamond' => 'linear-gradient(135deg,#a4dcf2,#6fc7e8)',
            'black'   => 'linear-gradient(135deg,#4a3d50,#2e2630)',
        ];
        $labels = ['silver' => 'Silver', 'gold' => 'Gold', 'diamond' => 'Diamond', 'black' => 'GALADO Black'];
        $key    = isset($grad[$tier]) ? $tier : 'silver';
        $extra  = 'black' === $key ? 'box-shadow:inset 0 0 0 1.5px #ffe9a8;' : '';
        return '<span style="display:inline-block;background:' . $grad[$key] . ';color:#fff;'
            . "font-family:'Baloo 2',sans-serif;font-weight:800;font-size:12px;letter-spacing:.04em;text-transform:uppercase;"
            . 'padding:5px 13px;border-radius:999px;' . $extra . '">' . esc_html($labels[$key]) . '</span>';
    }

    public static function add_endpoint() {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public static function menu_item($items) {
        // Insert after Dashboard.
        $out = [];
        foreach ($items as $key => $label) {
            $out[$key] = $label;
            if ('dashboard' === $key) {
                $out[self::ENDPOINT] = __('GALADO Club', 'galado-club');
            }
        }
        if (!isset($out[self::ENDPOINT])) {
            $out[self::ENDPOINT] = __('GALADO Club', 'galado-club');
        }
        return $out;
    }

    private static function b64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Short-lived HS256 JWT consumed by the Club's /sso endpoint. */
    private static function sso_token(WP_User $user) {
        $secret = self::sso_secret();
        if ('' === $secret) {
            return '';
        }
        $header  = self::b64url(wp_json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64url(wp_json_encode([
            'email'      => strtolower($user->user_email),
            'wp_user_id' => (int) $user->ID,
            'name'       => $user->display_name,
            'iat'        => time(),
            'exp'        => time() + 300,
        ]));
        $sig = self::b64url(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
        return $header . '.' . $payload . '.' . $sig;
    }

    /** Cached Club summary for the My Account tab (5 min transient per user). */
    private static function fetch_summary($email, $user_id) {
        $key    = 'galado_club_summary_' . $user_id;
        $cached = get_transient($key);
        if (false !== $cached) {
            return $cached;
        }
        $response = wp_remote_get(
            self::club_url() . '/api/members/' . rawurlencode(strtolower($email)) . '/summary',
            [
                'timeout' => 4,
                'headers' => ['x-club-bridge-secret' => self::bridge_secret()],
            ]
        );
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }
        set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
        return $data;
    }

    /** Shared Club panel (portrait + tier + coins + Enter button) for a logged-in user. */
    /** Load the Club app's fonts (Baloo 2 + Nunito) so the cards match the Club, not the store theme. */
    private static function club_font_link() {
        return '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;700;800&family=Nunito:wght@400;600;700&display=swap">';
    }

    /** Coral pill CTA matching the Club app buttons (avoids the store theme's square .button). */
    private static function cta_pill($url, $label) {
        return '<a href="' . esc_url($url) . '" style="display:inline-block;background:#ff7a59;color:#fff;'
            . "font-family:'Baloo 2',sans-serif;font-weight:700;font-size:16px;line-height:1;text-decoration:none;"
            . 'padding:14px 30px;border-radius:999px;">' . esc_html($label) . ' &rarr;</a>';
    }

    /** The branded GALADO coin (gold dubloon) image — used in place of the generic 🪙 emoji. */
    private static function coin_icon() {
        return '<img src="' . esc_url(self::club_url() . '/coin.png') . '" alt="" '
            . 'style="height:1.1em;width:auto;vertical-align:-0.18em;margin-right:8px;display:inline-block;" />';
    }

    private static function render_club_card($user, $heading) {
        $summary   = self::fetch_summary($user->user_email, $user->ID);
        $token     = self::sso_token($user);
        $enter_url = $token ? self::club_url() . '/sso?token=' . rawurlencode($token) : self::club_url();

        echo self::club_font_link();
        echo '<div style="border:1px solid #f3ddd2;border-radius:20px;padding:24px;background:#fff9f4;font-family:\'Nunito\',sans-serif;color:#3a2a22;">';
        echo '<h3 style="margin-top:0;font-family:\'Baloo 2\',sans-serif;font-weight:800;color:#3a2a22;">' . esc_html($heading) . '</h3>';

        if ($summary) {
            $coins = isset($summary['coins']) ? (int) $summary['coins'] : 0;

            echo '<div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">';
            echo self::avatar_html($summary, 96);
            echo '<div>';
            echo '<p style="margin:0 0 6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
                . self::tier_pill(isset($summary['tier']) ? $summary['tier'] : 'silver')
                . '<span style="opacity:.7;">member</span></p>';
            echo '<p style="margin:0 0 12px;">' . esc_html(number_format_i18n($coins)) . ' G-Coins ready to spend &mdash; dress up your Buddy &amp; join The Lounge.</p>';
            echo '</div></div>';
        } else {
            echo '<p>Your coins, badges and avatar are waiting &mdash; every GALADO order earns G-Coins.</p>';
        }

        echo '<p style="margin:14px 0 0;">' . self::cta_pill($enter_url, 'Enter the Club') . '</p>';
        echo '</div>';
    }

    /** My Account → "GALADO Club" tab. */
    public static function render_tab() {
        $user = wp_get_current_user();
        if (!$user || 0 === $user->ID) {
            return;
        }
        self::render_club_card($user, 'GALADO Club');
    }

    /** My Account → dashboard: a Club card so repeat customers see it every visit. */
    public static function dashboard_card() {
        $user = wp_get_current_user();
        if (!$user || 0 === $user->ID) {
            return;
        }
        // Gap below so the card isn't flush against the account menu beneath it.
        echo '<div style="margin:0 0 2rem;">';
        self::render_club_card($user, 'GALADO Club');
        echo '</div>';
    }

    /** Order-received (Thank-you) page: celebrate the coins just earned + send them in.
     *  Fires only AFTER payment, so it never distracts from checkout. */
    public static function thankyou_block($order_id) {
        // Hide third-party social-login "link your account" buttons on the order-received page.
        echo '<style>.woocommerce-order-received .social-login,.woocommerce-order-received .wc-social-login,.woocommerce-order-received .nsl-container{display:none!important;}</style>';
        echo self::club_font_link();

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $net  = max(0.0, (float) $order->get_total() - (float) $order->get_shipping_total());
        $user = $order->get_user(); // WP_User, or false for guest checkout
        $mult = ['silver' => 1.0, 'gold' => 1.2, 'diamond' => 1.5, 'black' => 2.0];
        $tier = 'silver';
        $summary = null;
        if ($user) {
            $summary = self::fetch_summary($user->user_email, $user->ID);
            if ($summary && isset($summary['tier'])) {
                $tier = $summary['tier'];
            }
        }
        $coins_est = (int) round($net * (isset($mult[$tier]) ? $mult[$tier] : 1.0));
        $earned    = $coins_est >= 1;

        echo '<section style="border:1px solid #f3ddd2;border-radius:20px;padding:24px;background:#fff9f4;margin:24px 0;font-family:\'Nunito\',sans-serif;color:#3a2a22;">';
        $hstyle = "margin-top:0;font-family:'Baloo 2',sans-serif;font-weight:800;color:#d85a30;";
        if ($earned) {
            echo '<h2 style="' . $hstyle . '">' . self::coin_icon() . 'You just earned ~' . esc_html(number_format_i18n($coins_est)) . ' G-Coins!</h2>';
            echo '<p style="margin:0 0 12px;">Spend them on looks, dress up your little Buddy, and climb the leaderboard in GALADO Club.</p>';
        } else {
            echo '<h2 style="' . $hstyle . '">🎀 Your GALADO Club is waiting</h2>';
            echo '<p style="margin:0 0 12px;">Dress up your little Buddy, spend your G-Coins on looks, and join The Lounge.</p>';
        }

        if ($user) {
            if ($summary && isset($summary['coins'])) {
                echo '<p style="margin:0 0 14px;">Your Club balance so far: <strong>' . esc_html(number_format_i18n((int) $summary['coins'])) . ' G-Coins</strong> <span style="opacity:.7;">(this order&rsquo;s coins land once it&rsquo;s processed).</span></p>';
            }
            $token = self::sso_token($user);
            $enter = $token ? self::club_url() . '/sso?token=' . rawurlencode($token) : self::club_url();
            echo self::cta_pill($enter, 'Open my Club');
        } else {
            $email = $order->get_billing_email();
            echo '<p style="margin:0 0 14px;">Create your free GALADO Club account' . ($earned ? ' to claim them' : '');
            if ($email) {
                echo ' &mdash; sign in with <strong>' . esc_html($email) . '</strong>';
            }
            echo '.</p>';
            echo self::cta_pill(self::club_url(), $earned ? 'Claim my G-Coins' : 'Join GALADO Club');
        }
        echo '</section>';
    }

    /** Club -> WP: mirror tier into user meta (Klaviyo segments + early-access gate). */
    public static function rest_routes() {
        // Public version ping — confirms which plugin build is live (no secrets exposed).
        register_rest_route('galado-club/v1', '/ping', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function () {
                return ['ok' => true, 'version' => self::VERSION, 'hooks' => ['transition_comment_status', 'comment_post', 'woocommerce_checkout_create_order', 'woocommerce_cart_calculate_fees']];
            },
        ]);
        register_rest_route('galado-club/v1', '/tier', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $email = sanitize_email((string) $request->get_param('email'));
                $tier  = sanitize_key((string) $request->get_param('tier'));
                if (!$email || !in_array($tier, ['silver', 'gold', 'diamond', 'black'], true)) {
                    return new WP_Error('bad_request', 'email and valid tier required', ['status' => 400]);
                }
                $wp_user = get_user_by('email', $email);
                if (!$wp_user) {
                    return new WP_Error('not_found', 'no user with that email', ['status' => 404]);
                }
                update_user_meta($wp_user->ID, 'galado_club_tier', $tier);
                return ['ok' => true, 'user_id' => $wp_user->ID, 'tier' => $tier];
            },
        ]);

        // Read a member's WooCommerce Points & Rewards balance.
        register_rest_route('galado-club/v1', '/points', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                if (!class_exists('WC_Points_Rewards_Manager')) {
                    return new WP_Error('no_points_plugin', 'Points & Rewards not active', ['status' => 501]);
                }
                $email   = sanitize_email((string) $request->get_param('email'));
                $wp_user = $email ? get_user_by('email', $email) : false;
                if (!$wp_user) {
                    return ['points' => 0, 'has_account' => false];
                }
                return ['points' => (int) WC_Points_Rewards_Manager::get_users_points($wp_user->ID), 'has_account' => true];
            },
        ]);

        // Deduct points — the Club credits the matching G-Coins only after this succeeds.
        register_rest_route('galado-club/v1', '/points/deduct', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                if (!class_exists('WC_Points_Rewards_Manager')) {
                    return new WP_Error('no_points_plugin', 'Points & Rewards not active', ['status' => 501]);
                }
                $email  = sanitize_email((string) $request->get_param('email'));
                $points = absint($request->get_param('points'));
                if (!$email || $points < 1) {
                    return new WP_Error('bad_request', 'email and positive points required', ['status' => 400]);
                }
                $wp_user = get_user_by('email', $email);
                if (!$wp_user) {
                    return new WP_Error('not_found', 'no user with that email', ['status' => 404]);
                }
                $balance = (int) WC_Points_Rewards_Manager::get_users_points($wp_user->ID);
                if ($points > $balance) {
                    return new WP_Error('insufficient_points', 'not enough points', ['status' => 409]);
                }
                WC_Points_Rewards_Manager::decrease_points($wp_user->ID, $points, 'galado-club-conversion');
                return ['ok' => true, 'deducted' => $points, 'balance' => (int) WC_Points_Rewards_Manager::get_users_points($wp_user->ID)];
            },
        ]);
    }
}

Galado_Club_Bridge::init();
