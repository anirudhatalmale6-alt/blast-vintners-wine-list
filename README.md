# Blast Vintners Wine List

A WordPress plugin holding the bespoke wine-list storefront for blastvintners.com.

Nothing here is new behaviour. This is the same code that has been running the
wine list for years — it just used to live in the wrong place.

Go-live procedure for the site this belongs to: [GO-LIVE.md](GO-LIVE.md).

## Why this plugin exists

The wine list is not a TablePress setting and it is not custom CSS. It is custom
PHP that had been edited directly into **five files belonging to other people's
software**:

| File | What had been added to it |
|---|---|
| `plugins/tablepress/tablepress.php` | the JavaScript and CSS for the quantity box and the add button |
| `plugins/tablepress/classes/class-render.php` | the extra **Buy** column and the click-to-expand descriptions |
| `plugins/tablepress/controllers/controller-admin.php` | turning imported spreadsheet rows into WooCommerce products |
| `plugins/tablepress/views/view-import.php` | the "Add Woocommerce Product" tickbox on the import screen |
| `themes/x/functions.php` | the endpoint that actually puts an item in the basket |

Two consequences followed from that:

1. **TablePress could never be updated.** An update overwrites its own files, so
   the wine list would vanish. The plugin has been frozen at version 1.4 (2014)
   ever since — while the database still records `tablepress_version: 1.9.2`,
   which is the fingerprint of an update that was applied and then rolled back.
2. **The theme could never be updated or changed**, because one of the five files
   was the theme's own `functions.php`.

With the code here instead, TablePress can be updated and the theme can be
changed or replaced without the wine list noticing.

## How it hooks in

Documented TablePress and WordPress hooks only. No third-party file is modified.

- `tablepress_table_output` — adds the Buy column, the expandable descriptions
  and the "Show Descriptions" toggle to any table that has a stored row → product map
- `wp_head` — prints the `tbpwooaddToCart()` script and the `.tbp-woo-*` styles
- `wp_ajax_my_custom_add_to_cart` / `wp_ajax_nopriv_my_custom_add_to_cart` — the
  add-to-basket endpoint (same action name as before, so nothing else has to change)
- `admin_footer` — puts the "Add Woocommerce Product" tickbox back on the
  TablePress import screen
- `save_post_tablepress_table` — when that box is ticked, creates the products
  and stores the row → product map in the `_table_press_woo_products` post meta
- also carried over from the theme: the `?ifr` iframe/Safari-cookie block, and
  the filter that hides thumbnails in the cart

## Verified equivalence

The refactor was checked against the unmodified site rather than assumed:

- the rendered wine list was captured before and after and compared **node by
  node** — 46,690 elements, attributes, values and text, in order
- the only differences on the wire are whitespace, attribute quote characters,
  and `<` inside one tasting note now being correctly escaped to `&lt;`. A real
  browser reports that description as an identical 417-character string either way
- add-to-basket was driven end to end in a real browser: 772 Buy buttons,
  quantity options 1–13 matching the 13 bottles in stock, and the basket showing
  **£31.00**, matching that row's "Inc Duty & Vat" column
- every file lints clean under PHP 8.3, which is the version this site is moving to


## TablePress 3.x compatibility

Verified against TablePress **3.4** as well as the 1.4 the site ran for a decade.
Two things changed in between and are handled here:

- **The import screen is now rendered by JavaScript after page load**, and has no
  row IDs to hang off. The "Add Woocommerce Product" tickbox is therefore
  anchored to the form and inserted immediately above the Import button, retrying
  while the screen builds itself.
- **`save_post` fires too early.** TablePress writes the table post *before* it
  records the table-ID-to-post-ID mapping, so a lookup during `save_post` finds
  nothing. This plugin uses `tablepress_event_added_table` and
  `tablepress_event_saved_table` instead, both of which fire after the mapping
  exists.

End-to-end import test on TablePress 3.4, WooCommerce 11.1.2, PHP 8.3:

| row | spreadsheet says | product created | quantity box offered |
|---|---|---|---|
| 1 | 6 bottles, unit 1, £58 | stock 6, price 58, cat "Burgundy Red" | 1,2,3,4,5,6 |
| 2 | 12 bottles, unit 2, £28 | stock 12, price 28, cat "Loire White" | 2,4,6,8,10,12 |
| 3 | 3 bottles, unit 1, £47 | stock 3, price 47, cat "Rhone Red" | 1,2,3 |

Note rows 2's quantity box counting in twos, and that every product got a
category — neither of which the original code managed (see `NOTES.md`).


