# Blast Vintners Wine List

A WordPress plugin holding the bespoke wine-list storefront for blastvintners.com.

Nothing here is new behaviour. This is the same code that has been running the
wine list for years — it just used to live in the wrong place.

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

## Known quirks, reproduced deliberately

These are faithful to the original so that the page renders identically. They are
*not* endorsements — see `NOTES.md`.

- the Buy column's `<th>`/`<td>` carry `class="column-5"`, which duplicates the
  Unit column's class. Some of the site's CSS depends on it
- the scroll-and-notice JavaScript is hard-coded to `#tablepress-39-scroll-wrapper`,
  so on any table other than 39 the confirmation message has nowhere to go
- the stored meta key is `prodcut_id`, spelled that way in the original
