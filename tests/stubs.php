<?php
/**
 * Just enough WordPress, WooCommerce, CURCY and Points & Rewards to load galado-club-bridge.php
 * under the PHP CLI and call its money paths directly. No database, no network. CLI only.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['__options']   = [];      // get_option
$GLOBALS['__cur']       = 'MYR';   // CURCY's currency for this request
$GLOBALS['__rate']      = 1.0;     // CURCY's straight rate for it (units per RM1)
$GLOBALS['__dp']        = 2;       // wc_get_price_decimals()
$GLOBALS['__orders']    = [];      // wc_get_order registry
$GLOBALS['__get_orders'] = null;   // callable(array $args): array for wc_get_orders
$GLOBALS['__user_id']   = 0;       // the signed-in user
$GLOBALS['__user_meta'] = [];      // [uid][key] => value
$GLOBALS['__users']     = [];      // email => WP_User
$GLOBALS['__routes']    = [];      // register_rest_route captures
$GLOBALS['__db_meta']   = [];      // order id => meta as stored in the database
$GLOBALS['__events']    = [];      // lock queries and points moves, in order
$GLOBALS['__locks_elsewhere'] = []; // named locks another process holds

function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function register_rest_route($ns, $route, $args) { $GLOBALS['__routes'][$route] = $args; }
function apply_filters($tag, $value) { return $value; }
function __($text) { return $text; }
function is_admin() { return false; }
function is_user_logged_in() { return $GLOBALS['__user_id'] > 0; }
function get_current_user_id() { return $GLOBALS['__user_id']; }
function current_user_can() { return false; }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['__options']) ? $GLOBALS['__options'][$name] : $default; }
function get_user_meta($uid, $key, $single = false) { return isset($GLOBALS['__user_meta'][$uid][$key]) ? $GLOBALS['__user_meta'][$uid][$key] : ''; }
function update_user_meta($uid, $key, $value) { $GLOBALS['__user_meta'][$uid][$key] = $value; }
function get_user_by($field, $value) { return isset($GLOBALS['__users'][$value]) ? $GLOBALS['__users'][$value] : false; }
function wc_get_order($id) { return ($id instanceof WC_Order) ? $id : (isset($GLOBALS['__orders'][$id]) ? $GLOBALS['__orders'][$id] : false); }
function wc_get_orders($args) { $f = $GLOBALS['__get_orders']; return $f ? $f($args) : []; }
function wc_get_price_decimals() { return $GLOBALS['__dp']; }
function wc_get_price_decimal_separator() { return '.'; }
function wc_get_price_thousand_separator() { return ','; }
function is_wp_error($thing) { return false; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function esc_url_raw($s) { return (string) $s; }
function rest_url($path) { return 'https://store.test/wp-json/' . $path; }
function wp_create_nonce() { return 'nonce'; }
function wp_json_encode($data) { return json_encode($data); }
function number_format_i18n($n) { return number_format((float) $n); }
function WC() { return $GLOBALS['__wc']; }
function wmc_get_price($price, $currency = false, $is_shipping = false) { return (float) $price * $GLOBALS['__rate']; }

class WOOMULTI_CURRENCY_Data {
    public static function get_ins() { return new self(); }
    public function get_current_currency() { return $GLOBALS['__cur']; }
}

class WP_User {
    public $ID;
    public $user_email;
    public $display_name = '';
    public function __construct($id = 0, $email = '') { $this->ID = $id; $this->user_email = $email; }
}

class WC_Order {
    public $id = 0;
    public $currency = 'MYR';
    public $total = 0;
    public $shipping_total = 0;
    public $meta = [];
    public $user_id = 0;
    public $billing_email = '';
    public $created = null;   // unix timestamp
    public $saved = 0;
    public function __construct(array $props = []) {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
        $GLOBALS['__orders'][$this->id] = $this;
        $GLOBALS['__db_meta'][$this->id] = $this->meta;
    }
    public function get_id() { return $this->id; }
    public function get_currency() { return $this->currency; }
    public function get_total() { return $this->total; }
    public function get_shipping_total() { return $this->shipping_total; }
    public function get_meta($key, $single = true) { return isset($this->meta[$key]) ? $this->meta[$key] : ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function add_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() { $this->saved++; $this->save_meta_data(); return $this->id; }
    public function save_meta_data() { $GLOBALS['__db_meta'][$this->id] = $this->meta; }
    public function read_meta_data($force_read = false) {
        if ($force_read) {
            $this->meta = isset($GLOBALS['__db_meta'][$this->id]) ? $GLOBALS['__db_meta'][$this->id] : [];
        }
    }
    public function get_user_id() { return $this->user_id; }
    public function get_customer_id() { return $this->user_id; }
    public function get_user() { return $this->user_id ? new WP_User($this->user_id, $this->billing_email) : false; }
    public function get_billing_email() { return $this->billing_email; }
    public function get_date_created() { return null === $this->created ? null : new DateTime('@' . $this->created); }
}

class WC_Points_Rewards_Manager {
    public static $log = [];
    public static $fail = false; // simulate Points & Rewards throwing mid-move
    public static function increase_points($uid, $points, $type, $data = null, $order_id = null) { self::move('+', $uid, $points, $type, $order_id); }
    public static function decrease_points($uid, $points, $type, $data = null, $order_id = null) { self::move('-', $uid, $points, $type, $order_id); }
    private static function move($sign, $uid, $points, $type, $order_id) {
        if (self::$fail) {
            throw new RuntimeException('points store unavailable');
        }
        self::$log[] = [$sign, $uid, $points, $type, $order_id];
        $GLOBALS['__events'][] = $sign . 'points ' . $points;
    }
}

/** $wpdb for MySQL named locks only. A lock named in $GLOBALS['__locks_elsewhere'] is held by another
 *  process: GET_LOCK answers its value there ('0' after the timeout, null for an error). */
