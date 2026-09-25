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
check('and logs one greppable line saying so',
    substr_count((string) file_get_contents($errlog), '[galado-club-bridge] points_not_awarded order=105 currency=SGD reason=no_rate paid=71.50'), 1);
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

echo "-- bug 4, atomic: one MySQL named lock per order, and the flag read fresh from the database inside it\n";
$GLOBALS['__events'] = [];
$app(420);
$B::return_app_credits(420);
check('the points move inside GET_LOCK and RELEASE_LOCK for that order',
    $GLOBALS['__events'], ["SELECT GET_LOCK('gld_app_credits_420', 5)", '+points 500', "SELECT RELEASE_LOCK('gld_app_credits_420')"]);
$log();
$GLOBALS['__events'] = [];
$B::return_app_credits(420);
check('a second call takes the lock, finds the order claimed and moves nothing',
    [$GLOBALS['__events'], $log()], [["SELECT GET_LOCK('gld_app_credits_420', 5)", "SELECT RELEASE_LOCK('gld_app_credits_420')"], []]);
$app(421); // this process loaded the order...
$GLOBALS['__db_meta'][421]['_galado_app_credits_returned'] = '1'; // ...then another gave the points back
$B::return_app_credits(421);
check('the stale copy in memory is not trusted: points already given back are not given twice', $log(), []);
$app(422);
$GLOBALS['__db_meta'][422]['_galado_app_credits_returned'] = '1'; // failed and returned by another process
$B::retake_app_credits(422); // while this one records the payment with a stale copy
check('a payment racing the return still takes the points again', $log(), [['-', 7, 500, 'galado-app-redemption', 422]]);
check('and clears the flag where it is stored', isset($GLOBALS['__db_meta'][422]['_galado_app_credits_returned']), false);
$app(423);
$GLOBALS['__locks_elsewhere']['gld_app_credits_423'] = '0'; // GET_LOCK gave up after 5 s
$GLOBALS['__events'] = [];
$B::return_app_credits(423);
$B::retake_app_credits(423);
check('lock not had within 5 s: nothing moves, nothing is flagged, nothing is released',
    [$log(), $GLOBALS['__events'], isset($GLOBALS['__db_meta'][423]['_galado_app_credits_returned'])],
    [[], ["SELECT GET_LOCK('gld_app_credits_423', 5)", "SELECT GET_LOCK('gld_app_credits_423', 5)"], false]);
check('and each attempt is logged with the order id, to retry by hand', [
    substr_count((string) file_get_contents($errlog), '[galado-club-bridge] app_credits_not_moved order=423 action=return reason=lock_timeout'),
    substr_count((string) file_get_contents($errlog), '[galado-club-bridge] app_credits_not_moved order=423 action=retake reason=lock_timeout'),
], [1, 1]);
$app(424);
$GLOBALS['__locks_elsewhere']['gld_app_credits_424'] = null; // GET_LOCK error
$B::return_app_credits(424);
check('a GET_LOCK error counts as not having the lock', $log(), []);
$app(425);
WC_Points_Rewards_Manager::$fail = true;
$GLOBALS['__events'] = [];
$threw = false;
try {
    $B::return_app_credits(425);
} catch (RuntimeException $e) {
    $threw = true;
}
WC_Points_Rewards_Manager::$fail = false;
check('Points & Rewards failing mid-move: the lock is still released and the order stays unclaimed',
    [$threw, end($GLOBALS['__events']), isset($GLOBALS['__db_meta'][425]['_galado_app_credits_returned'])],
    [true, "SELECT RELEASE_LOCK('gld_app_credits_425')", false]);
new WC_Order(['id' => 426, 'user_id' => 7, 'meta' => ['_galado_app_order' => '1']]);
$GLOBALS['__events'] = [];
$B::return_app_credits(426);
$B::retake_app_credits(426);
check('an order that redeemed no credits takes no lock at all', $GLOBALS['__events'], []);

