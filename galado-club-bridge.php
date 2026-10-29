<?php
/**
 * Plugin Name: GALADO Club Bridge
 * Description: Connects galado.com.my accounts to GALADO Club — adds a "GALADO Club" tab in My Account, signs members into club.galado.com.my (SSO), and mirrors Club tiers to user meta.
 * Version: 0.64.17
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

if ( ! function_exists( 'galado_strip_emoji' ) ) {
	/**
	 * GALADO personalisation rule (owner, 26 Sep 2026): no emoji. Same rule as the iOS app
	 * (galado-ios PrintableText.swift). Returns [clean text, whether anything was removed].
	 * Removes a whole character (grapheme) when it holds a code point above U+FFFF, U+FE0F or
	 * U+20E3, or starts with a BMP Emoji_Presentation code point. Letters in any script, digits,
	 * punctuation and text symbols (bullet, heart, star) stay. Trim the result yourself.
	 */
	function galado_strip_emoji( string $s ): array {
		$bad = '/[\x{10000}-\x{10FFFF}\x{FE0F}\x{20E3}]|^[\x{231A}-\x{231B}\x{23E9}-\x{23EC}\x{23F0}\x{23F3}\x{25FD}-\x{25FE}\x{2614}-\x{2615}\x{2648}-\x{2653}\x{267F}\x{2693}\x{26A1}\x{26AA}-\x{26AB}\x{26BD}-\x{26BE}\x{26C4}-\x{26C5}\x{26CE}\x{26D4}\x{26EA}\x{26F2}-\x{26F3}\x{26F5}\x{26FA}\x{26FD}\x{2705}\x{270A}-\x{270B}\x{2728}\x{274C}\x{274E}\x{2753}-\x{2755}\x{2757}\x{2795}-\x{2797}\x{27B0}\x{27BF}\x{2B1B}-\x{2B1C}\x{2B50}\x{2B55}]/u';
		$out = '';
		$removed = false;
		if ( preg_match_all( '/\X/u', $s, $m ) ) {
			foreach ( $m[0] as $g ) {
				if ( preg_match( $bad, $g ) ) { $removed = true; } else { $out .= $g; }
			}
		}
		return array( $out, $removed );
	}
}

final class Galado_Club_Bridge {

    const ENDPOINT = 'galado-club';
    const VERSION  = '0.64.2';   // 0.64.2: provision-customer enriches existing accounts (blank-only billing phone/name from POS)
    // 0.64.17: perk copy promises only what members get (owner, 8 Oct 2026: "Whichever that now we no longer
    //   give or honour then we need to remove and put a proper correct expectation"). Nothing ever gated drops
    //   by tier, so the cart tier perks swap "early access" for the coin multiplier and the member discount
    //   working online, in the app and in store; both join popups say "Birthday treats & surprises" (birthday
    //   G-Coins, Mystery and Buddy Boxes); the order-email Wallet line drops "first dibs" for shipping updates.
    // 0.64.16: the article sign-ups follow the popup (owner, 7 Oct 2026): the article card and the iPhone 18/Duo
    //   article boxes (source 'article') may ask for instant joins too; body class gld-club-glass and the
    //   preview pass flag switch them with the popup. The footer box stays email-only.
    // 0.64.15: the Club join popup in the site's glass look with instant membership (owner, 7 Oct 2026):
    //   email only, the Club makes the member at once (/api/club/join) and an existing member gets a
    //   sign-in link. Behind ?club_popup=new until the option galado_club_popup is 'glass'; the old popup
    //   and every other join surface are unchanged.
    // 0.59.0: the join popup now forwards the exact notice it showed the visitor, so the Club can
    //   record what someone was actually told. Klaviyo is cancelled in September and the popup is
    //   our biggest signup source; the Club will record these as joins, NOT marketing consent,
    //   because this popup only ever promised a sign-in link. Wording is now a single constant so
    //   the rendered line and the forwarded line cannot drift apart.
    // 0.58.2: crossing a tier silenced the near-miss line, so a basket that reached Gold and stopped
    //   RM27 short of Diamond never mentioned Diamond. The crossing message now carries the next tier
    //   too when it is inside the near-miss window.
    // 0.58.1: the bar never moved. wc_load_cart() inside a REST request returns an EMPTY cart, so
    //   /my-tier answered a confident RM0 while the shopper's basket held RM134. The endpoint no longer
    //   reports the basket at all; the page reads it from the totals row WooCommerce already rendered,
    //   which is the number the shopper is looking at and which Woo re-renders on every quantity change.
    // 0.58.0: TIER METER LIVE TO ALL SIGNED-IN MEMBERS (owner go-ahead 2026-08-05). Guests still get
    //   nothing emitted at all. Filter galado_tier_meter_public back to false to revert to admins only.
    // 0.57.1: tier meter moved BELOW the Use Shopping Credit prompt (hook priority 5 -> 20; Points &
    //   Rewards renders at 15 and 16). Owner's call: credits are an action, the bar is context.
    // 0.57.0: near-miss wording on the tier meter (owner picked 'copy only' from three mocked options).
    //   Within GALADO_TIER_NEAR_MISS RM of the next tier the headline becomes 'So close. RMx more after this
    //   order and <Tier> is yours.' Wording ONLY - no badge, no pulse, nothing that turns encouragement into
    //   pressure. Distance filterable via galado_tier_near_miss_rm (default 50) so it can be tuned live.
    // 0.56.2: owner feedback on the live basket. (a) THE BASKET READ AS RM0 even with items in it:
    //   WooCommerce does not boot the cart on a REST request, so WC()->cart was empty - my_tier now calls
    //   wc_load_cart(), and returns null rather than 0 when it still cannot read one, so the page falls back
    //   to the figure the cart itself rendered instead of telling a member their basket adds nothing.
    //   (b) The 'i' rendered as a serif CAPITAL I in an oval: the theme uppercases button text and sets a
    //   min-width, both now overridden, and the button is smaller and quieter. (c) The foot line was jargon
    //   ('RM2,055.00 so far, plus RM0.00 from this order') and is now a sentence.
    // 0.56.1: tier meter polish after looking at it rendered - threshold labels now sit at their own
    // position on the track so each lines up with its dot (they were evenly spaced and drifting off by
    // a few percent); the perk popover opens DOWNWARD because the meter sits at the top of the basket
    // and there is never room above (it was running off the top of the screen on a phone), capped and
    // scrollable so a long ladder cannot overflow; this order's slice of the bar reads at 0.55 alpha
    // since it is often thin; money over a thousand carries separators.
    // The exact line shown under the join-popup button. ONE definition, used both to render the
    // markup and to forward what the visitor saw to the Club, so the two cannot drift apart.
    //
    // Resolved here and never read back from the browser: anything the client can set an attacker
    // can set, and a forgeable record of what someone was told is worse than no record at all.
    //
    // Note what it does NOT say. No mention of marketing, newsletters or unsubscribing, and that
    // stays true: a Club join is not marketing consent and this line must never imply it is.
    // Consent, when it happens, comes from the separate ticked line below (POPUP_OPTIN_NOTICE),
    // never from this one. If this wording ever gains a marketing line, the Club side has to be
    // revisited in the same change, or we start emailing people on a promise we did not make.
    const POPUP_NOTICE = 'One tap, no password. We’ll email you a sign-in link.';

    // The marketing opt-in shown inside the join popup. UNTICKED by default (owner's call,
    // 2026-08-12): joining is the thing they came for, being emailed is a separate yes they have
    // to actually give. Deliberately a SECOND visible thing rather than a silent bundle: joining
    // the Club and agreeing to be emailed are two different answers, and the visitor gives both.
    // The tick is OPTIONAL and must stay optional. Requiring it to submit would make marketing
    // consent the price of Club membership, which is not consent, it is a toll.
    // This same constant renders the label AND is stored as the consent record, so the evidence
    // can never drift from what was on screen. Wording matches the footer band (snippet #215) so
    // the two capture surfaces cannot disagree.
    const POPUP_OPTIN_NOTICE = 'Also email me new drops, restocks and member offers. You can unsubscribe from any email.';

    // The line under the glass popup's button (instant join, owner's call 2026-10-07). Same rules as
    // POPUP_NOTICE: one definition renders it and is forwarded to the Club as the record of what the
    // join promised, and it says nothing about marketing. True on both paths: a new member gets the
    // welcome email with its one-tap link into their Club, an existing member gets a sign-in link.
    const POPUP_INSTANT_NOTICE = 'No password. We’ll email you a link to your Club.';

