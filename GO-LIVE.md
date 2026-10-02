# Go-live procedure — blastvintners.com

Everything below was rehearsed on `staging.blastvintners.com` first. Where the
rehearsal went wrong, the trap is written into the step rather than left for
someone to rediscover.

**We are not copying staging over live.** Staging's database is a snapshot taken
on 30 Sep 2026; real orders have been taken since. Go-live repeats the same
sequence against the live site, with a rollback at every point.

---

## Where live starts, and where it should end

| | Live now | After |
|---|---|---|
| PHP | 7.4.33 | **8.3** |
| WooCommerce | 3.6.7 (2019), DB schema 3.1.2 | **11.1.2**, DB schema 11.1.2 |
| TablePress | 1.4 (2014), 4 files hand-edited | **3.4**, untouched |
| X theme | 1.7.2, `functions.php` hand-edited | 1.7.2, `functions.php` stock |
| Yoast SEO | 11.5 | **28.6** |
| Security | WP Cerber 8.3 (delisted 2022) | **Wordfence 9.0.2** |
| Wine list | inside TablePress + the theme | its own plugin |
| Opayo gateway | AG 1.3.1 | **4.3.6** (licence covers it) |

---

## Order is not negotiable

Two orderings look reasonable and are wrong.

**PHP cannot go first.** WooCommerce 3.6.7 is a hard fatal on PHP 8 —
`wc-formatting-functions.php:791` uses `$color{0}`, removed in PHP 8.0. Switch
PHP before updating WooCommerce and the shop is dead, not degraded.

**The wine-list plugin cannot be activated before TablePress is replaced.** While
the hand-edited TablePress 1.4 is still in place, both it and the plugin add a
Buy column, and you get two. The plugin goes in inactive, TablePress is replaced,
*then* the plugin is activated.

---

## Before the window

- [ ] Agree a quiet hour. Check Opayo for orders in progress.
- [ ] Confirm the Opayo licence question is settled and the gateway version is decided.
- [ ] Confirm nobody else is mid-edit in the admin.

---

## 1. Backup — the only irreversible mistake is not having one

```
mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
  --routines --triggers --events -u USER -pPASS blastvin_wordpress \
  | gzip -6 > ~/GOLIVE-BACKUP-<date>/db.sql.gz

cd ~ && tar --warning=no-file-changed -czf ~/GOLIVE-BACKUP-<date>/public_html.tar.gz public_html
```

**Verify the contents, not that the files exist.** An archive nobody has opened
is not a backup.

- [ ] `gzip -t` clean on both
- [ ] `zcat db.sql.gz | grep -c '^CREATE TABLE'` — expect ~88
- [ ] `zcat db.sql.gz | tail -3` ends with `-- Dump completed`
- [ ] `tar -tzf public_html.tar.gz public_html/wp-config.php` succeeds

Keep rollback copies of each plugin directory before replacing it.

---

## 2. Deactivate the 2012 product importer

`dgrundel-woo-product-importer-6731130` registers an instance method as a static
callback, which is a `TypeError` on PHP 8. It is the **only** thing in the active
set that stops the site booting on 8.3, and no code scan finds it — `php -l`
passes it and a grep for removed functions passes it. It only shows up by running
the site.

Joe confirmed he imports through TablePress and doesn't use this. WooCommerce
also ships its own CSV importer.

- [ ] Deactivate it
- [ ] Shop still loads

---

## 3. WooCommerce 3.6.7 → 11.1.2, still on PHP 7.4

WooCommerce 11.1.2 requires PHP 7.4, so it runs on the current PHP. That is what
makes this order possible at all.

- [ ] Keep a copy of `plugins/woocommerce`
- [ ] Download `woocommerce.11.1.2.zip`, `unzip -tq` it **before** replacing anything
- [ ] Replace the directory
- [ ] Load the shop — expect it to work, and expect the database to still be on the old schema

### 3a. The database migration

57 versions, 121 callbacks. The site renders perfectly throughout while the
schema is six years behind, so **"the pages load" is not the test** — read
`woocommerce_db_version` instead.