## Removed: the iframe-embed support (1 Oct 2026)

The theme used to hide the menu and logo whenever the site was displayed inside
another website's frame, with a Safari storage-access workaround behind a `?ifr`
switch. That came across into this plugin verbatim, and has now been removed.

Three things had to be true before taking it out, and all three were checked:

1. **The arrangement it existed for is retired.** The site used to be embedded in
   a partner's page; the owner confirms that ended years ago.
2. **Nothing passes `?ifr`.** No page, post or option on the site contains it.
3. **Opayo does not frame anything.** The checkout plugin builds a form that
   POSTs the whole browser to
   `live.sagepay.com/gateway/service/vspform-register.vsp`, and the customer
   returns to the site with a `?crypt=` response. It is the Opayo *Form*
   protocol — a full redirect away and back, with no iframe at any point.

Removing it is also a small win. The CSS hid `#menu-primary-menus` and
`.x-brand img` on *every* page load and only restored them on `window.onload`,
which waits for every image on the page. Measured on the live site, DOM content
was ready at ~1.1s but `load` did not fire until ~1.65s, and the logo and
navigation were `display: none` for that whole gap. Checked side by side
afterwards: on staging both are visible as soon as the DOM is ready; on live
they are still hidden at the same moment.


## The red flash on click (2 Oct 2026)

Reported as "a red thin outline block appears where you clicked, then
disappears". Tracked down by driving real mouse and keyboard input rather than
reading the stylesheet, because the stylesheet alone gave the wrong answer.

The X theme paints a red focus ring on focusable things:

```css
a:focus, .x-btn:focus, [type=submit]:focus,
select:focus, input[type=radio]:focus, input[type=checkbox]:focus
  { outline: rgb(255,42,19) auto 5px; outline-offset: -1px; }
```

But a real mouse click on a **link** does not produce it — measured on the
untouched live site, the computed outline after a genuine click is `none`. The
rule only bites on controls the browser decides deserve a visible focus
indicator. On the wine list that is two of them:

| control | before | after |
|---|---|---|
| "Show Descriptions" checkbox | red ring on mouse click | **no ring** |
| quantity dropdown | red ring on mouse click | red ring (deliberately left) |
| links, the `+`, the add button | no ring | no ring |

The fix is `:focus-visible`, which lets the browser distinguish keyboard
navigation from a mouse click:

```css
a:focus:not(:focus-visible), …, input[type=checkbox]:focus:not(:focus-visible)
  { outline: none; }
```

**The dropdown is left alone on purpose.** Chrome reports `:focus-visible` as
true for a `<select>` even when it is clicked, because you operate one with the
arrow keys once it has focus. Suppressing it would be a real accessibility loss
for a cosmetic gain.

Verified both halves, not just the requested one: after a mouse click the
checkbox ring is gone; after pressing Tab the ring is still there
(`:focus-visible = true`, `outline: rgb(255,42,19)`). Removing a focus indicator
without checking keyboard users still have one would be the easy mistake here.

Lives in this plugin rather than the theme stylesheet because a theme update
would lose it, and because this install ignores the Customizer's Additional CSS.


## The header cart not updating (6 Oct 2026)

Reported after go-live: adding a wine put it in the basket, but the cart in the
top right still said "0 items", and clicking that cart went to the **home page**
rather than the basket.

Two symptoms, one cause. The header is WooCommerce Menu Cart, and while it
thinks the basket is empty it deliberately links to the shop front with the
title "Start shopping". So the wrong link was not a separate bug — it was the
stale count showing its working.

Why the count was stale:

- the old code updated `.bc-mnc__cart-link--count-circle`, a selector belonging
  to a different cart plugin that is not installed here. It matched **zero**
  elements, so that line had never done anything
- `wc_fragment_refresh` alone was not bringing the header back in step

The fix is to make this endpoint behave like WooCommerce's own add-to-cart: the
PHP returns `fragments` (markup keyed by CSS selector, built through
`woocommerce_add_to_cart_fragments`) plus the `cart_hash`, and the JavaScript
swaps those fragments in and then fires `added_to_cart` — the event the header
cart listens for to come out of its empty state.

Verified on the live site:

| | before | after |
|---|---|---|
| header cart text | `0 ITEMS £0.00` | `1 ITEM £31.00` |
| header cart link | `https://blastvintners.com` | `https://blastvintners.com/cart/` |
| clicking it | landed on the home page | lands on the basket |

## Known quirks, reproduced deliberately

These are faithful to the original so that the page renders identically. They are
*not* endorsements — see `NOTES.md`.

