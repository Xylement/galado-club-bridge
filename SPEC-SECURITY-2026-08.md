# GALADO Club Bridge plugin: security remediation

**Version:** 2.0 (2026-08-04, supersedes 1.0)
**For:** GALADO plugin developer
**From:** Clement
**Repo:** `galado-club-bridge` (CANONICAL). Never edit the `wp-plugin` working copy.
**Plugin version at time of writing:** 0.51.0
**Source:** independent source-review pentest, 4 Aug 2026. Every finding below was re-verified against the current 0.51.0 source before this was written. None are false positives, and none are intentional behaviour.

---

## 0. How to use this document

There are three findings, plus one question. They are ordered by **what you can act on**, not by severity, because the most severe one needs a coordinated change window and the other two do not.

| Order | Item | Severity | Your instruction |
|---|---|---|---|
| 1 | Welcome and referral discounts are replayable | **HIGH** | Fix and ship |
| 2 | Win-back balance can be double-spent | **MEDIUM** | Fix and ship |
| 3 | Shared secret spans two trust domains | **CRITICAL** | **Build it, then stop. Do not deploy.** |
| 4 | `/wallet-add` permission check | Question | Confirm and report |

Items 1 and 2 are pure plugin work, exploitable today, and cost real money. Please start there.

Item 3 is the most serious finding but it **cannot be deployed by the plugin alone**. Deploying only the plugin half will break the Apple Wallet service in production. Details in section 3.

**Out of scope for you:** the Club backend (Node) had three separate findings. Two are already fixed and deployed, one was accepted as-is. You do not need to touch anything outside this plugin.

**Do not change** reward amounts, discount values, minimum-cart thresholds, or any customer-facing copy. These are security fixes, not product changes.

---

## 1. HIGH: Bind the welcome token, and stop discount replay

**Code:** `galado-club-bridge.php:436-451` (`verify_welcome_token()`), `:461-478` (`first_order_discount()`), `:411-418` (`capture_referral()`), `:547-561` (`is_existing_customer()`)

This is the one worth doing first. It needs no special access to exploit, it works today, and every hit costs real ringgit.

### 1a. The RM30 welcome token is not bound to anyone

The token is `welcome.{memberId}.{expiry}.{signature}`. `verify_welcome_token()` checks the signature and the expiry, then returns true. It never looks at `$parts[1]`, the member id.

The practical effect: one member's token, forwarded to a friend or posted in a group chat, gives **anybody** RM30 off. It is a bearer token for a discount.

**Required:** resolve `$parts[1]` to a member and only grant when that member is the one checking out. For a logged-in shopper, compare against the current user. For a guest, the discount must not survive to payment unless the billing email resolves to that same member at order creation.

### 1b. The RM10 referral discount trusts a cookie's mere presence

`first_order_discount()` grants RM10 whenever `$_COOKIE['galado_ref']` is non-empty. The value is never checked against a real referral code, so anyone can set that cookie in their browser console and take RM10.

**Required:** validate the code server-side against a real, active referrer before granting, and grant nothing when it does not resolve. `capture_referral()` must also refuse to stamp an unvalidated code onto the order, because the Club webhook downstream may credit it.

### 1c. The intro offer is not consumed until payment

`is_existing_customer()` only counts orders in `wc-processing` and `wc-completed`. A shopper can create many `pending` orders, each carrying the discount, and pay them all.

**Required:** reserve or mark the entitlement at **order creation**, not at payment. A second discounted order must not be creatable while an unpaid discounted one exists for the same shopper, and once consumed the entitlement must not return.

### Acceptance criteria

- Member A's welcome token used by shopper B grants **no** RM30.
- A hand-set `galado_ref` cookie with a nonsense code grants **no** RM10 and writes no `galado_ref` order meta.
- Creating a second discounted order while the first is still `pending` does not attach the discount.
- **Regression, please do not skip:** a genuine first-time shopper with a genuine referral link still gets exactly RM10, and a genuine welcome-token holder still gets exactly RM30.

---

## 2. MEDIUM: Reserve the win-back balance at order creation

**Code:** `galado-club-bridge.php:485-505` (`winback_discount()`), `:507-521` (`capture_winback()`), `:524-543` (`consume_winback()`), hooks at `:96-98`

`winback_discount()` reads `_galado_winback_rm` and applies it as a negative fee. The balance is only decremented in `consume_winback()`, which fires on `woocommerce_order_status_processing` or `completed`. Between those two moments the balance is unreserved, so a member holding RM20 can open three checkouts, each showing RM20 off, and pay all three.

