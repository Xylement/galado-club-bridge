<?php
/**
 * The Club join popup (0.64.15): the glass popup asks the Club for instant membership, every other surface keeps
 * the sign-in link, the join notice and the marketing tick go where they always went, and the store renders the
 * glass popup only in preview or once the option says so.
 *
 *   php tests/test-popup-join.php
 *
 * Loads the real plugin file over tests/stubs.php and calls popup_join() and join_popup() directly. Exits 1 on failure.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/stubs.php';

define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('GALADO_CLUB_URL', 'https://club.test');
define('GALADO_CLUB_BRIDGE_SECRET', 'bridge-secret');

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code = '', $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
class WP_REST_Request {
    private $params;
    public function __construct(array $params) { $this->params = $params; }
    public function get_param($key) { return isset($this->params[$key]) ? $this->params[$key] : null; }
}
$GLOBALS['__transients'] = [];
$GLOBALS['__posts']      = [];           // every wp_remote_post: [url, decoded body]
$GLOBALS['__club']       = [200, '{"ok":true,"returning":false}'];
$GLOBALS['__emits']      = [];           // galado_newsletter_emit calls
function get_transient($k) { return isset($GLOBALS['__transients'][$k]) ? $GLOBALS['__transients'][$k] : false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function sanitize_email($s) { return filter_var(trim((string) $s), FILTER_SANITIZE_EMAIL); }
function is_email($s) { return false !== filter_var($s, FILTER_VALIDATE_EMAIL); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_unslash($s) { return $s; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_salt($scheme = 'auth') { return 'test-salt-' . $scheme; }
function wp_remote_post($url, $args) {
    $GLOBALS['__posts'][] = [$url, json_decode($args['body'], true), $args['headers']];
    return ['response' => ['code' => $GLOBALS['__club'][0]], 'body' => $GLOBALS['__club'][1]];
}
function wp_remote_retrieve_response_code($res) { return $res['response']['code']; }
function wp_remote_retrieve_body($res) { return $res['body']; }
function galado_newsletter_is_live() { return true; }
function galado_newsletter_emit($email, $name, $source, $notice) { $GLOBALS['__emits'][] = [$email, $source, $notice]; return true; }

require __DIR__ . '/../galado-club-bridge.php';
$B = 'Galado_Club_Bridge';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

function join_with(array $params, $club = null) {
    $GLOBALS['__posts'] = [];
    $GLOBALS['__emits'] = [];
    $GLOBALS['__transients'] = [];
    if ($club) {
        $GLOBALS['__club'] = $club;
    }
    return Galado_Club_Bridge::popup_join(new WP_REST_Request($params));
}
function last_post() { return end($GLOBALS['__posts']) ?: [null, null, null]; }

echo "-- the glass popup asks for instant membership (live: option galado_club_popup = glass)\n";
$GLOBALS['__options']['galado_club_popup'] = 'glass';
$out = join_with(['email' => 'Aisyah@Example.com', 'instant' => 1, 'optin' => true], [200, '{"ok":true,"returning":false}']);
list($url, $body, $headers) = last_post();
check('posts to the Club\'s instant join', $url, 'https://club.test/api/club/join');
check('as the bridge', $headers['x-club-bridge-secret'], 'bridge-secret');
check('with the visitor\'s IP and the popup source', [$body['clientIp'], $body['source']], ['203.0.113.7', 'popup']);
check('and the line the glass popup showed', $body['noticeText'], $B::POPUP_INSTANT_NOTICE);
check('a new member: returning false', $out, ['ok' => true, 'returning' => false]);
check('the tick records consent with the popup wording, once', $GLOBALS['__emits'], [['Aisyah@Example.com', 'popup', $B::POPUP_OPTIN_NOTICE]]);
$out = join_with(['email' => 'old.friend@example.com', 'instant' => 1], [200, '{"ok":true,"returning":true}']);
check('an existing member: returning true', $out, ['ok' => true, 'returning' => true]);
check('no tick, no consent', $GLOBALS['__emits'], []);


echo "-- before go-live only the owner's preview gets instant joins\n";
unset($GLOBALS['__options']['galado_club_popup']);
$out = join_with(['email' => 'stranger@example.com', 'instant' => 1, 'optin' => true]);
list($url, $body) = last_post();
check('instant=1 alone is not enough: the sign-in link instead', $url, 'https://club.test/api/claim/request');
check('still recorded with the line the glass popup showed', $body['noticeText'], $B::POPUP_INSTANT_NOTICE);
check('and the popup is told to say "check your inbox"', $out, ['ok' => true, 'link' => true]);
check('the tick still counts: the join itself happened', count($GLOBALS['__emits']), 1);
$out = join_with(['email' => 'stranger@example.com', 'instant' => 1, 'pv' => 'forged-pass-0123456789ab']);
check('a forged pass gets the link too', [last_post()[0], $out], ['https://club.test/api/claim/request', ['ok' => true, 'link' => true]]);
$_GET['club_popup'] = 'new';
ob_start(); Galado_Club_Bridge::join_popup(); $preview_html = ob_get_clean();
unset($_GET['club_popup']);
preg_match('/PASS="([0-9a-f]{24})"/', $preview_html, $pm);
check('the preview page carries a pass', isset($pm[1]), true);
$out = join_with(['email' => 'owner.test@example.com', 'instant' => 1, 'pv' => $pm[1] ?? ''], [200, '{"ok":true,"returning":false}']);
check('with it, the preview joins instantly', [last_post()[0], $out], ['https://club.test/api/club/join', ['ok' => true, 'returning' => false]]);

echo "-- the article card and the iPhone 18/Duo boxes follow the popup\n";
$GLOBALS['__options']['galado_club_popup'] = 'glass';
$out = join_with(['email' => 'reader.one@example.com', 'source' => 'blog_inline', 'instant' => 1, 'optin' => true], [200, '{"ok":true,"returning":false}']);
check('the article card joins instantly once live', [last_post()[0], $out], ['https://club.test/api/club/join', ['ok' => true, 'returning' => false]]);
check('with its own source for the ledger', $GLOBALS['__emits'], [['reader.one@example.com', 'blog_inline', $B::POPUP_OPTIN_NOTICE]]);
$out = join_with(['email' => 'reader.two@example.com', 'source' => 'article', 'instant' => 1], [200, '{"ok":true,"returning":true}']);
list($url, $body) = last_post();
check('an iPhone 18 article box joins as source article', [$url, $body['source'], $out], ['https://club.test/api/club/join', 'article', ['ok' => true, 'returning' => true]]);
check('and forwards no join notice (the Club records popup joins only)', isset($body['noticeText']), false);
unset($GLOBALS['__options']['galado_club_popup']);
$out = join_with(['email' => 'reader.three@example.com', 'source' => 'article', 'instant' => 1]);
check('before go-live an article box gets the sign-in link', [last_post()[0], $out], ['https://club.test/api/claim/request', ['ok' => true, 'link' => true]]);
check('the body class appears only in glass mode', [Galado_Club_Bridge::glass_body_class(['a']), (function () { $GLOBALS['__options']['galado_club_popup'] = 'glass'; $r = Galado_Club_Bridge::glass_body_class(['a']); unset($GLOBALS['__options']['galado_club_popup']); return $r; })()], [['a'], ['a', 'gld-club-glass']]);
$_GET['club_popup'] = 'new';
ob_start(); Galado_Club_Bridge::glass_preview_flag(); $flag = ob_get_clean();
check('the preview hands the article boxes the same pass', [Galado_Club_Bridge::glass_body_class([]), (bool) preg_match('/^<script>window\.GLDCLUB_PV="[0-9a-f]{24}";<\/script>$/', $flag)], [['gld-club-glass'], true]);
unset($_GET['club_popup']);
ob_start(); Galado_Club_Bridge::glass_preview_flag(); $flag = ob_get_clean();
check('and nothing outside the preview', $flag, '');

echo "-- the old popup and the blog card keep the sign-in link\n";
$out = join_with(['email' => 'mei@example.com', 'name' => 'Mei'], [200, '{"ok":true,"returning":false}']);
list($url, $body) = last_post();
check('old popup posts to claim/request', $url, 'https://club.test/api/claim/request');
check('with the old line', $body['noticeText'], $B::POPUP_NOTICE);
check('and answers as before', $out, ['ok' => true]);
$out = join_with(['email' => 'reader@example.com', 'source' => 'blog_inline']);
list($url, $body) = last_post();
check('the old blog card (no instant ask) still gets a link', $url, 'https://club.test/api/claim/request');
check('and forwards no notice', isset($body['noticeText']), false);

echo "-- failures\n";
$out = join_with(['email' => 'busy@example.com', 'instant' => 1, 'optin' => true], [429, '{"error":"Too many sign-ups from here."}']);
check('the Club\'s limit comes back as a 429', [$out instanceof WP_Error, $out->data['status']], [true, 429]);
check('in words a new joiner understands', $out->message, 'Too many tries from here. Please wait a few minutes.');
check('and no consent is recorded for a join that did not happen', $GLOBALS['__emits'], []);
$out = join_with(['email' => 'x@example.com', 'instant' => 1], [500, '{}']);
check('a Club error is a 502', $out->data['status'], 502);
$out = join_with(['email' => 'not-an-email', 'instant' => 1]);
check('a bad email never reaches the Club', [$out->data['status'], count($GLOBALS['__posts'])], [400, 0]);
$out = join_with(['email' => 'bot@example.com', 'instant' => 1, 'website' => 'http://spam.test']);
check('the honeypot pretends and calls nobody', [$out, count($GLOBALS['__posts'])], [['ok' => true], 0]);
$GLOBALS['__posts'] = [];
$GLOBALS['__transients'] = [];
for ($i = 0; $i < 7; $i++) {
    $out = Galado_Club_Bridge::popup_join(new WP_REST_Request(['email' => "burst{$i}@example.com", 'instant' => 1]));
}
check('the store\'s own limit stops the 7th try in an hour', [$out->data['status'], count($GLOBALS['__posts'])], [429, 6]);
$_COOKIE['galado_ref'] = 'abc-123';
join_with(['email' => 'referred@example.com', 'instant' => 1]);
list(, $body) = last_post();
check('a referral cookie is forwarded, cleaned', $body['ref'], 'ABC123');
unset($_COOKIE['galado_ref']);

echo "-- which popup the store renders\n";
function render_popup() { ob_start(); Galado_Club_Bridge::join_popup(); return ob_get_clean(); }
$old = render_popup();
check('by default: the old popup', [strpos($old, 'class="pj-vis"') !== false, strpos($old, 'pj-buds') !== false], [true, false]);
$_GET['club_popup'] = 'new';
$glass = render_popup();
check('?club_popup=new: the glass popup, in preview', [strpos($glass, 'pj-buds') !== false, strpos($glass, 'PREVIEW=true') !== false], [true, true]);
unset($_GET['club_popup']);
$GLOBALS['__options']['galado_club_popup'] = 'glass';
$glass = render_popup();
check('option set: the glass popup for everyone, not as a preview', [strpos($glass, 'pj-buds') !== false, strpos($glass, 'PREVIEW=false') !== false], [true, true]);
check('and with no preview pass in the page', strpos($glass, 'PASS=""') !== false, true);
check('it shows the line it forwards, verbatim', strpos($glass, esc_html($B::POPUP_INSTANT_NOTICE)) !== false, true);
check('and the tick, ticked, with the consent wording verbatim',
    strpos($glass, '<input type="checkbox" name="optin" checked/><span>' . esc_html($B::POPUP_OPTIN_NOTICE) . '</span>') !== false, true);
check('one email field and no name field', [substr_count($glass, 'type="email"'), substr_count($glass, 'name="name"')], [1, 0]);
check('it keeps the ids other surfaces rely on', [substr_count($glass, 'id="gldpj"'), substr_count($glass, 'id="gldpj-min"'), substr_count($glass, "'gldpj_v1'")], [1, 1, 1]);
check('no em dash anywhere a visitor reads', strpos($glass, "\u{2014}"), false);
$GLOBALS['__user_id'] = 7;
check('signed-in customers see no popup at all', render_popup(), '');
$GLOBALS['__user_id'] = 0;
unset($GLOBALS['__options']['galado_club_popup']);

echo "\n" . $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed\n";
exit($GLOBALS['__fail'] ? 1 : 0);