    const WELCOME_AMOUNT = 10;   // RM off a referred new customer's first order
    const WELCOME_MIN    = 30;   // min cart subtotal (RM) before the referral discount applies
    const WELCOME30_AMOUNT = 30; // RM off a Club member's first order (signed welcome token)
    const WELCOME30_MIN    = 120;// min cart subtotal (RM) for the Club welcome offer (legacy WEB token)
    // iOS app launch (galado-app-launch/HANDOVER-WEB-DEV.md section 3, 15 Sep 2026): the RM30
    // moves to the app. An `appwelcome` token (Club mint, same HMAC as `welcome`) is accepted
    // ONLY on app checkouts, with this lower minimum. The legacy `welcome` cookie path keeps
    // WELCOME30_MIN until the last web token expires (15 Oct 2026).
    const APP_WELCOME_MIN  = 100;
    // Reactivation win-back: an auto-applied discount for EXISTING customers who unlocked RM off
    // in the Club (RM10 per RM50 of cart). Amount lives in user meta _galado_winback_rm (+ expiry),
    // pushed by the Club; consumed on order payment. Protects margin via the min-cart ratio.
    const WINBACK_MIN  = 50; // RM min cart subtotal per WINBACK_STEP of discount
    const WINBACK_STEP = 10; // RM discount unlocked per WINBACK_MIN of cart
    const WINBACK_HOLD_MIN = 60; // minutes an unpaid PENDING order keeps holding its claim

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
        // Order-pay page: pre-select the gateway the order was CREATED with.
        // The app sends card shoppers here with the order set to stripe_cc, but
        // the page otherwise defaults to the first gateway (molpay), so they'd
        // have to re-pick Credit Card. The store's order-pay page has no
        // script-src CSP, so a tiny inline script checks the right radio.
        add_action('woocommerce_pay_order_before_submit', [__CLASS__, 'preselect_order_pay_gateway_script']);
        // Every order earns Shopping Credits on the FINAL amount paid (product +
        // customisation add-ons, less discounts/redemptions), not WooCommerce's
        // default per-product sum which ignores add-on FEES and under-credits any
        // order with add-ons.
        add_filter('wc_points_rewards_points_earned_for_purchase', [__CLASS__, 'points_earned_on_paid_amount'], 20, 2);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
        // SGD parity (17 Sep 2026): /app-order and its quote run under the currency the app asks
        // for. CURCY only registers its product-price hooks at init 99-100, and only when the
        // request's currency is not the base, so the pin must happen before that.
        add_action('init', [__CLASS__, 'pin_app_order_currency'], 1);
        // Two-way account link: a new galado.com.my registration also creates the Club member.
        add_action('user_register', [__CLASS__, 'on_user_register'], 20, 1);
        add_action('transition_comment_status', [__CLASS__, 'on_comment_transition'], 10, 3);
        add_action('comment_post', [__CLASS__, 'on_comment_post'], 10, 2);
        // Referral: capture ?ref= into a 30-day cookie, then stamp it onto the order at checkout.
        add_action('wp_footer', [__CLASS__, 'ref_cookie_script']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'capture_referral'], 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'capture_referral'], 10, 1);
        // Spend the first-order entitlement at order CREATION (not payment) so it can't be
        // replayed across several unpaid orders (security section 1c).
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'capture_first_order_discount'], 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'capture_first_order_discount'], 10, 1);
        // Club welcome offer: capture ?welcome=<signed token> into a 30-day cookie.
        add_action('wp_footer', [__CLASS__, 'welcome_cookie_script']);
        // iOS app webview: the auto-login link (/app-login) drops a `galado_app`
        // cookie; on My Account pages, strip the storefront chrome + account nav so
        // the warranty list reads like a native app screen, not a full web page.
        add_filter('body_class', [__CLASS__, 'app_view_body_class']);
        add_action('wp_head', [__CLASS__, 'app_view_styles'], 99);
        // Product back in stock -> tell the Club so app wishlisters get a push
        // (the Club dedups per member/product, so re-fires are harmless).
        add_action('woocommerce_product_set_stock_status', [__CLASS__, 'on_stock_status'], 10, 3);
        // First-order discount: the bigger of the Club welcome (RM30) or referral (RM10), never both.
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'first_order_discount']);
        // Reactivation win-back discount (existing customers, min-cart, from unlocked Club RM).
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'winback_discount']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'capture_winback'], 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'capture_winback'], 10, 1);
        // Consume the applied win-back RM once the order is paid (idempotent per order).
        add_action('woocommerce_order_status_processing', [__CLASS__, 'consume_winback'], 20, 1);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'consume_winback'], 20, 1);
        // SPEC-4 G4: Shopping Credits /app-order takes when it creates the pending order go back if
        // that order is cancelled (WooCommerce cancels it once unpaid past the hold-stock window, see
        // cancel_unpaid_app_order), fails, or is fully refunded; a failed order paid after all takes
        // them again. One flag per order, so no transition can move them twice.
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'return_app_credits'], 20, 1);
        add_action('woocommerce_order_status_failed', [__CLASS__, 'return_app_credits'], 20, 1);
        add_action('woocommerce_order_status_refunded', [__CLASS__, 'return_app_credits'], 20, 1);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'retake_app_credits'], 20, 1);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'retake_app_credits'], 20, 1);
        add_action('woocommerce_order_status_on-hold', [__CLASS__, 'retake_app_credits'], 20, 1);
        add_filter('woocommerce_cancel_unpaid_order', [__CLASS__, 'cancel_unpaid_app_order'], 10, 2);
        // 0.64.11: most accounts were created from past guest buyers' emails and carry no phone,
        // so the POS phone search could not find them. A paid order now copies its phone onto
        // the buyer's account when the account has none (blank-only, never overwrites).
        add_action('woocommerce_order_status_processing', [__CLASS__, 'sync_member_phone_from_order'], 30, 1);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'sync_member_phone_from_order'], 30, 1);
        // Cart-page tier meter for signed-in members (ports the iOS cart projection card).
        // Priority 20 puts it BELOW Points & Rewards, which renders its earn message at 15 and
        // the "Use Shopping Credit" prompt at 16. Credits are something the shopper can act on
        // right now; the tier bar is context, so credits lead (owner, 2026-08-05).
        add_action('woocommerce_before_cart', [__CLASS__, 'render_tier_meter'], 20);
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
        // POS counter-added custom items ride the order as positive FEE lines, which
        // Woo renders as small totals-footer rows: the emailed receipt looked like the
        // item was missing (one product, "Subtotal" below the real total, a stray fee
        // row). Promote positive fees into the email's items table and fold them into
        // the Subtotal row. Email-scoped; web orders and my-account views untouched.
        add_filter('woocommerce_email_order_items_table', [__CLASS__, 'pos_email_items_with_customs'], 10, 2);
        add_filter('woocommerce_get_order_item_totals', [__CLASS__, 'pos_email_totals'], 20, 2);
        // The "Hide Checkout Shipping Address" plugin fatals on any programmatic order
        // creation (its woocommerce_new_order handler reads WC_Checkout->shipping_method,
        // which calls WC()->session->get() — null outside a real browser checkout). Detach
        // it just-in-time when there is no session; real checkouts are unaffected.
        add_action('woocommerce_new_order', [__CLASS__, 'pos_guard_hcsa'], 1);
        // Club join popup (replaces the retired Klaviyo subscription popup): guests only,
        // never on cart/checkout/account. Name + email -> Club emails a one-tap sign-in link.
        add_action('wp_footer', [__CLASS__, 'join_popup'], 40);
        // The glass Club sign-ups switch on together: the body class flips the article boxes, the flag
        // hands the owner's preview pass to them (the popup carries its own).
        add_filter('body_class', [__CLASS__, 'glass_body_class']);
        add_action('wp_footer', [__CLASS__, 'glass_preview_flag'], 1);
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
        add_filter('woocommerce_product_get_image_id', [__CLASS__, 'samsung_store_api_image_id'], 20, 2);
        register_activation_hook(__FILE__, 'flush_rewrite_rules');
        register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
    }

    /* ---------------------------------------------------------------------
     * Samsung category thumbnails over the Store API
     *
     * Snippet #15 swaps a product's image on Samsung category PAGES so the
     * shopper sees the render for their own model. It guards on template
     * conditionals (is_product_category), which are false during a REST
     * request, so the Store API — and therefore the iOS app — kept serving
     * the iPhone hero on Galaxy category browses.
     *
     * This mirrors the swap for Store API list requests that are scoped to a
     * single Galaxy category, using ONLY the pinned meta. The snippet's
     * positional gallery fallback (second-to-last = Ultra) is unreliable —
     * it is wrong for most recent products — so an unpinned product keeps its
     * normal image rather than gambling on a wrong render.
     * ------------------------------------------------------------------ */

    /** 'ultra' | 'regular' | '' — resolved once per request from the Store API
     *  category parameter. Null until computed. */
    private static $samsung_ctx = null;

    private static function samsung_context() {
        if (null !== self::$samsung_ctx) {
            return self::$samsung_ctx;
        }
        self::$samsung_ctx = '';
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return self::$samsung_ctx;   // classic pages keep using snippet #15
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (false === strpos($uri, '/wc/store/')) {
            return self::$samsung_ctx;
        }
        $query = [];
        $parts = wp_parse_url($uri);
        if (empty($parts['query'])) {
            return self::$samsung_ctx;
        }
        parse_str($parts['query'], $query);
        $raw = isset($query['category']) ? $query['category'] : '';
        if ('' === $raw || is_array($raw)) {
            return self::$samsung_ctx;   // unscoped list: leave images alone
        }
        // The app's Samsung tile asks for every galaxy-* id at once, and the
        // website's own parent category page answers that with the non-Ultra
        // render, so a mixed Samsung browse resolves to 'regular'. Only an
        // all-Ultra scope gets the Ultra render.
        $refs = array_filter(array_map('trim', explode(',', (string) $raw)));
        if (empty($refs)) {
            return self::$samsung_ctx;
        }
        $all_ultra = true;
        foreach ($refs as $ref) {
            $term = is_numeric($ref)
                ? get_term((int) $ref, 'product_cat')
                : get_term_by('slug', $ref, 'product_cat');
            if (!$term || is_wp_error($term)) {
                return self::$samsung_ctx;
            }
            $slug = strtolower($term->slug);
            // Anything outside the Samsung tree means no single render fits.
            if ('samsung' !== $slug && 0 !== strpos($slug, 'galaxy-')) {
                return self::$samsung_ctx;
            }
            if (false === strpos($slug, 'ultra')) {
                $all_ultra = false;
            }
        }
        self::$samsung_ctx = $all_ultra ? 'ultra' : 'regular';
        return self::$samsung_ctx;
    }

    /** Swap in the pinned Galaxy render while listing a single Galaxy category. */
    public static function samsung_store_api_image_id($image_id, $product) {
        $mode = self::samsung_context();
        if ('' === $mode || !($product instanceof WC_Product)) {
            return $image_id;
        }
        $id  = $product->get_parent_id() ?: $product->get_id();
        $key = ('ultra' === $mode) ? '_galado_samsung_ultra_id' : '_galado_samsung_regular_id';
        $pinned = (int) get_post_meta($id, $key, true);
        return $pinned > 0 ? $pinned : $image_id;
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

    /** POS receipt email: render counter-added custom items (positive fee lines) as
     *  rows in the items table. Fee names already carry " × N" when qty > 1; parse it
     *  into the quantity column. Handles both the HTML and plain-text item tables. */
    public static function pos_email_items_with_customs($items_html, $order) {
        if (!self::is_pos_order($order)) {
            return $items_html;
        }
        $plain = false === strpos($items_html, '<');
        $rows = '';
        $style = "text-align:left; vertical-align:middle; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;";
        foreach ($order->get_fees() as $fee) {
            $total = (float) $fee->get_total();
            if ($total <= 0) {
                continue; // negative fees = discounts/credits, they stay in the totals footer
            }
            $name = $fee->get_name();
            $qty  = 1;
            if (preg_match('/^(.*)\s+\x{00D7}\s+(\d+)$/u', $name, $m)) {
                $name = $m[1];
                $qty  = (int) $m[2];
            }
            if ($plain) {
                $rows .= $name . ' X ' . $qty . ' = ' . wp_strip_all_tags(wc_price($total, ['currency' => $order->get_currency()])) . "\n";
            } else {
                $rows .= '<tr>'
                    . '<td class="td" style="' . $style . '">' . esc_html($name) . '</td>'
                    . '<td class="td" style="' . $style . '">' . (int) $qty . '</td>'
                    . '<td class="td" style="' . $style . '">' . wc_price($total, ['currency' => $order->get_currency()]) . '</td>'
                    . '</tr>';
            }
        }
        return $items_html . $rows;
    }

    /** Companion to the promotion above, active only while an email is rendering
     *  (the email templates fire woocommerce_email_header; storefront pages never
     *  do): drop the promoted fees' footer rows and fold their value into the
     *  Subtotal row so the numbers read like the POS receipt. */
    public static function pos_email_totals($total_rows, $order = null) {
        if (!self::is_pos_order($order) || !is_array($total_rows) || !did_action('woocommerce_email_header')) {
            return $total_rows;
        }
        $promoted = 0.0;
        foreach ($order->get_fees() as $item_id => $fee) {
            $total = (float) $fee->get_total();
            if ($total > 0) {
                $promoted += $total;
                unset($total_rows['fee_' . $item_id]);
            }
        }
        if ($promoted > 0 && isset($total_rows['cart_subtotal'])) {
            $total_rows['cart_subtotal']['value'] = wc_price($order->get_subtotal() + $promoted, ['currency' => $order->get_currency()]);
        }
        return $total_rows;
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
        // Only stamp a code the Club recognises — the order webhook credits the referrer off
        // this meta, so an unvalidated code must never ride through.
        if ('' !== $code && self::referral_code_valid($code)) {
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
<script>(function(){try{var w=new URLSearchParams(location.search).get('welcome');if(!w||!/^welcome\.[0-9]+\.(?:[A-Za-z0-9_-]+\.)?[0-9]+\.[A-Za-z0-9_-]+$/.test(w))return;var e=new Date(Date.now()+2592e6).toUTCString();document.cookie='galado_welcome='+w+'; expires='+e+'; path=/; SameSite=Lax';}catch(e){}})();</script>
<?php
    }

    /**
     * Verify a Club-minted welcome token. NEW 5-part tokens
     * (welcome.<memberId>.<emailBind>.<exp>.<sig>) are EMAIL-BOUND: the bind must match the
     * checkout email, so a forwarded link no longer grants RM30 to someone else — no login
     * required, the match is against whatever email the shopper checks out with. LEGACY 4-part
     * tokens (welcome.<memberId>.<exp>.<sig>) stay valid until they expire (<=30 days) so no
     * already-issued offer is stranded during the migration; GALADO_WELCOME_STRICT (default
     * off) rejects them outright once every offer in circulation has been re-minted bound.
     * (b64url() is the pre-existing helper below — do not add another; a duplicate declaration
     * is a parse fatal.)
     */
    private static function verify_welcome_token($token, $email = '', $prefixes = ['welcome']) {
        $secret = self::sso_secret();
        if ('' === $secret || !$token) {
            return false;
        }
        $parts = explode('.', (string) $token);
        $n = count($parts);
        if ((4 !== $n && 5 !== $n) || !in_array($parts[0], $prefixes, true)) {
            return false;
        }
        $sig     = $parts[$n - 1];
        $payload = implode('.', array_slice($parts, 0, $n - 1));
        $calc    = self::b64url(hash_hmac('sha256', $payload, $secret, true));
        if (!hash_equals($calc, $sig)) {
            return false;
        }
        if ((int) $parts[$n - 2] < time()) {
            return false; // expired
        }
        if (5 === $n) {
            $email = strtolower(trim((string) $email));
            if ('' === $email) {
                return false; // bound token, but we don't know the shopper's email yet
            }
            $bind = self::b64url(hash_hmac('sha256', 'welcomebind:' . $email, $secret, true));
            return hash_equals($parts[2], $bind);
        }
        return !(defined('GALADO_WELCOME_STRICT') && GALADO_WELCOME_STRICT);
    }

    /** Currencies the app may transact in (SPEC-SGD-PARITY.md section 4). */
    const APP_CURRENCIES = ['MYR', 'SGD'];

    /** The request currency for /app-order and /app-order/quote, read from the JSON body before
     *  CURCY initialises. Missing = MYR (builds before 1.0.4 send none). An invalid code pins
     *  nothing here; the route itself answers 400. */
    public static function pin_app_order_currency() {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (false === strpos($uri, 'galado-club/v1/app-order')) {
            return;
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        $cur  = is_array($body) ? strtoupper(trim((string) ($body['currency'] ?? 'MYR'))) : 'MYR';
        if (in_array($cur, self::APP_CURRENCIES, true)) {
            $_COOKIE['wmc_current_currency'] = $cur;
        }
    }

    /** The currency CURCY is pricing this request in (the store base when CURCY is off). */
    private static function current_currency_code() {
        if (class_exists('WOOMULTI_CURRENCY_Data')) {
            return (string) WOOMULTI_CURRENCY_Data::get_ins()->get_current_currency();
        }
        return (string) get_option('woocommerce_currency', 'MYR');
    }

    /** An RM amount in the request's currency at CURCY's rate, WITHOUT beauty rounding: the same
     *  straight conversion CURCY applies to shipping (RM30 -> S$12.00). Used for every RM figure
     *  the bridge owns: welcome amount and minimum, add-ons, Shopping Credits, shipping rules. */
    private static function to_request_currency($rm) {
        $rm = (float) $rm;
        if (!function_exists('wmc_get_price') || self::current_currency_code() === (string) get_option('woocommerce_currency', 'MYR')) {
            return $rm;
        }
        return round((float) wmc_get_price($rm, false, true), wc_get_price_decimals());
    }

    /** CURCY's straight rate for this request's currency, unrounded (units per RM1): 1.0 in RM, null
     *  when CURCY cannot give one. */
    private static function current_rate() {
        if (!function_exists('wmc_get_price') || self::current_currency_code() === (string) get_option('woocommerce_currency', 'MYR')) {
            return 1.0;
        }
        $rate = (float) wmc_get_price(1, false, true);
        return $rate > 0 ? $rate : null;
    }

    /** The rate CURCY recorded on this order when it was placed (`wmc_order_info`), in order-currency
     *  units per RM1: the only rate an order's RM value is ever read at (SPEC-4 G1), never today's.
     *  CURCY prices with its fee inside `rate`; a snapshot that keeps the fee apart (`rate_fee`,
     *  `rate_fee_type` fixed | percentage) has it folded in here, or the fee itself would earn. 1.0 for
     *  an order in the store currency; null for a foreign order with no usable snapshot. */
    private static function order_rate($order) {
        $code = strtoupper((string) $order->get_currency());
        if ('' === $code || strtoupper((string) get_option('woocommerce_currency', 'MYR')) === $code) {
            return 1.0;
        }
        $info = $order->get_meta('wmc_order_info', true);
        if (is_string($info)) {
            $info = json_decode($info, true);
        }
        $c    = (is_array($info) && isset($info[$code]) && is_array($info[$code])) ? $info[$code] : [];
        $rate = (float) ($c['rate'] ?? 0);
        if ($rate <= 0) {
            return null;
        }
        $fee = (float) ($c['rate_fee'] ?? $c['fee'] ?? 0);
        if ($fee <= 0) {
            return $rate;
        }
        return false !== stripos((string) ($c['rate_fee_type'] ?? $c['fee_type'] ?? ''), 'percent') ? $rate * (1 + $fee / 100) : $rate + $fee;
    }

    /** An amount in the order's own currency, in RM at the order's recorded rate; null without one,
     *  never the raw foreign figure passed off as RM. */
    private static function order_amount_in_base($order, $amount) {
        $rate = self::order_rate($order);
        if (null === $rate) {
            return null;
        }
        return 1.0 === $rate ? (float) $amount : round((float) $amount / $rate, 2);
    }

    /** "RM30" / "S$12" for fee labels; the "GALADO Club welcome" PREFIX never changes (the Club's
     *  webhook and the app both match on it), only the visible amount follows the currency. */
    private static function money_label($amount) {
        $code = self::current_currency_code();
        $sym  = ['MYR' => 'RM', 'SGD' => 'S$', 'USD' => '$'][$code] ?? ($code . ' ');
        $amount = round((float) $amount, 2);
        return $sym . ((floor($amount) == $amount) ? (string) (int) $amount : number_format($amount, 2, '.', ''));
    }

    /** Owner, 26 Sep 2026: no emoji in personalisation. The order-item tables are MyISAM
     *  utf8mb3, so WordPress refuses any meta row holding a 4-byte character and returns
     *  nothing: the name vanishes from the order while the rest of the line saves (orders
     *  426534, 426260, 425903). iOS 1.0.8 strips emoji as the customer types; this guard is
     *  what protects every build from 1.0.2 to 1.0.7 that still sends them. The Club forwards
     *  a 400 message and the app shows it at Place Order. */
    const EMOJI_MESSAGE = "Emoji can't be printed on your case. Please remove this item from your cart and add it again with the name typed without emoji.";

    /** WP_Error when any personalisation value carries an emoji, else null. Uses the shared
     *  helper (galado-app-launch/assets/no-emoji), the same rule and test cases as the app. */
    private static function custom_emoji_error(array $lines) {
        foreach ($lines as $l) {
            if (!is_array($l) || !is_array($l['custom'] ?? null)) {
                continue;
            }
            foreach ($l['custom'] as $c) {
                $v = is_array($c) ? (string) ($c['value'] ?? '') : '';
                if ($v === '') {
                    continue;
                }
                list(, $removed) = galado_strip_emoji($v);
                if ($removed) {
                    return new WP_Error('emoji_not_printable', self::EMOJI_MESSAGE, ['status' => 400]);
                }
            }
        }
        return null;
    }

    /** The label of the first personalisation the caller sent that is NOT on the saved line,
     *  else null. Read straight from the table: a value the column cannot hold is dropped by
     *  WordPress without an error, and the in-memory item still believes it was written.
     *  A value of only spaces counts as lost too, because the sanitiser empties it and the
     *  write loop then skips it. Underscore labels are excluded: those are refused on purpose
     *  (server-minted fulfilment keys), which is a refusal, not a loss. */
    private static function missing_custom_label($item_id, array $custom) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id = %d",
                (int) $item_id
            ),
            ARRAY_A
        );
        $saved = [];
        foreach ((array) $rows as $r) {
            $saved[(string) $r['meta_key']][] = (string) $r['meta_value'];
        }
        foreach ($custom as $c) {
            if (!is_array($c)) {
                continue;
            }
            $k = sanitize_text_field((string) ($c['label'] ?? ''));
            if ($k === '' || strpos($k, '_') === 0 || (string) ($c['value'] ?? '') === '') {
                continue;
            }
            $k = mb_substr($k, 0, 80);
            $v = mb_substr(sanitize_textarea_field((string) $c['value']), 0, 500);
            if ($v === '' || !isset($saved[$k]) || !in_array($v, $saved[$k], true)) {
                return $k;
            }
        }
        return null;
    }

    /**
     * Price an /app-order body without creating an order. Mirrors the route step by step: PWP lines
     * at the bundles-quote unit, other lines at the product price CURCY gives this request,
     * add-ons, the app welcome (items subtotal vs the converted minimum), the coupon (WooCommerce's
     * own WC_Discounts on an in-memory order that is never saved), Shopping Credits, and shipping
     * by the zone rules. Returns amounts in the request currency.
     */
    private static function app_order_quote(array $p) {
        $lines   = is_array($p['lines'] ?? null) ? $p['lines'] : [];
        $billing = is_array($p['billing'] ?? null) ? $p['billing'] : [];
        $email   = sanitize_email((string) ($billing['email'] ?? ''));
        if (!count($lines) || !$email) {
            return new WP_Error('bad_request', 'lines and billing.email required', ['status' => 400]);
        }
        $cur = strtoupper(trim((string) ($p['currency'] ?? 'MYR')));
        if (!in_array($cur, self::APP_CURRENCIES, true) || self::current_currency_code() !== $cur) {
            return new WP_Error('bad_currency', 'currency must be MYR or SGD', ['status' => 400]);
        }
        $emoji = self::custom_emoji_error($lines);
        if ($emoji) {
            return $emoji;
        }
        $dp = wc_get_price_decimals();

        $pwp_map = [];
        $pwp_quotes = is_array($p['pwp_quotes'] ?? null) ? $p['pwp_quotes'] : [];
        if ($pwp_quotes && class_exists('GALADO_Bundles_App')) {
            $claimed = [];
            foreach ($pwp_quotes as $q) {
                if (!is_array($q)) continue;
                $q['claimed'] = array_values(array_unique(array_merge(array_map('strval', (array) ($q['claimed'] ?? [])), $claimed)));
                $quote = GALADO_Bundles_App::quote($q);
                if (empty($quote['ok'])) {
                    return new WP_Error('bad_request', 'bundle_quote: ' . (string) ($quote['message'] ?? 'invalid'), ['status' => 400]);
                }
                foreach ((array) $quote['lines'] as $ql) {
                    $pwp_map[(int) $ql['product_id'] . '|' . (int) $ql['variation_id']][] = ['unit' => (float) $ql['unit'], 'qty' => (int) $ql['qty']];
                    if (!empty($ql['pwp']) && !empty($ql['circle'])) {
                        $claimed[] = (string) $ql['circle'];
                    }
                }
            }
        }

        $scratch = new WC_Order(); // in memory only, for WC_Discounts; never saved
        $scratch->set_billing_email($email);
        $out_lines = [];
        $items = 0.0;
        $qty_total = 0;
        foreach ($lines as $l) {
            $pid = absint($l['product_id'] ?? 0);
            $vid = absint($l['variation_id'] ?? 0);
            $qty = max(1, absint($l['quantity'] ?? 1));
            $product = wc_get_product($vid ?: $pid);
            if (!$product || !$product->is_purchasable()) {
                return new WP_Error('bad_request', 'unknown or unpurchasable product ' . ($vid ?: $pid), ['status' => 400]);
            }
            if (!$vid && $product->is_type('variable')) {
                return new WP_Error('bad_request', 'variation_id required for variable product ' . $pid, ['status' => 400]);
            }
            $key = $pid . '|' . $vid;
            if (!empty($pwp_map[$key]) && (int) $pwp_map[$key][0]['qty'] === $qty) {
                $unit = (float) array_shift($pwp_map[$key])['unit'];
            } else {
                $unit = (float) $product->get_price();
            }
            $line = round($unit * $qty, $dp);
            $items += $line;
            $qty_total += $qty;
            $item = new WC_Order_Item_Product();
            // WC_Discounts keys an order's lines by item id, and an unsaved item's id is 0, so on this
            // never-saved order each line replaced the one before it and a coupon priced the LAST line
            // only (DIAM10D on RM219: RM2.33 instead of RM21.90, 7 Oct 2026). A temporary id per line,
            // far above any real order item, keeps them apart; the scratch order is never saved.
            $item->set_id(PHP_INT_MAX - count($out_lines));
            $item->set_product($product);
            $item->set_quantity($qty);
            $item->set_subtotal((string) $line);
            $item->set_total((string) $line);
            $scratch->add_item($item);
            $out_lines[] = ['product_id' => $pid, 'variation_id' => $vid, 'quantity' => $qty, 'unit' => round($unit, $dp), 'total' => $line];
        }

        $fees = [];
        $addon_rm = (float) ($p['addon_total'] ?? 0);
        $addon = ($addon_rm > 0 && $addon_rm < 10000) ? self::to_request_currency($addon_rm) : 0.0;
        if ($addon > 0) {
            $fees[] = ['name' => 'Customisation add-ons', 'total' => $addon];
        }
        $welcome_token = (string) ($p['welcome_token'] ?? '');
        if ('' !== $welcome_token
            && preg_match('/^appwelcome\.[A-Za-z0-9._-]{10,400}$/', $welcome_token)
            && self::verify_welcome_token($welcome_token, $email, ['appwelcome'])
            && !self::has_app_order($email)
            && $items >= self::to_request_currency(self::APP_WELCOME_MIN)) {
            $w = self::to_request_currency(self::WELCOME30_AMOUNT);
            $fees[] = ['name' => 'GALADO Club welcome: ' . self::money_label($w) . ' off your first order', 'total' => -$w];
        }

        $discount = 0.0;
        $free_coupon = false;
        $coupon_code = strtoupper(sanitize_text_field((string) ($p['coupon'] ?? '')));
        if ('' !== $coupon_code) {
            $coupon = new WC_Coupon($coupon_code);
            $disc = new WC_Discounts($scratch);
            $ok = $disc->apply_coupon($coupon);
            if (is_wp_error($ok)) {
                return new WP_Error('bad_coupon', $ok->get_error_message(), ['status' => 400]);
            }
            $discount = round((float) array_sum($disc->get_discounts_by_item()), $dp);
            $free_coupon = (bool) $coupon->get_free_shipping();
        }

        $credits_points = absint($p['credits_points'] ?? 0);
        if ($credits_points > 0) {
            $user = get_user_by('email', $email);
            if (!class_exists('WC_Points_Rewards_Manager') || !$user) {
                return new WP_Error('bad_request', 'credits unavailable for this account', ['status' => 400]);
            }
            if ($credits_points > (int) WC_Points_Rewards_Manager::get_users_points($user->ID)) {
                return new WP_Error('insufficient_points', 'not enough Shopping Credits', ['status' => 409]);
            }
            $fees[] = ['name' => 'Shopping Credits redeemed', 'total' => -self::to_request_currency(round($credits_points / 10, 2))];
        }

        $rate = self::app_order_shipping_rate(
            ['country' => sanitize_text_field((string) ($billing['country'] ?? 'MY')), 'state' => sanitize_text_field((string) ($billing['state'] ?? '')),
             'postcode' => sanitize_text_field((string) ($billing['postcode'] ?? '')), 'city' => sanitize_text_field((string) ($billing['city'] ?? '')),
             'address' => '', 'address_2' => ''],
            $items + $addon, $discount, $qty_total, $free_coupon
        );
        $shipping = $rate ? (float) $rate['cost'] : 0.0;
        $fees_total = array_sum(array_column($fees, 'total'));
        $total = max(0.0, round($items - $discount + $fees_total + $shipping, $dp));
        return [
            'currency'       => $cur,
            'lines'          => $out_lines,
            'items_subtotal' => round($items, $dp),
            'discount_total' => $discount,
            'fees'           => $fees,
            'shipping'       => $rate ? ['title' => $rate['title'], 'method_id' => $rate['method_id'], 'total' => $shipping] : null,
            'total'          => $total,
        ];
    }

    /**
     * Shipping for an order built outside the cart (the app's /app-order), by the store's OWN zone
     * rules (SPEC-SGD-PARITY.md B4, 17 Sep 2026). WooCommerce's free_shipping method reads
     * WC()->cart, which does not exist on this REST route, so the rules are evaluated here from each
     * enabled method's saved settings, the same way the checkout evaluates them:
     *   free_shipping  requires '' | min_amount | coupon | either | both; min compared against the
     *                  items subtotal less coupon discounts unless ignore_discounts is 'yes';
     *   flat_rate      its cost setting ([qty] and [cost] placeholders supported; anything else
     *                  unsupported is skipped and logged rather than guessed).
     * The cheapest available rate wins, which is what the checkout lands on for these zones.
     * Returns ['method_id','instance_id','title','cost'] or null when the zone offers nothing.
     */
    private static function app_order_shipping_rate($destination, $items_subtotal, $discount_total, $qty, $free_shipping_coupon) {
        if (!class_exists('WC_Shipping_Zones')) {
            return null;
        }
        $zone = WC_Shipping_Zones::get_zone_matching_package(['destination' => $destination]);
        if (!$zone) {
            return null;
        }
        $decimals = wc_get_price_decimals();
        $best = null;
        foreach ($zone->get_shipping_methods(true) as $method) {
            $cost = null;
            if ('free_shipping' === $method->id) {
                $requires = (string) $method->get_option('requires', '');
                // RAW setting, converted once here. get_option() is already converted by CURCY's
                // woocommerce_shipping_free_shipping_instance_option filter under a non-base
                // currency, so converting that value again made RM150 read as S$24 (17 Sep 2026).
                $min_raw  = isset($method->instance_settings['min_amount']) ? $method->instance_settings['min_amount'] : $method->get_option('min_amount', 0);
                $min      = self::to_request_currency((float) $min_raw);
                $basis    = ('yes' === $method->get_option('ignore_discounts', 'no')) ? $items_subtotal : ($items_subtotal - $discount_total);
                $has_min  = round($basis, $decimals) >= $min;
                $ok = ('' === $requires) ? true
                    : (('min_amount' === $requires) ? $has_min
                    : (('coupon' === $requires) ? $free_shipping_coupon
                    : (('either' === $requires) ? ($has_min || $free_shipping_coupon)
                    : (('both' === $requires) ? ($has_min && $free_shipping_coupon) : false))));
                if ($ok) {
                    $cost = 0.0;
                }
            } elseif ('flat_rate' === $method->id) {
                $raw  = trim((string) $method->get_option('cost', ''));
                $expr = str_replace(['[qty]', '[cost]'], [(string) (int) $qty, (string) (float) $items_subtotal], $raw);
                if ('' === $expr) {
                    $cost = 0.0;
                } elseif (preg_match('/^[0-9.\s+*\/()-]+$/', $expr) && is_numeric(trim($expr))) {
                    $cost = self::to_request_currency((float) $expr);
                } elseif (preg_match('/^[0-9.\s+*\/()-]+$/', $expr)) {
                    // simple arithmetic only, e.g. "10 + 2 * [qty]" after substitution
                    if (!class_exists('WC_Eval_Math')) {
                        include_once WC()->plugin_path() . '/includes/libraries/class-wc-eval-math.php';
                    }
                    $cost = self::to_request_currency((float) WC_Eval_Math::evaluate($expr));
                } else {
                    error_log('[galado-club-bridge] app-order shipping: unsupported flat_rate cost on instance ' . $method->get_instance_id());
                    continue;
                }
            } else {
                continue; // local pickup and anything else: not deliverable from the app
            }
            if (null !== $cost && (null === $best || $cost < $best['cost'])) {
                $best = [
                    'method_id'   => $method->id,
                    'instance_id' => (int) $method->get_instance_id(),
                    'title'       => (string) $method->get_title(),
                    'cost'        => round(max(0.0, $cost), $decimals),
                ];
            }
        }
        return $best;
    }

    /** Is the current request an APP checkout: a Store API request (the app is the only Store API
     *  client) that also identifies itself, by the `X-Galado-App` header or a `GaladoApp` user
     *  agent? Everything else, including the normal web cart and checkout, is not. */
    private static function is_app_checkout() {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $rest = (defined('REST_REQUEST') && REST_REQUEST) || false !== strpos($uri, 'rest_route=');
        if (!$rest || false === strpos($uri, 'wc/store')) {
            return false;
        }
        if (!empty($_SERVER['HTTP_X_GALADO_APP'])) {
            return true;
        }
        return false !== stripos((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'GaladoApp');
    }

    /** The app's welcome token, sent as a header on every Store API call (never a cookie). */
    private static function app_welcome_header_token() {
        $t = (string) ($_SERVER['HTTP_X_GALADO_WELCOME'] ?? '');
        return preg_match('/^appwelcome\.[A-Za-z0-9._-]{10,400}$/', $t) ? $t : '';
    }

    /** The qualify rule for the APP offer (settled 15 Sep 2026): every member's FIRST app order,
     *  existing web customers included. So the only disqualifier is a prior app order for this
     *  shopper: a Store API checkout (created_via store-api) or a bridge /app-order (meta), in a
     *  paid or completed state. The Club applies the same predicate from its own records. */
    private static function has_app_order($email) {
        if (!function_exists('wc_get_orders')) {
            return false;
        }
        $args = ['limit' => 20, 'return' => 'ids', 'status' => ['wc-completed', 'wc-processing']];
        if (is_user_logged_in()) {
            $args['customer_id'] = get_current_user_id();
        } else {
            $email = strtolower(trim((string) $email));
            if ('' === $email) {
                return false;
            }
            $args['billing_email'] = $email;
        }
        foreach (wc_get_orders($args) as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            if ('store-api' === $order->get_created_via() || '1' === (string) $order->get_meta('_galado_app_order', true)) {
                return true;
            }
        }
        return false;
    }

    /** The email this checkout resolves to: the account email when logged in, otherwise the
     * billing email once entered (same source is_existing_customer() reads for guests). */
    private static function current_checkout_email() {
        if (is_user_logged_in()) {
            $u = wp_get_current_user();
            return $u ? strtolower((string) $u->user_email) : '';
        }
        return (function_exists('WC') && WC()->customer) ? strtolower((string) WC()->customer->get_billing_email()) : '';
    }

    /** Is this a real, active referral code? Asks the Club (bridge-gated, read-only) and caches
     * the verdict briefly so cart recalculation does not hammer it. Fails CLOSED — an unknown
     * code, an unreachable Club, or a missing secret grants no RM10 (never a leak). */
    private static function referral_code_valid($raw) {
        $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $raw), 0, 12));
        if ('' === $code) {
            return false;
        }
        $key    = 'galado_ref_valid_' . $code;
        $cached = get_transient($key);
        if (false !== $cached) {
            return '1' === $cached;
        }
        $secret = self::bridge_secret();
        if ('' === $secret) {
            return false;
        }
        $res = wp_remote_get(self::club_url() . '/api/referral/valid/' . rawurlencode($code), [
            'timeout' => 4,
            'headers' => ['x-club-bridge-secret' => $secret],
        ]);
        if (is_wp_error($res) || 200 !== (int) wp_remote_retrieve_response_code($res)) {
            return false;
        }
        $data  = json_decode(wp_remote_retrieve_body($res), true);
        $valid = is_array($data) && !empty($data['valid']);
        set_transient($key, $valid ? '1' : '0', ($valid ? 15 : 5) * MINUTE_IN_SECONDS);
        return $valid;
    }

    /** Has this shopper already CREATED a first-order discount (welcome or referral) on any
     * order, in any status? Closes the "many pending discounted orders" replay: the intro
     * offer is spent at order creation, not payment, and does not return. */
    private static function has_used_intro() {
        if (!function_exists('wc_get_orders')) {
            return false;
        }
        // No meta_query: WooCommerce's order data store rejects it (doing_it_wrong) and silently
        // runs the query WITHOUT the condition, so every shopper with any prior order in any
        // status (pending, failed, cancelled) was treated as having used the intro discount
        // (found 6 Sep 2026). Fetch the shopper's recent orders and check the meta per order,
        // which behaves the same on post storage and on HPOS.
        $args = [
            'limit'  => 20,
            'return' => 'ids',
            'status' => array_keys(wc_get_order_statuses()),
        ];
        if (is_user_logged_in()) {
            $args['customer_id'] = get_current_user_id();
        } else {
            $email = self::current_checkout_email();
            if ('' === $email) {
                return false;
            }
            $args['billing_email'] = $email;
        }
        foreach (wc_get_orders($args) as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && '' !== (string) $order->get_meta('_galado_intro_discount', true)) {
                return true;
            }
        }
        return false;
    }

    /** Stamp the intro discount actually applied onto the order at CREATION, so has_used_intro()
     * sees it immediately (before payment) and a second discounted order cannot be created. */
    public static function capture_first_order_discount($order) {
        if (!$order || !function_exists('WC') || !WC()->cart) {
            return;
        }
        $amount = 0.0;
        $type   = '';
        foreach (WC()->cart->get_fees() as $fee) {
            if (0 === strpos($fee->name, 'GALADO Club welcome')) {
                $amount = abs((float) $fee->amount);
                $type   = 'welcome';
            } elseif (0 === strpos($fee->name, 'Referral welcome')) {
                $amount = abs((float) $fee->amount);
                $type   = 'referral';
            }
        }
        if ($amount > 0) {
            $order->update_meta_data('_galado_intro_discount', $amount);
            $order->update_meta_data('_galado_intro_type', $type);
        }
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
        $email    = self::current_checkout_email();
        $subtotal = (float) $cart->get_subtotal();
        // APP path (15 Sep 2026): an `appwelcome` header token on an app Store API checkout,
        // email-bound, for a shopper with no prior app order, RM100 minimum. Evaluated BEFORE the
        // existing-customer gate on purpose: existing web customers qualify for their first app
        // order. Anywhere else an appwelcome token is ignored, so it can never leak to the web.
        $app_token = self::app_welcome_header_token();
        if ('' !== $app_token && self::is_app_checkout()) {
            // B1 (17 Sep 2026): amount and minimum in the cart's currency. Under SGD the RM100/RM30
            // rule was read as S$100/S$30, 2.5x the intended discount.
            $amount = self::to_request_currency(self::WELCOME30_AMOUNT);
            if (self::verify_welcome_token($app_token, $email, ['appwelcome'])
                && !self::has_app_order($email)
                && $subtotal >= self::to_request_currency(self::APP_WELCOME_MIN)) {
                $cart->add_fee('GALADO Club welcome: ' . self::money_label($amount) . ' off your first order', -1 * $amount, false);
            }
            return;
        }
        if (self::is_existing_customer() || self::has_used_intro()) {
            return;
        }
        if (!empty($_COOKIE['galado_welcome'])
            && self::verify_welcome_token(wp_unslash($_COOKIE['galado_welcome']), $email)
            && $subtotal >= self::to_request_currency(self::WELCOME30_MIN)) {
            $amount = self::to_request_currency(self::WELCOME30_AMOUNT);
            $cart->add_fee('GALADO Club welcome: ' . self::money_label($amount) . ' off your first order', -1 * $amount, false);
            return;
        }
        if (!empty($_COOKIE['galado_ref'])
            && self::referral_code_valid(wp_unslash($_COOKIE['galado_ref']))
            && $subtotal >= self::to_request_currency(self::WELCOME_MIN)) {
            $amount = self::to_request_currency(self::WELCOME_AMOUNT);
            $cart->add_fee('Referral welcome: ' . self::money_label($amount) . ' off your first order', -1 * $amount, false);
        }
    }

    /** RM discount winback_discount() gave this request's cart. The fee is in the cart's currency; the
     *  balance it spends is RM, so this is what capture_winback() stamps on the order. */
    private static $winback_rm = 0.0;

    /**
     * Reactivation win-back discount. Auto-applies the member's unlocked Club RM (from user meta,
     * pushed by the Club) as a negative fee, capped at RM10 per RM50 of cart so margin is protected.
     * For EXISTING customers (the whole point is bringing them back), so no new-customer gate.
     */
    public static function winback_discount($cart) {
        self::$winback_rm = 0.0;
        if ((is_admin() && !defined('DOING_AJAX')) || !function_exists('WC') || !is_user_logged_in()) {
            return;
        }
        $uid   = get_current_user_id();
        $avail = (float) get_user_meta($uid, '_galado_winback_rm', true);
        if ($avail <= 0) {
            return;
        }
        $expires = (int) get_user_meta($uid, '_galado_winback_expires', true);
        if ($expires && time() > $expires) {
            return;
        }
        // The balance is only debited on payment, so RM already claimed by a live unpaid order
        // has to come off the top or the same RM can be spent again on a second order.
        $avail = max(0.0, $avail - self::winback_reserved($uid));
        if ($avail <= 0) {
            return;
        }
        $subtotal = (float) $cart->get_subtotal();
        // SPEC-4 bug 3: the balance and its RM10-per-RM50 rule are RM; the cart may not be. Steps are
        // counted against the RM50 converted into the cart's currency, and the RM discount is charged
        // converted the same way (the straight CURCY rate the welcome fee uses). RM carts unchanged.
        $step_min = self::to_request_currency(self::WINBACK_MIN);
        if ($step_min <= 0) {
            return;
        }
        $by_cart  = floor($subtotal / $step_min) * self::WINBACK_STEP;
        $discount = min($avail, $by_cart);
        if ($discount <= 0) {
            return;
        }
        $amount = self::to_request_currency($discount);
        self::$winback_rm = $discount;
        // capture_winback() matches the "GALADO Club reward" prefix; only the amount follows the currency.
        $cart->add_fee('GALADO Club reward: ' . self::money_label($amount) . ' off', -1 * $amount, false);
    }

    /** Stamp the win-back discount actually applied onto the order, so payment can consume it once. */
    public static function capture_winback($order) {
        if (!$order || !function_exists('WC') || !WC()->cart) {
            return;
        }
        $applied = 0.0;
        foreach (WC()->cart->get_fees() as $fee) {
            if (strpos($fee->name, 'GALADO Club reward') === 0) {
                $applied += abs((float) $fee->amount);
            }
        }
        if ($applied > 0) {
            // In RM, the unit consume_winback() debits and winback_reserved() holds: the fee is in the
            // cart's currency, the RM figure is what winback_discount() worked out for this same cart.
            $order->update_meta_data('_galado_winback_applied', self::$winback_rm > 0 ? self::$winback_rm : $applied);
        }
    }

    /**
     * RM this member has already claimed on live orders that have not paid yet (audit vuln-0001).
     * consume_winback only debits the balance on payment, so two unpaid orders could each apply
     * the same RM and both then pay. Mirrors has_used_intro(): nothing is written, so a cancelled
     * or failed order releases its hold by itself and no balance can be lost to a missed restore.
     */
    private static function winback_reserved($uid) {
        if (!$uid || !function_exists('wc_get_orders')) {
            return 0.0;
        }
        // The order this very cart is already paying for must not block its own cart: the shopper
        // who bounces off the payment page and comes back would otherwise watch their RM vanish.
        $mine = (WC()->session) ? (int) WC()->session->get('order_awaiting_payment') : 0;
        // Deliberately no meta_query: this has to behave identically on legacy post storage and on
        // HPOS, and a silent empty result would fail OPEN (no hold, bug still live). One customer's
        // live orders are a handful, so the claim stamp is read off each one below instead.
        $orders = wc_get_orders([
            'customer_id' => $uid,
            'limit'       => 20,
            'status'      => ['wc-pending', 'wc-on-hold', 'wc-processing'],
        ]);
        $held = 0.0;
        foreach ($orders as $order) {
            if ($order->get_id() === $mine || $order->get_meta('_galado_winback_consumed')) {
                continue;
            }
            // An abandoned checkout must not lock the balance for good, so a PENDING order lets go
            // after WINBACK_HOLD_MIN (Woo's own unpaid-order window). on-hold and processing are
            // real money in flight - bank transfer, gateway settling - and hold until consumed.
            if ('pending' === $order->get_status()) {
                $created = $order->get_date_created();
                if ($created && (time() - $created->getTimestamp()) > self::WINBACK_HOLD_MIN * MINUTE_IN_SECONDS) {
                    continue;
                }
            }
            $held += (float) $order->get_meta('_galado_winback_applied');
        }
        return $held;
    }

    /** On payment, subtract the applied win-back RM from the member's balance. Idempotent per order. */
    /** Copy a paid order's billing phone onto the buyer's account when the account has none:
     *  the order's customer for a logged-in order, else the account that owns the billing
     *  email (a member who checked out as a guest). Blank-only: a saved number is never changed. */
    public static function sync_member_phone_from_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $phone = trim(preg_replace('/[^0-9+\-\s]/', '', (string) $order->get_billing_phone()));
        if (strlen(preg_replace('/\D/', '', $phone)) < 8) {
            return;
        }
        $uid = (int) $order->get_user_id();
        if (!$uid) {
            $email = (string) $order->get_billing_email();
            $uid = $email ? (int) email_exists($email) : 0;
        }
        if (!$uid) {
            return;
        }
        if (strlen(preg_replace('/\D/', '', (string) get_user_meta($uid, 'billing_phone', true))) >= 7) {
            return;
        }
        update_user_meta($uid, 'billing_phone', $phone);
    }

    public static function consume_winback($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_galado_winback_consumed')) {
            return;
        }
        $applied = (float) $order->get_meta('_galado_winback_applied');
        if ($applied <= 0) {
            return;
        }
        $uid = $order->get_user_id();
        if (!$uid) {
            $u = get_user_by('email', $order->get_billing_email());
            $uid = $u ? $u->ID : 0;
        }
        if ($uid) {
            $avail = (float) get_user_meta($uid, '_galado_winback_rm', true);
            update_user_meta($uid, '_galado_winback_rm', max(0, $avail - $applied));
        }
        $order->update_meta_data('_galado_winback_consumed', '1');
        $order->save();
    }

    /** The Shopping Credits /app-order took for this order (_galado_app_credits_points) and the
     *  account they came from: [points, user id], or [0, 0] when there is nothing to move. */
    private static function app_credits($order) {
        $points = (int) $order->get_meta('_galado_app_credits_points');
        if ($points <= 0 || !class_exists('WC_Points_Rewards_Manager')) {
            return [0, 0];
        }
        $uid = (int) $order->get_user_id();
        if (!$uid) {
            $u   = get_user_by('email', $order->get_billing_email());
            $uid = $u ? (int) $u->ID : 0;
        }
        return [$points, $uid];
    }

    /**
     * Move an /app-order's credits while holding a MySQL named lock for that order, deciding on the
     * returned flag as stored in the database, not on a copy in memory or the object cache. Two
     * processes changing the same order at once (the unpaid-order sweep and an admin cancel, a
     * duplicate cron run) otherwise both see the order unclaimed and both move the points.
     * $move($order, $points, $uid) runs only inside the lock. Without the lock within 5 s nothing
     * moves and the order id is logged, to retry by hand.
     */
    private static function with_app_credits_lock($order_id, $action, $move) {
        global $wpdb;
        $order = wc_get_order($order_id);
        if (!$order || (int) $order->get_meta('_galado_app_credits_points') <= 0) {
            return; // no redeemed app credits on this order: nothing to lock for
        }
        $id   = (int) $order->get_id();
        $lock = 'gld_app_credits_' . $id;
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock))) {
            error_log('[galado-club-bridge] app_credits_not_moved order=' . $id . ' action=' . $action . ' reason=lock_timeout');
            return;
        }
        try {
            // Re-read through the order's own data store with the cache skipped: correct for posts
            // storage (this store) and for HPOS alike.
            $order->read_meta_data(true);
            list($points, $uid) = self::app_credits($order);
            if ($points && $uid) {
                $move($order, $points, $uid);
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** Give back the credits an /app-order took, once: it was cancelled (unpaid past the hold window
     *  included), failed, or was fully refunded. Paid and still paid, it never runs. */
    public static function return_app_credits($order_id) {
        self::with_app_credits_lock($order_id, 'return', function ($order, $points, $uid) {
            if ('1' === (string) $order->get_meta('_galado_app_credits_returned')) {
                return; // already given back
            }
            WC_Points_Rewards_Manager::increase_points($uid, $points, 'galado-app-redemption-returned', null, $order->get_id());
            $order->update_meta_data('_galado_app_credits_returned', '1');
            $order->save();
        });
    }

    /** A failed order whose credits went back and that is then paid anyway (the shopper retries on
     *  the pay page) takes them again, once, so the discount they bought is never also kept as points. */
    public static function retake_app_credits($order_id) {
        self::with_app_credits_lock($order_id, 'retake', function ($order, $points, $uid) {
            if ('1' !== (string) $order->get_meta('_galado_app_credits_returned')) {
                return; // never given back, so nothing to take again
            }
            WC_Points_Rewards_Manager::decrease_points($uid, $points, 'galado-app-redemption', null, $order->get_id());
            $order->delete_meta_data('_galado_app_credits_returned');
            $order->save();
        });
    }

    /** WooCommerce's unpaid-order sweep cancels only checkout-created orders in 10.5.3, the version
     *  this store runs; from 10.6 core also sweeps store-api ones. /app-order sets no created_via, so
     *  it is skipped either way. Opt in the ones holding redeemed credits, so they are cancelled after
     *  the hold-stock window like a website checkout and return_app_credits() gives the points back. */
    public static function cancel_unpaid_app_order($cancel, $order) {
        if ($cancel || !($order instanceof WC_Order)) {
            return $cancel;
        }
        return '1' === (string) $order->get_meta('_galado_app_order') && (int) $order->get_meta('_galado_app_credits_points') > 0;
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
           . '<div style="font-size:14px;line-height:1.5;color:#4A4A4A;margin-bottom:16px;">Add your GALADO Club Card to your phone Wallet: order updates with tracking the moment we ship, your coins and your member barcode. <strong style="color:#111111;">Worth 150 G-Coins the moment you add it.</strong></div>'
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
        // SPEC-4 bug 6: coins are RM-based, so the estimate converts at the order's own recorded rate
        // (an S$71.50 order promised ~60 coins and earned 149). With no rate it promises nothing:
        // the Club queues such an order rather than crediting it.
        $net  = self::order_amount_in_base($order, max(0.0, (float) $order->get_total() - (float) $order->get_shipping_total()));
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
        $coins_est = null === $net ? 0 : (int) round($net * (isset($mult[$tier]) ? $mult[$tier] : 1.0));
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

    /** Club -> WP: mirror tier into user meta. Nothing gates on it; the Club does not call /tier (checked 8 Oct 2026). */
    public static function rest_routes() {
        // Public version ping — confirms which plugin build is live (no secrets exposed).
        //
        // `sha` is a fingerprint of this file as it sits on the server. VERSION alone answers a
        // weaker question: it proves a file shipped, not which contents shipped, and it can be
        // bumped by a commit whose actual edit was lost in a bad merge. The hash cannot lie.
        // It exists because changes here can be invisible from outside: anything behind
        // galado_marketing_optin_live renders nothing at all while the gate is off, so there is
        // otherwise no way to confirm the deployed behaviour before it is live to everyone.
        register_rest_route('galado-club/v1', '/ping', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function () {
                $sha = hash_file('sha256', __FILE__);
                return ['ok' => true, 'version' => self::VERSION, 'sha' => $sha ? substr($sha, 0, 12) : null, 'hooks' => ['transition_comment_status', 'comment_post', 'woocommerce_checkout_create_order', 'woocommerce_cart_calculate_fees', 'user_register']];
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
        // The signed-in member's own tier standing, for the cart meter. Session-scoped:
        // it takes no email, so it can only ever report on whoever is logged in.
        register_rest_route('galado-club/v1', '/my-tier', [
            'methods'             => 'GET',
            'permission_callback' => function () { return is_user_logged_in(); },
            'callback'            => [__CLASS__, 'my_tier'],
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
                    // Enrich, never overwrite: a walk-in "create" at the POS often hits an
                    // email that already has a store account (Club member, past web buyer).
                    // This branch used to return untouched, so the phone typed at the
                    // counter was dropped and the member stayed unfindable by phone search
                    // (billing_phone empty). Fill ONLY blank fields; existing data wins.
                    $fill = [
                        'billing_phone'      => preg_replace('/[^0-9+\-\s]/', '', sanitize_text_field((string) $request->get_param('phone'))),
                        'billing_first_name' => sanitize_text_field((string) $request->get_param('first_name')),
                        'billing_last_name'  => sanitize_text_field((string) $request->get_param('last_name')),
                    ];
                    $filled = [];
                    foreach ($fill as $meta_key => $value) {
                        $value = trim((string) $value);
                        if ('' !== $value && '' === trim((string) get_user_meta($existing, $meta_key, true))) {
                            update_user_meta($existing, $meta_key, $value);
                            $filled[] = $meta_key;
                        }
                    }
                    return ['ok' => true, 'status' => 'exists', 'user_id' => (int) $existing, 'filled' => $filled];
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
                $phone = preg_replace('/[^0-9+\-\s]/', '', sanitize_text_field((string) $request->get_param('phone')));
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

        // Club -> WP: grant one win-back discount step (RM off) to a member's store account.
        // Idempotent per `step` (onboard|wallet): re-pushing a step never double-adds.
        register_rest_route('galado-club/v1', '/winback/grant', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $email = sanitize_email((string) $request->get_param('email'));
                $step  = preg_replace('/[^a-z]/', '', (string) $request->get_param('step'));
                $rm    = absint($request->get_param('rm'));
                $exp_r = (string) $request->get_param('expiresAt');
                if (!$email || !in_array($step, ['onboard', 'wallet'], true) || $rm < 1 || $rm > 100) {
                    return new WP_Error('bad_request', 'email, step (onboard|wallet), rm required', ['status' => 400]);
                }
                $user = get_user_by('email', $email);
                if (!$user) {
                    return new WP_Error('not_found', 'no user with that email', ['status' => 404]);
                }
                $uid   = $user->ID;
                $steps = get_user_meta($uid, '_galado_winback_steps', true);
                if (!is_array($steps)) {
                    $steps = [];
                }
                if (in_array($step, $steps, true)) {
                    return ['ok' => true, 'already' => true, 'available' => (float) get_user_meta($uid, '_galado_winback_rm', true)];
                }
                $avail = (float) get_user_meta($uid, '_galado_winback_rm', true);
                update_user_meta($uid, '_galado_winback_rm', $avail + $rm);
                $steps[] = $step;
                update_user_meta($uid, '_galado_winback_steps', $steps);
                $exp = strtotime($exp_r);
                if ($exp) {
                    $cur = (int) get_user_meta($uid, '_galado_winback_expires', true);
                    update_user_meta($uid, '_galado_winback_expires', max($cur, $exp));
                }
                return ['ok' => true, 'granted' => $rm, 'available' => $avail + $rm, 'steps' => $steps];
            },
        ]);

        // Club -> WP: clear a member's win-back balance entirely (test cleanup / manual reset).
        register_rest_route('galado-club/v1', '/winback/clear', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $email = sanitize_email((string) $request->get_param('email'));
                if (!$email) {
                    return new WP_Error('bad_request', 'email required', ['status' => 400]);
                }
                $user = get_user_by('email', $email);
                if (!$user) {
                    return ['ok' => true, 'cleared' => false, 'reason' => 'no user'];
                }
                delete_user_meta($user->ID, '_galado_winback_rm');
                delete_user_meta($user->ID, '_galado_winback_steps');
                delete_user_meta($user->ID, '_galado_winback_expires');
                return ['ok' => true, 'cleared' => true];
            },
        ]);

        // 30-day (rolling-window) best sellers by real unit sales, for the iOS
        // app's Shop rail. The Store API's orderby=popularity is all-time
        // total_sales; a windowed ranking needs the WC Analytics lookup tables,
        // which require admin access the app deliberately does not carry.
        register_rest_route('galado-club/v1', '/best-sellers', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                global $wpdb;
                $days  = (int) $request->get_param('days');
                $days  = $days > 0 ? min($days, 365) : 30;
                $limit = (int) $request->get_param('limit');
                $limit = $limit > 0 ? min($limit, 100) : 12;
                // Optional category scope: comma list of product_cat term ids.
                // An IN subquery (not a JOIN) keeps SUM correct when a product
                // sits in several of the passed categories.
                $cat_ids = array_values(array_filter(
                    array_map('intval', explode(',', (string) $request->get_param('categoryIds'))),
                    function ($v) { return $v > 0; }
                ));
                $cat_where = '';
                if (!empty($cat_ids)) {
                    $in = implode(',', $cat_ids);
                    $cat_where = "AND l.product_id IN ("
                        . "SELECT tr.object_id FROM {$wpdb->term_relationships} tr "
                        . "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id "
                        . "WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ($in))";
                }
                $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
                $stats  = $wpdb->prefix . 'wc_order_stats';
                $lookup = $wpdb->prefix . 'wc_order_product_lookup';
                $ids = [];
                if ($wpdb->get_var("SHOW TABLES LIKE '{$lookup}'") === $lookup) {
                    // Over-fetch, then collapse variations to parents + drop
                    // unpublished, so we still land `limit` live products.
                    $rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT l.product_id AS id, SUM(l.product_qty) AS sold
                         FROM {$lookup} l
                         INNER JOIN {$stats} s ON s.order_id = l.order_id
                         WHERE s.date_created >= %s
                           AND s.status IN ('wc-completed', 'wc-processing')
                           {$cat_where}
                         GROUP BY l.product_id
                         HAVING sold > 0
                         ORDER BY sold DESC
                         LIMIT %d",
                        $since,
                        $limit * 3
                    ));
                    foreach ((array) $rows as $r) {
                        $pid  = (int) $r->id;
                        $post = get_post($pid);
                        if ($post && $post->post_type === 'product_variation') {
                            $pid = (int) $post->post_parent;
                        }
                        if ($pid && get_post_status($pid) === 'publish' && !in_array($pid, $ids, true)) {
                            $ids[] = $pid;
                        }
                        if (count($ids) >= $limit) { break; }
                    }
                }
                return new WP_REST_Response(['days' => $days, 'ids' => array_values($ids)], 200);
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
                $req_currency = strtoupper(trim((string) ($p['currency'] ?? 'MYR')));
                if (!in_array($req_currency, self::APP_CURRENCIES, true) || self::current_currency_code() !== $req_currency) {
                    return new WP_Error('bad_currency', 'currency must be MYR or SGD', ['status' => 400]);
                }
                $emoji = self::custom_emoji_error($lines);
                if ($emoji) {
                    return $emoji;
                }
                // Staged PWP purchases (galado-bundles engine): the app sends
                // its quote REQUESTS, never prices. Re-run the same server
                // quote the PDP used and price matching lines from it — the
                // engine's rules (model fit, once-per-circle, anchors, tiers,
                // combo splits) apply to app orders byte-identically.
                $pwp_map = [];       // "pid|vid" => [ ['unit' => x, 'qty' => n], ... ]
                $pwp_saving = 0.0;
                $pwp_quotes = is_array($p['pwp_quotes'] ?? null) ? $p['pwp_quotes'] : [];
                if ($pwp_quotes && class_exists('GALADO_Bundles_App')) {
                    // Once-per-circle spans the WHOLE order: circles claimed by
                    // an earlier block are declared to the next one, so a second
                    // purchase of the same circle pays the normal price (the
                    // cart engine gets this by reading WC()->cart).
                    $claimed = [];
                    foreach ($pwp_quotes as $q) {
                        if (!is_array($q)) continue;
                        $q['claimed'] = array_values(array_unique(array_merge(
                            array_map('strval', (array) ($q['claimed'] ?? [])), $claimed
                        )));
                        $quote = GALADO_Bundles_App::quote($q);
                        if (empty($quote['ok'])) {
                            return new WP_Error('bad_request',
                                'bundle_quote: ' . (string) ($quote['message'] ?? 'invalid'), ['status' => 400]);
                        }
                        foreach ((array) $quote['lines'] as $ql) {
                            $key = (int) $ql['product_id'] . '|' . (int) $ql['variation_id'];
                            // S1 (17 Sep 2026): keep the RM `unit_base` (bundles v0.21+). `unit` is
                            // already in the shopper's currency and CURCY converts again on read.
                            $pwp_map[$key][] = [
                                'unit'      => (float) $ql['unit'],
                                'unit_base' => isset($ql['unit_base']) ? (float) $ql['unit_base'] : null,
                                'qty'       => (int) $ql['qty'],
                            ];
                            if (!empty($ql['pwp']) && !empty($ql['circle'])) {
                                $claimed[] = (string) $ql['circle'];
                            }
                        }
                        $pwp_saving += (float) ($quote['totals']['saving'] ?? 0);
                    }
                }

                $order = wc_create_order(['status' => 'pending']);
                if (is_wp_error($order)) {
                    return $order;
                }
                $order->set_currency($req_currency);
                foreach ($lines as $l) {
                    $pid = absint($l['product_id'] ?? 0);
                    $vid = absint($l['variation_id'] ?? 0);
                    $qty = max(1, absint($l['quantity'] ?? 1));
                    $product = wc_get_product($vid ?: $pid);
                    if (!$product || !$product->is_purchasable()) {
                        $order->delete(true);
                        return new WP_Error('bad_request', 'unknown or unpurchasable product ' . ($vid ?: $pid), ['status' => 400]);
                    }
                    // A variable parent passes is_purchasable() and prices at its
                    // CHEAPEST variation, so a line that forgot variation_id would
                    // be silently underpriced and have no variation attribute to
                    // fulfil against. Make the caller name the variation.
                    if (!$vid && $product->is_type('variable')) {
                        $order->delete(true);
                        return new WP_Error('bad_request', 'variation_id required for variable product ' . $pid, ['status' => 400]);
                    }
                    // PWP line: price it from the server quote (first unclaimed
                    // quoted entry for this product/variation), never from the
                    // catalogue. Applied on the in-memory PRODUCT before
                    // add_product(), the same way the web cart engine sets
                    // prices — mutating the ITEM afterwards left the order's
                    // cached items at catalogue price, so line rows said RM263
                    // while calculate_totals() summed RM332 and the customer
                    // was billed the undiscounted figure (found by a real E2E
                    // order, 410900).
                    $pwp_key = $pid . '|' . $vid;
                    if (!empty($pwp_map[$pwp_key]) && (int) $pwp_map[$pwp_key][0]['qty'] === $qty) {
                        $entry = array_shift($pwp_map[$pwp_key]);
                        // S1 (owner TestFlight, 17 Sep 2026): write the RM figure and let CURCY convert
                        // ONCE on read, with the same wmc_get_price the bundles quote used for `unit`,
                        // so order line == quote line by construction. v0.64.7-0.64.9 wrote `unit`
                        // (already S$) and relied on wmc_product_get_price_condition to stop a second
                        // conversion; CURCY's VARIATION price hook never consults that filter, so every
                        // PWP line was converted twice (orders 425323/425324). Under MYR unit_base ==
                        // unit. A bundles build without unit_base only exists in MYR, where unit is RM.
                        $base = null !== $entry['unit_base'] ? $entry['unit_base'] : $entry['unit'];
                        $product->set_price((string) $base);
                    }
                    $item_id = $order->add_product($product, $qty);
                    if ($item_id && is_array($l['custom'] ?? null)) {
                        $item = $order->get_item($item_id);
                        foreach ($l['custom'] as $c) {
                            $k = sanitize_text_field((string) ($c['label'] ?? ''));
                            $v = sanitize_textarea_field((string) ($c['value'] ?? ''));
                            if ($k === '' || $v === '') {
                                continue;
                            }
                            // Callers may only write VISIBLE meta. WooCommerce hides
                            // underscore-prefixed item meta, and those keys are reserved
                            // for server-minted fulfilment data (e.g. the Studio print
                            // link). Letting a signed-in member set them would let them
                            // point the admin "Download print file" button, or a customer
                            // order-email image, at any host of their choosing.
                            if (strpos($k, '_') === 0) {
                                continue;
                            }
                            $item->add_meta_data(mb_substr($k, 0, 80), mb_substr($v, 0, 500), false);
                        }
                        $item->save();
                        // Read the rows back before promising anything: this is the silent drop
                        // that lost three customers' names. No pay link for a half-saved order.
                        $lost = self::missing_custom_label($item_id, $l['custom']);
                        if (null !== $lost) {
                            $order->update_status('cancelled', sprintf(
                                'Bridge: personalisation "%s" did not save on item #%d, so the order was cancelled instead of billed. Order-item tables are utf8mb3 (SPEC-NO-EMOJI-NAMES).',
                                $lost, (int) $item_id
                            ));
                            return new WP_Error('custom_meta_lost', sprintf(
                                'We could not save "%s" on one of your items. Please remove that item from your cart and add it again.',
                                $lost
                            ), ['status' => 400]);
                        }
                    }
                }
                $addon_rm = (float) ($p['addon_total'] ?? 0); // the app sends add-ons in RM
                $addon    = ($addon_rm > 0 && $addon_rm < 10000) ? self::to_request_currency($addon_rm) : 0.0;
                if ($addon > 0) {
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name('Customisation add-ons');
                    $fee->set_amount((string) $addon);
                    $fee->set_total((string) $addon);
                    $order->add_item($fee);
                }
                // App welcome RM30 on the customised/bundle path (15 Sep 2026). Same token, same
                // rule and the SAME fee name as the cart path: the Club's webhook records the
                // redemption by that prefix, and capture_first_order_discount() never runs here,
                // so the intro meta is stamped by hand.
                $welcome_token = (string) ($p['welcome_token'] ?? '');
                if ('' !== $welcome_token
                    && preg_match('/^appwelcome\.[A-Za-z0-9._-]{10,400}$/', $welcome_token)
                    && self::verify_welcome_token($welcome_token, $email, ['appwelcome'])
                    && !self::has_app_order($email)) {
                    $order->calculate_totals(false);
                    if ((float) $order->get_subtotal() >= self::to_request_currency(self::APP_WELCOME_MIN)) {
                        $w_amount = self::to_request_currency(self::WELCOME30_AMOUNT);
                        $wfee = new WC_Order_Item_Fee();
                        $wfee->set_name('GALADO Club welcome: ' . self::money_label($w_amount) . ' off your first order');
                        $wfee->set_amount((string) (-$w_amount));
                        $wfee->set_total((string) (-$w_amount));
                        $order->add_item($wfee);
                        $order->add_meta_data('_galado_intro_discount', (string) $w_amount);
                        $order->add_meta_data('_galado_intro_type', 'appwelcome');
                    }
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
                    $credits_value = self::to_request_currency(round($credits_points / 10, 2)); // 10 points = RM1
                    WC_Points_Rewards_Manager::decrease_points($user->ID, $credits_points, 'galado-app-redemption');
                    $credit_fee = new WC_Order_Item_Fee();
                    $credit_fee->set_name('Shopping Credits redeemed');
                    $credit_fee->set_amount((string) (-$credits_value));
                    $credit_fee->set_total((string) (-$credits_value));
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
                // B4 (17 Sep 2026): a shipping line by the zone rules for the delivery address.
                // Before this, customised and bundle orders to Singapore paid no shipping at all.
                // The free-shipping threshold uses what the website's cart subtotal would hold:
                // items (PWP prices included) plus customisation add-ons, less coupon discounts.
                $ship_qty = 0;
                foreach ($order->get_items('line_item') as $li) {
                    $ship_qty += (int) $li->get_quantity();
                }
                $free_coupon = false;
                foreach ($order->get_coupon_codes() as $code) {
                    $cp = new WC_Coupon($code);
                    if ($cp->get_free_shipping()) {
                        $free_coupon = true;
                    }
                }
                $rate = self::app_order_shipping_rate(
                    ['country' => $addr['country'], 'state' => $addr['state'], 'postcode' => $addr['postcode'],
                     'city' => $addr['city'], 'address' => $addr['address_1'], 'address_2' => $addr['address_2']],
                    (float) $order->get_subtotal() + $addon,
                    (float) $order->get_discount_total(),
                    $ship_qty,
                    $free_coupon
                );
                if ($rate) {
                    $ship = new WC_Order_Item_Shipping();
                    $ship->set_method_title($rate['title']);
                    $ship->set_method_id($rate['method_id']);
                    $ship->set_instance_id($rate['instance_id']);
                    $ship->set_total((string) $rate['cost']);
                    $order->add_item($ship);
                }
                if ($pwp_saving > 0) {
                    // Same analytics key the web cart engine records, so bundle
                    // reporting spans both channels.
                    $order->add_meta_data('_galado_bundle_saving', (string) round($pwp_saving, 2));
                }
                // The same rate record CURCY writes on website checkouts (symbol.php
                // add_order_currency_info), so the Club's coin maths and CURCY's reports can convert
                // this order back to RM at the rate it was placed under.
                if (class_exists('WOOMULTI_CURRENCY_Data')) {
                    $wmc      = WOOMULTI_CURRENCY_Data::get_ins();
                    $wmc_info = $wmc->get_list_currencies();
                    $wmc_info[$wmc->get_default_currency()]['is_main'] = 1;
                    $order->update_meta_data('wmc_order_info', $wmc_info);
                }
                $order->calculate_totals();
                $order->save();
                return [
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                    'pay_url'   => self::app_pay_url($order, $user),
                    'currency'  => $order->get_currency(),
                    'total'     => (float) $order->get_total(),
                ];
            },
        ]);

        // GET /currency-rates (SPEC-SGD-PARITY 5.4): CURCY's own rates for the app currencies, so the
        // Club converts RM payloads (add-on option prices, the welcome offer, Shopping Credits) at
        // exactly the store's setting instead of a copy that could drift. Straight rate only; the
        // Club applies it without beauty rounding, like the bridge does for add-ons and fees.
        register_rest_route('galado-club/v1', '/currency-rates', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function () {
                $base = (string) get_option('woocommerce_currency', 'MYR');
                $out  = [$base => ['rate' => 1.0, 'decimals' => wc_get_price_decimals()]];
                if (class_exists('WOOMULTI_CURRENCY_Data')) {
                    foreach (WOOMULTI_CURRENCY_Data::get_ins()->get_list_currencies() as $code => $c) {
                        if (in_array($code, self::APP_CURRENCIES, true) && isset($c['rate']) && (float) $c['rate'] > 0) {
                            $out[$code] = ['rate' => (float) $c['rate'], 'decimals' => (int) ($c['decimals'] ?? 2)];
                        }
                    }
                }
                return ['base' => $base, 'currencies' => $out];
            },
        ]);

        // POST /app-order/quote (SPEC-SGD-PARITY 5.3): the /app-order body priced exactly as the
        // order would be, WITHOUT creating anything, so the app shows the server's total before
        // Place Order. Nothing is written: no order, no points deducted, no meta.
        register_rest_route('galado-club/v1', '/app-order/quote', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                return self::app_order_quote((array) $request->get_json_params());
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

        // App -> auto-login link into a pre-approved My Account page (e.g. the
        // warranty list) so the member never meets a WP login form. Bridge-authed:
        // the club server has already verified the member's own session, and
        // vouches for `email`. Mirrors /app-pay-link, but the destination is a
        // whitelisted key -> fixed path (never a raw URL), so it can't open-redirect.
        register_rest_route('galado-club/v1', '/app-login-link', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $p     = $request->get_json_params();
                $email = sanitize_email((string) ($p['email'] ?? ''));
                $path  = self::app_login_dest(sanitize_key((string) ($p['dest'] ?? '')));
                $user  = $email ? get_user_by('email', $email) : false;
                if (!$user || !$path) {
                    return new WP_Error('bad_request', 'unknown member or destination', ['status' => 400]);
                }
                $token = wp_generate_password(48, false, false);
                set_transient('gld_app_login_' . $token, [
                    'uid'  => (int) $user->ID,
                    'path' => $path,
                ], 10 * MINUTE_IN_SECONDS);
                return ['login_url' => rest_url('galado-club/v1/app-login/' . $token)];
            },
        ]);

        // Browser-facing half: a single-use token signs the member's WP session
        // in and lands on the pre-approved page. Only ever redirects on-site
        // (home_url + a whitelisted relative path).
        register_rest_route('galado-club/v1', '/app-login/(?P<token>[A-Za-z0-9]{20,64})', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function (WP_REST_Request $request) {
                $token = (string) $request['token'];
                $data  = get_transient('gld_app_login_' . $token);
                delete_transient('gld_app_login_' . $token); // single use, even when invalid
                $user  = is_array($data) ? get_user_by('id', absint($data['uid'] ?? 0)) : false;
                $path  = is_array($data) ? (string) ($data['path'] ?? '') : '';
                if (!$user || '' === $path) {
                    wp_safe_redirect(home_url('/')); // expired link: plain storefront
                    exit;
                }
                wp_set_auth_cookie($user->ID, false);
                // Mark this browsing session as the in-app webview so app_view_styles
                // strips the storefront chrome on the pages it lands on. Session cookie,
                // only ever set on the app auto-login path (never on the public site).
                setcookie('galado_app', '1', [
                    'expires'  => 0,
                    'path'     => '/',
                    'secure'   => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                wp_safe_redirect(home_url($path));
                exit;
            },
        ]);

        // Club web -> store auto-login. Same single-use, whitelisted-destination pattern as
        // /app-login, but for the normal browser (Club dashboard outbound store links): it does
        // NOT set the galado_app chrome-stripping cookie, so the member lands on the full
        // storefront, signed in, at their member price. Bridge-authed mint; 10 min TTL; single use.
        register_rest_route('galado-club/v1', '/store-login-link', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $p     = $request->get_json_params();
                $email = sanitize_email((string) ($p['email'] ?? ''));
                $path  = self::store_login_dest(sanitize_key((string) ($p['dest'] ?? '')));
                $user  = $email ? get_user_by('email', $email) : false;
                if (!$user || !$path) {
                    return new WP_Error('bad_request', 'unknown member or destination', ['status' => 400]);
                }
                $token = wp_generate_password(48, false, false);
                set_transient('gld_store_login_' . $token, [
                    'uid'  => (int) $user->ID,
                    'path' => $path,
                ], 10 * MINUTE_IN_SECONDS);
                return ['login_url' => rest_url('galado-club/v1/store-login/' . $token)];
            },
        ]);

        // Browser-facing half: a single-use token signs the member's WP session in and lands on
        // the pre-approved store page (home_url + a whitelisted relative path). No app cookie, so
        // the storefront keeps its normal chrome.
        register_rest_route('galado-club/v1', '/store-login/(?P<token>[A-Za-z0-9]{20,64})', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function (WP_REST_Request $request) {
                $token = (string) $request['token'];
                $data  = get_transient('gld_store_login_' . $token);
                delete_transient('gld_store_login_' . $token); // single use, even when invalid
                $user  = is_array($data) ? get_user_by('id', absint($data['uid'] ?? 0)) : false;
                $path  = is_array($data) ? (string) ($data['path'] ?? '') : '';
                if (!$user || '' === $path) {
                    wp_safe_redirect(home_url('/')); // expired link: plain storefront
                    exit;
                }
                wp_set_auth_cookie($user->ID, false);
                wp_safe_redirect(home_url($path));
                exit;
            },
        ]);

        // CP's iOS App panel: paid orders the app produced. Two markers, one
        // union: `_galado_app_order` meta (customised/bundle orders created by
        // /app-order) and created_via=store-api (native cart checkouts — the
        // app is the only store-api client).
        register_rest_route('galado-club/v1', '/app-stats', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function () {
                $paid = ['processing', 'completed', 'on-hold'];
                $meta_ids = wc_get_orders([
                    'limit'      => -1,
                    'return'     => 'ids',
                    'status'     => $paid,
                    'meta_key'   => '_galado_app_order',
                    'meta_value' => '1',
                ]);
                $api_ids = wc_get_orders([
                    'limit'       => -1,
                    'return'      => 'ids',
                    'status'      => $paid,
                    'created_via' => 'store-api',
                ]);
                $ids     = array_values(array_unique(array_merge($meta_ids, $api_ids)));
                $cut     = strtotime('-30 days');
                $revenue = 0.0;
                $d30     = 0;
                $rev30   = 0.0;
                $unconverted = 0;
                foreach ($ids as $id) {
                    $order = wc_get_order($id);
                    if (!$order) {
                        continue;
                    }
                    // SPEC-4 bug 7: revenue in RM, each order at its own recorded rate. A foreign
                    // order with no rate still counts as an order, adds no revenue, and is reported.
                    $total = self::order_amount_in_base($order, $order->get_total());
                    if (null === $total) {
                        $unconverted++;
                        $total = 0.0;
                    }
                    $revenue += $total;
                    $created  = $order->get_date_created();
                    if ($created && $created->getTimestamp() >= $cut) {
                        $d30++;
                        $rev30 += $total;
                    }
                }
                return [
                    'orders'      => count($ids),
                    'revenue'     => round($revenue, 2),
                    'orders_30d'  => $d30,
                    'revenue_30d' => round($rev30, 2),
                    'unconverted' => $unconverted,
                ];
            },
        ]);

        // App -> coupon metadata (type + amount) so the cart can EXPLAIN a
        // coupon on customised lines, which live app-side until the order is
        // created (the Store API cart shows RM0 discount for them, reading as
        // "coupon broken"). Read-only meta; validity is still enforced at
        // order creation by apply_coupon().
        register_rest_route('galado-club/v1', '/coupon-meta', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'bridge_auth'],
            'callback'            => function (WP_REST_Request $request) {
                $p    = $request->get_json_params();
                $code = wc_format_coupon_code((string) ($p['code'] ?? ''));
                if ('' === $code || !wc_get_coupon_id_by_code($code)) {
                    return new WP_Error('not_found', 'coupon not found', ['status' => 404]);
                }
                $coupon = new WC_Coupon($code);
                return [
                    'code'   => $coupon->get_code(),
                    'type'   => $coupon->get_discount_type(), // percent | fixed_cart | fixed_product
                    'amount' => (float) $coupon->get_amount(),
                ];
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
                    // Mostly-numeric query → phone lookup on digits only, so every stored format
                    // matches (+60 12-345 6789, 0123456789, 123456789, stray invisible characters).
                    // The country code (60/65) and trunk 0 are dropped and the rest is matched
                    // anywhere in the number, so a number that is still being typed already finds
                    // its member. Dropping a prefix can only add candidates, never lose a match.
                    $core = $digits;
                    if (strlen($core) >= 8 && (0 === strpos($core, '60') || 0 === strpos($core, '65'))) {
                        $core = substr($core, 2);
                    }
                    $core = ltrim($core, '0');
                    if (strlen($core) >= 6) {
                        $ids = $wpdb->get_col($wpdb->prepare(
                            "SELECT user_id FROM {$wpdb->usermeta}
                              WHERE meta_key = 'billing_phone'
                                AND REGEXP_REPLACE(meta_value, '[^0-9]', '') LIKE %s
                              LIMIT 10",
                            '%' . $wpdb->esc_like($core) . '%'
                        ));
                    }
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

    /** On the order-pay page, tick the radio for the gateway the order was
     *  created with (WooCommerce/MOLPay otherwise default to the first one).
     *  The app sets card orders to stripe_cc, so the shopper lands with Credit
     *  Card already selected. No-op when the order has no chosen gateway (the
     *  customised flow, where the shopper picks on the page). */
    public static function preselect_order_pay_gateway_script() {
        $order_id = absint(get_query_var('order-pay'));
        if (!$order_id && isset($GLOBALS['wp']->query_vars['order-pay'])) {
            $order_id = absint($GLOBALS['wp']->query_vars['order-pay']);
        }
        if (!$order_id) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $pm = $order->get_payment_method();
        if (!$pm) {
            return;
        }
        $target = 'payment_method_' . preg_replace('/[^a-z0-9_]/', '', $pm);
        ?>
<script>
(function(){
  function pick(){
    var el = document.getElementById(<?php echo wp_json_encode($target); ?>);
    if (!el || el.checked) { return; }
    el.checked = true;
    if (window.jQuery) { jQuery(el).trigger('click').trigger('change'); }
    else { el.click(); el.dispatchEvent(new Event('change', { bubbles: true })); }
  }
  if (document.readyState !== 'loading') { pick(); }
  else { document.addEventListener('DOMContentLoaded', pick); }
})();
</script>
        <?php
    }

    /** Every order earns Shopping Credits on the FINAL amount the customer paid —
     *  product + customisation add-ons, less any discount or Shopping Credits
     *  redeemed. get_total() already nets all of these (add-ons are positive fee
     *  lines, redemptions/discounts negative), so it is exactly "what they paid".
     *  WooCommerce's default instead sums per-product points and ignores add-on
     *  FEES, under-crediting any order with add-ons (e.g. RM149 product + RM35
     *  add-on earned 75 instead of 92; an all-add-on product earned 0). This
     *  mirrors the on-site "RM2 = 1 point" promise on the amount actually charged.
     *
     *  Note: this replaces Woo's per-product figure, so a per-product or category
     *  points multiplier would not apply. There are none today (the DOUBLE Reward
     *  category is empty); revisit here if such a promo is ever introduced. */
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
        // SPEC-4 G4: points are RM money (10 points = RM1), so a foreign-currency order earns on its
        // RM value at the rate CURCY recorded on it, never today's (an S$ total read as RM earned
        // about 2.5x too few). MYR orders are unchanged. With no recorded rate it earns nothing here
        // rather than points on the raw foreign figure, and logs one greppable line. Remedy by hand
        // once the rate is known: credit round(paid / rate x the ratio) points through this bridge's
        // POST /points/add (email, points).
        $total = self::order_amount_in_base($order, $order->get_total());
        if (null === $total) {
            error_log('[galado-club-bridge] points_not_awarded order=' . $order->get_id() . ' currency=' . $order->get_currency()
                . ' reason=no_rate paid=' . $order->get_total());
            return 0;
        }
        $base = $total * ($earn / $per);
        if ($base <= 0) {
            return 0;
        }
        switch (get_option('wc_points_rewards_earn_points_rounding', 'round')) {
            case 'ceil':  return (int) ceil($base);
            case 'floor': return (int) floor($base);
            default:      return (int) round($base);
        }
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

    /** Whitelist of app auto-login destinations -> fixed on-site relative paths.
     *  The app never sends a raw URL, only one of these keys, so /app-login can
     *  never be turned into an open redirect. */
    private static function app_login_dest($dest) {
        $map = [
            'warranties'   => '/my-account/warranties/',
            'orders'       => '/my-account/orders/',
            'account'      => '/my-account/',
            'edit-address' => '/my-account/edit-address/',
            // Case designer opened from the iOS app: signed in (member pricing)
            // and chrome-stripped via the galado_app cookie.
            'studio'       => '/studio/',
        ];
        return $map[$dest] ?? '';
    }

    /** Whitelist of Club-web -> store auto-login destinations -> fixed on-site relative paths.
     *  Only whitelisted keys are ever accepted (never a raw URL), so /store-login can never be
     *  turned into an open redirect. Used by the Club dashboard's outbound store links. */
    private static function store_login_dest($dest) {
        $map = [
            'mys-collection' => '/collections/member-price/',
            'mys-stylink'    => '/product/stylink-metal-chain/',
            'mys-luna'       => '/product/luna-guard/',
            // 9.9 Flash Sale (9 Sep 2026): the Club dashboard banner lands members on the
            // sale page already signed in, so member pricing and the tier discount apply.
            'flash-99'       => '/99-flash-sale/',
        ];
        return $map[$dest] ?? '';
    }

    /** Product flipped to instock -> notify the Club (app wishlist pushes).
     *  Variations report their PARENT id: the app wishlists parent products. */
    public static function on_stock_status($product_id, $status, $product = null) {
        if ('instock' !== $status) {
            return;
        }
        $secret = self::bridge_secret();
        if ('' === $secret) {
            return;
        }
        $p = $product instanceof WC_Product ? $product : wc_get_product($product_id);
        if (!$p) {
            return;
        }
        $parent_id = $p->get_parent_id() ?: $p->get_id();
        $parent    = $parent_id === $p->get_id() ? $p : wc_get_product($parent_id);
        wp_remote_post(self::club_url() . '/webhooks/stock', [
            'timeout'  => 4,
            'blocking' => false, // fire-and-forget; the Club dedups
            'headers'  => ['content-type' => 'application/json', 'x-club-bridge-secret' => $secret],
            'body'     => wp_json_encode([
                'product_id' => (int) $parent_id,
                'name'       => $parent ? html_entity_decode(wp_strip_all_tags($parent->get_name())) : '',
            ]),
        ]);
    }

    /** True when the current request is the iOS app webview (set by /app-login). */
    private static function is_app_view() {
        return !empty($_COOKIE['galado_app']);
    }

    /** Tag the <body> so the app-view CSS can scope to it. */
    public static function app_view_body_class($classes) {
        if (self::is_app_view()) {
            $classes[] = 'galado-app';
        }
        return $classes;
    }

    /** In the app webview, strip the storefront chrome so a page like the warranty
     *  list or the case designer reads as a focused, native-feeling screen. Scoped
     *  to `.galado-app` (cookie-gated) so it never touches the public site; emits on
     *  My Account pages and the Studio designer, which the app opens full-screen. */
    public static function app_view_styles() {
        if (!self::is_app_view()) {
            return;
        }
        $is_account = function_exists('is_account_page') && is_account_page();
        $is_studio  = function_exists('is_page') && is_page('studio');
        if (!$is_account && !$is_studio) {
            return;
        }
        echo '<style id="gld-app-view">'
            . 'body.galado-app #top-bar,'
            . 'body.galado-app #header,'
            . 'body.galado-app .header-wrapper,'
            . 'body.galado-app #footer,'
            . 'body.galado-app .absolute-footer,'
            // The "Join the Club" popup (#gldpj) and its minimised coin (#gldpj-min)
            // show to guests after ~7s. Redundant in the app (the app IS the Club)
            // and it covers the design canvas. Its JS reveals it with an inline
            // display:flex, so !important is required to keep it hidden.
            . 'body.galado-app #gldpj,'
            . 'body.galado-app #gldpj-min,'
            . 'body.galado-app .woocommerce-store-notice{display:none!important}';
        if (!$is_account) {
            echo '</style>';
            return;
        }
        echo 'body.galado-app .my-account .vertical-tabs>.large-3.col{display:none!important}'
            . 'body.galado-app .my-account .vertical-tabs{margin:0!important}'
            . 'body.galado-app .my-account .vertical-tabs>.large-9.col{flex:0 0 100%!important;max-width:100%!important;padding:0!important}'
            . 'body.galado-app .page-wrapper.my-account{padding:10px 14px 28px!important}'
            . 'body.galado-app .woocommerce-MyAccount-content{padding:0!important}'
            . 'body.galado-app .gwarr-warranty-card{width:auto!important;max-width:100%!important;box-sizing:border-box!important}'
            . 'body.galado-app .gwarr-warranty-head{flex-wrap:wrap!important;row-gap:6px!important}'
            . 'body.galado-app .gwarr-meta{grid-template-columns:1fr!important;gap:2px 0!important}'
            . 'body.galado-app .gwarr-meta dd{margin:0 0 6px!important}'
            . 'body.galado-app .gwarr-product{overflow-wrap:anywhere!important;word-break:break-word!important}'
            . '</style>';
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

    /** Member pricing is a storefront concept: it must reach real shoppers (classic
     *  pages + the Store API block cart/checkout) but NEVER the programmatic wc/v3
     *  REST API. The POS reads catalogue prices over wc/v3 authenticated by a
     *  consumer key, which WooCommerce treats as is_user_logged_in() = true, so
     *  without this guard the counter would receive the 20%-off member price and be
     *  unable to charge full price. Allow only the Store API (wc/store/*, block
     *  cart/checkout) inside REST; every other REST/integration read gets the
     *  original price. */
    private static function mys_shopper_context() {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            return false !== strpos($uri, '/wc/store/');
        }
        return true;
    }

    private static function mys_applies($product) {
        return self::mys_window_active() && self::mys_shopper_context() && is_user_logged_in() && self::mys_hero_parent($product);
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
        // Context ('s' shopper / 'x' api-admin) keeps the wc/v3 REST reads (POS) in
        // their own cached-range bucket so they never inherit a member-priced range.
        $hash[] = 'mys:' . (self::mys_window_active() ? '1' : '0')
            . ':' . (is_user_logged_in() ? 'm' : 'g')
            . ':' . (self::mys_shopper_context() ? 's' : 'x');
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
        }
        // Guests: no prompt during the sale. Normal price shows; no join-to-unlock distraction.
        // (Member price itself is applied by the login-gated price filters, not here, so pricing is unaffected.)
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
        // Acquisition surface, forwarded to the Club so signups are attributable (popup vs blog etc.).
        // Whitelisted; anything unrecognised falls back to 'popup' (the original, unchanged default).
        $source = sanitize_key((string) $request->get_param('source'));
        if (!in_array($source, ['popup', 'blog_sidebar', 'blog_inline', 'article'], true)) {
            $source = 'popup';
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
        // The glass sign-ups (the popup, the article card #149 and the iPhone 18/Duo article boxes)
        // ask for instant membership: the Club makes the member at once and says whether they already
        // were one. Granted only while they are live (option galado_club_popup = 'glass') or to the
        // owner's preview, which carries a signed daily pass. Otherwise, a stale cached page included,
        // the visitor gets the sign-in link and the surface says so.
        // The article sources never reach the Club's club_joined record (it stays popup-only), so their
        // marketing consent is the tick alone. If that ever changes, G-Send's SURFACES_WITH_OPTIN_TICK
        // must learn these sources first, or an untick would be overruled by its implied opt-in.
        $glass   = !empty($request->get_param('instant'));
        $instant = $glass && ('glass' === get_option('galado_club_popup') || self::glass_preview_pass_ok((string) $request->get_param('pv')));
        $res = wp_remote_post(GALADO_CLUB_URL . ($instant ? '/api/club/join' : '/api/claim/request'), [
            'timeout' => 8,
            'headers' => ['content-type' => 'application/json', 'x-club-bridge-secret' => GALADO_CLUB_BRIDGE_SECRET],
            // noticeText: the join wording this visitor was actually shown, resolved HERE from the
            // same constant that rendered it rather than accepted from the request. The Club
            // stores it as the evidence for what the JOIN itself promised.
            //
            // It is not the whole story once the opt-in tick is live: a visitor who ticks also saw
            // POPUP_OPTIN_NOTICE, and that wording goes straight to the send ledger with their
            // consent, not through here. Do NOT add it to this payload. One system decides who
            // gets email, and a second copy of a consent record is a second copy that can drift.
            //
            // Only for the popup: the blog card is rendered by a separate surface with its
            // own wording, and the Club does not record joins from it today.
            'body'    => wp_json_encode(array_merge(
                ['email' => $email, 'name' => mb_substr($name, 0, 60), 'clientIp' => $ip, 'source' => $source],
                'popup' === $source ? ['noticeText' => $glass ? self::POPUP_INSTANT_NOTICE : self::POPUP_NOTICE] : [],
                $ref ? ['ref' => $ref] : []
            )),
        ]);
        if (is_wp_error($res)) {
            return new WP_Error('unreachable', 'The Club is catching its breath. Please try again shortly.', ['status' => 503]);
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if (429 === $code) {
            return new WP_Error('slow_down', $glass ? 'Too many tries from here. Please wait a few minutes.' : 'Too many sign-in requests. Please wait a few minutes.', ['status' => 429]);
        }
        if ($code >= 400) {
            return new WP_Error('failed', 'Something went sideways. Please try again.', ['status' => 502]);
        }
        // Marketing consent, only when the visitor left the box ticked. Fires AFTER the Club call
        // succeeded, so we never record consent against a join that did not happen.
        //
        // Any join surface may ask: $source is already narrowed to the allowlist above, and a
        // surface that renders no tick cannot send `optin`, so this stays inert for it. That is why
        // there is no per-source condition here. Two doors into the same Club asking different
        // things of people is the inconsistency this avoids.
        //
        // THE PRICE OF THAT: every surface that shows a tick MUST render POPUP_OPTIN_NOTICE
        // verbatim, because that is the wording stored as the consent record. A surface wording it
        // differently would file consent under a sentence its visitor never read. Read the constant,
        // do not copy the string.
        //
        // $source is passed through rather than hardcoded so the ledger records which door they
        // actually came in by, which is what the Audience tab counts.
        //
        // galado_newsletter_emit() lives in Code Snippet #215, which someone can deactivate in one
        // click. Without function_exists() that click would fatal every popup submit, which is the
        // same shape as the refactor that took the store down. The guard is not optional.
        //
        // Its return value is deliberately ignored: it blocks for up to 5s and a ledger problem
        // must never fail a Club join. A dropped write leaves a log line on the store instead.
        if (!empty($request->get_param('optin')) && function_exists('galado_newsletter_emit')) {
            galado_newsletter_emit($email, $name, $source, self::POPUP_OPTIN_NOTICE);
        }
        if ($instant) {
            $body = json_decode((string) wp_remote_retrieve_body($res), true);
            return ['ok' => true, 'returning' => !empty($body['returning'])];
        }
        return $glass ? ['ok' => true, 'link' => true] : ['ok' => true];
    }

    public static function join_popup() {
        if (is_admin() || is_user_logged_in()) {
            return;
        }
        if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) {
            return;
        }
        if (self::glass_mode()) {
            self::glass_join_popup();
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
#gldpj .pj-optin{display:flex;align-items:flex-start;gap:10px;margin:4px 0 2px;padding:6px 0;cursor:pointer;font-size:13px;line-height:1.45;color:#111111;text-align:left;}
#gldpj .pj-optin input{flex:none;width:18px;height:18px;margin:1px 0 0;accent-color:#E4002B;cursor:pointer;}
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
#gldpj-min{position:fixed;right:18px;left:auto;top:calc(50% - 26px);bottom:auto;z-index:999998;width:52px;height:52px;border-radius:50%;background:#fff;border:2px solid #111111;box-shadow:0 6px 18px rgba(17,17,17,.22);cursor:pointer;padding:0;align-items:center;justify-content:center;transition:transform .15s;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-tap-highlight-color:transparent;}
#gldpj-min:hover{transform:scale(1.08);}
#gldpj-min img{width:30px;height:auto;display:block;margin:0 auto;}
#gldpj-min .pj-mx{position:absolute;top:-7px;left:-7px;width:20px;height:20px;border-radius:50%;background:#111111;color:#fff;border:2px solid #fff;font:700 12px/16px Arial,sans-serif;text-align:center;box-shadow:0 2px 6px rgba(17,17,17,.3);}
@media (max-width:640px){
#gldpj-min{right:12px;left:auto;top:calc(50% - 24px);bottom:auto;width:48px;height:48px;}
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
<li><span class="pj-pk"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12v9H4v-9M2 8h20v4H2zM12 8v13M12 8S10.7 4 8 4a2 2 0 1 0 0 4h4zM12 8s1.3-4 4-4a2 2 0 1 1 0 4h-4z"/></svg></span>Birthday treats &amp; surprises</li>
</ul>
<form class="pj-f" novalidate onsubmit="return false">
<input type="text" name="name" placeholder="Your name" maxlength="60" autocomplete="given-name"/>
<input type="email" name="email" placeholder="Email" maxlength="254" required autocomplete="email"/>
<input type="text" name="website" class="pj-hp" tabindex="-1" autocomplete="off" aria-hidden="true"/>
<?php if (function_exists('galado_newsletter_is_live') && galado_newsletter_is_live()) : ?>
<label class="pj-optin"><input type="checkbox" name="optin" checked/><span><?php echo esc_html(self::POPUP_OPTIN_NOTICE); ?></span></label>
<?php endif; ?>
<button type="submit" class="pj-btn">I&#8217;M IN &#10022;</button>
<p class="pj-err"></p>
</form>
<p class="pj-note"><?php echo esc_html(self::POPUP_NOTICE); ?></p>
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
<span class="pj-mx" title="Hide" aria-label="Hide the Club coin">&times;</span>
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
/* X on the coin: hides it for the rest of the browsing session (it can cover the
   case-designer canvas); back next session so the acquisition surface survives. */
function chipClosed(){try{return sessionStorage.getItem('gldpj_chip_x')==='1'}catch(e){return false}}
function closeChip(){try{sessionStorage.setItem('gldpj_chip_x','1')}catch(e){}hideChip()}
function showChip(){if(chipClosed())return;chips().forEach(function(c){c.style.display='flex'})}
function hideChip(){chips().forEach(function(c){c.style.display='none'})}
function openAll(){boxes().forEach(function(b){b.classList.add('on');b.style.display='flex'});hideChip()}
function closeAll(d){if(!isOpen())return;boxes().forEach(function(b){b.classList.remove('on');b.style.display='none'});showChip();if(d)save({snooze:Date.now()+d*864e5})}
function joined(){hideChip();save({joined:true})}
/* Submit telemetry. Same event name and shape as the blog card's (snippet #149) so the two
   surfaces are comparable side by side, distinguished by method. Fires ONLY after a successful
   response, matching the card, or the numbers mean different things and comparing them teaches
   us nothing. NEVER carries the email address: this counts the submit, never who submitted.
   Without this the popup produces members and no submit count, so it has no completion rate. */
function track(optin){var p={method:'popup',join_source:'popup',optin:!!optin};
if(typeof window.gtag==='function'){window.gtag('event','club_join_submit',p)}
else if(window.dataLayer&&window.dataLayer.push){p.event='club_join_submit';window.dataLayer.push(p)}}
/* Minimised coin is draggable (pointer events = touch + mouse): it sits bottom-left by
   default, which can cover a product page's sticky add-to-cart price bar - so customers
   can move it anywhere; the spot is remembered across pages. A plain tap still opens
   the popup; only a real drag (>6px) suppresses the click that follows it. */
var GLDPJ_DRAG_AT=0,PKEY='gldpj_pos_v1';
function clampPos(pos,c){var w=c.offsetWidth||52,h=c.offsetHeight||52,M=8;
return{x:Math.min(Math.max(pos.x,M),(window.innerWidth||360)-w-M),
y:Math.min(Math.max(pos.y,M),(window.innerHeight||640)-h-M)}}
function applyPos(){var p=null;try{p=JSON.parse(localStorage.getItem(PKEY)||'null')}catch(e){}
if(!p||typeof p.x!=='number'||typeof p.y!=='number')return;
chips().forEach(function(c){var q=clampPos(p,c);
c.style.left=q.x+'px';c.style.top=q.y+'px';c.style.bottom='auto';c.style.right='auto'})}
if(window.PointerEvent)chips().forEach(function(c){
var pid=null,sx=0,sy=0,ox=0,oy=0,moved=false;
c.addEventListener('pointerdown',function(e){
if(e.button&&e.button!==0)return;
if(e.target&&e.target.closest&&e.target.closest('.pj-mx'))return; /* X badge: skip drag+pointer-capture so the trailing click keeps .pj-mx as its target and the close branch runs (capture would retarget the click to #gldpj-min and re-open the popup) */
pid=e.pointerId;moved=false;sx=e.clientX;sy=e.clientY;
var r=c.getBoundingClientRect();ox=r.left;oy=r.top;
try{c.setPointerCapture(pid)}catch(err){}});
c.addEventListener('pointermove',function(e){
if(pid===null||e.pointerId!==pid)return;
var dx=e.clientX-sx,dy=e.clientY-sy;
if(!moved&&(dx*dx+dy*dy)<36)return;
moved=true;
var q=clampPos({x:ox+dx,y:oy+dy},c);
c.style.left=q.x+'px';c.style.top=q.y+'px';c.style.bottom='auto';c.style.right='auto'});
function endDrag(e){
if(pid===null||(e.pointerId!==undefined&&e.pointerId!==pid))return;
pid=null;
if(moved){GLDPJ_DRAG_AT=Date.now();
var r=c.getBoundingClientRect();
try{localStorage.setItem(PKEY,JSON.stringify({x:r.left,y:r.top}))}catch(err){}}}
c.addEventListener('pointerup',endDrag);
c.addEventListener('pointercancel',endDrag);});
window.addEventListener('resize',applyPos);
applyPos();
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAll(7)});
document.addEventListener('click',function(e){
var t=e.target;if(!t)return;
if(t.closest&&t.closest('#gldpj-min .pj-mx')){e.preventDefault();e.stopPropagation();closeChip();return}
if(t.closest&&t.closest('#gldpj-min')){if(Date.now()-GLDPJ_DRAG_AT<400)return;openAll();return}
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
var ck=f.querySelector('input[name=optin]');
if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){err.textContent='Please enter a valid email.';err.style.display='block';return}
err.style.display='none';btn.disabled=true;btn.textContent='Sending...';
fetch(GLDPJ_REST,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:name,email:email,website:hp,optin:ck?ck.checked:false})})
.then(function(r){return r.json().then(function(b){return{ok:r.ok,body:b}})})
.then(function(r){if(r.ok){card.querySelector('.pj-mail').textContent=email;card.querySelector('.pj-main').style.display='none';card.querySelector('.pj-done').style.display='block';joined();track(ck&&ck.checked)}else{err.textContent=(r.body&&r.body.message)||'Something went sideways. Please try again.';err.style.display='block';btn.disabled=false;btn.textContent='JOIN'}})
.catch(function(){err.textContent='Could not reach us. Please try again.';err.style.display='block';btn.disabled=false;btn.textContent='JOIN'});
},true);
if(st&&st.snooze&&Date.now()<st.snooze){showChip()}else{setTimeout(openAll,7000)}
})();
</script>
<?php
    }

    /* -- Club join popup, glass (2026-10-07) ------------------------------------------------------
     * The site's bright glass look with instant membership (owner's calls, 7 Oct 2026: instant member,
     * email only). One field: the Club makes the member at once (REST /popup-join with instant=1 ->
     * Club /api/club/join), its welcome email carries the one-tap link into their Club, and someone
     * already in the Club gets the usual sign-in link instead. Same ids, storage keys and coin as the
     * popup above, so the blog card (#149), the iPhone 18 guard (#202), the glass menus (#430) and the
     * app's hiding rules keep working with either.
     * Preview on any page with ?club_popup=new. Live for every guest when the option galado_club_popup
     * is 'glass'. Rollback: delete that option. Instant joins stop at once; a page still cached with the
     * glass popup sends the sign-in link instead and says so, until the page caches are cleared. */

    private static function glass_popup_preview() {
        return isset($_GET['club_popup']) && 'new' === $_GET['club_popup'];
    }

    // Whether the glass Club sign-ups are on for this request: live for everyone once the option says
    // so, or the owner's preview. The article card (#149) reads this too, so they all switch together.
    public static function glass_mode() {
        return self::glass_popup_preview() || 'glass' === get_option('galado_club_popup');
    }

    // The iPhone 18/Duo articles carry both versions of their sign-up boxes; this class shows the Club one.
    public static function glass_body_class($classes) {
        if (!is_admin() && self::glass_mode()) {
            $classes[] = 'gld-club-glass';
        }
        return $classes;
    }

    public static function glass_preview_flag() {
        if (!is_admin() && self::glass_popup_preview()) {
            echo '<script>window.GLDCLUB_PV=' . wp_json_encode(self::glass_preview_pass()) . ';</script>';
        }
    }

    // The preview's pass for instant joins before go-live: a signed daily value, never stored.
    private static function glass_preview_pass($days_ago = 0) {
        $day = gmdate('Y-m-d', time() - $days_ago * DAY_IN_SECONDS);
        return substr(hash_hmac('sha256', 'galado-club-popup-preview|' . $day, wp_salt('auth')), 0, 24);
    }

    private static function glass_preview_pass_ok($pass) {
        return '' !== $pass && (hash_equals(self::glass_preview_pass(0), $pass) || hash_equals(self::glass_preview_pass(1), $pass));
    }

    public static function glass_join_popup() {
        $rest    = esc_url_raw(rest_url('galado-club/v1/popup-join'));
        $preview = self::glass_popup_preview();
        $pass    = $preview ? self::glass_preview_pass() : '';
        $club    = 'https://club.galado.com.my/';
        ?>
<div id="gldpj" role="dialog" aria-modal="true" aria-labelledby="gldpj-title">
<style>
#gldpj{--pj-ink:#111111;--pj-ink-2:#3d3d3d;--pj-mute:#66625c;--pj-faint:#8f8a82;--pj-red:#E4002B;--pj-line:rgb(17 17 17 / 8%);--pj-wash:rgb(17 17 17 / 5%);--pj-ease:cubic-bezier(.16,1,.3,1);--pj-pop:cubic-bezier(.34,1.56,.64,1);position:fixed;inset:0;z-index:999999;display:none;flex-direction:column;padding:24px 16px;overflow-y:auto;overscroll-behavior:contain;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:var(--pj-ink);text-align:left;-webkit-font-smoothing:antialiased;}
#gldpj.on{display:flex;}
#gldpj *{box-sizing:border-box;}
#gldpj [hidden]{display:none !important;}
#gldpj .pj-scrim{position:fixed;inset:0;background:rgb(240 239 236 / 52%);-webkit-backdrop-filter:blur(12px) saturate(140%);backdrop-filter:blur(12px) saturate(140%);opacity:0;transition:opacity .35s ease;}
#gldpj.in .pj-scrim{opacity:1;}
#gldpj .pj-wrap{position:relative;width:100%;max-width:440px;margin:auto;padding-top:112px;}
#gldpj .pj-buds{position:absolute;top:0;right:66px;width:190px;height:170px;}
#gldpj .pj-bud{position:absolute;top:-24px;width:auto;max-width:none;height:232px;margin:0;opacity:0;transform:translateY(70px);transition:transform .75s var(--pj-pop),opacity .25s ease;filter:drop-shadow(0 10px 12px rgb(17 17 17 / 12%));}
#gldpj .pj-bud1{left:0;transition-delay:.12s;}
#gldpj .pj-bud2{left:58px;top:-31px;height:240px;z-index:1;transition-delay:.2s;}
#gldpj .pj-coin{position:absolute;left:2px;top:46px;width:46px;height:auto;max-width:none;margin:0;z-index:2;opacity:0;transform:translateY(16px) rotate(-30deg) scale(.6);transition:transform .7s var(--pj-pop) .42s,opacity .25s ease .42s;}
#gldpj.in .pj-bud{opacity:1;transform:none;}
#gldpj.in .pj-coin{opacity:1;transform:rotate(-14deg);}
#gldpj .pj-card{position:relative;z-index:1;padding:24px 24px 16px;border-radius:28px;background:rgb(255 255 255 / 78%);border:1px solid rgb(255 255 255 / 75%);-webkit-backdrop-filter:blur(28px) saturate(185%);backdrop-filter:blur(28px) saturate(185%);box-shadow:inset 1px 1px 0 rgb(255 255 255 / 95%),inset -1px -1px 0 rgb(255 255 255 / 35%),0 0 0 1px rgb(17 17 17 / 6%),0 2px 4px rgb(17 17 17 / 5%),0 40px 80px -26px rgb(46 34 62 / 40%);opacity:0;transform:translateY(28px) scale(.98);transition:opacity .35s ease,transform .6s var(--pj-ease);}
#gldpj .pj-card::before{content:"";position:absolute;inset:0;border-radius:inherit;pointer-events:none;background:linear-gradient(125deg,rgb(255 255 255 / 46%) 0%,rgb(255 255 255 / 10%) 36%,rgb(255 255 255 / 0%) 58%);}
#gldpj.in .pj-card{opacity:1;transform:none;}
#gldpj .pj-main,#gldpj .pj-done{position:relative;}
#gldpj .pj-x{position:absolute;top:14px;right:14px;z-index:3;display:flex;align-items:center;justify-content:center;width:36px;height:36px;min-height:0;margin:0;padding:0;border:0;border-radius:999px;background:var(--pj-wash);color:var(--pj-ink);box-shadow:none;cursor:pointer;transition:background-color .2s;}
#gldpj .pj-x:hover{background:rgb(17 17 17 / 9%);}
#gldpj .pj-x svg{width:15px;height:15px;fill:currentColor;}
#gldpj .pj-lockup{display:flex;align-items:center;gap:8px;margin:2px 0 16px;padding:0;}
#gldpj .pj-lockup b{font:800 17px/1 'Archivo','Arial Black',Arial,sans-serif;letter-spacing:.02em;color:var(--pj-ink);}
#gldpj .pj-lockup i{font:700 10.5px/1 'Inter',sans-serif;font-style:normal;letter-spacing:.06em;text-transform:uppercase;color:#fff;background:var(--pj-red);padding:4px 9px 3px;border-radius:999px;}
#gldpj h2{margin:0 0 6px;padding:0;font:800 32px/1.02 'Archivo','Arial Black',Arial,sans-serif;letter-spacing:-.025em;text-transform:none;color:var(--pj-ink);text-wrap:balance;}
#gldpj .pj-sub{margin:0 0 18px;padding:0;font:400 15px/1.45 'Inter',sans-serif;color:var(--pj-mute);}
#gldpj .pj-perks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 18px;padding:0;list-style:none;}
#gldpj .pj-perks li{display:flex;flex-direction:column;align-items:flex-start;gap:10px;margin:0;padding:12px 11px 13px;border-radius:16px;background:rgb(255 255 255 / 64%);border:1px solid var(--pj-line);font:600 12.5px/1.3 'Inter',sans-serif;color:var(--pj-ink);list-style:none;}
#gldpj .pj-perks svg{width:22px;height:22px;fill:currentColor;flex:none;}
#gldpj .pj-f{margin:0;padding:0;border:0;background:none;}
#gldpj .pj-field{position:relative;margin:0;}
#gldpj .pj-field>svg{position:absolute;left:18px;top:50%;z-index:1;width:20px;height:20px;margin-top:-10px;fill:var(--pj-faint);pointer-events:none;transition:fill .2s;}
#gldpj .pj-field:focus-within>svg{fill:var(--pj-ink);}
#gldpj input.pj-email{display:block;width:100%;height:58px;margin:0;padding:0 128px 0 48px;border:1px solid;border-color:rgb(17 17 17 / 10%) !important;border-radius:999px !important;background:#fff;color:var(--pj-ink);font:400 16px/1 'Inter',sans-serif;letter-spacing:0;box-shadow:0 1px 2px rgb(17 17 17 / 4%) !important;outline:none;-webkit-appearance:none;appearance:none;transition:border-color .2s,box-shadow .2s;}
#gldpj input.pj-email::placeholder{color:var(--pj-faint);opacity:1;}
#gldpj input.pj-email:hover{border-color:rgb(17 17 17 / 18%) !important;}
#gldpj input.pj-email:focus{border-color:var(--pj-ink) !important;box-shadow:0 0 0 4px rgb(17 17 17 / 8%) !important;}
#gldpj .pj-go,#gldpj .pj-ok{display:flex;align-items:center;justify-content:center;min-height:0;margin:0;border:0;border-radius:999px;background:var(--pj-ink);color:#fff;font:700 15px/1 'Archivo','Arial Black',Arial,sans-serif;letter-spacing:.01em;text-transform:none;white-space:nowrap;box-shadow:0 8px 20px -10px rgb(17 17 17 / 60%);cursor:pointer;transition:transform .25s var(--pj-ease),background-color .2s;}
#gldpj .pj-go{position:absolute;top:6px;right:6px;height:46px;padding:0 20px;}
#gldpj .pj-go:hover,#gldpj .pj-ok:hover{background:#2b2b2b;color:#fff;}
#gldpj .pj-go:active,#gldpj .pj-ok:active{transform:scale(.97);}
#gldpj .pj-go:disabled{opacity:.6;cursor:default;transform:none;}
#gldpj .pj-hp{position:absolute;left:-9999px;width:0;height:0;opacity:0;}
#gldpj .pj-optin{display:flex;align-items:flex-start;gap:10px;margin:14px 0 0;padding:0 6px;font:400 12.5px/1.45 'Inter',sans-serif;letter-spacing:0;text-transform:none;color:var(--pj-mute);text-wrap:pretty;cursor:pointer;}
#gldpj .pj-optin input{flex:none;width:18px;height:18px;margin:0;padding:0;border:1px solid rgb(17 17 17 / 28%);border-radius:6px;background:#fff center/12px no-repeat;-webkit-appearance:none;appearance:none;cursor:pointer;transition:background-color .15s,border-color .15s;}
#gldpj .pj-optin input:checked{background-color:var(--pj-ink);border-color:var(--pj-ink);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 256 256'%3E%3Cpath fill='%23fff' d='M232.49,80.49l-128,128a12,12,0,0,1-17,0l-56-56a12,12,0,1,1,17-17L96,183,215.51,63.51a12,12,0,0,1,17,17Z'/%3E%3C/svg%3E");}
#gldpj .pj-err{margin:10px 6px 0;padding:0;font:600 13px/1.4 'Inter',sans-serif;color:#C4001F;}
#gldpj .pj-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:16px 0 0;padding:14px 6px 0;border-top:1px solid var(--pj-line);}
#gldpj .pj-note{margin:0;padding:0;font:400 12px/1.45 'Inter',sans-serif;color:var(--pj-faint);text-wrap:pretty;}
#gldpj .pj-no{flex:none;min-height:0;margin:0;padding:6px 0;border:0;background:none;box-shadow:none;font:600 12.5px/1 'Inter',sans-serif;letter-spacing:0;text-transform:none;color:var(--pj-mute);text-decoration:underline;text-decoration-color:rgb(17 17 17 / 25%);text-underline-offset:3px;cursor:pointer;}
#gldpj .pj-no:hover{color:var(--pj-ink);}
#gldpj :is(.pj-x,.pj-go,.pj-no,.pj-ok,.pj-optin input):focus-visible{outline:2px solid var(--pj-ink);outline-offset:2px;}
#gldpj .pj-done{display:none;padding:2px 0 8px;}
#gldpj.done .pj-main{display:none;}
#gldpj.done .pj-done{display:block;}
#gldpj .pj-done h3{margin:4px 0 10px;padding:0;font:800 32px/1.02 'Archivo','Arial Black',Arial,sans-serif;letter-spacing:-.025em;text-transform:none;color:var(--pj-ink);outline:none;}
#gldpj .pj-done p{margin:0 0 8px;padding:0;font:400 15px/1.5 'Inter',sans-serif;color:var(--pj-ink-2);text-wrap:pretty;}
#gldpj .pj-done p.pj-to{display:flex;align-items:center;gap:8px;width:fit-content;max-width:100%;margin:12px 0 14px;padding:9px 16px 9px 12px;border-radius:999px;background:rgb(255 255 255 / 72%);border:1px solid var(--pj-line);font:600 14.5px/1.3 'Inter',sans-serif;color:var(--pj-ink);}
#gldpj .pj-to svg{flex:none;width:18px;height:18px;fill:var(--pj-mute);}
#gldpj .pj-to strong{min-width:0;font-weight:600;overflow-wrap:anywhere;}
#gldpj .pj-nw{white-space:nowrap;}
#gldpj .pj-done p.pj-small{font-size:13px;color:var(--pj-mute);}
#gldpj .pj-done .pj-back,#gldpj .pj-done .pj-link,#gldpj.back .pj-done .pj-new,#gldpj.link .pj-done .pj-new{display:none;}
#gldpj.back .pj-done h3 .pj-back,#gldpj.link .pj-done h3 .pj-link{display:inline;}
#gldpj.back .pj-done p.pj-back,#gldpj.link .pj-done p.pj-link{display:block;}
#gldpj .pj-ok{width:100%;height:52px;margin:18px 0 0;padding:0 24px;font-size:16px;}
#gldpj.done .pj-bud1{animation:pj-hop .7s var(--pj-pop) .05s;}
#gldpj.done .pj-bud2{animation:pj-hop .7s var(--pj-pop) .16s;}
#gldpj.done .pj-coin{animation:pj-flip .9s var(--pj-ease) .1s;}
@keyframes pj-hop{0%,100%{transform:none;}40%{transform:translateY(-18px);}}
@keyframes pj-flip{0%{transform:rotate(-14deg);}50%{transform:translateY(-22px) rotate(-14deg) scaleX(-1);}100%{transform:rotate(-14deg);}}
#gldpj-min{position:fixed;right:18px;left:auto;top:calc(50% - 27px);bottom:auto;z-index:999998;width:54px;height:54px;min-height:0;margin:0;padding:0;align-items:center;justify-content:center;border:1px solid rgb(255 255 255 / 80%);border-radius:50%;background:rgb(255 255 255 / 80%);-webkit-backdrop-filter:blur(20px) saturate(180%);backdrop-filter:blur(20px) saturate(180%);box-shadow:inset 0 1px 0 rgb(255 255 255 / 90%),0 0 0 1px rgb(17 17 17 / 6%),0 12px 26px -10px rgb(17 17 17 / 38%);cursor:pointer;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-tap-highlight-color:transparent;transition:transform .2s cubic-bezier(.16,1,.3,1);}
#gldpj-min:hover{transform:scale(1.06);}
#gldpj-min img{display:block;width:30px;height:auto;margin:0 auto;}
#gldpj-min .pj-mx{position:absolute;top:-6px;left:-6px;width:20px;height:20px;border:2px solid #fff;border-radius:50%;background:#111111;color:#fff;font:700 12px/16px Arial,sans-serif;text-align:center;box-shadow:0 2px 6px rgb(17 17 17 / 30%);}
@media (max-width:640px){
#gldpj{padding:12px 10px calc(10px + env(safe-area-inset-bottom, 0px));}
#gldpj .pj-wrap{max-width:none;margin:auto 0 0;padding-top:106px;}
#gldpj .pj-buds{right:52px;transform:scale(.92);transform-origin:100% 0;}
#gldpj .pj-card{padding:22px 18px 14px;transform:translateY(56px);}
#gldpj h2,#gldpj .pj-done h3{font-size:28px;}
#gldpj .pj-sub{font-size:14.5px;margin-bottom:16px;}
#gldpj .pj-perks{gap:6px;margin-bottom:16px;}
#gldpj .pj-perks li{gap:8px;padding:10px 10px 11px;font-size:12px;border-radius:14px;}
#gldpj .pj-perks svg{width:20px;height:20px;}
#gldpj .pj-go{padding:0 16px;}
#gldpj input.pj-email{padding-right:112px;}
#gldpj-min{right:12px;top:calc(50% - 25px);width:50px;height:50px;}
}
@media (prefers-reduced-motion:reduce){
#gldpj .pj-card,#gldpj .pj-bud,#gldpj .pj-coin,#gldpj .pj-scrim{transform:none !important;transition:opacity .2s ease !important;animation:none !important;}
#gldpj.in .pj-coin{transform:rotate(-14deg) !important;}
}
@media (prefers-reduced-transparency:reduce){
#gldpj .pj-card{background:#fff;-webkit-backdrop-filter:none;backdrop-filter:none;}
#gldpj .pj-scrim{background:rgb(241 240 238 / 94%);-webkit-backdrop-filter:none;backdrop-filter:none;}
#gldpj-min{background:#fff;-webkit-backdrop-filter:none;backdrop-filter:none;}
}
@supports not ((-webkit-backdrop-filter:blur(1px)) or (backdrop-filter:blur(1px))){
#gldpj .pj-card{background:rgb(255 255 255 / 97%);}
#gldpj .pj-scrim{background:rgb(241 240 238 / 90%);}
#gldpj-min{background:#fff;}
}
</style>
<div class="pj-scrim"></div>
<div class="pj-wrap">
<div class="pj-buds" aria-hidden="true">
<img class="pj-bud pj-bud1" src="<?php echo esc_url($club . 'avatar-girl-outfit-casual-denim-cut.png'); ?>" alt="" width="522" height="700" loading="lazy" decoding="async"/>
<img class="pj-bud pj-bud2" src="<?php echo esc_url($club . 'avatar-boy-outfit-casual-denim-cut.png'); ?>" alt="" width="522" height="700" loading="lazy" decoding="async"/>
<img class="pj-coin" src="<?php echo esc_url($club . 'coin.png'); ?>" alt="" width="220" height="198" loading="lazy" decoding="async"/>
</div>
<div class="pj-card">
<button type="button" class="pj-x" aria-label="Close"><svg viewBox="0 0 256 256" aria-hidden="true"><path d="M208.49,191.51a12,12,0,0,1-17,17L128,145,64.49,208.49a12,12,0,0,1-17-17L111,128,47.51,64.49a12,12,0,0,1,17-17L128,111l63.51-63.52a12,12,0,0,1,17,17L145,128Z"/></svg></button>
<div class="pj-main">
<p class="pj-lockup"><b>GALADO</b><i>Club</i></p>
<h2 id="gldpj-title">Join the Club</h2>
<p class="pj-sub">Free for life. Your email is all it takes.</p>
<ul class="pj-perks">
<li><svg viewBox="0 0 256 256" aria-hidden="true"><path d="M184,89.57V84c0-25.08-37.83-44-88-44S8,58.92,8,84v40c0,20.89,26.25,37.49,64,42.46V172c0,25.08,37.83,44,88,44s88-18.92,88-44V132C248,111.3,222.58,94.68,184,89.57ZM232,132c0,13.22-30.79,28-72,28-3.73,0-7.43-.13-11.08-.37C170.49,151.77,184,139,184,124V105.74C213.87,110.19,232,122.27,232,132ZM72,150.25V126.46A183.74,183.74,0,0,0,96,128a183.74,183.74,0,0,0,24-1.54v23.79A163,163,0,0,1,96,152,163,163,0,0,1,72,150.25Zm96-40.32V124c0,8.39-12.41,17.4-32,22.87V123.5C148.91,120.37,159.84,115.71,168,109.93ZM96,56c41.21,0,72,14.78,72,28s-30.79,28-72,28S24,97.22,24,84,54.79,56,96,56ZM24,124V109.93c8.16,5.78,19.09,10.44,32,13.57v23.37C36.41,141.4,24,132.39,24,124Zm64,48v-4.17c2.63.1,5.29.17,8,.17,3.88,0,7.67-.13,11.39-.35A121.92,121.92,0,0,0,120,171.41v23.46C100.41,189.4,88,180.39,88,172Zm48,26.25V174.4a179.48,179.48,0,0,0,24,1.6,183.74,183.74,0,0,0,24-1.54v23.79a165.45,165.45,0,0,1-48,0Zm64-3.38V171.5c12.91-3.13,23.84-7.79,32-13.57V172C232,180.39,219.59,189.4,200,194.87Z"/></svg>Rewards on every order</li>
<li><svg viewBox="0 0 256 256" aria-hidden="true"><path d="M225.86,102.82c-3.77-3.94-7.67-8-9.14-11.57-1.36-3.27-1.44-8.69-1.52-13.94-.15-9.76-.31-20.82-8-28.51s-18.75-7.85-28.51-8c-5.25-.08-10.67-.16-13.94-1.52-3.56-1.47-7.63-5.37-11.57-9.14C146.28,23.51,138.44,16,128,16s-18.27,7.51-25.18,14.14c-3.94,3.77-8,7.67-11.57,9.14C88,40.64,82.56,40.72,77.31,40.8c-9.76.15-20.82.31-28.51,8S41,67.55,40.8,77.31c-.08,5.25-.16,10.67-1.52,13.94-1.47,3.56-5.37,7.63-9.14,11.57C23.51,109.73,16,117.56,16,128s7.51,18.27,14.14,25.18c3.77,3.94,7.67,8,9.14,11.57,1.36,3.27,1.44,8.69,1.52,13.94.15,9.76.31,20.82,8,28.51s18.75,7.85,28.51,8c5.25.08,10.67.16,13.94,1.52,3.56,1.47,7.63,5.37,11.57,9.14C109.72,232.49,117.56,240,128,240s18.27-7.51,25.18-14.14c3.94-3.77,8-7.67,11.57-9.14,3.27-1.36,8.69-1.44,13.94-1.52,9.76-.15,20.82-.31,28.51-8s7.85-18.75,8-28.51c.08-5.25.16-10.67,1.52-13.94,1.47-3.56,5.37-7.63,9.14-11.57C232.49,146.27,240,138.44,240,128S232.49,109.73,225.86,102.82Zm-11.55,39.29c-4.79,5-9.75,10.17-12.38,16.52-2.52,6.1-2.63,13.07-2.73,19.82-.1,7-.21,14.33-3.32,17.43s-10.39,3.22-17.43,3.32c-6.75.1-13.72.21-19.82,2.73-6.35,2.63-11.52,7.59-16.52,12.38S132,224,128,224s-9.15-4.92-14.11-9.69-10.17-9.75-16.52-12.38c-6.1-2.52-13.07-2.63-19.82-2.73-7-.1-14.33-.21-17.43-3.32s-3.22-10.39-3.32-17.43c-.1-6.75-.21-13.72-2.73-19.82-2.63-6.35-7.59-11.52-12.38-16.52S32,132,32,128s4.92-9.14,9.69-14.11,9.75-10.17,12.38-16.52c2.52-6.1,2.63-13.07,2.73-19.82.1-7,.21-14.33,3.32-17.43S70.51,56.9,77.55,56.8c6.75-.1,13.72-.21,19.82-2.73,6.35-2.63,11.52-7.59,16.52-12.38S124,32,128,32s9.15,4.92,14.11,9.69,10.17,9.75,16.52,12.38c6.1,2.52,13.07,2.63,19.82,2.73,7,.1,14.33.21,17.43,3.32s3.22,10.39,3.32,17.43c.1,6.75.21,13.72,2.73,19.82,2.63,6.35,7.59,11.52,12.38,16.52S224,124,224,128,219.08,137.14,214.31,142.11ZM120,96a24,24,0,1,0-24,24A24,24,0,0,0,120,96ZM88,96a8,8,0,1,1,8,8A8,8,0,0,1,88,96Zm72,40a24,24,0,1,0,24,24A24,24,0,0,0,160,136Zm0,32a8,8,0,1,1,8-8A8,8,0,0,1,160,168Zm13.66-74.34-80,80a8,8,0,0,1-11.32-11.32l80-80a8,8,0,0,1,11.32,11.32Z"/></svg>Up to 15% off for members</li>
<li><svg viewBox="0 0 256 256" aria-hidden="true"><path d="M216,72H180.92c.39-.33.79-.65,1.17-1A29.53,29.53,0,0,0,192,49.57,32.62,32.62,0,0,0,158.44,16,29.53,29.53,0,0,0,137,25.91a54.94,54.94,0,0,0-9,14.48,54.94,54.94,0,0,0-9-14.48A29.53,29.53,0,0,0,97.56,16,32.62,32.62,0,0,0,64,49.57,29.53,29.53,0,0,0,73.91,71c.38.33.78.65,1.17,1H40A16,16,0,0,0,24,88v32a16,16,0,0,0,16,16v64a16,16,0,0,0,16,16H200a16,16,0,0,0,16-16V136a16,16,0,0,0,16-16V88A16,16,0,0,0,216,72ZM149,36.51a13.69,13.69,0,0,1,10-4.5h.49A16.62,16.62,0,0,1,176,49.08a13.69,13.69,0,0,1-4.5,10c-9.49,8.4-25.24,11.36-35,12.4C137.7,60.89,141,45.5,149,36.51Zm-64.09.36A16.63,16.63,0,0,1,96.59,32h.49a13.69,13.69,0,0,1,10,4.5c8.39,9.48,11.35,25.2,12.39,34.92-9.72-1-25.44-4-34.92-12.39a13.69,13.69,0,0,1-4.5-10A16.6,16.6,0,0,1,84.87,36.87ZM40,88h80v32H40Zm16,48h64v64H56Zm144,64H136V136h64Zm16-80H136V88h80v32Z"/></svg>Birthday treats &amp; surprises</li>
</ul>
<form class="pj-f" novalidate>
<div class="pj-field">
<svg viewBox="0 0 256 256" aria-hidden="true"><path d="M224,48H32a8,8,0,0,0-8,8V192a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A8,8,0,0,0,224,48ZM203.43,64,128,133.15,52.57,64ZM216,192H40V74.19l82.59,75.71a8,8,0,0,0,10.82,0L216,74.19V192Z"/></svg>
<input class="pj-email" type="email" name="email" placeholder="Your email" aria-label="Email" maxlength="254" required autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false"/>
<button type="submit" class="pj-go">I&#8217;m in &#10022;</button>
</div>
<input type="text" name="website" class="pj-hp" tabindex="-1" autocomplete="off" aria-hidden="true"/>
<?php if (function_exists('galado_newsletter_is_live') && galado_newsletter_is_live()) : ?>
<label class="pj-optin"><input type="checkbox" name="optin" checked/><span><?php echo esc_html(self::POPUP_OPTIN_NOTICE); ?></span></label>
<?php endif; ?>
<p class="pj-err" role="alert" hidden></p>
</form>
<div class="pj-foot">
<p class="pj-note"><?php echo esc_html(self::POPUP_INSTANT_NOTICE); ?></p>
<button type="button" class="pj-no">No thanks</button>
</div>
</div>
<div class="pj-done">
<h3 tabindex="-1"><span class="pj-new">You&#8217;re in &#10022;</span><span class="pj-back">Welcome back &#10022;</span><span class="pj-link">Check your inbox &#10022;</span></h3>
<p class="pj-new">Welcome to GALADO Club. We&#8217;ve sent a link to your Club to</p>
<p class="pj-back">You&#8217;re already in the Club. We&#8217;ve sent a <span class="pj-nw">one-tap</span> <span class="pj-nw">sign-in</span> link to</p>
<p class="pj-link">One last tap: we&#8217;ve sent a link to finish joining to</p>
<p class="pj-to"><svg viewBox="0 0 256 256" aria-hidden="true"><path d="M224,48H32a8,8,0,0,0-8,8V192a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A8,8,0,0,0,224,48ZM203.43,64,128,133.15,52.57,64ZM216,192H40V74.19l82.59,75.71a8,8,0,0,0,10.82,0L216,74.19V192Z"/></svg><strong class="pj-mail"></strong></p>
<p class="pj-new pj-small">Shop with this email and every order earns you <span class="pj-nw">G-Coins</span>.</p>
<p class="pj-back pj-link pj-small">It works for 20 minutes. If it plays hide and seek, peek in spam.</p>
<button type="button" class="pj-ok">Keep shopping</button>
</div>
</div>
</div>
</div>
<button id="gldpj-min" type="button" aria-label="Join the GALADO Club" title="GALADO Club" style="display:none;">
<img src="<?php echo esc_url($club . 'coin.png'); ?>" alt="" loading="lazy"/>
<span class="pj-mx" title="Hide" aria-label="Hide the Club coin">&times;</span>
</button>
<script>
var GLDPJ_REST=<?php echo wp_json_encode($rest); ?>;
(function(){
if(window.__gldpjBooted)return;window.__gldpjBooted=1;
var KEY='gldpj_v1',PREVIEW=<?php echo $preview ? 'true' : 'false'; ?>,PASS=<?php echo wp_json_encode($pass); ?>,didJoin=false;
function boxes(){return [].slice.call(document.querySelectorAll('#gldpj'))}
function chips(){return [].slice.call(document.querySelectorAll('#gldpj-min'))}
if(!boxes().length)return;
var st=null;try{st=JSON.parse(localStorage.getItem(KEY)||'null')}catch(e){}
/* ?club_popup=new is the owner's preview: it opens whatever this browser said before */
if(PREVIEW)st=null;
if(st&&st.joined)return;
function save(o){try{localStorage.setItem(KEY,JSON.stringify(o))}catch(e){}}
function isOpen(){return boxes().some(function(b){return b.classList.contains('on')})}
/* X on the coin: hides it for the rest of the browsing session (it can cover the
   case-designer canvas); back next session so the acquisition surface survives. */
function chipClosed(){try{return sessionStorage.getItem('gldpj_chip_x')==='1'}catch(e){return false}}
function closeChip(){try{sessionStorage.setItem('gldpj_chip_x','1')}catch(e){}hideChip()}
function showChip(){if(chipClosed())return;chips().forEach(function(c){c.style.display='flex'})}
function hideChip(){chips().forEach(function(c){c.style.display='none'})}
/* The pictures load lazily; fetch them before the popup opens so the Buddies arrive with it. */
function warm(){boxes().forEach(function(b){[].slice.call(b.querySelectorAll('img')).forEach(function(i){var w=new Image();w.src=i.currentSrc||i.src})})}
function openAll(){boxes().forEach(function(b){b.classList.remove('done','back','link');b.classList.add('on');
requestAnimationFrame(function(){requestAnimationFrame(function(){b.classList.add('in')})})});hideChip()}
function hideAll(){boxes().forEach(function(b){b.classList.remove('in');setTimeout(function(){if(!b.classList.contains('in'))b.classList.remove('on')},260)})}
/* After a join the X and the backdrop just close it: no coin, no snooze over the joined flag. */
function closeAll(d){if(!isOpen())return;hideAll();if(didJoin)return;showChip();if(d)save({snooze:Date.now()+d*864e5})}
function joined(){didJoin=true;hideChip();save({joined:true})}
/* Submit telemetry. Same event name and shape as the blog card's (snippet #149) so the two
   surfaces are comparable side by side, distinguished by method. Fires ONLY after a successful
   response. NEVER carries the email address: this counts the submit, never who submitted. */
function track(optin){var p={method:'popup',join_source:'popup',optin:!!optin};
if(typeof window.gtag==='function'){window.gtag('event','club_join_submit',p)}
else if(window.dataLayer&&window.dataLayer.push){p.event='club_join_submit';window.dataLayer.push(p)}}
/* Minimised coin is draggable (pointer events = touch + mouse) so it never has to sit on a
   product page's sticky add-to-cart bar; the spot is remembered across pages. A plain tap still
   opens the popup; only a real drag (>6px) suppresses the click that follows it. */
var GLDPJ_DRAG_AT=0,PKEY='gldpj_pos_v1';
function clampPos(pos,c){var w=c.offsetWidth||52,h=c.offsetHeight||52,M=8;
return{x:Math.min(Math.max(pos.x,M),(window.innerWidth||360)-w-M),
y:Math.min(Math.max(pos.y,M),(window.innerHeight||640)-h-M)}}
function applyPos(){var p=null;try{p=JSON.parse(localStorage.getItem(PKEY)||'null')}catch(e){}
if(!p||typeof p.x!=='number'||typeof p.y!=='number')return;
chips().forEach(function(c){var q=clampPos(p,c);
c.style.left=q.x+'px';c.style.top=q.y+'px';c.style.bottom='auto';c.style.right='auto'})}
if(window.PointerEvent)chips().forEach(function(c){
var pid=null,sx=0,sy=0,ox=0,oy=0,moved=false;
c.addEventListener('pointerdown',function(e){
if(e.button&&e.button!==0)return;
if(e.target&&e.target.closest&&e.target.closest('.pj-mx'))return; /* X badge: no drag or pointer capture, so its click still closes the coin */
pid=e.pointerId;moved=false;sx=e.clientX;sy=e.clientY;
var r=c.getBoundingClientRect();ox=r.left;oy=r.top;
try{c.setPointerCapture(pid)}catch(err){}});
c.addEventListener('pointermove',function(e){
if(pid===null||e.pointerId!==pid)return;
var dx=e.clientX-sx,dy=e.clientY-sy;
if(!moved&&(dx*dx+dy*dy)<36)return;
moved=true;
var q=clampPos({x:ox+dx,y:oy+dy},c);
c.style.left=q.x+'px';c.style.top=q.y+'px';c.style.bottom='auto';c.style.right='auto'});
function endDrag(e){
if(pid===null||(e.pointerId!==undefined&&e.pointerId!==pid))return;
pid=null;
if(moved){GLDPJ_DRAG_AT=Date.now();
var r=c.getBoundingClientRect();
try{localStorage.setItem(PKEY,JSON.stringify({x:r.left,y:r.top}))}catch(err){}}}
c.addEventListener('pointerup',endDrag);
c.addEventListener('pointercancel',endDrag);});
window.addEventListener('resize',applyPos);
applyPos();
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAll(7)});
document.addEventListener('click',function(e){
var t=e.target;if(!t||!t.closest)return;
if(t.closest('#gldpj-min .pj-mx')){e.preventDefault();e.stopPropagation();closeChip();return}
if(t.closest('#gldpj-min')){if(Date.now()-GLDPJ_DRAG_AT<400)return;warm();openAll();return}
if(!isOpen())return;
if(t.closest('#gldpj .pj-x')){closeAll(7);return}
if(t.closest('#gldpj .pj-no')){closeAll(14);return}
if(t.closest('#gldpj .pj-ok')){hideAll();return}
},true);
document.addEventListener('submit',function(e){
var f=e.target;if(!f||!f.classList||!f.classList.contains('pj-f'))return;
e.preventDefault();
var box=f.closest('#gldpj')||document,err=f.querySelector('.pj-err'),btn=f.querySelector('.pj-go'),label=btn.innerHTML;
var email=(f.querySelector('input[name=email]').value||'').trim();
var hp=(f.querySelector('input[name=website]').value||'');
var ck=f.querySelector('input[name=optin]');
function fail(m){err.textContent=m;err.hidden=false;btn.disabled=false;btn.innerHTML=label}
if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){fail('Please enter a valid email.');return}
err.hidden=true;btn.disabled=true;btn.textContent='Joining…';
fetch(GLDPJ_REST,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:email,website:hp,optin:ck?ck.checked:false,instant:1,pv:PASS})})
.then(function(r){return r.json().then(function(b){return{ok:r.ok,body:b}})})
.then(function(r){
if(!r.ok){fail((r.body&&r.body.message)||'Something went sideways. Please try again.');return}
[].slice.call(box.querySelectorAll('.pj-mail')).forEach(function(m){m.textContent=email});
box.classList.toggle('back',!!(r.body&&r.body.returning));box.classList.toggle('link',!!(r.body&&r.body.link));box.classList.add('done');
var h=box.querySelector('.pj-done h3');if(h)h.focus({preventScroll:true});
btn.disabled=false;btn.innerHTML=label;
joined();track(ck&&ck.checked)})
.catch(function(){fail('Could not reach us. Please try again.')});
},true);
/* A tap on the backdrop closes it. iOS Safari sends no click up to the document for a tap on a plain
   element, so the backdrop pieces listen themselves. */