**Required:** reserve at order creation. When a discounted order is created, decrement the available balance or write a hold that `winback_discount()` subtracts from availability. Release the hold when an unpaid order is cancelled, fails, or expires. `consume_winback()` then finalises rather than being the only debit. Keep the existing `_galado_winback_consumed` guard so a status transition cannot double-debit.

### Acceptance criteria

- With RM20 available, a second concurrent checkout offers RM0, not RM20.
- Cancelling an unpaid discounted order returns the balance to available.
- A normal single order still applies and consumes exactly once, and the member's remaining balance is correct afterwards.

---

## 3. CRITICAL: Split the shared secret. Build it, then stop.

**Code:** `galado-club-bridge.php:256-274` (`bridge_secret()`, `wallet_url()`, `wallet_post()`), `:315-319` (`bridge_auth()`)

### The problem

`GALADO_CLUB_BRIDGE_SECRET` is used across two different trust boundaries at once:

1. **Outbound**, as the auth header on every server call to the Apple Wallet service.
2. **Inbound**, as the *only* permission check on privileged plugin routes: `/points/add`, `/points/deduct`, `/winback/grant`, `/provision-customer`, `/provision-customers`, `/tier`, `/app-login-link`, `/store-login-link`, `/app-order`, `/app-pay-link`.

Anyone who obtains that value from the wallet path can call `/app-login-link` for **any** member, consume the returned URL, and act as them. The same value also moves points balances.

Both ends already use `hash_equals()` correctly. The flaw is the shared credential, not the comparison.

### Your change, and only this

1. Introduce a new, separate constant `GALADO_WALLET_SECRET`.
2. `wallet_post()` sends `GALADO_WALLET_SECRET`. It must never send `bridge_secret()`.
3. `bridge_auth()` keeps using `GALADO_CLUB_BRIDGE_SECRET`, now exclusively for inbound routes.
4. If `GALADO_WALLET_SECRET` is undefined, wallet calls fail closed: return null and log a non-secret error. Never silently fall back to the bridge secret.
5. Neither value may be logged, echoed, or returned in any response or error body.

### Then stop, and tell Clement it is ready

**Do not deploy this to production, and do not rotate anything.**

The reason is not caution for its own sake. That same secret currently does five jobs across four systems, and one of them is signing the member QR code that is **baked into every Apple Wallet card already sitting on customers' phones**, which the in-store POS scanner verifies. Rotating it naively invalidates every issued card's QR and breaks scanning at the counter until all passes are regenerated and pushed.

Clement's side owns the Club backend, the wallet service and the POS, and will run the rotation as a scheduled dual-key change: accept old and new together, re-issue passes, verify in-store scanning, then retire the old value. Your plugin change goes live as part of that window.

So: build it, keep it on a branch or behind an unreleased version, confirm it works against a staging wallet endpoint if you have one, and report that it is ready.

### Acceptance criteria (for the build, not a deploy)

- `bridge_secret()` is referenced only by `bridge_auth()` and inbound-route code, never by `wallet_post()`.
- With `GALADO_WALLET_SECRET` undefined, wallet calls fail closed and log without exposing a value.
- No secret value appears in `error_log`, REST responses, or admin notices.

---

## 4. Please confirm: `/wallet-add`

`/wallet-add` is registered with `permission_callback => '__return_true'`, so it is publicly callable. It is probably the public Apple Wallet pass endpoint and harmless, but please confirm it cannot mutate a balance, a points value, or an order. If it can, gate it and tell Clement.

For contrast, `/app-pay/` and `/app-login/` are also `__return_true` but are token-gated inside their handlers, which is correct as-is.

---

## 5. Ground rules

- Edit **`galado-club-bridge`** only. The `wp-plugin` copy is a working copy and gets overwritten.
- Bump the plugin version and note each change in `readme.txt`.
- Deploy through the existing Git Sync flow, not by editing files on the server.
- No secret values in commits, comments, logs, or this repo.
- Test on staging against a throwaway order. Do not test the discount paths against real customer orders.
- **Timing:** the win-back and welcome flows are part of a migration going live this week, so sections 1 and 2 should land before or alongside that rollout.

## 6. Reporting back

For each item, please reply with what changed, the plugin version it shipped in, and the acceptance-criteria results. For section 3, just confirm it is built and holding.

If you believe any of these is intentional rather than a defect, say so before changing it and we will agree the approach first.
