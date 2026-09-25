<?php
/**
 * SPEC-4 section 6 money fixes in the bridge, one section per bug, at arbitrary rates (SGD 0.31317
 * and a zero-decimal JPY 38.868, 24 Sep 2026) and at the 0.40 SGD was recorded at on real orders.
 *
 *   php tests/test-money-fixes.php
 *
 * Loads the real plugin file over tests/stubs.php and calls its hooks directly. Exits 1 on failure.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/stubs.php';
require __DIR__ . '/../galado-club-bridge.php';

$B = 'Galado_Club_Bridge';
$GLOBALS['__options']['woocommerce_currency'] = 'MYR';
$GLOBALS['__options']['wc_points_rewards_earn_points_ratio'] = '1:2'; // "Every RM2 spent = 1 point"
$GLOBALS['__options']['wc_points_rewards_earn_points_rounding'] = 'round';
ini_set('error_log', $errlog = tempnam(sys_get_temp_dir(), 'galado-test-'));

echo "-- bug 1: Shopping Credits earn on the order's RM value, at the rate recorded on the order\n";
galado_test_currency('MYR', 1.0); // points are awarded on a status change, never in the buyer's request
check('MYR RM149 is unchanged: 75 points', $B::points_earned_on_paid_amount(0, new WC_Order(['id' => 101, 'total' => '149.00'])), 75);
check('SGD S$71.50 recorded at 0.40 = RM178.75: 89 points, not 36',
    $B::points_earned_on_paid_amount(0, new WC_Order(['id' => 102, 'currency' => 'SGD', 'total' => '71.50',
        'meta' => ['wmc_order_info' => galado_test_wmc(['SGD' => 0.4])]])), 89);
check('JPY 5,970 at 38.868 = RM153.60: 77 points',
    $B::points_earned_on_paid_amount(0, new WC_Order(['id' => 103, 'currency' => 'JPY', 'total' => '5970',
        'meta' => ['wmc_order_info' => galado_test_wmc(['JPY' => 38.868])]])), 77);
check('a 3% fee kept apart from the rate is folded in: S$48.50 = RM150.36, 75 points',
    $B::points_earned_on_paid_amount(0, new WC_Order(['id' => 104, 'currency' => 'SGD', 'total' => '48.50',
        'meta' => ['wmc_order_info' => galado_test_wmc(['SGD' => 0.31317], ['SGD' => ['rate_fee' => '3', 'rate_fee_type' => 'percentage']])]])), 75);
check('the order id alone works too', $B::points_earned_on_paid_amount(0, 102), 89);
check('SGD with no recorded rate earns nothing, never points on the raw S$ figure',
    $B::points_earned_on_paid_amount(36, new WC_Order(['id' => 105, 'currency' => 'SGD', 'total' => '71.50'])), 0);
check('and says so in the PHP error log', false !== strpos((string) file_get_contents($errlog), 'order 105'), true);
galado_test_currency('SGD', 0.31317);
check("today's rate never stands in for the order's own", $B::points_earned_on_paid_amount(0, 102), 89);
$GLOBALS['__options']['wc_points_rewards_earn_points_ratio'] = '0:0';
check('a misconfigured ratio still leaves WooCommerce its own figure', $B::points_earned_on_paid_amount(12, 102), 12);
$GLOBALS['__options']['wc_points_rewards_earn_points_ratio'] = '1:2';

echo "-- bug 3: the win-back fee converts into the cart's currency; its RM stays RM on the order\n";
$GLOBALS['__user_id'] = 7;
$winback = function ($subtotal, $avail = 20) use ($B) {
    $GLOBALS['__user_meta'][7] = ['_galado_winback_rm' => $avail];
    $cart = WC()->cart;
    $cart->fees = [];
    $cart->subtotal = $subtotal;
    $B::winback_discount($cart);
    $order = new WC_Order(['id' => 200]);
    $B::capture_winback($order);
    $fee = $cart->fees ? $cart->fees[0] : null;
    return [$fee ? $fee->name : null, $fee ? $fee->amount : null, $order->get_meta('_galado_winback_applied')];
};
galado_test_currency('MYR', 1.0);
check('MYR RM120 cart: RM20 off, labelled as before, RM20 stamped',
    $winback(120.0), ['GALADO Club reward: RM20 off', -20.0, 20.0]);
galado_test_currency('SGD', 0.31317);
check('SGD S$37.58 cart (RM120): two steps, S$6.26 off, RM20 stamped for consume_winback',
    $winback(37.58), ['GALADO Club reward: S$6.26 off', -6.26, 20.0]);
check('SGD S$120 cart: still RM20 of discount, never S$20',
    $winback(120.0), ['GALADO Club reward: S$6.26 off', -6.26, 20.0]);
check('SGD cart under the converted RM50 step: nothing', $winback(15.0), [null, null, '']);
check('a RM10 balance caps it: S$3.13', $winback(100.0, 10), ['GALADO Club reward: S$3.13 off', -3.13, 10.0]);
galado_test_currency('JPY', 38.868, 0);
check('zero-decimal JPY 4,664 cart (RM120): JPY 777 off, RM20 stamped',
    $winback(4664.0), ['GALADO Club reward: JPY 777 off', -777.0, 20.0]);
$GLOBALS['__user_id'] = 0;
galado_test_currency('MYR', 1.0);

echo "-- bug 4: app Shopping Credits come back once when the order is cancelled, fails or is fully refunded\n";
$GLOBALS['__users']['guestpay@example.com'] = new WP_User(9, 'guestpay@example.com');
$app = function ($id, $user = 7, $points = 500, $email = 'app@example.com') {
    return new WC_Order(['id' => $id, 'user_id' => $user, 'billing_email' => $email,
        'meta' => ['_galado_app_order' => '1', '_galado_app_credits_points' => (string) $points]]);
};
$log = function () { $l = WC_Points_Rewards_Manager::$log; WC_Points_Rewards_Manager::$log = []; return $l; };
$app(401);
$B::return_app_credits(401);
$B::return_app_credits(401);
check('cancelled (unpaid past the hold window): 500 back to user 7, once', $log(), [['+', 7, 500, 'galado-app-redemption-returned', 401]]);
$app(402);
$B::return_app_credits(402);  // failed
$B::retake_app_credits(402);  // the shopper retries and pays after all
$B::retake_app_credits(402);  // completed later
$B::return_app_credits(402);  // then fully refunded
check('failed, then paid, then fully refunded: back, taken again, back',
    $log(), [['+', 7, 500, 'galado-app-redemption-returned', 402], ['-', 7, 500, 'galado-app-redemption', 402], ['+', 7, 500, 'galado-app-redemption-returned', 402]]);
$app(403);
$B::retake_app_credits(403);  // processing
$B::retake_app_credits(403);  // completed
check('a paid order that stays paid returns nothing', $log(), []);
new WC_Order(['id' => 404, 'user_id' => 7, 'meta' => ['_galado_app_order' => '1']]);
$B::return_app_credits(404);
check('an app order that redeemed no credits: nothing to return', $log(), []);
$app(405, 0, 120, 'guestpay@example.com');
$B::return_app_credits(405);
check('an order without a customer id returns to the account that owns the billing email', $log(), [['+', 9, 120, 'galado-app-redemption-returned', 405]]);
check('WooCommerce may cancel an unpaid app order that holds credits', $B::cancel_unpaid_app_order(false, $app(406)), true);
check('but not an app order without credits (unchanged)',
    $B::cancel_unpaid_app_order(false, new WC_Order(['id' => 407, 'meta' => ['_galado_app_order' => '1']])), false);
check('checkout orders keep WooCommerce\'s own answer', $B::cancel_unpaid_app_order(true, new WC_Order(['id' => 408])), true);

@unlink($errlog);
echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
