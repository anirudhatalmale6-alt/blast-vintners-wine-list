# Findings while moving this code

Observations from reading the original and checking them against the live data.
None of these are changed by the refactor — they are listed so the decisions are
yours rather than mine.

## 1. Every import creates a fresh set of products

`wp_insert_post()` is called for each spreadsheet row with no check for an
existing product, so importing a list again creates duplicates rather than
updating what is there.

Measured on the live database:

- **1,764** product records, but only **848** distinct titles
- "Porseleinberg Syrah" exists **10** times; "Bandol Rouge Pradeaux" 9 times;
  "Chablis Vauprin Lavantureux" and three others 8 times each

Old copies are never unpublished, so they stay in the shop and in search results.

## 2. The importer looks for column names the spreadsheets do not use

The import code reads the header row by exact name:

```php
$product_cat_key = array_search('Category', $product_header);
$product_qty_key = array_search('Bottles',  $product_header);
```

The actual header row of the main list is:

```
Region | Vintage | Wine | Quantity | Unit | Status | Ex Vat | Inc Duty & Vat | Description
```

So `Category` and `Bottles` are never found. Consistent with what is in the
database: only **139** category assignments across **1,636** published products.

The stored quantities *are* correct, which means the map currently in the
database was written by an earlier version of this code that used different
column names. As the code stands today, a fresh import would fall back to
`_stock = 1` for every wine — so a line with 12 bottles would sell out after one.

**This plugin matches column names case-insensitively and accepts the
alternatives actually in use** (`Region` for category, `Quantity`/`Qty` for
bottles). That is the one deliberate behavioural change, and it only affects
future imports.

## 3. `_sku` is always empty

`$product_info['sku']` is never assigned before being written, so every product
gets an empty SKU. Confirmed: `_sku` is NULL on the products checked.

## 4. The add-to-cart endpoint is unauthenticated

`wp_ajax_nopriv_my_custom_add_to_cart` with no nonce. The worst a stranger can do
is put items in their own basket, so this is low risk, and it has been left as it
was rather than changed underneath a working shop.

## 5. Descriptions are stored twice

Each description lives both in the table's own data and in the
`_table_press_woo_products` meta, and again as the product's `post_content`.
They can drift apart. The renderer uses the copy in the meta.