Traps from the rehearsal:

- `WC_Install::update()` is **private** in WooCommerce 11. Use `WC_Install::install()`.
- WordPress's generic "critical error" page hides what actually went wrong. Set
  `WP_DISABLE_FATAL_ERROR_HANDLER` while migrating.
- WooCommerce **staggers the callbacks one second apart**. A tight loop outruns
  them, `run()` returns 0, and it looks stuck when it is merely waiting. Pace the
  drain and count pending rows.
- Run it from the CLI, detached. A browser request gets killed on disconnect and
  takes the migration with it.
- `get_option('woocommerce_db_version')` inside a long-running process serves a
  **stale in-process cache**. During the rehearsal it reported 6.4.0 while the
  table already said 11.1.2. Judge progress from the table.

- [ ] `woocommerce_db_version` reads `11.1.2` **read from the options table**
- [ ] No failed rows in `wp_actionscheduler_actions`
- [ ] Orders, products and customers all still present (count them)

Errors mentioning `wp_wc_order_stats` columns (`total_sales`, `gross_total`) are
expected and harmless — the analytics tables get created fresh with the modern
schema, so historical rename steps find nothing to rename.

---

## 4. The wine list moves out of TablePress and the theme

This is the step that has to be tight. Order within it matters.

- [ ] Upload `blast-vintners-wine-list.php` to `wp-content/plugins/` — **do not activate yet**
- [ ] Replace `plugins/tablepress` with TablePress 3.4 (this removes the hand-edits in `classes/class-render.php`, `controllers/controller-admin.php`, `views/view-import.php` and `tablepress.php` in one move)
- [ ] Restore `themes/x/functions.php` to the stock version
- [ ] **Activate the plugin**

Between the TablePress swap and the activation, the Buy column is missing. Keep
it to seconds.

- [ ] Wine list shows 772 Buy buttons
- [ ] A description expands when clicked
- [ ] Add to basket works and the basket shows the **price from the Inc Duty & Vat column**
- [ ] TablePress admin lists all 15 tables

---

## 5. Yoast 11.5 → 28.6, Cerber → Wordfence 9.0.2

- [ ] Yoast replaced; **check the site is still indexable** — Yoast carries sitewide noindex settings
- [ ] **Deactivate Cerber first, then activate Wordfence.** Never both at once — two security plugins fighting is what locked Joe out of his own admin in August 2026
- [ ] Confirm you can still log in before going any further
- [ ] Cerber's old settings include trusted-IP entries for a connection Joe no longer has; they do not need carrying over

---

## 6. PHP 7.4 → 8.3

This account has no MultiPHP. It uses the **CloudLinux PHP Selector**, in cPanel
under Software → "Select PHP Version". The per-directory override is an
`.htaccess` handler:

```
AddHandler application/x-httpd-alt-php83___lsphp .php
```

**Do not choose PHP 8.0 or 8.2 on this server.** Their module sets do not include
`mysqli`, and WordPress then shows "Your PHP installation appears to be missing
the MySQL extension" — which reads like catastrophic failure. 8.1 and 8.3 both
include it.

- [ ] Switch to 8.3
- [ ] Home, wine list, shop, basket, my-account, checkout all load
- [ ] Add to basket still works

Rollback is removing one line from `.htaccess`.

---

## 6a. Opayo gateway 1.3.1 → 4.3.6

**This step must come after both step 3 and step 6, and cannot be moved earlier.**
4.3.6 declares `Requires PHP: 8.1` and `WC requires at least: 7.1.0`. Live is on
PHP 7.4 and WooCommerce 3.6.7, so until those are done the site cannot run it.

That constraint also explains something confusing: live's WordPress only ever
offered an update to **2.2.5.1**, never 4.3.6. That is the licensing system
correctly serving the newest version the site can actually run. Once PHP and
WooCommerce are current, 4.3.6 becomes the right version — and it is the one
Joe's account offers for download.