echo "-- bug 6: the thank-you estimate and the cart tier meter are in RM\n";
$thanks = function ($props) use ($B) {
    ob_start();
    $B::thankyou_block((new WC_Order($props))->get_id());
    return (string) ob_get_clean();
};
galado_test_currency('SGD', 0.31317); // the buyer's own request: today's rate must not be used
$html = $thanks(['id' => 301, 'currency' => 'SGD', 'total' => '71.50', 'shipping_total' => '12.00',
    'billing_email' => 'sg@example.com', 'meta' => ['wmc_order_info' => galado_test_wmc(['SGD' => 0.4])]]);
check('S$71.50 incl S$12 shipping at 0.40 promises ~149 coins, the figure it earns (was ~60)', false !== strpos($html, 'You just earned ~149 G-Coins!'), true);
$html = $thanks(['id' => 302, 'currency' => 'SGD', 'total' => '71.50', 'shipping_total' => '12.00', 'billing_email' => 'sg@example.com']);
check('no recorded rate: no coin promise', false === strpos($html, 'You just earned') && false !== strpos($html, 'Your GALADO Club is waiting'), true);
galado_test_currency('MYR', 1.0);
$html = $thanks(['id' => 303, 'total' => '149.00', 'billing_email' => 'my@example.com']);
check('MYR RM149 unchanged: ~149', false !== strpos($html, 'You just earned ~149 G-Coins!'), true);

$meter = function ($code, $rate, $basket) use ($B) {
    galado_test_currency($code, $rate);
    $GLOBALS['__user_id'] = 7;
    WC()->cart->total = $basket;
    ob_start();
    $B::render_tier_meter();
    $out = (string) ob_get_clean();
    $GLOBALS['__user_id'] = 0;
    return $out;
};
$out = $meter('SGD', 0.31317, 71.50);
preg_match('/window\.GLD_TIER = (\{.*?\});\n/', $out, $m);
$cfg = $m ? json_decode($m[1], true) : [];
check('the meter carries the rate the basket converts at', isset($cfg['rate']) ? $cfg['rate'] : null, 0.31317);
check('and the basket as the shopper sees it', isset($cfg['order']) ? $cfg['order'] : null, 71.5);
check('the page divides the basket by that rate before adding it to RM lifetime spend',
    false !== strpos($out, 'var order = basketTotal() / (+CFG.rate || 1);'), true);
$out = $meter('MYR', 1.0, 149.0);
preg_match('/window\.GLD_TIER = (\{.*?\});\n/', $out, $m);
$cfg = $m ? json_decode($m[1], true) : [];
check('an RM basket converts at 1', isset($cfg['rate']) ? (float) $cfg['rate'] : null, 1.0);
check('no CURCY rate: no meter at all rather than a wrong bar', $meter('SGD', 0.0, 71.50), '');
galado_test_currency('MYR', 1.0);

echo "-- bug 7: /app-stats sums revenue in RM, each order at its own rate\n";
new WC_Order(['id' => 601, 'total' => '100.00', 'created' => time() - 3600]);
new WC_Order(['id' => 602, 'currency' => 'SGD', 'total' => '40.00', 'created' => time() - 7200,
    'meta' => ['wmc_order_info' => galado_test_wmc(['SGD' => 0.4])]]);
new WC_Order(['id' => 603, 'currency' => 'SGD', 'total' => '50.00', 'created' => time() - 60 * 86400]);
$GLOBALS['__get_orders'] = function ($args) {
    return isset($args['created_via']) ? [602] : [601, 602, 603];
};
$B::rest_routes();
$stats = call_user_func($GLOBALS['__routes']['/app-stats']['callback']);
check('orders counted once each', $stats['orders'], 3);
check('revenue RM100 + S$40 at 0.40 = RM200 (the S$50 order has no rate and adds nothing)', $stats['revenue'], 200.0);
check('30-day window', [$stats['orders_30d'], $stats['revenue_30d']], [2, 200.0]);
check('and the order it could not convert is reported, not hidden', isset($stats['unconverted']) ? $stats['unconverted'] : null, 1);

@unlink($errlog);
echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