class Galado_Test_WPDB {
    public $queries = [];
    public function prepare($query, ...$args) {
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $query);
    }
    public function get_var($query) {
        $this->log($query);
        if (preg_match("/^SELECT GET_LOCK\('([^']+)', (\d+)\)$/", $query, $m)) {
            return array_key_exists($m[1], $GLOBALS['__locks_elsewhere']) ? $GLOBALS['__locks_elsewhere'][$m[1]] : '1';
        }
        return null;
    }
    public function query($query) {
        $this->log($query);
        return 1;
    }
    private function log($query) {
        $this->queries[] = $query;
        $GLOBALS['__events'][] = $query;
    }
}
$GLOBALS['wpdb'] = new Galado_Test_WPDB();

class Galado_Test_Cart {
    public $subtotal = 0.0;
    public $total = 0.0;
    public $fees = [];
    public function get_subtotal() { return $this->subtotal; }
    public function get_total($context = 'view') { return $this->total; }
    public function add_fee($name, $amount, $taxable = false) { $this->fees[] = (object) ['name' => $name, 'amount' => $amount]; }
    public function get_fees() { return $this->fees; }
}
$GLOBALS['__wc'] = (object) ['cart' => new Galado_Test_Cart(), 'session' => null];

/** A wmc_order_info snapshot, as CURCY writes it: currency => [rate, decimals, ...]. */
function galado_test_wmc(array $rates, array $extra = []) {
    $info = ['MYR' => ['rate' => '1', 'decimals' => '2', 'is_main' => 1]];
    foreach ($rates as $code => $rate) {
        $info[$code] = array_merge(['rate' => (string) $rate, 'decimals' => 'JPY' === $code ? '0' : '2'], isset($extra[$code]) ? $extra[$code] : []);
    }
    return $info;
}

/** Point the "request" at a currency: CURCY's current code, straight rate and decimals. */
function galado_test_currency($code, $rate, $dp = 2) {
    $GLOBALS['__cur']  = $code;
    $GLOBALS['__rate'] = $rate;
    $GLOBALS['__dp']   = $dp;
}

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function check($label, $got, $want) {
    if ($got === $want) {
        $GLOBALS['__pass']++;
        echo "ok   {$label}\n";
    } else {
        $GLOBALS['__fail']++;
        echo "FAIL {$label}\n     want " . var_export($want, true) . "\n     got  " . var_export($got, true) . "\n";
    }
}
