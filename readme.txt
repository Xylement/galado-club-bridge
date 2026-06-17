=== GALADO Club Bridge ===
Stable tag: 0.4.0
Requires at least: 5.8
Requires PHP: 7.4
License: GPLv2 or later

Connects galado.com.my (WooCommerce) to GALADO Club (club.galado.com.my): My Account
"GALADO Club" tab, SSO sign-in, tier + Shopping-Credits mirroring, and
order/warranty/review webhooks for G-Coin crediting.

== Changelog ==
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