- the Buy column's `<th>`/`<td>` carry `class="column-5"`, which duplicates the
  Unit column's class. Some of the site's CSS depends on it
- the scroll-and-notice JavaScript is hard-coded to `#tablepress-39-scroll-wrapper`,
  so on any table other than 39 the confirmation message has nowhere to go
- the stored meta key is `prodcut_id`, spelled that way in the original

---

## 1.1.0 — re-import updates products instead of needing them deleted (15 Oct 2026)

**Before:** every import created a brand-new product for every row, so the only
way to re-import was to delete all products first. Every wine's page, address and
search history was thrown away each time, and the producer pages (Chateau
Pradeaux, Guy Breton, Planque, ...) kept Buy buttons pointing at products deleted
long ago — every click answered "There are no items available at this time".

**Now** a product is identified by its row: **wine + vintage + unit + status**
(`_bvwl_key`). Status is in the key on purpose: the same wine in bond and duty paid
are two prices and must never merge.

| On import | What happens |
|---|---|
| row matches a product | price, stock, description, category, vintage updated in place; address kept |
| row matches nothing | new product |
| product no longer on any imported table | stock 0 / out of stock — **not deleted**; page stays, comes back when re-listed |
| same wine twice on one sheet | both rows sell the one product; listed in the import report |

`_bvwl_tables` records which tables' latest import listed a product, so a wine that
leaves the Transit table but is still on the Full List stays in stock.

After an import, the next admin screen shows a report: *N new, N updated, N
unchanged, N no longer listed*. An unchanged re-import of the 745-row list takes
~2 s (was ~4 min) and changes nothing.

### The one-off adoption (`adopt_existing_products()`)

Products made by 1.0 had no key. On first run each one is keyed from the table row
it was created for. Where two products turned out to be the same wine (the 31
Transit rows are identical to Full List rows and had been imported twice, plus one
row duplicated on the sheet), the first is kept, tables re-pointed at it, the spare
moved to Drafts (never deleted) with `_bvwl_merged_into`, and its old address 301s
to the kept one. Live, 15 Oct: 744 keyed, 32 merged, 776 → 744 published.
Rollback data: `~/BACKUP-import-v11-2026-10-15/`.

### Render side

Each table row resolves its product through the key, not the stored ID — this is
what fixed the producer pages. Rows with no live product show **Sold out** instead
of a "+" that can only fail. The "added to your cart" message now appears above
whichever table was clicked (it was hard-wired to table 39).

⚠ **TablePress caches each table's finished HTML for a day, for logged-out visitors
only** — so whoever runs the import never sees a stale table. The plugin invalidates
all table caches after an import and on `woocommerce_product_set_stock_status`
(a wine selling out or coming back). Without that, the producer pages kept pointing
at the old products after adoption even though the data was right.

## Product pages (1.1.0)

The X theme's product tabs are switched off on this site (`wc_pdc_tabs = no`), and
the description only ever rendered inside them — so the descriptions written for
every wine reached no page a customer could see, only the hidden JSON-LD. Now
printed in the summary with a Vintage / units / In bond–Duty paid line (skipped if
the tabs are ever re-enabled, so it never shows twice). Yoast title gets the
vintage ("Pinot Noir Schueller 2023 - Blast Vintners"); empty meta descriptions
fall back to the first ~155 characters of the description.

Product **names** deliberately do not get the vintage: Joe's back office reads
orders by name.

## Product codes (1.1.0, same day)

Joe's back office gives every product a unique 8-digit code; he used to strip it
before importing. With a code column on the sheet (heading `PRD_CODE` — what his stock software exports —, `Code`, `Product Code`,
`Stock Code`, `Item Code`, `Wine Code`, `SKU`, `Ref` or `Reference`, any position):

- rows match on the code first (`_bvwl_code`, leading zeros ignored since Excel drops
  them), falling back to wine+vintage+unit+status for products that have no code
  yet; the first such import attaches the codes. A product carrying a *different*
  code is never matched by name — two codes are two products.
- correcting a wine's name keeps the same product and address (tested: renamed row
  → "0 new, 1 updated").
- the code is saved as the WooCommerce SKU, so it prints on the admin "New order"
  email; hidden from the product page and its JSON-LD.

⚠ **TablePress stores hidden columns by POSITION.** Every wine table hides exactly
one column, Description. A code column inserted anywhere but the end shifts it, so
Description reappears and **"Inc Duty & Vat" (the price) disappears** — proven on
staging. So on any table WITH a code column, visibility is set by heading via
`tablepress_table_raw_render_data`: Code + Description hidden, everything else
shown. Tables without a code column are untouched.
