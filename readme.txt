=== GALADO Club Bridge ===
Stable tag: 0.17.7
Requires at least: 5.8
Requires PHP: 7.4
License: GPLv2 or later

Connects galado.com.my (WooCommerce) to GALADO Club (club.galado.com.my): My Account
"GALADO Club" tab, SSO sign-in, tier + Shopping-Credits mirroring, customer
provisioning, Points read/add/deduct/lifetime, POS customer search + receipts,
the HCSA order-fatal guard, and store-signup/review webhooks. NOTE: the Woo
ORDER webhook is a native WooCommerce webhook created by hand in wp-admin, not
registered by this plugin.

The authoritative version is `const VERSION` in galado-club-bridge.php; verify
the LIVE build with the public GET /wp-json/galado-club/v1/ping. Deploy is
push-to-Xylement/galado-club-bridge (WP Git Sync), NOT the zips in this folder
(the v0.2.x zips are obsolete manual-upload relics — do not deploy them).

== Changelog ==
= 0.25.0 =
* POS receipt email is queued via Action Scheduler instead of sent inline: the POS
  Send button answers instantly even when SMTP is slow (inline send kept as fallback).
= 0.5.0–0.15.2 =
* POS support (customer search, receipts, email/Klaviyo suppression), HCSA
  order-fatal guard, single/bulk customer provisioning, Points add/deduct/
  lifetime endpoints, welcome first-order discount, WCPA product-form dump,
  named new-account greeting. See the nested repo commit log for per-version
  detail (github.com/Xylement/galado-club-bridge).
= 0.4.0 =
* Referral RM10 welcome: -RM10 at checkout for a referred new customer (galado_ref cookie + no prior paid orders, min RM30 subtotal).
= 0.3.0 =
* Referral program: capture ?ref= into a 30-day cookie and stamp it onto orders (classic + block checkout); the Club credits the referrer 10% of net spend.
= 0.2.3 =
* Review & Earn: credit on review approval — transition_comment_status + comment_post (auto-approved) hooks; public /ping version route.
= 0.2.2 =
* Review approval -> Club credit hook.
= 0.2.1 =
* My Account tab mirrors the Club avatar portrait.