- [ ] Keep a copy of `plugins/sage-pay-server-woocommerce-premium`
- [ ] `unzip -tq` the zip **before** replacing anything
- [ ] Replace the directory
- [ ] **Check the plugin is still active.** Updating through WordPress's own
      updater left it deactivated on the rehearsal; replacing the directory
      directly did not. Either way, check rather than assume — while it is off,
      checkout offers no card option at all.
- [ ] Confirm `woocommerce_ag_sagepay_server_settings` still holds the vendor name and password

The Form gateway keeps its ID — `inc/ag-woocommerce-sagepay-class.php` still
declares `$this->id = "ag_sagepay_server"` — so the saved settings are found and
the configured gateway carries over intact. Verified on staging: title,
description and enabled state all survived.

4.3.6 registers six gateways where 1.3.1 registered one: `ag_sagepay_server`
(the Form integration in use), plus `ag_sagepay_redirect`, `ag_opayo_pi`,
`ag_opayo_pi_redirect`, `ag_opayo_pi_dropin` and `ag_opayo_direct`. **All five
new ones arrive disabled. Leave them disabled** — the tested, working payment
route is the Form one, and switching routes is a separate decision with its own
testing.

It also adds block-checkout support, card tokenisation and fraud checks, none of
which are in use. 202 PHP files, all clean under PHP 8.3.

---

## 7. Checkout

- [ ] Opayo appears at checkout as "Credit / Debit Card Payment"
- [ ] A real transaction completes and reaches Opayo
- [ ] The order appears in WooCommerce with the right total and status
- [ ] The customer lands back on the order-received page
- [ ] Refund the test transaction

The integration is the Opayo **Form** protocol: the browser POSTs to
`live.sagepay.com/gateway/service/vspform-register.vsp` and returns with an
encrypted `?crypt=` response. A redirect away and back, not an embedded frame.

---

## 8. Things that must NOT travel from staging

Staging is deliberately hidden. If any of this reaches live it is a serious
problem, and one of them is silent.

- [ ] **No `noindex`.** Staging has `blog_public = 0`, a `Disallow: /` robots.txt and an `X-Robots-Tag` header. On live this would quietly remove the shop from Google.
- [ ] **No basic-auth block** in `.htaccess`
- [ ] **No staging URLs** in the database
- [ ] **Outbound mail re-enabled.** Staging carries `wp-content/mu-plugins/000-staging-safety.php`, which blocks `wp_mail` outright so a cloned shop cannot email real customers about old orders. Live has no `mu-plugins` directory at all and must stay that way — if that file ever reaches live, order confirmations stop arriving and nothing visibly breaks, which is the worst kind of fault.
- [ ] **Live Opayo credentials in place and not in test mode**
- [ ] **Remove `anirudha-qa`**, the staging-only admin account
- [ ] **Remove the staging trusted-IP entry** from the security plugin

---

## 9. After

- [ ] Place a real order end to end and refund it
- [ ] Check order confirmation emails actually arrive
- [ ] Watch for 24 hours
- [ ] Run an import of the real wine list and confirm products come out with the right price, stock **and category**
- [ ] Configure Wordfence properly: file-change alerts to Joe's inbox, two-factor on the admin login, scans scheduled overnight
- [ ] Quarantine the junk: the 2012 importer, the WorldPay plugin, `Back up Wordpress V2.02` (which holds WooCommerce 2.0.20 from 2013), `dev/`, `~/wp-content2`, `~/wp-includes`

---

## If it goes wrong

| Step | Rollback |
|---|---|
| PHP | remove the `AddHandler` line from `.htaccess` |
| Any plugin | restore its directory from the rollback copy |
| Wine list | restore the four TablePress files and `themes/x/functions.php`, deactivate the plugin |
| Database | restore `db.sql.gz` — **this loses orders taken since the backup**, so it is the last resort, and the reason the window should be quiet |

Everything up to and including step 6 is reversible without touching the
database. Only the WooCommerce schema migration is one-way, which is why the
verified backup in step 1 comes before anything else.
