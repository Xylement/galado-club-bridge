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

@unlink($errlog);
echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
