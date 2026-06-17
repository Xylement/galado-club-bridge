<?php
/**
 * Plugin Name: GALADO Club Bridge
 * Description: Connects galado.com.my accounts to GALADO Club — adds a "GALADO Club" tab in My Account, signs members into club.galado.com.my (SSO), and mirrors Club tiers to user meta.
 * Version: 0.2.3
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
    const VERSION  = '0.2.3';

    public static function init() {
        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'render_tab']);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
        add_action('transition_comment_status', [__CLASS__, 'on_comment_transition'], 10, 3);
        add_action('comment_post', [__CLASS__, 'on_comment_post'], 10, 2);
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
     * Mirror the Club home's 2D portrait selection (web Dashboard.tsx + cosmetics.ts):
     * custom uploaded photo -> outfit-aware portrait -> plain base buddy.
     */
    private static function portrait_url(array $summary) {
        $base = isset($summary['avatarBase']) && 'boy' === $summary['avatarBase'] ? 'boy' : 'girl';

        // 1) Custom uploaded photo wins. Served by the Club origin, so prefix root-relative paths
        //    (this <img> renders on galado.com.my, not the Club domain).
        $custom = isset($summary['customPhoto']) ? trim((string) $summary['customPhoto']) : '';
        if ($custom !== '') {
            return preg_match('#^https?://#i', $custom) ? $custom : self::club_url() . '/' . ltrim($custom, '/');
        }

        // 2) Outfit-aware portrait — only outfits with a baked 2D portrait. Keep in sync with cosmetics.ts.
        $portrait_outfits = ['outfit-cozy-hoodie-set', 'outfit-summer-tee-shorts'];
        if (!empty($summary['equipped']) && is_array($summary['equipped'])) {
            foreach ($summary['equipped'] as $eq) {
                if (isset($eq['slot'], $eq['slug']) && 'outfit' === $eq['slot'] && in_array($eq['slug'], $portrait_outfits, true)) {
                    return self::club_url() . '/avatar-' . $base . '-' . $eq['slug'] . '.png';
                }
            }
        }

        // 3) Plain base buddy.
        return self::club_url() . '/avatar-' . $base . '.png';
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

    public static function render_tab() {
        $user = wp_get_current_user();
        if (!$user || 0 === $user->ID) {
            return;
        }
        $summary   = self::fetch_summary($user->user_email, $user->ID);
        $token     = self::sso_token($user);
        $enter_url = $token ? self::club_url() . '/sso?token=' . rawurlencode($token) : self::club_url();

        $tier_labels = [
            'silver'  => 'Silver',
            'gold'    => 'Gold',
            'diamond' => 'Diamond',
            'black'   => 'GALADO Black',
        ];

        echo '<div style="border:1px solid #f3ddd2;border-radius:20px;padding:24px;background:#fff9f4;">';
        echo '<h3 style="margin-top:0;">GALADO Club</h3>';

        if ($summary) {
            $portrait = self::portrait_url($summary);
            $tier     = isset($summary['tier'], $tier_labels[$summary['tier']]) ? $tier_labels[$summary['tier']] : 'Silver';
            $coins    = isset($summary['coins']) ? (int) $summary['coins'] : 0;

            echo '<div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">';
            echo '<img src="' . esc_url($portrait) . '" alt="Your Club avatar" width="96" height="96" style="border-radius:50%;object-fit:cover;object-position:top;border:4px solid #ffd9cf;" />';
            echo '<div>';
            echo '<p style="margin:0 0 4px;"><strong>' . esc_html($tier) . '</strong> member</p>';
            echo '<p style="margin:0 0 12px;">' . esc_html(number_format_i18n($coins)) . ' G-Coins ready to spend</p>';
            echo '</div></div>';
        } else {
            echo '<p>Your coins, badges and avatar are waiting — every GALADO order earns G-Coins.</p>';
        }

        echo '<p style="margin-bottom:0;"><a class="button" href="' . esc_url($enter_url) . '">Enter the Club &rarr;</a></p>';
        echo '</div>';
    }

    /** Club -> WP: mirror tier into user meta (Klaviyo segments + early-access gate). */
    public static function rest_routes() {
        // Public version ping — confirms which plugin build is live (no secrets exposed).
        register_rest_route('galado-club/v1', '/ping', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function () {
                return ['ok' => true, 'version' => self::VERSION, 'hooks' => ['transition_comment_status', 'comment_post']];
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
