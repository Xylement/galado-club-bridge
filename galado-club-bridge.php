<?php
/**
 * Plugin Name: GALADO Club Bridge
 * Description: Connects galado.com.my accounts to GALADO Club — adds a "GALADO Club" tab in My Account, signs members into club.galado.com.my (SSO), and mirrors Club tiers to user meta.
 * Version: 0.30.0
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
    const VERSION  = '0.30.0';
    const WELCOME_AMOUNT = 10;   // RM off a referred new customer's first order
    const WELCOME_MIN    = 30;   // min cart subtotal (RM) before the referral discount applies
    const WELCOME30_AMOUNT = 30; // RM off a Club member's first order (signed welcome token)
    const WELCOME30_MIN    = 120;// min cart subtotal (RM) for the Club welcome offer

    // ── Mid-Year Member Sale (Thu 16 – Sun 19 Jul 2026, MYT) — SPEC-MIDYEAR-SALE-STORE.md ──
    // Members (= any logged-in customer; every store account is Club-provisioned since the
    // v0.12 two-way bridge + full backfill) pay 20% off the CURRENT selling price of the two
    // hero products, all variants. Runtime filters only — no product data is written, so the
    // rollback is the window passing (or reverting this block). Self-arming/disarming.
    const MYS_HEROES  = [389955, 389852];       // Stylink Metal Chain, Luna Guard (variable parents)
    const MYS_FACTOR  = 0.80;                   // member pays 20% off the current selling price
    const MYS_START   = '2026-07-16 00:00:00';  // MYT — hard on-by is 11:00 same day
    const MYS_END     = '2026-07-20 00:00:00';  // MYT — off Mon 20 Jul 00:00
    const MYS_PREVIEW = 'mys-0716';             // ?gldmys=mys-0716 = display preview for THIS request only
    const MYS_BLOCKED_COUPONS = ['lvlup5', 'diam10d', 'gblk15']; // tier codes never stack on heroes (best single price)

    public static function init() {
        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'render_tab']);
        // On-site activation (post-payment + account only — never before checkout):
        add_action('woocommerce_account_dashboard', [__CLASS__, 'dashboard_card']);
        add_action('woocommerce_thankyou', [__CLASS__, 'thankyou_block']);
        // Order-confirmation email "Add to Wallet" block (customer emails only; dark until launch).
        add_action('woocommerce_email_after_order_table', [__CLASS__, 'wallet_add_email_block'], 25, 4);
        // App orders carry no shipping-method line, so WooCommerce hides the
        // delivery address on order emails; buyers should see where their
        // parcel is going.
        add_filter('woocommerce_order_needs_shipping_address', [__CLASS__, 'app_order_needs_shipping_address'], 10, 3);
        // Every order earns Shopping Credits on the FINAL amount paid (product +
        // customisation add-ons, less discounts/redemptions), not WooCommerce's
        // default per-product sum which ignores add-on FEES and under-credits any
        // order with add-ons. Product/category multipliers (DOUBLE Reward) kept.
        add_filter('wc_points_rewards_points_earned_for_purchase', [__CLASS__, 'points_earned_on_paid_amount'], 20, 2);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
        // Two-way account link: a new galado.com.my registration also creates the Club member.
        add_action('user_register', [__CLASS__, 'on_user_register'], 20, 1);
        add_action('transition_comment_status', [__CLASS__, 'on_comment_transition'], 10, 3);
        add_action('comment_post', [__CLASS__, 'on_comment_post'], 10, 2);
        // Referral: capture ?ref= into a 30-day cookie, then stamp it onto the order at checkout.
        add_action('wp_footer', [__CLASS__, 'ref_cookie_script']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'capture_referral'], 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'capture_referral'], 10, 1);
        // Club welcome offer: capture ?welcome=<signed token> into a 30-day cookie.
        add_action('wp_footer', [__CLASS__, 'welcome_cookie_script']);
        // First-order discount: the bigger of the Club welcome (RM30) or referral (RM10), never both.
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'first_order_discount']);
        // POS (pos.galado.com.my) orders carry _pos_order meta: the sale happened at the
        // counter, so suppress WooCommerce CUSTOMER emails and keep the order out of
        // Klaviyo (flows like post-purchase would otherwise fire on walk-in sales).
        // The admin "New order" email deliberately still fires — inbox paper trail.
        foreach ([
            'customer_processing_order',
            'customer_completed_order',
            'customer_on_hold_order',
            'customer_invoice',
            'customer_refunded_order',
            'customer_partially_refunded_order',
        ] as $pos_email_id) {
            add_filter('woocommerce_email_enabled_' . $pos_email_id, [__CLASS__, 'pos_suppress_email'], 10, 2);
        }
        add_filter('woocommerce_webhook_should_deliver', [__CLASS__, 'pos_filter_webhook'], 10, 3);
        // The WooCommerce "new account" email addresses the customer only by their
        // auto-generated username (email local part, e.g. "layhar78"). Inject a warm
        // "Hi <name>," greeting at the top of that email's body so it opens with the name
        // the customer entered. Priority 20 runs after WC's header render, so the greeting
        // lands just under the heading; scoped to this one email — order emails untouched.
        add_action('woocommerce_email_header', [__CLASS__, 'new_account_greeting'], 20, 2);
        // In-store receipt emails read like a receipt, not a shipping confirmation.
        add_filter('woocommerce_email_subject_customer_completed_order', [__CLASS__, 'pos_email_subject'], 10, 2);
        add_filter('woocommerce_email_heading_customer_completed_order', [__CLASS__, 'pos_email_heading'], 10, 2);
        add_action('woocommerce_email_before_order_table', [__CLASS__, 'pos_email_intro'], 10, 4);
        // The "Hide Checkout Shipping Address" plugin fatals on any programmatic order
        // creation (its woocommerce_new_order handler reads WC_Checkout->shipping_method,
        // which calls WC()->session->get() — null outside a real browser checkout). Detach
        // it just-in-time when there is no session; real checkouts are unaffected.
        add_action('woocommerce_new_order', [__CLASS__, 'pos_guard_hcsa'], 1);
        // Club join popup (replaces the retired Klaviyo subscription popup): guests only,
        // never on cart/checkout/account. Name + email -> Club emails a one-tap sign-in link.
        add_action('wp_footer', [__CLASS__, 'join_popup'], 40);
        // Mid-Year Member Sale (16–19 Jul 2026): member price on the two heroes, the
        // join-to-unlock prompt for guests, and the tier-coupon stacking block. All
        // window-gated (see MYS_* constants) — dormant outside the window.
        add_filter('woocommerce_product_get_price', [__CLASS__, 'mys_member_price'], 20, 2);
        add_filter('woocommerce_product_get_sale_price', [__CLASS__, 'mys_member_sale_price'], 20, 2);
        add_filter('woocommerce_product_variation_get_price', [__CLASS__, 'mys_member_price'], 20, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [__CLASS__, 'mys_member_sale_price'], 20, 2);
        add_filter('woocommerce_variation_prices_price', [__CLASS__, 'mys_member_price'], 20, 2);
        add_filter('woocommerce_variation_prices_sale_price', [__CLASS__, 'mys_member_sale_price'], 20, 2);
        add_filter('woocommerce_get_variation_prices_hash', [__CLASS__, 'mys_prices_hash'], 20, 1);
        add_filter('woocommerce_coupon_is_valid_for_product', [__CLASS__, 'mys_block_tier_coupons'], 20, 3);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'mys_pdp_prompt'], 15);
        register_activation_hook(__FILE__, 'flush_rewrite_rules');
        register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
    }

    private static function is_pos_order($order) {
        return $order instanceof WC_Order && '1' === (string) $order->get_meta('_pos_order');
    }

    /** True while the POS is deliberately sending an email receipt for a POS order. */
    private static $pos_email_override = false;

    public static function pos_suppress_email($enabled, $order = null) {
        if (self::$pos_email_override) {
            return $enabled;
        }
        return self::is_pos_order($order) ? false : $enabled;
    }

    /** POS -> WP: send the native Woo order email as a paperless receipt (explicit, per order). */
    public static function pos_send_order_email(WP_REST_Request $request) {
        $order_id = absint($request->get_param('order_id'));
        $email    = sanitize_email((string) $request->get_param('email'));
        $order    = $order_id ? wc_get_order($order_id) : false;
        if (!$order) {
            return new WP_Error('not_found', 'no such order', ['status' => 404]);
        }
        if ($email && is_email($email)) {
            $order->set_billing_email($email);
            $order->save();
        }
        if (!$order->get_billing_email()) {
            return new WP_Error('no_email', 'order has no email — provide one', ['status' => 400]);
        }
        self::$pos_email_override = true;
        $emails = WC()->mailer()->get_emails();
        if (isset($emails['WC_Email_Customer_Completed_Order'])) {
            $emails['WC_Email_Customer_Completed_Order']->trigger($order_id);
        }
        self::$pos_email_override = false;
        return ['ok' => true, 'sent_to' => $order->get_billing_email()];
    }

    public static function pos_email_subject($subject, $order = null) {
        return self::is_pos_order($order) ? 'Your GALADO receipt' : $subject;
    }

    public static function pos_email_heading($heading, $order = null) {
        return self::is_pos_order($order) ? 'Thanks for shopping with us!' : $heading;
    }

    public static function pos_email_intro($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if ($sent_to_admin || !self::is_pos_order($order)) {
            return;
        }
        if (!is_object($email) || 'customer_completed_order' !== $email->id) {
            return;
        }
        $text = 'It was lovely having you at the store today — here is your receipt, safe in your inbox. '
              . 'It also works as your proof of purchase for warranty, so no need to keep a paper slip. '
              . 'Good news: this purchase earned you Shopping Credits — see them anytime at club.galado.com.my.';
        if ($plain_text) {
            echo $text . "\n\n";
        } else {
            echo '<p style="margin:0 0 16px;">' . esc_html($text) . '</p>';
        }
    }

    /** Echo a "Hi <name>," line at the top of the new-account email body. */
    public static function new_account_greeting($email_heading, $email = null) {
        if (!is_object($email) || 'customer_new_account' !== $email->id) {
            return;
        }
        $user = $email->object;
        if (!($user instanceof WP_User)) {
            return;
        }
        $first = get_user_meta($user->ID, 'first_name', true);
        $name  = $first !== '' ? $first
               : (($user->display_name && $user->display_name !== $user->user_login) ? $user->display_name : '');
        $name  = trim((string) $name);
        if ('' === $name) {
            return; // no real name on file → leave WooCommerce's default wording
        }
        echo '<p style="margin:0 0 16px;">' . esc_html('Hi ' . $name . ',') . '</p>';
    }

    public static function pos_guard_hcsa() {
        if (function_exists('WC') && null === WC()->session && function_exists('wc_hcsa_adjust_order_shipping_fields')) {
            remove_action('woocommerce_new_order', 'wc_hcsa_adjust_order_shipping_fields', 99);
        }
    }

    /** Keep POS orders out of Klaviyo's order webhook; every other webhook (incl. the Club's) still fires. */
    public static function pos_filter_webhook($should_deliver, $webhook, $arg) {
        if (!$should_deliver || !is_object($webhook) || false === strpos((string) $webhook->get_delivery_url(), 'klaviyo.com')) {
            return $should_deliver;
        }
        if (0 !== strpos((string) $webhook->get_topic(), 'order.')) {
            return $should_deliver;
        }
        $order = is_numeric($arg) ? wc_get_order((int) $arg) : null;
        return self::is_pos_order($order) ? false : $should_deliver;
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

    /** Wallet service base (pos.galado.com.my/wallet). Same shared bridge secret as the Club. */
    private static function wallet_url() {
        return defined('GALADO_WALLET_URL') ? rtrim(GALADO_WALLET_URL, '/') : 'https://pos.galado.com.my/wallet';
    }

    /** Server-to-server POST to the wallet service (bridge secret). Returns decoded body or null. */
    private static function wallet_post($path, $body) {
        $secret = self::bridge_secret();
        if ('' === $secret) {
            return null;
        }
        $res = wp_remote_post(self::wallet_url() . $path, [
            'timeout' => 6,
            'headers' => ['content-type' => 'application/json', 'x-club-bridge-secret' => $secret],
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 300) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($data) ? $data : null;
    }

    /**
     * "Add to Wallet" surface for the order-received page — member-only, one badge by device,
     * resolving the buyer's own Club pass so it's one tap. DARK by default: only renders for
     * admins (so Clement can vet it on a real order) until GALADO_WALLET_ADD_LIVE is defined true.
     * The 150 G-Coins land on the CONFIRMED add (wallet → Club /api/pos/wallet-adopt), never here.
     */
    private static function wallet_add_block($order) {
        $live = defined('GALADO_WALLET_ADD_LIVE') && GALADO_WALLET_ADD_LIVE;
        if (!$live && !current_user_can('manage_options')) {
            return; // dark for real buyers until the launch flag is flipped
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $is_ios = (bool) preg_match('/iPhone|iPod/i', $ua);
        $is_android = !$is_ios && (bool) preg_match('/Android/i', $ua);
        if (!$is_ios && !$is_android) {
            return; // the add sheet is phone-only
        }
        // Pure-PHP render: no wallet call here. The official badge links to the signed /wallet-add
        // resolver, which detects the device on click and 302s to the Apple pkpass / Google save.
        $badge = $is_ios
            ? 'https://galado.com.my/gld-files/uploads/2026/07/gld-add-to-apple-wallet.png'
            : 'https://galado.com.my/gld-files/uploads/2026/07/gld-add-to-google-wallet.png';
        $url = esc_url(self::wallet_add_url($order->get_id()));
        echo '<div style="margin:20px 0;padding:18px 20px;background:#F5F5F3;border:1px solid #ECECEA;border-radius:16px;font-family:\'Inter\',sans-serif;color:#111111;">';
        echo '<p style="margin:0 0 12px;font-size:14px;line-height:1.5;">Add your Club Card to your Wallet and we&rsquo;ll drop <strong>150 G-Coins</strong> in your account.</p>';
        echo '<a href="' . $url . '" style="display:inline-block;"><img src="' . esc_url($badge) . '" alt="' . ($is_ios ? 'Add to Apple Wallet' : 'Save to Google Wallet') . '" style="height:46px;width:auto;display:block;border:0;"></a>';
        if (!$live) {
            echo '<p style="margin:10px 0 0;font-size:11px;color:#8C8C8C;">Preview (admins only) &middot; hidden from buyers until launch.</p>';
        }
        echo '</div>';
    }

    /** Shared permission check for all server-to-server bridge routes. */
    public static function bridge_auth(WP_REST_Request $request) {
        $secret = self::bridge_secret();
        return '' !== $secret && hash_equals($secret, (string) $request->get_header('x-club-bridge-secret'));
    }

    /** New galado.com.my registration → mirror into the Club so a store signup is also a Club member. */
    public static function on_user_register($user_id) {
        $secret = self::bridge_secret();
        if ('' === $secret) {
            return;
        }
        $user  = get_userdata($user_id);
        $email = $user ? $user->user_email : '';
        if (!$email || !is_email($email)) {
            return;
        }
        wp_remote_post(self::club_url() . '/webhooks/store-signup', [
            'timeout'  => 4,
            'blocking' => false, // fire-and-forget; the Club upsert is idempotent
            'headers'  => ['content-type' => 'application/json', 'x-club-bridge-secret' => $secret],
            'body'     => wp_json_encode(['email' => strtolower($email)]),
        ]);
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
<script>(function(){try{var r=new URLSearchParams(location.search).get('ref');if(!r)return;r=r.replace(/[^A-Za-z0-9]/g,'').slice(0,12).toUpperCase();if(!r)return;var e=new Date(Date.now()+2592e6).toUTCString();document.cookie='galado_ref='+r+'; expires='+e+'; domain=.galado.com.my; path=/; SameSite=Lax';}catch(e){}})();</script>
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
     * Club welcome offer — capture ?welcome=<signed token> into a 30-day first-party cookie.
     * The token is minted per-member by the Club (HMAC over the shared SSO secret) so the RM30
     * can't be faked or pasted around as a public promo. Reads the live URL so cached pages work.
     */
    public static function welcome_cookie_script() {
        if (is_admin()) {
            return;
        }
        ?>
<script>(function(){try{var w=new URLSearchParams(location.search).get('welcome');if(!w||!/^welcome\.[0-9]+\.[0-9]+\.[A-Za-z0-9_-]+$/.test(w))return;var e=new Date(Date.now()+2592e6).toUTCString();document.cookie='galado_welcome='+w+'; expires='+e+'; path=/; SameSite=Lax';}catch(e){}})();</script>
<?php
    }

    /** Verify a Club-minted welcome token (welcome.<memberId>.<exp>.<base64url-hmac>). */
    private static function verify_welcome_token($token) {
        $secret = self::sso_secret();
        if ('' === $secret || !$token) {
            return false;
        }
        $parts = explode('.', (string) $token);
        if (count($parts) !== 4 || 'welcome' !== $parts[0]) {
            return false;
        }
        $payload = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
        $calc = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
        if (!hash_equals($calc, $parts[3])) {
            return false;
        }
        return (int) $parts[2] >= time();
    }

    /**
     * First-order discount as a negative cart fee. A never-ordered shopper gets the BIGGER of:
     *  - the Club welcome (RM30 off, min RM120) when a valid signed ?welcome token cookie is set, or
     *  - the referral welcome (RM10 off, min RM30) when a ?ref cookie is set.
     * Never both (no stacking). Existing customers get neither; the min subtotal protects margin.
     * Applied as a negative fee so it shows on cart + checkout and flows into the order total
     * (so a referrer's 10% is on what the friend actually paid).
     */
    public static function first_order_discount($cart) {
        if ((is_admin() && !defined('DOING_AJAX')) || !function_exists('WC')) {
            return;
        }
        if (self::is_existing_customer()) {
            return;
        }
        $subtotal = (float) $cart->get_subtotal();
        if (!empty($_COOKIE['galado_welcome'])
            && self::verify_welcome_token(wp_unslash($_COOKIE['galado_welcome']))
            && $subtotal >= self::WELCOME30_MIN) {
            $cart->add_fee(__('GALADO Club welcome: RM30 off your first order', 'galado-club'), -1 * self::WELCOME30_AMOUNT, false);
            return;
        }
        if (!empty($_COOKIE['galado_ref']) && $subtotal >= self::WELCOME_MIN) {
            $cart->add_fee(__('Referral welcome: RM10 off your first order', 'galado-club'), -1 * self::WELCOME_AMOUNT, false);
        }
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
            . "font-family:'Archivo',sans-serif;font-weight:700;font-size:11px;letter-spacing:.08em;text-transform:uppercase;"
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
    /** Load the brand fonts (Archivo + Inter) so the cards match Brand Guidelines v1.0. */
    private static function club_font_link() {
        return '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;600&display=swap">';
    }

    /** Coral pill CTA matching the Club app buttons (avoids the store theme's square .button). */
    private static function cta_pill($url, $label) {
        return '<a href="' . esc_url($url) . '" style="display:inline-block;background:#111111;color:#fff;'
            . "font-family:'Archivo',sans-serif;font-weight:700;font-size:15px;line-height:1;text-decoration:none;"
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
        echo '<div style="border:1px solid #ECECEA;border-radius:20px;padding:24px;background:#FFFFFF;font-family:\'Inter\',sans-serif;color:#111111;box-shadow:0 4px 16px rgba(17,17,17,.06);">';
        echo '<h3 style="margin-top:0;font-family:\'Archivo\',sans-serif;font-weight:800;color:#111111;letter-spacing:-.02em;">' . esc_html($heading) . '</h3>';

        if ($summary) {
            $coins = isset($summary['coins']) ? (int) $summary['coins'] : 0;

            echo '<div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">';
            echo self::avatar_html($summary, 96);
            echo '<div>';
            echo '<p style="margin:0 0 6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
                . self::tier_pill(isset($summary['tier']) ? $summary['tier'] : 'silver')
                . '<span style="opacity:.7;">member</span></p>';
            echo '<p style="margin:0 0 12px;">' . esc_html(number_format_i18n($coins)) . ' G-Coins ready to spend. Dress up your Buddy and join The Lounge.</p>';
            echo '</div></div>';
        } else {
            echo '<p>Your coins, badges and avatar are waiting: every GALADO order earns G-Coins.</p>';
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
    /** Signed, no-login wallet-add link for the confirmation email (HMAC ties it to the order). */
    private static function wallet_add_url($order_id) {
        $sig = hash_hmac('sha256', 'walletadd:' . $order_id, self::sso_secret());
        return add_query_arg(['o' => $order_id, 's' => $sig], rest_url('galado-club/v1/wallet-add'));
    }

    /** Signed, no-login wallet-add link keyed to a MEMBER email (welcome series / lifecycle emails,
     *  where there is no order). HMAC ties it to the lowercased email so it can only add that member's
     *  own pass. Klaviyo stores this per profile as {{ person.wallet_add_url }}. */
    private static function wallet_add_url_member($email) {
        $email = strtolower(trim((string) $email));
        $sig   = hash_hmac('sha256', 'walletaddm:' . $email, self::sso_secret());
        return add_query_arg(['m' => $email, 's' => $sig], rest_url('galado-club/v1/wallet-add'));
    }

    /** Order-confirmation email "Add to Wallet" block. Customer emails only; dark unless launched
     *  OR the recipient is a WP admin (so Clement can preview by resending himself an order email). */
    public static function wallet_add_email_block($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if ($sent_to_admin || $plain_text || !is_a($order, 'WC_Order')) {
            return;
        }
        $eid = is_object($email) && isset($email->id) ? $email->id : '';
        if ($eid && !in_array($eid, ['customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice'], true)) {
            return;
        }
        $live  = defined('GALADO_WALLET_ADD_LIVE') && GALADO_WALLET_ADD_LIVE;
        $buyer = $order->get_user(); // WP_User or false
        if (!$buyer) {
            return; // guest checkout — join flows handle them, no pass to add
        }
        if (!$live && !user_can($buyer, 'manage_options')) {
            return; // dark: only shows in emails to admin recipients while previewing
        }
        $url = esc_url(self::wallet_add_url($order->get_id()));
        echo '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:16px 0;"><tr><td style="background:#F5F5F3;border:1px solid #ECECEA;border-radius:16px;padding:22px;font-family:Arial,Helvetica,sans-serif;color:#111111;">'
           . '<div style="font-size:18px;font-weight:bold;margin-bottom:8px;">Track your G-Coins from your lock screen</div>'
           . '<div style="font-size:14px;line-height:1.5;color:#4A4A4A;margin-bottom:16px;">Add your GALADO Club Card to your phone Wallet: your coins, your member barcode, and first dibs on drops. <strong style="color:#111111;">Worth 150 G-Coins the moment you add it.</strong></div>'
           . '<a href="' . $url . '" style="display:inline-block;text-decoration:none;"><img src="https://galado.com.my/gld-files/uploads/2026/07/gld-add-to-apple-wallet.png" alt="Add to Apple Wallet" width="149" height="46" style="display:block;border:0;height:46px;width:149px;"></a>'
           . ($live ? '' : '<div style="font-size:11px;color:#8C8C8C;margin-top:12px;">Preview (admin recipients only) &mdash; hidden from buyers until launch.</div>')
           . '</td></tr></table>';
    }

    /** No-login redirect target for the email button: verify HMAC, detect the device, resolve the
     *  buyer's pass, and 302 to the add sheet (Apple .pkpass / Google save). */
    public static function wallet_add_redirect(WP_REST_Request $request) {
        $sig = (string) $request->get_param('s');
        $m   = strtolower(trim((string) $request->get_param('m')));
        $customer_id = 0;
        if ('' !== $m) {
            // Member-email mode (welcome series / lifecycle emails — no order in play).
            $expect = hash_hmac('sha256', 'walletaddm:' . $m, self::sso_secret());
            if ('' === $sig || !hash_equals($expect, $sig) || !is_email($m)) {
                wp_die('This add-to-Wallet link is invalid or expired.', 'GALADO Club', ['response' => 400]);
            }
            $email   = sanitize_email($m);
            $wp_user = get_user_by('email', $email);
            $customer_id = $wp_user ? (int) $wp_user->ID : 0;
        } else {
            // Order mode (order-confirmation email — HMAC tied to the order id).
            $order_id = (int) $request->get_param('o');
            $expect   = hash_hmac('sha256', 'walletadd:' . $order_id, self::sso_secret());
            if (!$order_id || '' === $sig || !hash_equals($expect, $sig)) {
                wp_die('This add-to-Wallet link is invalid or expired.', 'GALADO Club', ['response' => 400]);
            }
            $order = wc_get_order($order_id);
            $email = $order ? $order->get_billing_email() : '';
            $customer_id = $order ? (int) $order->get_customer_id() : 0;
            if (!$email) {
                wp_die('We could not find your order.', 'GALADO Club', ['response' => 404]);
            }
        }
        $ua         = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $is_ios     = (bool) preg_match('/iPhone|iPod/i', $ua);
        $is_android = !$is_ios && (bool) preg_match('/Android/i', $ua);
        if (!$is_ios && !$is_android) {
            wp_die('Open this link on your phone to add your GALADO Club Card to your Wallet.', 'GALADO Club', ['response' => 200]);
        }
        $summary = self::fetch_summary($email, $customer_id);
        $tier    = ($summary && isset($summary['tier'])) ? $summary['tier'] : 'silver';
        $payload = ['email' => $email, 'tier' => $tier];
        $r = $is_ios ? self::wallet_post('/issue', $payload) : self::wallet_post('/google/save-link', $payload);
        if (!$r || empty($r['url'])) {
            wp_die('Your card is not ready yet &mdash; please try again in a moment.', 'GALADO Club', ['response' => 502]);
        }
        wp_redirect($r['url'], 302);
        exit;
    }

    /** Bridge-secret batch signer: given member emails, return their signed wallet-add links.
     *  Lets the Klaviyo sync populate {{ person.wallet_add_url }} without ever seeing the SSO
     *  secret (it stays in WordPress). POST { "emails": [...] } or { "email": "..." }. */
    public static function wallet_add_link_batch(WP_REST_Request $request) {
        $emails = $request->get_param('emails');
        if (!is_array($emails)) {
            $one    = (string) $request->get_param('email');
            $emails = '' !== $one ? [$one] : [];
        }
        $links = [];
        foreach (array_slice($emails, 0, 1000) as $e) {
            $e = strtolower(trim((string) $e));
            if (is_email($e) && !isset($links[$e])) {
                $links[$e] = self::wallet_add_url_member($e);
            }
        }
        return ['links' => $links, 'count' => count($links)];
    }

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

        echo '<section style="border:1px solid #ECECEA;border-radius:20px;padding:24px;background:#FFFFFF;margin:24px 0;font-family:\'Inter\',sans-serif;color:#111111;box-shadow:0 4px 16px rgba(17,17,17,.06);">';
        $hstyle = "margin-top:0;font-family:'Archivo',sans-serif;font-weight:800;color:#111111;letter-spacing:-.02em;";
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
                echo ', sign in with <strong>' . esc_html($email) . '</strong>';
            }
            echo '.</p>';
            echo self::cta_pill(self::club_url(), $earned ? 'Claim my G-Coins' : 'Join GALADO Club');
        }
        echo '</section>';

        // Add-to-Wallet (member-only, one badge by device, dark until launch). The 150-coin
        // bonus is credited by the wallet on the CONFIRMED add, not by this surface.
        if ($user) {
            self::wallet_add_block($order);
        }
    }

    /** Club -> WP: mirror tier into user meta (Klaviyo segments + early-access gate). */
    public static function rest_routes() {
        // Public version ping — confirms which plugin build is live (no secrets exposed).
        register_rest_route('galado-club/v1', '/ping', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function () {
                return ['ok' => true, 'version' => self::VERSION, 'hooks' => ['transition_comment_status', 'comment_post', 'woocommerce_checkout_create_order', 'woocommerce_cart_calculate_fees', 'user_register']];
            },
        ]);
        // Storefront join popup -> Club magic link. Public (guests submit it), defended in
        // layers: honeypot field, per-IP transient throttle here, then the Club's own caps.
        register_rest_route('galado-club/v1', '/popup-join', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'popup_join'],
        ]);
        // No-login "Add to Wallet" redirect for order-confirmation (?o=) AND welcome/lifecycle
        // emails (?m=member email). HMAC-signed either way; verified inside the callback.
        register_rest_route('galado-club/v1', '/wallet-add', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'wallet_add_redirect'],
        ]);
        // Bridge-secret batch signer used by the Klaviyo sync to fill {{ person.wallet_add_url }}.
        register_rest_route('galado-club/v1', '/wallet-add-link', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => [__CLASS__, 'wallet_add_link_batch'],
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

        // Club -> WP: create a native WooCommerce customer for a Club member who signed up on the
        // Club, so they also have a store login. Idempotent (no-op if the email already exists);
        // WooCommerce sends its own new-account / set-password email.
        register_rest_route('galado-club/v1', '/provision-customer', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $email = sanitize_email((string) $request->get_param('email'));
                if (!$email || !is_email($email)) {
                    return new WP_Error('bad_request', 'a valid email is required', ['status' => 400]);
                }
                $existing = email_exists($email);
                if ($existing) {
                    return ['ok' => true, 'status' => 'exists', 'user_id' => (int) $existing];
                }
                if (!function_exists('wc_create_new_customer')) {
                    return new WP_Error('no_woocommerce', 'WooCommerce not active', ['status' => 501]);
                }
                // Name is set AT creation (passed into wp_insert_user via the $args) so it's
                // already on the user when WooCommerce fires the new-account email during
                // wc_create_new_customer — otherwise that email (and its greeting) sees only
                // the auto-generated username. Empty password → WooCommerce emails a
                // set-password link per the store's registration setting.
                $first = sanitize_text_field((string) $request->get_param('first_name'));
                $last  = sanitize_text_field((string) $request->get_param('last_name'));
                $phone = sanitize_text_field((string) $request->get_param('phone'));
                $full  = trim("$first $last");
                $create_args = [];
                if ($first !== '') { $create_args['first_name'] = $first; }
                if ($last !== '')  { $create_args['last_name']  = $last; }
                if ($full !== '')  { $create_args['display_name'] = $full; $create_args['nickname'] = $full; }
                $user_id = wc_create_new_customer($email, '', '', $create_args);
                if (is_wp_error($user_id)) {
                    return new WP_Error('create_failed', $user_id->get_error_message(), ['status' => 409]);
                }
                update_user_meta($user_id, 'galado_club_origin', 'club');
                // Billing name/phone for the customer profile + future orders.
                if ($first !== '') { update_user_meta($user_id, 'billing_first_name', $first); }
                if ($last !== '')  { update_user_meta($user_id, 'billing_last_name', $last); }
                if ($phone !== '') { update_user_meta($user_id, 'billing_phone', $phone); }
                return ['ok' => true, 'status' => 'created', 'user_id' => (int) $user_id];
            },
        ]);

        // Club -> WP: BULK provision for the one-time backfill of Club-only members. Up to 200
        // emails per call. `silent` suppresses the WooCommerce new-account email; `dry_run` only
        // counts (email_exists check, no writes) so we can measure how many accounts a run creates.
        register_rest_route('galado-club/v1', '/provision-customers', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $emails  = $request->get_param('emails');
                $dry_run = filter_var($request->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN);
                if (!is_array($emails) || count($emails) === 0) {
                    return new WP_Error('bad_request', 'emails[] required', ['status' => 400]);
                }
                if (count($emails) > 200) {
                    return new WP_Error('too_many', 'max 200 emails per call', ['status' => 400]);
                }
                $created = 0; $exists = 0; $failed = 0;
                foreach ($emails as $raw) {
                    $email = sanitize_email((string) $raw);
                    if (!$email || !is_email($email)) { $failed++; continue; }
                    if (email_exists($email)) { $exists++; continue; }
                    if ($dry_run) { $created++; continue; } // would-create (no write)
                    // Bare wp_insert_user (role customer) — deliberately does NOT fire
                    // woocommerce_created_customer, so a bulk backfill triggers NO signup
                    // reward-points, NO Klaviyo push, and NO new-account email. These people
                    // sync to Klaviyo/earn points only when they place a real order (unchanged).
                    $local = sanitize_user(substr($email, 0, strpos($email, '@')), true);
                    if ('' === $local) { $local = 'member'; }
                    $username = $local; $n = 1;
                    while (username_exists($username)) { $username = $local . '-' . (++$n); }
                    $uid = wp_insert_user([
                        'user_login' => $username,
                        'user_email' => $email,
                        'user_pass'  => wp_generate_password(24, true, true),
                        'role'       => 'customer',
                    ]);
                    if (is_wp_error($uid)) { $failed++; continue; }
                    update_user_meta($uid, 'galado_club_origin', 'club-backfill');
                    // Points & Rewards grants signup points on the core user_register hook (fires for
                    // ANY new user, even this bare insert). Zero out whatever it auto-awarded — a
                    // backfilled account earns nothing until it places a real order.
                    if (class_exists('WC_Points_Rewards_Manager')) {
                        $bal = (int) WC_Points_Rewards_Manager::get_users_points($uid);
                        if ($bal > 0) {
                            WC_Points_Rewards_Manager::decrease_points($uid, $bal, 'galado-club-backfill');
                        }
                    }
                    $created++;
                }
                return ['ok' => true, 'dry_run' => $dry_run, 'created' => $created, 'exists' => $exists, 'failed' => $failed, 'count' => count($emails)];
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

        // Rewards: lifetime points EARNED (positive log entries) — drives the milestone ladder.
        register_rest_route('galado-club/v1', '/points/lifetime', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                global $wpdb;
                $email   = sanitize_email((string) $request->get_param('email'));
                $wp_user = $email ? get_user_by('email', $email) : false;
                if (!$wp_user) {
                    return ['lifetime' => 0, 'balance' => 0, 'has_account' => false];
                }
                $t = $wpdb->prefix . 'wc_points_rewards_user_points_log';
                $lifetime = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(points),0) FROM $t WHERE user_id = %d AND points > 0", $wp_user->ID
                ));
                $balance = class_exists('WC_Points_Rewards_Manager')
                    ? (int) WC_Points_Rewards_Manager::get_users_points($wp_user->ID) : 0;
                return ['lifetime' => $lifetime, 'balance' => $balance, 'has_account' => true];
            },
        ]);

        // POS -> WP: credit Shopping Credits (Points & Rewards) into a member's account.
        register_rest_route('galado-club/v1', '/points/add', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                if (!class_exists('WC_Points_Rewards_Manager')) {
                    return new WP_Error('no_points_plugin', 'Points & Rewards not active', ['status' => 501]);
                }
                $email  = sanitize_email((string) $request->get_param('email'));
                $points = absint($request->get_param('points'));
                if (!$email || $points < 1 || $points > 200000) {
                    return new WP_Error('bad_request', 'email and positive points required', ['status' => 400]);
                }
                $wp_user = get_user_by('email', $email);
                if (!$wp_user) {
                    return new WP_Error('not_found', 'no user with that email', ['status' => 404]);
                }
                WC_Points_Rewards_Manager::increase_points($wp_user->ID, $points, 'galado-pos-credit');
                return ['ok' => true, 'added' => $points, 'balance' => (int) WC_Points_Rewards_Manager::get_users_points($wp_user->ID)];
            },
        ]);

        // POS -> WP: the WCPA customization fields assigned to a product (normalized).
        // Lets the POS render the same Name/Font/Colour/Photo options the web product page has.
        register_rest_route('galado-club/v1', '/product-form', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $product_id = absint($request->get_param('product_id'));
                if (!$product_id) {
                    return new WP_Error('bad_request', 'product_id required', ['status' => 400]);
                }
                $form_ids = get_post_meta($product_id, '_wcpa_product_meta', true);
                if (!is_array($form_ids) || !count($form_ids)) {
                    return ['fields' => []];
                }
                $fields = [];
                foreach ($form_ids as $fid) {
                    $data = get_post_meta((int) $fid, '_wcpa_fb-editor-data', true);
                    $defs = is_string($data) ? json_decode($data, true) : $data;
                    if (!is_array($defs)) { continue; }
                    foreach ($defs as $f) {
                        $type = (string) ($f['type'] ?? '');
                        if (!in_array($type, ['text', 'textarea', 'select', 'radio-group', 'checkbox-group', 'color-group', 'image-group', 'file'], true)) {
                            continue;
                        }
                        $options = [];
                        foreach ((array) ($f['values'] ?? []) as $i => $v) {
                            $options[] = [
                                'label' => (string) ($v['label'] ?? ''),
                                'value' => (string) ($v['value'] ?? ($v['label'] ?? ('option-' . ($i + 1)))),
                                'color' => (string) ($v['color'] ?? ''),
                                'image' => (string) ($v['image'] ?? ''),
                                'price' => (string) ($v['price'] ?? ''),
                            ];
                        }
                        // WCPA conditional logic (field shows only when another
                        // field holds a given value) — passed through raw so the
                        // iOS app can nest dependent fields (strap colour under
                        // the strap option). Carrier key names vary by version.
                        $logic = [];
                        foreach (['logic', 'cl_rule', 'cl_val', 'cl_fields', 'relations', 'clRules', 'conditions', 'condition', 'enableCl', 'relCl'] as $lk) {
                            if (isset($f[$lk])) {
                                $logic[$lk] = $f[$lk];
                            }
                        }
                        $fields[] = [
                            'form_id'     => (int) $fid,
                            'type'        => $type,
                            'name'        => (string) ($f['name'] ?? ''),
                            'label'       => (string) ($f['label'] ?? ''),
                            'description' => (string) ($f['description'] ?? ''),
                            'placeholder' => (string) ($f['placeholder'] ?? ''),
                            'maxlength'   => (int) ($f['maxlength'] ?? 0),
                            'required'    => !empty($f['required']),
                            'options'     => $options,
                            'logic'       => $logic ?: null,
                            'field_keys'  => array_keys($f),
                        ];
                    }
                }
                return ['fields' => $fields];
            },
        ]);

        // iOS app: create a PENDING order carrying customisation line meta
        // (same {label: value} format the POS writes), returning the hosted
        // payment URL. Member pays via checkout/order-pay → normal gateway +
        // email + stock machinery takes over. pos_guard_hcsa (priority 1 on
        // woocommerce_new_order) already covers this programmatic creation.
        register_rest_route('galado-club/v1', '/app-order', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $p       = $request->get_json_params();
                $lines   = is_array($p['lines'] ?? null) ? $p['lines'] : [];
                $billing = is_array($p['billing'] ?? null) ? $p['billing'] : [];
                $email   = sanitize_email((string) ($billing['email'] ?? ''));
                if (!count($lines) || !$email) {
                    return new WP_Error('bad_request', 'lines and billing.email required', ['status' => 400]);
                }
                $order = wc_create_order(['status' => 'pending']);
                if (is_wp_error($order)) {
                    return $order;
                }
                foreach ($lines as $l) {
                    $pid = absint($l['product_id'] ?? 0);
                    $vid = absint($l['variation_id'] ?? 0);
                    $qty = max(1, absint($l['quantity'] ?? 1));
                    $product = wc_get_product($vid ?: $pid);
                    if (!$product || !$product->is_purchasable()) {
                        $order->delete(true);
                        return new WP_Error('bad_request', 'unknown or unpurchasable product ' . ($vid ?: $pid), ['status' => 400]);
                    }
                    $item_id = $order->add_product($product, $qty);
                    if ($item_id && is_array($l['custom'] ?? null)) {
                        $item = $order->get_item($item_id);
                        foreach ($l['custom'] as $c) {
                            $k = sanitize_text_field((string) ($c['label'] ?? ''));
                            $v = sanitize_textarea_field((string) ($c['value'] ?? ''));
                            if ($k !== '' && $v !== '') {
                                $item->add_meta_data(mb_substr($k, 0, 80), mb_substr($v, 0, 500), false);
                            }
                        }
                        $item->save();
                    }
                }
                $addon = (float) ($p['addon_total'] ?? 0);
                if ($addon > 0 && $addon < 10000) {
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name('Customisation add-ons');
                    $fee->set_amount((string) $addon);
                    $fee->set_total((string) $addon);
                    $order->add_item($fee);
                }
                $user = get_user_by('email', $email);
                // Coupon (tier codes etc.) — validated by Woo itself.
                $coupon = strtoupper(sanitize_text_field((string) ($p['coupon'] ?? '')));
                if ($coupon !== '') {
                    $applied = $order->apply_coupon($coupon);
                    if (is_wp_error($applied)) {
                        $order->delete(true);
                        return new WP_Error('bad_coupon', $applied->get_error_message(), ['status' => 400]);
                    }
                }
                // Shopping Credits redemption — same deduction the POS uses
                // (10 points = RM1), surfaced as a negative fee line.
                $credits_points = absint($p['credits_points'] ?? 0);
                if ($credits_points > 0) {
                    if (!class_exists('WC_Points_Rewards_Manager') || !$user) {
                        $order->delete(true);
                        return new WP_Error('bad_request', 'credits unavailable for this account', ['status' => 400]);
                    }
                    $balance = (int) WC_Points_Rewards_Manager::get_users_points($user->ID);
                    if ($credits_points > $balance) {
                        $order->delete(true);
                        return new WP_Error('insufficient_points', 'not enough Shopping Credits', ['status' => 409]);
                    }
                    $credits_rm = round($credits_points / 10, 2);
                    WC_Points_Rewards_Manager::decrease_points($user->ID, $credits_points, 'galado-app-redemption');
                    $credit_fee = new WC_Order_Item_Fee();
                    $credit_fee->set_name('Shopping Credits redeemed');
                    $credit_fee->set_amount((string) (-$credits_rm));
                    $credit_fee->set_total((string) (-$credits_rm));
                    $order->add_item($credit_fee);
                    $order->add_meta_data('_galado_app_credits_points', (string) $credits_points);
                }
                $addr = [
                    'first_name' => sanitize_text_field((string) ($billing['first_name'] ?? '')),
                    'last_name'  => sanitize_text_field((string) ($billing['last_name'] ?? '')),
                    'address_1'  => sanitize_text_field((string) ($billing['address_1'] ?? '')),
                    'address_2'  => sanitize_text_field((string) ($billing['address_2'] ?? '')),
                    'city'       => sanitize_text_field((string) ($billing['city'] ?? '')),
                    'state'      => sanitize_text_field((string) ($billing['state'] ?? '')),
                    'postcode'   => sanitize_text_field((string) ($billing['postcode'] ?? '')),
                    'country'    => sanitize_text_field((string) ($billing['country'] ?? 'MY')),
                    'email'      => $email,
                    'phone'      => sanitize_text_field((string) ($billing['phone'] ?? '')),
                ];
                $order->set_address($addr, 'billing');
                $order->set_address($addr, 'shipping');
                if ($user) {
                    $order->set_customer_id($user->ID);
                }
                $order->add_meta_data('_galado_app_order', '1');
                $order->calculate_totals();
                $order->save();
                return [
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                    'pay_url'   => self::app_pay_url($order, $user),
                ];
            },
        ]);

        // App -> fresh payment link for an existing order (Resume Payment).
        // The club server calls this with the signed-in member's email; the
        // billing-email match is the ownership check.
        register_rest_route('galado-club/v1', '/app-pay-link', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $p     = $request->get_json_params();
                $order = wc_get_order(absint($p['order_id'] ?? 0));
                $email = sanitize_email((string) ($p['email'] ?? ''));
                if (!$order || !$email || 0 !== strcasecmp($order->get_billing_email(), $email)) {
                    return new WP_Error('not_found', 'order not found', ['status' => 404]);
                }
                if (!$order->needs_payment()) {
                    return ['paid' => true, 'status' => $order->get_status()];
                }
                return [
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                    'pay_url'   => self::app_pay_url($order, get_user_by('email', $email)),
                ];
            },
        ]);

        // Browser-facing half of the app payment hand-off: a single-use token
        // signs the member's own WP session in, then lands on order-pay, so
        // the app's payment sheet never shows a login form. Tokens are minted
        // only by bridge-authenticated calls above (10 min TTL).
        register_rest_route('galado-club/v1', '/app-pay/(?P<token>[A-Za-z0-9]{20,64})', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function (WP_REST_Request $request) {
                $token = (string) $request['token'];
                $data  = get_transient('gld_app_pay_' . $token);
                delete_transient('gld_app_pay_' . $token); // single use, even when invalid
                $order = is_array($data) ? wc_get_order(absint($data['order'] ?? 0)) : false;
                $user  = is_array($data) ? get_user_by('id', absint($data['uid'] ?? 0)) : false;
                if (!$order || !$user) {
                    wp_safe_redirect(home_url('/')); // expired link: plain storefront, no hints
                    exit;
                }
                wp_set_auth_cookie($user->ID, false);
                wp_safe_redirect($order->get_checkout_payment_url());
                exit;
            },
        ]);

        register_rest_route('galado-club/v1', '/order-email', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => [__CLASS__, 'pos_send_order_email'],
        ]);

        // POS -> WP: find customers by phone / email / name (walk-in member lookup).
        register_rest_route('galado-club/v1', '/customer-search', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                global $wpdb;
                $q = trim((string) $request->get_param('q'));
                if (strlen($q) < 3) {
                    return new WP_Error('bad_request', 'query too short', ['status' => 400]);
                }
                $digits = preg_replace('/\D+/', '', $q);
                $ids = [];
                if (strlen($digits) >= 6 && strlen($digits) >= strlen($q) - 4) {
                    // Mostly-numeric query → phone lookup. Match loosely on the trailing digits
                    // so 0123456789 / +60123456789 / 012-345 6789 all hit the same row.
                    $tail = substr($digits, -8);
                    $ids = $wpdb->get_col($wpdb->prepare(
                        "SELECT user_id FROM {$wpdb->usermeta}
                          WHERE meta_key = 'billing_phone'
                            AND REPLACE(REPLACE(REPLACE(REPLACE(meta_value,' ',''),'-',''),'+',''),'.','') LIKE %s
                          LIMIT 10",
                        '%' . $wpdb->esc_like($tail)
                    ));
                } else {
                    $like = '%' . $wpdb->esc_like($q) . '%';
                    $ids = $wpdb->get_col($wpdb->prepare(
                        "SELECT DISTINCT u.ID FROM {$wpdb->users} u
                           LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
                                 AND m.meta_key IN ('billing_first_name','billing_last_name','first_name','last_name')
                          WHERE u.user_email LIKE %s OR u.display_name LIKE %s OR m.meta_value LIKE %s
                          LIMIT 10",
                        $like, $like, $like
                    ));
                }
                $out = [];
                foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 10) as $uid) {
                    $u = get_userdata($uid);
                    if (!$u) { continue; }
                    $first = get_user_meta($uid, 'billing_first_name', true) ?: get_user_meta($uid, 'first_name', true);
                    $last  = get_user_meta($uid, 'billing_last_name', true) ?: get_user_meta($uid, 'last_name', true);
                    $name  = trim("$first $last") ?: $u->display_name;
                    $out[] = [
                        'user_id' => $uid,
                        'email'   => $u->user_email,
                        'name'    => $name,
                        'phone'   => (string) get_user_meta($uid, 'billing_phone', true),
                        'points'  => class_exists('WC_Points_Rewards_Manager') ? (int) WC_Points_Rewards_Manager::get_users_points($uid) : 0,
                        'tier'    => (string) get_user_meta($uid, 'galado_club_tier', true),
                    ];
                }
                return ['customers' => $out];
            },
        ]);
    }

    /** Emails hide the shipping block when an order has no shipping-method
     *  line (app orders never do). Force it on for them: the address is set. */
    public static function app_order_needs_shipping_address($needs, $hide, $order) {
        if (!$needs && $order instanceof WC_Order && $order->get_meta('_galado_app_order')) {
            return true;
        }
        return $needs;
    }

    /** Every order earns Shopping Credits on the FINAL amount the customer paid —
     *  product + customisation add-ons, less any discount or Shopping Credits
     *  redeemed (get_total() nets both). WooCommerce's default sums per-product
     *  points and ignores add-on FEES, under-crediting any order with add-ons
     *  (e.g. an RM149 product + RM35 add-on earned 75 instead of 92; all-add-on
     *  products earned 0). Mirrors the on-site "RM2 = 1 point" promise on the
     *  amount actually charged.
     *
     *  Product/category point multipliers are preserved: when Woo's own boosted
     *  per-product figure (e.g. the DOUBLE Reward category, 200%) beats the base
     *  rate, keep it and add the add-on fees Woo ignored, rather than flattening
     *  it to the final-amount rule. */
    public static function points_earned_on_paid_amount($points, $order) {
        $order = ($order instanceof WC_Order) ? $order : wc_get_order($order);
        if (!$order) {
            return $points;
        }
        $ratio = explode(':', (string) get_option('wc_points_rewards_earn_points_ratio', '1:1'));
        $earn  = isset($ratio[0]) ? (float) $ratio[0] : 0.0;
        $per   = isset($ratio[1]) ? (float) $ratio[1] : 0.0;
        if ($earn <= 0 || $per <= 0) {
            return $points; // misconfigured ratio: leave WooCommerce's value untouched
        }
        $rate  = $earn / $per;
        $round = get_option('wc_points_rewards_earn_points_rounding', 'round');
        $apply = function ($n) use ($round) {
            return (int) ('ceil' === $round ? ceil($n) : ('floor' === $round ? floor($n) : round($n)));
        };
        // Boost in play (multiplier/override lifted Woo's per-product figure above
        // the plain product rate): preserve it, then add the add-on fees on top.
        if ((int) $points > $apply((float) $order->get_subtotal() * $rate)) {
            $fees = 0.0;
            foreach ($order->get_items('fee') as $fee) {
                $amt = (float) $fee->get_total(); // add-ons are positive; redemptions negative
                if ($amt > 0) {
                    $fees += $amt;
                }
            }
            return (int) $points + $apply($fees * $rate);
        }
        // Standard case: earn on the final amount charged (incl. add-ons).
        return $apply((float) $order->get_total() * $rate);
    }

    /** Payment URL for an app-created order. Members get a single-use
     *  auto-login token URL (order-pay refuses guests once an order belongs
     *  to an account); orders without a WP user fall back to the plain
     *  key-guarded order-pay URL, which guests can open directly. */
    private static function app_pay_url($order, $user) {
        if (!$user) {
            return $order->get_checkout_payment_url();
        }
        $token = wp_generate_password(48, false, false);
        set_transient('gld_app_pay_' . $token, [
            'uid'   => (int) $user->ID,
            'order' => (int) $order->get_id(),
        ], 10 * MINUTE_IN_SECONDS);
        return rest_url('galado-club/v1/app-pay/' . $token);
    }

    /* ── Mid-Year Member Sale helpers (see MYS_* constants for the what/why) ─────────
     * Preview: append ?gldmys=mys-0716 to a hero product URL to see the in-window
     * behaviour any day. Display-only for that request — cart/checkout requests
     * without the param re-price normally, so nobody can BUY early via the preview. */

    private static function mys_window_active() {
        if (isset($_GET['gldmys']) && self::MYS_PREVIEW === (string) $_GET['gldmys']) {
            return true;
        }
        try {
            $tz  = new DateTimeZone('Asia/Kuala_Lumpur');
            $now = new DateTime('now', $tz);
            return $now >= new DateTime(self::MYS_START, $tz) && $now < new DateTime(self::MYS_END, $tz);
        } catch (Exception $e) {
            return false; // a TZ hiccup must never discount (or fatal) the storefront
        }
    }

    /** Hero parent ID when $product is a hero or one of its variations, else 0. */
    private static function mys_hero_parent($product) {
        if (!($product instanceof WC_Product)) {
            return 0;
        }
        $id = $product->get_parent_id() ?: $product->get_id();
        return in_array((int) $id, self::MYS_HEROES, true) ? (int) $id : 0;
    }

    private static function mys_applies($product) {
        return self::mys_window_active() && is_user_logged_in() && self::mys_hero_parent($product);
    }

    /** 20% off the current selling price for logged-in members, heroes only. */
    public static function mys_member_price($price, $product) {
        if ('' === $price || null === $price || !self::mys_applies($product)) {
            return $price;
        }
        return (string) round(((float) $price) * self::MYS_FACTOR, 2);
    }

    /** Forces is_on_sale() for members so the strikethrough renders (Stylink shows
     *  RM86 struck → RM68.80; Luna shows its RM109 regular struck → RM78.40). The
     *  member price is 20% off the UNFILTERED current selling price ('edit'). */
    public static function mys_member_sale_price($price, $product) {
        if (!self::mys_applies($product)) {
            return $price;
        }
        $base = $product->get_price('edit');
        if ('' === $base || null === $base) {
            return $price;
        }
        return (string) round(((float) $base) * self::MYS_FACTOR, 2);
    }

    /** Variation price ranges are transient-cached by hash — salt it with the
     *  window + login state so member/guest never share a cached range. */
    public static function mys_prices_hash($hash) {
        $hash[] = 'mys:' . (self::mys_window_active() ? '1' : '0') . ':' . (is_user_logged_in() ? 'm' : 'g');
        return $hash;
    }

    /** Best-single-price: the standing tier codes give nothing on the heroes while
     *  the member price runs (coupon stays valid for other items in the cart). */
    public static function mys_block_tier_coupons($valid, $product, $coupon) {
        if (!$valid || !self::mys_window_active()) {
            return $valid;
        }
        if (!($coupon instanceof WC_Coupon) || !in_array(strtolower($coupon->get_code()), self::MYS_BLOCKED_COUPONS, true)) {
            return $valid;
        }
        return self::mys_hero_parent($product) ? false : $valid;
    }

    /** Under the PDP price: members get a quiet confirmation, guests get the
     *  join-free prompt (the acquisition engine — visible, not buried). */
    public static function mys_pdp_prompt() {
        global $product;
        if (!self::mys_window_active() || !($product instanceof WC_Product) || !self::mys_hero_parent($product)) {
            return;
        }
        if (is_user_logged_in()) {
            echo '<p style="margin:6px 0 14px;font-weight:700;color:#0E7A57;">&#10022; GALADO Club member price applied (20% off)</p>';
            return;
        }
        $member_price = wc_price(round(((float) $product->get_price()) * self::MYS_FACTOR, 2));
        echo '<div style="margin:6px 0 16px;padding:14px 16px;border:2px solid #111111;border-radius:14px;background:#FFF7F2;">'
            . '<p style="margin:0 0 4px;font-weight:800;color:#111111;">GALADO Club members pay ' . wp_kses_post($member_price) . ' on this (20% off)</p>'
            . '<p style="margin:0 0 10px;font-size:13px;color:#5f5f66;">Free to join, takes one tap. Free shipping stays, of course.</p>'
            . '<a href="https://club.galado.com.my/?src=midyear-store" style="display:inline-block;background:#E4002B;color:#fff;font-weight:800;border-radius:999px;padding:10px 18px;text-decoration:none;">Join the Club free</a>'
            . '<a href="' . esc_url(wc_get_page_permalink('myaccount')) . '" style="display:inline-block;margin-left:12px;font-weight:700;color:#111111;">Already a member? Log in</a>'
            . '</div>';
    }

    /* -- Club join popup (2026-07-08, replaces the retired Klaviyo popup) -----------
     * Guests only. Name + email -> REST /popup-join -> Club /api/claim/request -> the Club
     * emails a one-tap magic sign-in link (20 min TTL). No voucher -- the pitch is the Club. */

    public static function popup_join(WP_REST_Request $request) {
        if ('' !== trim((string) $request->get_param('website'))) {
            return ['ok' => true]; // honeypot -> pretend success
        }
        $email = sanitize_email((string) $request->get_param('email'));
        $name  = sanitize_text_field((string) $request->get_param('name'));
        if (!$email || !is_email($email)) {
            return new WP_Error('bad_request', 'Please enter a valid email.', ['status' => 400]);
        }
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        // Referral carry-through: the visitor's own galado_ref cookie (set when they opened a
        // store share link). Forwarded so the Club can park it server-side and the referral
        // survives even when the magic link opens in a different browser.
        $ref = '';
        if (!empty($_COOKIE['galado_ref'])) {
            $ref = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_COOKIE['galado_ref'])), 0, 12));
        }
        $key = 'gld_pj_' . md5($ip);
        $n   = (int) get_transient($key);
        if ($n >= 6) {
            return new WP_Error('slow_down', 'Too many tries from this connection. Please wait a while.', ['status' => 429]);
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
        $res = wp_remote_post(GALADO_CLUB_URL . '/api/claim/request', [
            'timeout' => 8,
            'headers' => ['content-type' => 'application/json', 'x-club-bridge-secret' => GALADO_CLUB_BRIDGE_SECRET],
            'body'    => wp_json_encode(array_merge(
                ['email' => $email, 'name' => mb_substr($name, 0, 60), 'clientIp' => $ip, 'source' => 'popup'],
                $ref ? ['ref' => $ref] : []
            )),
        ]);
        if (is_wp_error($res)) {
            return new WP_Error('unreachable', 'The Club is catching its breath. Please try again shortly.', ['status' => 503]);
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if (429 === $code) {
            return new WP_Error('slow_down', 'Too many sign-in requests. Please wait a few minutes.', ['status' => 429]);
        }
        if ($code >= 400) {
            return new WP_Error('failed', 'Something went sideways. Please try again.', ['status' => 502]);
        }
        return ['ok' => true];
    }

    public static function join_popup() {
        if (is_admin() || is_user_logged_in()) {
            return;
        }
        if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) {
            return;
        }
        $rest = esc_url_raw(rest_url('galado-club/v1/popup-join'));
        ?>
<div id="gldpj" role="dialog" aria-modal="true" aria-labelledby="gldpj-title" style="display:none;">
<style>
#gldpj{position:fixed;inset:0;z-index:999999;align-items:center;justify-content:center;padding:18px;background:rgba(17,17,17,.45);overflow:auto;}
#gldpj.on{display:flex;}
#gldpj .pj-card{position:relative;width:100%;max-width:440px;margin:auto;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 24px 70px rgba(17,17,17,.28);font-family:'Inter',-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;}
#gldpj .pj-vis{position:relative;overflow:hidden;min-height:210px;background:#FBF1F0;background-image:radial-gradient(130% 118% at 40% -2%,#FEF8F7 0%,#FBEBEA 52%,#F2DAD9 100%);}
#gldpj .pj-vis .b{position:absolute;bottom:0;width:auto;}
#gldpj .pj-phone{position:absolute;left:20px;bottom:-52px;width:150px;border-radius:22px;overflow:hidden;background:#fff;border:5px solid #111111;box-shadow:0 12px 30px rgba(17,17,17,.30);transform:rotate(-5deg);}
#gldpj .pj-phone img{display:block;width:100%;}
#gldpj .pj-vis .pjb1{right:6px;height:170px;z-index:3;}
#gldpj .pj-vis .pjb2{right:78px;height:138px;z-index:2;}
#gldpj .pj-form{padding:24px 26px 22px;}
#gldpj .pj-eyebrow{text-align:center;font-family:'Archivo','Arial Black',Arial,sans-serif;font-weight:700;font-size:11px;letter-spacing:2px;color:#E4002B;text-transform:uppercase;}
#gldpj h2{margin:8px 0 16px;text-align:center;font-family:'Archivo','Arial Black',Arial,sans-serif;font-weight:900;font-size:26px;line-height:1.06;letter-spacing:-.5px;color:#111111;text-transform:uppercase;}
#gldpj .pj-perks{list-style:none;margin:0 0 16px;padding:0;display:grid;gap:10px;}
#gldpj .pj-perks li{display:flex;align-items:center;gap:10px;font-size:13.5px;line-height:1.35;color:#111111;font-weight:500;}
#gldpj .pj-pk{width:26px;height:26px;flex:none;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#FFF0F2;color:#E4002B;}
#gldpj .pj-pk svg{width:15px;height:15px;}
#gldpj .pj-pk img{width:17px;height:17px;}
#gldpj input[type=text],#gldpj input[type=email]{display:block;width:100%;box-sizing:border-box;border:0;border-bottom:2px solid #D9D9D9;padding:10px 2px;margin:0 0 12px;font-size:15px;color:#111;background:transparent;outline:none;border-radius:0;}
#gldpj input:focus{border-bottom-color:#111111;}
#gldpj .pj-hp{position:absolute;left:-9999px;opacity:0;height:0;width:0;}
#gldpj .pj-btn{display:block;width:100%;border:0;cursor:pointer;background:#E4002B;color:#fff;font-family:'Archivo','Arial Black',Arial,sans-serif;font-weight:800;font-size:16px;letter-spacing:1px;padding:15px 20px;border-radius:999px;margin-top:6px;}
#gldpj .pj-btn:disabled{opacity:.6;cursor:default;}
#gldpj .pj-note{margin:12px 0 0;font-size:12px;color:#8C8C8C;text-align:center;}
#gldpj .pj-no{display:block;margin:12px auto 0;background:none;border:0;cursor:pointer;font-size:12px;letter-spacing:1px;color:#8C8C8C;text-transform:uppercase;font-weight:600;}
#gldpj .pj-x{position:absolute;top:12px;right:14px;background:none;border:0;cursor:pointer;font-size:22px;line-height:1;color:#8C8C8C;padding:6px;z-index:9;}
#gldpj .pj-err{display:none;margin:10px 0 0;font-size:13px;color:#E4002B;font-weight:600;text-align:center;}
#gldpj .pj-done{display:none;text-align:center;padding:12px 6px;}
#gldpj .pj-done .pj-em{font-size:40px;line-height:1;}
#gldpj .pj-done h3{margin:10px 0 6px;font-family:'Archivo','Arial Black',Arial,sans-serif;font-weight:900;font-size:22px;color:#111;}
#gldpj .pj-done p{margin:0 0 6px;font-size:14px;color:#4A4A4A;line-height:1.6;}
#gldpj-min{position:fixed;left:18px;bottom:18px;z-index:999998;width:52px;height:52px;border-radius:50%;background:#fff;border:2px solid #111111;box-shadow:0 6px 18px rgba(17,17,17,.22);cursor:pointer;padding:0;align-items:center;justify-content:center;transition:transform .15s;}
#gldpj-min:hover{transform:scale(1.08);}
#gldpj-min img{width:30px;height:auto;display:block;margin:0 auto;}
@media (max-width:640px){
#gldpj-min{left:12px;bottom:12px;width:48px;height:48px;}
#gldpj .pj-vis{min-height:196px;}
#gldpj .pj-phone{width:140px;}
#gldpj .pj-vis .pjb1{height:158px;}
#gldpj .pj-vis .pjb2{height:128px;}
#gldpj h2{font-size:24px;}
#gldpj .pj-form{padding:22px 20px 18px;}
}
</style>
<div class="pj-card">
<button type="button" class="pj-x" aria-label="Close">&times;</button>
<div class="pj-vis" aria-hidden="true">
<div class="pj-phone"><img src="https://galado.com.my/gld-files/uploads/2026/07/club-rewards-preview.jpg" alt="" loading="lazy"/></div>
<img class="b pjb2" src="https://club.galado.com.my/avatar-boy-outfit-baju-raya-cut.png" alt="" loading="lazy"/>
<img class="b pjb1" src="https://club.galado.com.my/avatar-girl-outfit-cny-cut.png" alt="" loading="lazy"/>
</div>
<div class="pj-form">
<div class="pj-main">
<div class="pj-eyebrow">GALADO Club &middot; Free to join</div>
<h2 id="gldpj-title">Meet the Club</h2>
<ul class="pj-perks">
<li><span class="pj-pk"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8.3 12.2l2.4 2.4 5-5.2"/></svg></span>Free Lifetime Membership</li>
<li><span class="pj-pk"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2.5 12.5A2 2 0 0 1 2 11.1V4a2 2 0 0 1 2-2h7.1a2 2 0 0 1 1.4.6l7.1 7.1a2 2 0 0 1 0 2.7z"/><circle cx="7" cy="7" r="1.3"/></svg></span>Up to 15% off for members</li>
<li><span class="pj-pk"><img src="https://club.galado.com.my/coin.png" alt=""/></span>Rewards on every order</li>
<li><span class="pj-pk"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12v9H4v-9M2 8h20v4H2zM12 8v13M12 8S10.7 4 8 4a2 2 0 1 0 0 4h4zM12 8s1.3-4 4-4a2 2 0 1 1 0 4h-4z"/></svg></span>Early access &amp; surprises</li>
</ul>
<form class="pj-f" novalidate onsubmit="return false">
<input type="text" name="name" placeholder="Your name" maxlength="60" autocomplete="given-name"/>
<input type="email" name="email" placeholder="Email" maxlength="254" required autocomplete="email"/>
<input type="text" name="website" class="pj-hp" tabindex="-1" autocomplete="off" aria-hidden="true"/>
<button type="submit" class="pj-btn">I&#8217;M IN &#10022;</button>
<p class="pj-err"></p>
</form>
<p class="pj-note">One tap, no password. We&#8217;ll email you a sign-in link.</p>
<button type="button" class="pj-no">No thanks</button>
</div>
<div class="pj-done">
<div class="pj-em" aria-hidden="true">&#9993;&#65039;</div>
<h3>Check your inbox &#10022;</h3>
<p>We sent a one-tap sign-in link to <strong class="pj-mail"></strong>.</p>
<p style="font-size:12.5px;color:#8C8C8C;">It works for 20 minutes. If it plays hide and seek, peek in spam.</p>
<button type="button" class="pj-btn pj-ok" style="max-width:220px;margin:14px auto 0;">Done</button>
</div>
</div>
</div>
</div>
<button id="gldpj-min" type="button" aria-label="Join the GALADO Club" title="GALADO Club" style="display:none;">
<img src="https://club.galado.com.my/coin.png" alt="" loading="lazy"/>
</button>
<script>
var GLDPJ_REST=<?php echo wp_json_encode($rest); ?>;
(function(){
if(window.__gldpjBooted)return;window.__gldpjBooted=1;
var KEY='gldpj_v1';
function boxes(){return [].slice.call(document.querySelectorAll('#gldpj'))}
function chips(){return [].slice.call(document.querySelectorAll('#gldpj-min'))}
if(!boxes().length)return;
var st=null;try{st=JSON.parse(localStorage.getItem(KEY)||'null')}catch(e){}
if(st&&st.joined)return;
function save(o){try{localStorage.setItem(KEY,JSON.stringify(o))}catch(e){}}
function isOpen(){return boxes().some(function(b){return b.style.display==='flex'})}
function showChip(){chips().forEach(function(c){c.style.display='flex'})}
function hideChip(){chips().forEach(function(c){c.style.display='none'})}
function openAll(){boxes().forEach(function(b){b.classList.add('on');b.style.display='flex'});hideChip()}
function closeAll(d){if(!isOpen())return;boxes().forEach(function(b){b.classList.remove('on');b.style.display='none'});showChip();if(d)save({snooze:Date.now()+d*864e5})}
function joined(){hideChip();save({joined:true})}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAll(7)});
document.addEventListener('click',function(e){
var t=e.target;if(!t)return;
if(t.closest&&t.closest('#gldpj-min')){openAll();return}
if(!isOpen())return;
if(t.id==='gldpj'){closeAll(7);return}
if(!t.closest)return;
if(t.closest('#gldpj .pj-x')){closeAll(7);return}
if(t.closest('#gldpj .pj-no')){closeAll(14);return}
if(t.closest('#gldpj .pj-ok')){boxes().forEach(function(b){b.style.display='none'});joined();return}
},true);
document.addEventListener('submit',function(e){
var f=e.target;if(!f||!f.classList||!f.classList.contains('pj-f'))return;
e.preventDefault();
var card=f.closest('.pj-card')||document,err=card.querySelector('.pj-err'),btn=f.querySelector('.pj-btn');
var email=(f.querySelector('input[name=email]').value||'').trim();
var name=(f.querySelector('input[name=name]').value||'').trim();
var hp=(f.querySelector('input[name=website]').value||'');
if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){err.textContent='Please enter a valid email.';err.style.display='block';return}
err.style.display='none';btn.disabled=true;btn.textContent='Sending...';
fetch(GLDPJ_REST,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:name,email:email,website:hp})})
.then(function(r){return r.json().then(function(b){return{ok:r.ok,body:b}})})
.then(function(r){if(r.ok){card.querySelector('.pj-mail').textContent=email;card.querySelector('.pj-main').style.display='none';card.querySelector('.pj-done').style.display='block';joined()}else{err.textContent=(r.body&&r.body.message)||'Something went sideways. Please try again.';err.style.display='block';btn.disabled=false;btn.textContent='JOIN'}})
.catch(function(){err.textContent='Could not reach us. Please try again.';err.style.display='block';btn.disabled=false;btn.textContent='JOIN'});
},true);
if(st&&st.snooze&&Date.now()<st.snooze){showChip()}else{setTimeout(openAll,7000)}
})();
</script>
<?php
    }
}

Galado_Club_Bridge::init();