boxes().forEach(function(b){[b,b.querySelector('.pj-scrim'),b.querySelector('.pj-wrap')].forEach(function(el){
if(el)el.addEventListener('click',function(e){if(e.target===el&&isOpen())closeAll(7)})})});
/* The pictures are fetched just before the popup opens, never during the page's own load. */
function warmSoon(ms){setTimeout(function(){if(window.requestIdleCallback){requestIdleCallback(warm,{timeout:1500})}else{warm()}},ms)}
if(PREVIEW){warm();setTimeout(openAll,1200)}
else if(st&&st.snooze&&Date.now()<st.snooze){showChip()}
else{warmSoon(4500);setTimeout(openAll,7000)}
})();
</script>
<?php
    }

    /**
     * Tier ladder, thresholds in RM of lifetime spend. Mirrors TIER_MINS in the Club
     * (web/src/lib/tiers.ts) and the iOS cart card, which is the design this follows.
     */
    private static function tier_ladder() {
        return [
            ['key' => 'silver',  'name' => 'Silver',        'min' => 0,    'colour' => '#9AA7B5', 'off' => 0],
            ['key' => 'gold',    'name' => 'Gold',          'min' => 500,  'colour' => '#E9A93D', 'off' => 5],
            ['key' => 'diamond', 'name' => 'Diamond',       'min' => 1000, 'colour' => '#6FC7E8', 'off' => 10],
            ['key' => 'black',   'name' => 'GALADO Black',  'min' => 2000, 'colour' => '#2E2630', 'off' => 15],
        ];
    }

    /** Perks unlocked AT each tier. Everything below carries upward (see 'inherits'). */
    private static function tier_perks() {
        return [
            'silver' => [
                'inherits' => '',
                'items'    => [
                    'Free shipping across Malaysia, every order',
                    '6-month warranty on every case',
                    'Buddy locker, shop and dressing room',
                    'Daily Arcade, quizzes and G-Coin games',
                    'Collector titles, badges and Mystery Boxes',
                ],
            ],
            'gold' => [
                'inherits' => 'Everything in Silver',
                'items'    => ['1.2× G-Coins on every order', 'Your 5% off works online, in the app and in store'],
            ],
            'diamond' => [
                'inherits' => 'Everything in Silver and Gold',
                'items'    => ['1.5× G-Coins on every order', 'Your 10% off works online, in the app and in store'],
            ],
            'black' => [
                'inherits' => 'Everything in Silver, Gold and Diamond',
                'items'    => [
                    '2× G-Coins on every order',
                    'Your 15% off works online, in the app and in store',
                    '12-month warranty, double the standard 6 months',
                    'Dark mode across the whole Club',
                ],
            ],
        ];
    }

    /**
     * The member's own tier standing. Session-scoped on purpose: it reads the logged-in
     * user and takes no email, so one member can never ask for another's figures.
     *
     * Deliberately a separate request rather than part of the cart render: the Club call
     * can be slow, and the cart page must not wait on it to paint.
     */
    public static function my_tier() {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return ['ok' => false];
        }
        $summary = self::fetch_summary($user->user_email, $user->ID);
        if (!is_array($summary) || !isset($summary['lifetimeSpend'])) {
            // No lifetime figure means no honest bar to draw, so the block stays hidden
            // rather than showing a plausible but invented position.
            return ['ok' => false];
        }
        // The basket is deliberately NOT read here. wc_load_cart() in a REST request yields an
        // EMPTY cart rather than the shopper's, so this returned a confident RM0 while their
        // basket held RM134 (owner, 2026-08-05). The page reads the basket from the totals row
        // WooCommerce itself rendered, which is the same number the shopper is looking at.
        return [
            'ok'       => true,
            'tier'     => (string) ($summary['tier'] ?? 'silver'),
            'lifetime' => (float) $summary['lifetimeSpend'],
        ];
    }

    /**
     * Cart-page tier meter for signed-in members: where this order lands them on the
     * ladder. Ports the iOS cart projection card (App/Features/Shop/CartView.swift).
     *
     * Visible to every signed-in member. Guests get nothing at all: this returns before any
     * markup is emitted, so a logged-out cart is exactly the page it was before this existed.
     * `galado_tier_meter_public` can be filtered to false to fall back to administrators only.
     */
    public static function render_tier_meter() {
        if (!is_user_logged_in()) {
            return;
        }
        // Open to every signed-in member since 2026-08-05 (owner). The filter stays so it can be
        // turned off again without a deploy: return false and it falls back to administrators only.
        if (!apply_filters('galado_tier_meter_public', true) && !current_user_can('manage_woocommerce')) {
            return;
        }
        // SPEC-4 bug 6: the basket is in the shopper's currency, the ladder and lifetime spend in RM.
        // The page converts the basket at CURCY's straight rate; without one there is no honest bar.
        $rate = self::current_rate();
        if (!$rate) {
            return;
        }
        // This hook runs on the cart page, where the cart is genuinely loaded, so the basket
        // total taken here is authoritative for the first paint.
        $cart_total = (function_exists('WC') && WC()->cart) ? (float) WC()->cart->get_total('edit') : 0.0;
        $cfg = [
            'rest'   => esc_url_raw(rest_url('galado-club/v1/my-tier')),
            'nonce'  => wp_create_nonce('wp_rest'),
            'order'  => $cart_total,
            'rate'   => $rate,
            'dec'    => function_exists('wc_get_price_decimal_separator') ? wc_get_price_decimal_separator() : '.',
            'sep'    => function_exists('wc_get_price_thousand_separator') ? wc_get_price_thousand_separator() : ',',
            // Within this many RM of the next tier the headline switches to the near-miss
            // wording. Filterable so the distance can be tuned without a deploy: too wide and
            // "so close" stops meaning anything, too narrow and almost nobody sees it.
            'near'   => (float) apply_filters('galado_tier_near_miss_rm', 50),
            'ladder' => self::tier_ladder(),
            'perks'  => self::tier_perks(),
        ];
        ?>
<div id="gld-tier" class="gld-tier" hidden>
  <div class="gld-tier__head">
    <span class="gld-tier__mark" aria-hidden="true"></span>
    <p class="gld-tier__headline"></p>
  </div>
  <div class="gld-tier__barrow">
    <div class="gld-tier__meter">
      <div class="gld-tier__track"><div class="gld-tier__dots"></div></div>
      <div class="gld-tier__labels"></div>
    </div>
    <button type="button" class="gld-tier__info" aria-expanded="false" aria-controls="gld-tier-perks">
      <span aria-hidden="true">i</span><span class="screen-reader-text">What each tier gets you</span>
    </button>
  </div>
  <p class="gld-tier__foot"></p>
  <div class="gld-tier__perks" id="gld-tier-perks" hidden></div>
</div>
<style>
.gld-tier{position:relative;margin:0 0 24px;padding:16px 18px 14px;border:1px solid #E7E7E9;border-radius:14px;background:#fff;color:#111}
.gld-tier__head{display:flex;align-items:flex-start;gap:8px;margin-bottom:12px}
.gld-tier__mark{flex:0 0 auto;width:16px;height:16px;margin-top:2px;border-radius:50%;background:#E4002B}
.gld-tier__headline{margin:0;font-size:14.5px;line-height:1.35;font-weight:700;letter-spacing:-.01em}
.gld-tier__barrow{display:flex;align-items:flex-start;gap:12px}
.gld-tier__meter{flex:1 1 auto;min-width:0}
.gld-tier__track{position:relative;height:8px;border-radius:99px;background:#EDEDEF}
.gld-tier__fill{position:absolute;top:0;left:0;height:8px;border-radius:99px;width:0;transition:width .9s cubic-bezier(.16,1,.3,1)}
.gld-tier__fill--order{background:rgba(228,0,43,.55)}
.gld-tier__dots{position:absolute;inset:0}
.gld-tier__dot{position:absolute;top:50%;width:12px;height:12px;margin:-6px 0 0 -6px;border-radius:50%;background:#EDEDEF;box-shadow:0 0 0 2px #fff;transform:scale(.82);transition:background .4s ease,transform .4s ease}
.gld-tier__dot.is-on{transform:scale(1)}
.gld-tier__labels{position:relative;height:29px;margin:10px 0 0}
.gld-tier__lab{position:absolute;top:0;display:flex;flex-direction:column;gap:1px;text-align:center;transform:translateX(-50%);white-space:nowrap}
.gld-tier__lab:first-child{transform:none;text-align:left}
.gld-tier__lab:last-child{transform:translateX(-100%);text-align:right}
.gld-tier__lab b{font-size:11.5px;font-weight:600;color:#6B6B73;letter-spacing:-.01em}
.gld-tier__lab.is-here b{font-weight:800;color:#111}
.gld-tier__lab span{font-size:10px;color:#9A9AA2;font-variant-numeric:tabular-nums}
.gld-tier__foot{margin:10px 0 0;font-size:11.5px;color:#6B6B73;font-variant-numeric:tabular-nums}
/* The theme uppercases button text and gives buttons a min-width, which turned this into a
   serif capital I in an oval. Every one of those is overridden explicitly. */
.gld-tier__info{flex:0 0 22px;box-sizing:border-box;margin-top:-7px;width:22px;height:22px;min-width:22px;min-height:22px;
  padding:0;border:1px solid #E2E2E6;border-radius:50%;background:#fff;color:#A8A8B0;
  font-family:inherit;font-size:12px;font-weight:600;font-style:italic;line-height:20px;letter-spacing:0;
  text-transform:none;text-align:center;cursor:pointer;box-shadow:none;
  transition:border-color .2s ease,color .2s ease}
.gld-tier__info:hover,.gld-tier__info:focus-visible{border-color:#B4B4BC;color:#57575E;background:#fff}
.gld-tier__info:focus-visible{outline:2px solid #E4002B;outline-offset:2px}
/* Opens DOWNWARD: the meter sits at the top of the basket, so there is never room above it.
   Capped and scrollable so a long ladder cannot run off a short phone screen. */
.gld-tier__perks{position:absolute;right:14px;top:calc(100% - 6px);z-index:30;width:min(310px,calc(100vw - 48px));
  max-height:min(62vh,440px);overflow-y:auto;-webkit-overflow-scrolling:touch;
  padding:14px 16px;border:1px solid #E7E7E9;border-radius:14px;background:#fff;box-shadow:0 18px 40px rgba(17,17,17,.16);text-align:left}
.gld-tier__perks[hidden]{display:none}
.gld-tier__ptitle{margin:0 0 10px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#9A9AA2}
.gld-tier__grp{padding:9px 0;border-top:1px solid #F0F0F2}
.gld-tier__grp:first-of-type{border-top:0;padding-top:0}
.gld-tier__ghead{display:flex;align-items:center;gap:7px;margin-bottom:5px}
.gld-tier__gdot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
.gld-tier__gname{font-size:12.5px;font-weight:700;color:#111;letter-spacing:-.01em}
.gld-tier__goff{margin-left:auto;font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px;background:#F2F2F4;color:#4A4A52;white-space:nowrap}
.gld-tier__grp.is-here .gld-tier__goff{background:#E4002B;color:#fff}
.gld-tier__gin{margin:0 0 4px;font-size:11px;color:#9A9AA2;font-style:italic}
.gld-tier__perks ul{margin:0;padding:0;list-style:none}
.gld-tier__perks li{position:relative;padding-left:13px;margin:0 0 3px;font-size:11.5px;line-height:1.45;color:#4A4A52}
.gld-tier__perks li:before{content:"";position:absolute;left:0;top:7px;width:4px;height:4px;border-radius:50%;background:#C9C9CF}
@media (max-width:600px){
  .gld-tier{padding:14px 15px 12px}
  .gld-tier__perks{right:0;left:0;width:auto}
}
@media (prefers-reduced-motion:reduce){
  .gld-tier__fill,.gld-tier__dot{transition:none}
}
</style>
<script>
window.GLD_TIER = <?php echo wp_json_encode($cfg); ?>;
(function () {
  var CFG = window.GLD_TIER || {}, box = document.getElementById('gld-tier');
  if (!box || !CFG.rest) return;
  var ladder = CFG.ladder || [], cap = ladder.length ? ladder[ladder.length - 1].min : 1;

  function rm(n, dp) {
    var parts = (+n || 0).toFixed(dp || 0).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return 'RM' + parts.join('.');
  }
  function tierAt(spend) { var t = ladder[0]; ladder.forEach(function (x) { if (spend >= x.min) t = x; }); return t; }

  // Built once; only the numbers move afterwards.
  function scaffold() {
    var track = box.querySelector('.gld-tier__track'), dots = box.querySelector('.gld-tier__dots');
    var labs = box.querySelector('.gld-tier__labels');
    var order = document.createElement('div'); order.className = 'gld-tier__fill gld-tier__fill--order';
    var life = document.createElement('div'); life.className = 'gld-tier__fill gld-tier__fill--life';
    track.insertBefore(order, dots); track.insertBefore(life, dots);
    ladder.forEach(function (t, i) {
      if (i > 0) {
        var d = document.createElement('span');
        d.className = 'gld-tier__dot'; d.dataset.key = t.key;
        d.style.left = (t.min / cap * 100) + '%';
        dots.appendChild(d);
      }
      var l = document.createElement('span');
      l.className = 'gld-tier__lab'; l.dataset.key = t.key;
      l.style.left = (t.min / cap * 100) + '%';
      l.innerHTML = '<b></b><span></span>';
      l.querySelector('b').textContent = t.name === 'GALADO Black' ? 'Black' : t.name;
      l.querySelector('span').textContent = t.min === 0 ? 'Join' : rm(t.min);
      labs.appendChild(l);
    });
    buildPerks();
  }

  function buildPerks() {
    var host = box.querySelector('.gld-tier__perks'), p = CFG.perks || {};
    var h = '<p class="gld-tier__ptitle">What each tier gets you</p>';
    ladder.forEach(function (t) {
      var d = p[t.key] || { items: [], inherits: '' };
      h += '<div class="gld-tier__grp" data-key="' + t.key + '">'
        + '<div class="gld-tier__ghead"><span class="gld-tier__gdot" style="background:' + t.colour + '"></span>'
        + '<span class="gld-tier__gname"></span>'
        + '<span class="gld-tier__goff">' + (t.off ? t.off + '% off' : 'Member') + '</span></div>';
      if (d.inherits) h += '<p class="gld-tier__gin"></p>';
      h += '<ul></ul></div>';
      host.insertAdjacentHTML('beforeend', h); h = '';
      var grp = host.lastElementChild;
      grp.querySelector('.gld-tier__gname').textContent = t.name;
      if (d.inherits) grp.querySelector('.gld-tier__gin').textContent = d.inherits;
      var ul = grp.querySelector('ul');
      (d.items || []).forEach(function (item) {
        var li = document.createElement('li'); li.textContent = item; ul.appendChild(li);
      });
    });
    if (!host.firstChild) host.innerHTML = '';
  }

  /**
   * The basket total, taken from the totals row WooCommerce rendered on this very page.
   * That is the same figure the shopper is reading, and it stays correct after a quantity
   * change because Woo re-renders that row itself. Falls back to the total captured server
   * side when the row is missing (a theme could rename it), and only then to zero.
   */
  function basketTotal() {
    var el = document.querySelector('.order-total .woocommerce-Price-amount')
          || document.querySelector('.order-total .amount');
    if (el) {
      var t = String(el.textContent || '');
      if (CFG.sep) { t = t.split(CFG.sep).join(''); }
      if (CFG.dec && CFG.dec !== '.') { t = t.split(CFG.dec).join('.'); }
      var n = parseFloat(t.replace(/[^0-9.]/g, ''));
      if (isFinite(n)) return n;
    }
    return +CFG.order || 0;
  }

  function paint(d) {
    // The basket is in the shopper's currency; the ladder and lifetime spend are RM.
    var order = basketTotal() / (+CFG.rate || 1);
    var life = +d.lifetime || 0, projected = life + order;
    var now = tierAt(life), next = null, reachedTier = tierAt(projected);
    ladder.forEach(function (t) { if (next === null && projected < t.min) next = t; });

    var head = box.querySelector('.gld-tier__headline'), mark = box.querySelector('.gld-tier__mark');
    if (reachedTier.min > now.min) {
      // Crossing a tier used to silence the near-miss line entirely, so a basket that
      // reached Gold and stopped RM27 short of Diamond said nothing about Diamond
      // (owner, 2026-08-05). Celebrate the crossing, then point at the next one when it
      // is genuinely within reach.
      var over = 'This order takes you to ' + reachedTier.name + '.';
      if (next) {
        var near = Math.ceil(next.min - projected);
        if (near <= (+CFG.near || 0)) {
          over += ' ' + next.name + ' is only ' + rm(near) + ' further.';
        }
      }
      head.textContent = over;
      mark.style.background = reachedTier.colour;
    } else if (next) {
      var gap = Math.ceil(next.min - projected);
      // Close enough to be worth naming as close. Wording only, deliberately: no badge, no
      // pulse, nothing that turns encouragement into pressure.
      head.textContent = (gap <= (+CFG.near || 0))
        ? 'So close. ' + rm(gap) + ' more after this order and ' + next.name + ' is yours.'
        : rm(gap) + ' more after this order to reach ' + next.name + '.';
      mark.style.background = '#E4002B';
    } else {
      head.textContent = "You're at GALADO Black, the top of the Club.";
      mark.style.background = reachedTier.colour;
    }

    box.querySelector('.gld-tier__fill--order').style.width = Math.min(projected / cap, 1) * 100 + '%';
    var lf = box.querySelector('.gld-tier__fill--life');
    lf.style.width = Math.min(life / cap, 1) * 100 + '%';
    lf.style.background = now.colour;

    ladder.forEach(function (t) {
      var dot = box.querySelector('.gld-tier__dot[data-key="' + t.key + '"]');
      if (dot) {
        var on = projected >= t.min;
        dot.classList.toggle('is-on', on);
        dot.style.background = on ? t.colour : '#EDEDEF';
      }
      var lab = box.querySelector('.gld-tier__lab[data-key="' + t.key + '"]');
      if (lab) lab.classList.toggle('is-here', t.key === reachedTier.key);
      var grp = box.querySelector('.gld-tier__grp[data-key="' + t.key + '"]');
      if (grp) grp.classList.toggle('is-here', t.key === reachedTier.key);
    });

    box.querySelector('.gld-tier__foot').textContent = order > 0
      ? "You've spent " + rm(life, 2) + ' with us so far. This basket adds ' + rm(order, 2) + '.'
      : "You've spent " + rm(life, 2) + ' with us so far.';
    box.hidden = false;
  }

  // The "i": hover for pointers, click or keyboard for everyone, Escape to close.
  var btn = box.querySelector('.gld-tier__info'), pop = box.querySelector('.gld-tier__perks'), pinned = false;
  function show(on) { pop.hidden = !on; btn.setAttribute('aria-expanded', on ? 'true' : 'false'); }
  btn.addEventListener('click', function () { pinned = !pinned; show(pinned); });
  btn.addEventListener('mouseenter', function () { show(true); });
  btn.addEventListener('focus', function () { show(true); });
  btn.addEventListener('mouseleave', function () { if (!pinned) show(false); });
  btn.addEventListener('blur', function () { if (!pinned) show(false); });
  pop.addEventListener('mouseenter', function () { show(true); });
  pop.addEventListener('mouseleave', function () { if (!pinned) show(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && pinned) { pinned = false; show(false); btn.focus(); }
  });
  document.addEventListener('click', function (e) {
    if (pinned && !box.contains(e.target)) { pinned = false; show(false); }
  });

  function load() {
    fetch(CFG.rest, { credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.ok) { paint(d); } else { box.hidden = true; } })
      .catch(function () { box.hidden = true; });
  }

  scaffold();
  load();
  // Quantity changes and coupons redraw the totals; the projection follows them.
  if (window.jQuery) { window.jQuery(document.body).on('updated_cart_totals', load); }
})();
</script>
        <?php
    }

}

Galado_Club_Bridge::init();
