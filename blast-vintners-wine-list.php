<?php
/**
 * Plugin Name: Blast Vintners Wine List
 * Description: The bespoke wine-list storefront: the Buy column and expandable descriptions on TablePress tables, the add-to-cart endpoint behind them, and the spreadsheet-import-to-WooCommerce-product step. Previously this code lived inside the TablePress plugin's own source files and inside the X theme's functions.php, which meant updating either one destroyed it. It now lives here, on documented hooks only.
 * Version: 1.3.1
 * Author: Anirudha Talmale
 * Requires PHP: 7.4
 *
 * Behaviour is deliberately identical to the previous in-core version, including
 * quirks, so that the rendered page is byte-for-byte the same. Anything that looks
 * like a bug below is reproduced on purpose; see the notes in NOTES.md.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BVWL_Wine_List' ) ) :

final class BVWL_Wine_List {

	const META_KEY = '_table_press_woo_products';
	const VERSION  = '1.3.1';

	/*
	 * Since 1.1.0 a product is identified by what the spreadsheet row says it is
	 * -- wine + vintage + unit + status -- rather than by the ID the last import
	 * happened to give it. That is what lets a re-import UPDATE the products that
	 * are already there instead of needing them all deleted first.
	 */
	const KEY_META     = '_bvwl_key';      // normalised "wine|vintage|unit|status"
	const TABLES_META  = '_bvwl_tables';   // ids of the tables whose latest import listed this product
	const VINTAGE_META = '_bvwl_vintage';
	const UNIT_META    = '_bvwl_unit';
	const STATUS_META  = '_bvwl_status';
	const CODE_META    = '_bvwl_code';     // Joe's 8-digit product code, when the sheet carries it
	const ADOPTED_OPTION = 'bvwl_adopted_existing';

	/** Headings the product-code column may have. Matched case-insensitively. */
	const CODE_HEADERS = array( 'PRD_CODE', 'PRD CODE', 'Code', 'Product Code', 'Stock Code', 'Item Code', 'Wine Code', 'SKU', 'Ref', 'Reference' );

	/** @var array<int,array> cached row->product maps, keyed by TablePress table id */
	private $row_products_cache = array();

	/** @var array<string,array<int,int>> per table: rendered row index => row index in the stored table */
	private $row_origin = array();

	/** @var array|null per-request product index, see product_index() */
	private $product_index = null;

	public static function instance() {
		static $inst = null;
		if ( null === $inst ) {
			$inst = new self();
		}
		return $inst;
	}

	private function __construct() {
		// --- front end -------------------------------------------------
		add_action( 'wp_head', array( $this, 'print_assets' ) );
		add_filter( 'tablepress_table_output', array( $this, 'augment_table' ), 10, 3 );

		// --- add to cart endpoint --------------------------------------
		add_action( 'wp_ajax_my_custom_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_my_custom_add_to_cart', array( $this, 'ajax_add_to_cart' ) );

		// --- carried over from the X theme's functions.php --------------
		add_filter( 'woocommerce_cart_item_thumbnail', '__return_empty_string' );

		// --- spreadsheet import -> WooCommerce products -----------------
		add_action( 'admin_footer', array( $this, 'print_import_checkbox' ) );

		// TablePress's own events. Both hand us the table ID, and both fire
		// *after* the table ID -> post ID map has been written, which matters:
		// hooking save_post instead runs too early, when that map does not yet
		// know about the new table and the lookup comes back empty.
		add_action( 'tablepress_event_added_table', array( $this, 'on_table_written' ), 10, 1 );
		add_action( 'tablepress_event_saved_table', array( $this, 'on_table_written' ), 10, 1 );
		add_action( 'admin_notices', array( $this, 'print_import_report' ) );

		// TablePress keeps each table's finished HTML -- Buy buttons and "Sold
		// out" included -- for up to a day, for logged-out visitors only (so the
		// person who runs the import never sees a stale one). Throw it away
		// whenever what those buttons depend on changes.
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'schedule_table_cache_flush' ) );
		add_action( 'shutdown', array( $this, 'maybe_flush_table_caches' ) );

		// A new version of this plugin can change what a table renders to, but
		// TablePress would keep serving yesterday's HTML to visitors for up to a
		// day. Proven the hard way: a fixed Buy-button mapping went live and the
		// cached, wrong one kept being served. So a version change flushes.
		add_action( 'init', array( $this, 'flush_on_version_change' ) );

		// --- single product page: vintage + description, search snippet ----
		add_action( 'woocommerce_single_product_summary', array( $this, 'product_page_details' ), 25 );
		add_action( 'wp', array( $this, 'product_layout_setup' ) );
		add_action( 'template_redirect', array( $this, 'render_region_page' ), 20 );
		add_filter( 'wpseo_title', array( $this, 'seo_title' ) );
		add_filter( 'wpseo_title', array( $this, 'region_seo_title' ) );
		add_filter( 'wpseo_metadesc', array( $this, 'region_seo_description' ) );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'region_seo_description' ) );
		add_filter( 'wpseo_metadesc', array( $this, 'seo_description' ) );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'seo_description' ) );
		add_action( 'template_redirect', array( $this, 'redirect_merged_duplicate' ) );

		// --- the product code: matched on, never shown to customers --------
		add_filter( 'tablepress_table_raw_render_data', array( $this, 'hide_code_column' ), 10, 2 );
		add_filter( 'tablepress_table_raw_render_data', array( $this, 'remember_row_origins' ), 99, 2 );
		add_filter( 'wc_product_sku_enabled', array( $this, 'sku_only_behind_the_scenes' ) );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'structured_data_without_code' ), 10, 2 );
	}

	/**
	 * The code column is for matching, not for customers.
	 *
	 * TablePress remembers hidden columns by POSITION, not by heading. Every wine
	 * table here hides exactly one column, Description (the Buy column's toggle
	 * shows it instead). Add a Code column anywhere but the very end and every
	 * column shifts one place: Description reappears and "Inc Duty & Vat" -- the
	 * price -- disappears. So on any table that has a code column, visibility is
	 * decided by heading instead: Code and Description hidden, everything else
	 * shown, wherever the columns sit. Tables without a code column are left
	 * exactly as TablePress has them.
	 */
	public function hide_code_column( $table, $render_options ) {
		if ( empty( $table['data'][0] ) || ! is_array( $table['data'][0] ) ) {
			return $table;
		}
		$header = $table['data'][0];
		if ( false === self::find_col( $header, self::CODE_HEADERS ) ) {
			return $table;
		}
		$hide = array_merge( self::CODE_HEADERS, array( 'Description' ) );
		foreach ( $header as $idx => $label ) {
			$table['visibility']['columns'][ $idx ] = ( false === self::find_col( array( $label ), $hide ) ) ? 1 : 0;
		}
		return $table;
	}

	/**
	 * One Full List, many views: [table id=39 filter="Guy Breton"] shows only the
	 * matching rows (TablePress Row Filter extension). That extension DELETES the
	 * non-matching rows and renumbers the rest before rendering, so "row 5" of a
	 * filtered table is not row 5 of the stored one -- and every Buy button would
	 * be wired to the wrong wine. Record where each surviving row came from.
	 *
	 * The surviving rows are an in-order subset of the stored rows with their
	 * cells untouched, so walking both lists side by side recovers the mapping.
	 */
	public function remember_row_origins( $table, $render_options ) {
		$table_id = isset( $table['id'] ) ? (string) $table['id'] : '';
		unset( $this->row_origin[ $table_id ] );
		if ( '' === $table_id || empty( $table['data'] ) ) {
			return $table;
		}
		$post_id = $this->table_id_to_post_id( $table_id );
		$stored  = $post_id ? json_decode( get_post( $post_id )->post_content, true ) : null;
		if ( ! is_array( $stored ) || count( $stored ) === count( $table['data'] ) ) {
			return $table; // nothing removed: row N is row N
		}
		$map = array();
		$j   = 0;
		$n   = count( $stored );
		foreach ( $table['data'] as $i => $row ) {
			while ( $j < $n && $stored[ $j ] != $row ) { // loose: same cells, ints vs strings tolerated
				$j++;
			}
			if ( $j >= $n ) {
				$this->row_origin[ $table_id ] = array(); // could not line up: safer to offer no Buy buttons than wrong ones
				return $table;
			}
			$map[ $i ] = $j;
			$j++;
		}
		$this->row_origin[ $table_id ] = $map;
		return $table;
	}

	/**
	 * The code is stored as the WooCommerce SKU so it appears in the admin and on
	 * the "New order" email Joe receives (WooCommerce prints SKUs on admin emails
	 * only). Keep it off the public product page.
	 */
	public function sku_only_behind_the_scenes( $enabled ) {
		if ( ! is_admin() && function_exists( 'is_product' ) && is_product() ) {
			return false;
		}
		return $enabled;
	}

	/** ...and out of the product page's hidden search-engine data too. */
	public function structured_data_without_code( $markup, $product ) {
		if ( is_array( $markup ) && $product instanceof WC_Product && isset( $markup['sku'] ) && (string) $markup['sku'] === (string) $product->get_sku() && '' !== (string) $product->get_sku() ) {
			$markup['sku'] = $product->get_id();
		}
		return $markup;
	}

	/**
	 * A duplicate product hidden by adopt_existing_products() leaves its old
	 * address behind. Send it, permanently, to the product it was merged into.
	 */
	public function redirect_merged_duplicate() {
		if ( ! is_404() ) {
			return;
		}
		$slug = get_query_var( 'name' );
		if ( ! $slug || 'product' !== get_query_var( 'post_type' ) && ! get_query_var( 'product' ) ) {
			return;
		}
		global $wpdb;
		$keep = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT m.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bvwl_merged_into'
				  WHERE p.post_type = 'product' AND p.post_status = 'draft' AND p.post_name = %s LIMIT 1",
				$slug
			)
		);
		if ( $keep && 'publish' === get_post_status( $keep ) ) {
			wp_safe_redirect( get_permalink( $keep ), 301 );
			exit;
		}
	}

	/* =====================================================================
	 * TablePress output cache
	 * ===================================================================== */

	private $flush_tables = false;

	public function flush_on_version_change() {
		if ( get_option( 'bvwl_version' ) !== self::VERSION ) {
			update_option( 'bvwl_version', self::VERSION, false );
			$this->schedule_table_cache_flush();
		}
	}

	public function schedule_table_cache_flush() {
		$this->flush_tables = true;
	}

	public function maybe_flush_table_caches() {
		if ( $this->flush_tables ) {
			$this->flush_table_caches();
		}
	}

	/** Once per request at most; covers every table, since any of them can show a given wine. */
	public function flush_table_caches() {
		$this->flush_tables = false;
		if ( class_exists( 'TablePress' ) && isset( TablePress::$model_table ) && method_exists( TablePress::$model_table, 'invalidate_table_output_caches' ) ) {
			TablePress::$model_table->invalidate_table_output_caches();
			return true;
		}
		return false;
	}

	/* =====================================================================
	 * Identity of a wine: the row key
	 * ===================================================================== */

	private static function norm( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ), 'UTF-8' ) : strtolower( trim( $s ) );
	}

	/**
	 * Status (IB / DP) is part of the key on purpose: the same wine can be on the
	 * list in bond AND duty paid at two different prices, and those must never
	 * be merged into one product.
	 */
	public static function make_key( $wine, $vintage, $unit, $status ) {
		return self::norm( $wine ) . '|' . self::norm( $vintage ) . '|' . self::norm( $unit ) . '|' . self::norm( $status );
	}

	/** Find a header column by any of its known names, case-insensitively. */
	private static function find_col( array $header, array $names ) {
		foreach ( $names as $want ) {
			foreach ( $header as $idx => $label ) {
				if ( 0 === strcasecmp( trim( (string) $label ), $want ) ) {
					return $idx;
				}
			}
		}
		return false;
	}

	/** Column positions for one table's header row. */
	private static function columns( array $header ) {
		return array(
			'cat'     => self::find_col( $header, array( 'Category', 'Region' ) ),
			'wine'    => self::find_col( $header, array( 'Wine', 'Name' ) ),
			'desc'    => self::find_col( $header, array( 'Description' ) ),
			'cost'    => self::find_col( $header, array( 'Inc Duty & Vat', 'Inc Duty and Vat', 'Price' ) ),
			'unit'    => self::find_col( $header, array( 'Unit' ) ),
			'vintage' => self::find_col( $header, array( 'Vintage' ) ),
			'qty'     => self::find_col( $header, array( 'Bottles', 'Quantity', 'Qty' ) ),
			'status'  => self::find_col( $header, array( 'Status' ) ),
			'code'    => self::find_col( $header, self::CODE_HEADERS ),
		);
	}

	/**
	 * The code as given, tidied. Excel quietly drops leading zeros from numbers,
	 * so matching ignores them ("01234567" and "1234567" are the same code).
	 */
	private static function row_code( array $row, array $cols ) {
		return preg_replace( '/\s+/u', '', self::cell( $row, $cols['code'] ) );
	}

	private static function code_id( $code ) {
		$code = strtolower( preg_replace( '/\s+/u', '', (string) $code ) );
		if ( '' !== $code && ctype_digit( $code ) ) {
			$code = ltrim( $code, '0' );
			$code = '' === $code ? '0' : $code;
		}
		return $code;
	}

	/**
	 * Which product does this row describe? The code if the sheet has one;
	 * otherwise wine + vintage + unit + status. A product that already carries a
	 * DIFFERENT code is never matched by name -- two codes are two products.
	 */
	private static function resolve_row( array $row, array $cols, array $index ) {
		$code = self::code_id( self::row_code( $row, $cols ) );
		if ( '' !== $code && isset( $index['by_code'][ $code ] ) ) {
			return $index['by_code'][ $code ];
		}
		$key = self::row_key( $row, $cols );
		if ( isset( $index['by_key'][ $key ] ) ) {
			$id   = $index['by_key'][ $key ];
			$has  = $index['by_id'][ $id ]['code'];
			if ( '' === $code || '' === $has || $has === $code ) {
				return $id;
			}
		}
		return 0;
	}

	private static function cell( array $row, $idx ) {
		return ( false !== $idx && isset( $row[ $idx ] ) ) ? (string) $row[ $idx ] : '';
	}

	private static function row_key( array $row, array $cols ) {
		return self::make_key(
			self::cell( $row, $cols['wine'] ),
			self::cell( $row, $cols['vintage'] ),
			self::cell( $row, $cols['unit'] ),
			self::cell( $row, $cols['status'] )
		);
	}

	/**
	 * Every product, once per request: id => [status, stock_status, key], plus
	 * key => id for the keyed ones. One query instead of one per table row.
	 */
	private function product_index( $refresh = false ) {
		if ( null !== $this->product_index && ! $refresh ) {
			return $this->product_index;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_status, k.meta_value AS k, s.meta_value AS ss, c.meta_value AS c
				   FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s
				   LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status'
				   LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
				  WHERE p.post_type = 'product' AND p.post_status IN ('publish','private','draft','pending')
				  ORDER BY p.ID ASC",
				self::KEY_META,
				self::CODE_META
			)
		);
		$by_id   = array();
		$by_key  = array();
		$by_code = array();
		foreach ( $rows as $r ) {
			$id   = (int) $r->ID;
			$code = self::code_id( (string) $r->c );
			$by_id[ $id ] = array(
				'status' => $r->post_status,
				'stock'  => (string) $r->ss,
				'key'    => (string) $r->k,
				'code'   => $code,
			);
			if ( '' !== $code && ( ! isset( $by_code[ $code ] ) || ( 'publish' === $r->post_status && 'publish' !== $by_id[ $by_code[ $code ] ]['status'] ) ) ) {
				$by_code[ $code ] = $id;
			}
			// Published wins over a hidden one with the same key; otherwise the oldest.
			if ( '' !== (string) $r->k ) {
				if ( ! isset( $by_key[ $r->k ] ) || ( 'publish' === $r->post_status && 'publish' !== $by_id[ $by_key[ $r->k ] ]['status'] ) ) {
					$by_key[ $r->k ] = $id;
				}
			}
		}
		$this->product_index = array( 'by_id' => $by_id, 'by_key' => $by_key, 'by_code' => $by_code );
		return $this->product_index;
	}

	/* =====================================================================
	 * Table id  <->  post id
	 * ===================================================================== */

	private function table_id_to_post_id( $table_id ) {
		$opt = get_option( 'tablepress_tables' );
		if ( ! $opt ) {
			return 0;
		}
		$opt = json_decode( $opt );
		if ( ! isset( $opt->table_post ) ) {
			return 0;
		}
		foreach ( $opt->table_post as $key => $value ) {
			if ( (string) $key === (string) $table_id ) {
				return (int) $value;
			}
		}
		return 0;
	}

	private function post_id_to_table_id( $post_id ) {
		$opt = get_option( 'tablepress_tables' );
		if ( ! $opt ) {
			return '';
		}
		$opt = json_decode( $opt );
		if ( ! isset( $opt->table_post ) ) {
			return '';
		}
		foreach ( $opt->table_post as $key => $value ) {
			if ( (int) $value === (int) $post_id ) {
				return (string) $key;
			}
		}
		return '';
	}

	/* =====================================================================
	 * Front end: the row -> product map for one table
	 *
	 * Mirrors what the old TablePress_Render::_render_table() did: only rows
	 * that are VISIBLE get an entry, re-indexed from zero in visible order, so
	 * entry N lines up with the Nth body row that actually gets printed.
	 * ===================================================================== */

	private function get_row_products( $table ) {
		$table_id = isset( $table['id'] ) ? $table['id'] : '';
		if ( '' === $table_id || ! class_exists( 'woocommerce' ) ) {
			return array();
		}
		$origin    = isset( $this->row_origin[ (string) $table_id ] ) ? $this->row_origin[ (string) $table_id ] : null;
		$cache_key = $table_id . '|' . ( null === $origin ? '' : md5( wp_json_encode( $origin ) ) );
		if ( isset( $this->row_products_cache[ $cache_key ] ) ) {
			return $this->row_products_cache[ $cache_key ];
		}
		if ( array() === $origin ) {
			return array(); // a filtered table whose rows could not be traced back
		}

		$post_id = $this->table_id_to_post_id( $table_id );
		$meta    = $post_id ? get_post_meta( $post_id, self::META_KEY, true ) : '';

		$row_products = array();
		if ( is_array( $meta ) && ! empty( $meta ) ) {

			$has_a_product = false;
			foreach ( $meta as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['prodcut_id'] ) ) { // key spelling is the original's
					$has_a_product = true;
					break;
				}
			}

			if ( $has_a_product && ! empty( $table['visibility']['rows'] ) ) {

				// The row itself says which wine it is. Resolving through that,
				// rather than trusting the stored product ID, is what keeps the
				// producer pages working: their stored IDs pointed at products a
				// later delete-and-reimport had removed, so every Buy button on
				// them answered "There are no items available at this time".
				$raw   = json_decode( get_post( $post_id )->post_content, true );
				$cols  = ( is_array( $raw ) && ! empty( $raw[0] ) ) ? self::columns( $raw[0] ) : null;
				$index = $this->product_index();

				$n = 0;
				foreach ( $table['visibility']['rows'] as $shown => $visible ) {
					// On a filtered table, translate back to the stored row.
					$key = ( null !== $origin && isset( $origin[ $shown ] ) ) ? $origin[ $shown ] : $shown;
					if ( $key > 0 && $visible ) {
						$pid = isset( $meta[ $key ]['prodcut_id'] ) ? (int) $meta[ $key ]['prodcut_id'] : 0;

						if ( $cols && false !== $cols['wine'] && isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
							$found = self::resolve_row( $raw[ $key ], $cols, $index );
							if ( $found && 'publish' === $index['by_id'][ $found ]['status'] ) {
								$pid = $found;
							}
						}

						$available = $pid
							&& isset( $index['by_id'][ $pid ] )
							&& 'publish' === $index['by_id'][ $pid ]['status']
							&& 'outofstock' !== $index['by_id'][ $pid ]['stock'];

						$row_products[ $n ] = array(
							'product_id'  => $pid,
							'available'   => $available,
							'description' => isset( $meta[ $key ]['description'] ) ? $meta[ $key ]['description'] : '',
							'qty'         => isset( $meta[ $key ]['qty'] ) ? $meta[ $key ]['qty'] : '',
							'unit'        => isset( $meta[ $key ]['unit'] ) ? $meta[ $key ]['unit'] : '',
						);
						$n++;
					}
				}
			}
		}

		$this->row_products_cache[ $cache_key ] = $row_products;
		return $row_products;
	}

	/* =====================================================================
	 * Front end: add the Buy column and the expandable descriptions
	 * ===================================================================== */

	public function augment_table( $output, $table, $render_options ) {

		$row_products = $this->get_row_products( $table );
		if ( empty( $row_products ) ) {
			return $output;
		}

		// Which visible column holds the wine name? Same lookup as before: the
		// header row of the data that actually got rendered.
		$wine_col_idx = false;
		if ( ! empty( $table['data'][0] ) && is_array( $table['data'][0] ) ) {
			$wine_col_idx = array_search( 'Wine', $table['data'][0], false );
		}

		$doc = $this->load_html( $output );
		if ( ! $doc ) {
			return $output; // unparseable for some reason: leave the table exactly as it was
		}
		list( $doc, $wrapper ) = $doc;

		$xp     = new DOMXPath( $doc );
		$tables = $xp->query( './/table', $wrapper );
		if ( ! $tables->length ) {
			return $output;
		}
		$table_el = $tables->item( 0 );

		// --- header: append the Buy column ------------------------------
		$head_rows = $xp->query( './/thead/tr', $table_el );
		foreach ( $head_rows as $tr ) {
			$th = $doc->createElement( 'th', 'Buy' );
			$th->setAttribute( 'class', 'column-5' ); // class name kept from the original markup
			$tr->appendChild( $th );
		}

		// --- body rows ---------------------------------------------------
		$body_rows = $xp->query( './/tbody/tr', $table_el );
		$i = 0;
		foreach ( $body_rows as $tr ) {
			if ( ! isset( $row_products[ $i ] ) ) {
				$i++;
				continue;
			}
			$rp      = $row_products[ $i ];
			$row_idx = $i + 1; // the old code counted the header as row 0

			// wine cell: wrap what is there, then add the hidden description
			if ( false !== $wine_col_idx && $wine_col_idx ) { // `&& $wine_col_idx` reproduces the original's truthiness test
				$cells = $xp->query( './td|./th', $tr );
				$cell  = $cells->item( (int) $wine_col_idx );
				if ( $cell ) {
					$this->wrap_wine_cell( $doc, $cell, $row_idx, (string) $rp['description'] );
				}
			}

			// buy cell
			$td = $this->build_buy_cell( $doc, $row_idx, $rp );
			$tr->appendChild( $td );
			$i++;
		}

		$html = $this->inner_html( $wrapper );

		// --- the "Show Descriptions" toggle above the table --------------
		$toggle = "<p class='tbpwoodesc'>Show Descriptions <input type='checkbox'  onclick='jQuery(\".tbp-woo-desc\").toggle()'/></p>\n";

		return $toggle . $html;
	}

	private function wrap_wine_cell( DOMDocument $doc, DOMElement $cell, $row_idx, $description ) {
		$toggle_js = 'jQuery("#tbp-woo-desc' . $row_idx . '").toggle("slow");';

		$name_div = $doc->createElement( 'div' );
		$name_div->setAttribute( 'onclick', $toggle_js );
		while ( $cell->firstChild ) {
			$name_div->appendChild( $cell->firstChild );
		}

		$desc_div = $doc->createElement( 'div' );
		$desc_div->setAttribute( 'class', 'tbp-woo-desc' );
		$desc_div->setAttribute( 'id', 'tbp-woo-desc' . $row_idx );
		$desc_div->setAttribute( 'style', 'display:none;' );
		$desc_div->setAttribute( 'onclick', $toggle_js );
		$p = $doc->createElement( 'p' );
		$p->appendChild( $doc->createTextNode( (string) $description ) );
		$desc_div->appendChild( $p );

		$cell->appendChild( $name_div );
		$cell->appendChild( $doc->createTextNode( ' ' ) );
		$cell->appendChild( $desc_div );
		$cell->appendChild( $doc->createTextNode( "\n            " ) );
	}

	private function build_buy_cell( DOMDocument $doc, $row_idx, array $rp ) {
		$td = $doc->createElement( 'td' );
		$td->setAttribute( 'class', 'column-5' );

		// A wine that is gone or sold out says so, instead of offering a "+"
		// that can only ever end in an error message.
		if ( isset( $rp['available'] ) && ! $rp['available'] ) {
			$span = $doc->createElement( 'span', 'Sold out' );
			$span->setAttribute( 'class', 'bvwl-soldout' );
			$td->appendChild( $span );
			return $td;
		}

		$a = $doc->createElement( 'a', '+' );
		// No space after the colon: DOMDocument percent-encodes spaces in href
		// (it treats href as a URI), which would turn the original
		// "javascript: void(0)" into "javascript:%20void(0)". Same effect, but
		// there is no reason to ship a URL-encoded space.
		$a->setAttribute( 'href', 'javascript:void(0)' );
		$a->setAttribute( 'onclick', 'jQuery(".tbp-woo-qty").hide("slow");jQuery("#tbp-woo-qty' . $row_idx . '").show("slow");' );
		$a->setAttribute( 'style', 'text-decoration: none;color: #000;font-size: 18px;padding: 0 5px;' );
		$td->appendChild( $a );
		$td->appendChild( $doc->createTextNode( "\n\n        " ) );

		$wrap = $doc->createElement( 'div' );
		$wrap->setAttribute( 'class', 'tbp-woo-qty' );
		$wrap->setAttribute( 'id', 'tbp-woo-qty' . $row_idx );
		$wrap->setAttribute( 'style', 'display: none;' );

		$select = $doc->createElement( 'select' );
		$select->setAttribute( 'class', 'tbp-woo-qt' );
		$select->setAttribute( 'id', 'tbp-woo-qt' . $row_idx );

		// For wines sold by the case (unit > 1) the sheet's quantity column counts
		// CASES, and the price is per case -- Joe confirmed, 22 Oct 2026: "quantity
		// = 1 and unit = 12 is 1 case of 12 bottles". The original code treated
		// the quantity as bottles and offered multiples of the unit (6, 12, 18...)
		// up to it, which for 151 of 152 case wines produced an EMPTY list, and for
		// the one that wasn't empty offered "6" -- six cases. Offer 1..N cases.
		if ( $rp['qty'] && $rp['unit'] && is_int( $rp['unit'] ) && is_int( $rp['qty'] ) && $rp['unit'] > 1 ) {
			$select->setAttribute( 'class', 'tbp-woo-qt bvwl-case' );
			for ( $n = 1; $n <= $rp['qty']; $n++ ) {
				$o = $doc->createElement( 'option', $n . ( 1 === $n ? ' case' : ' cases' ) );
				$o->setAttribute( 'value', (string) $n );
				$select->appendChild( $o );
			}
		} elseif ( $rp['qty'] && $rp['unit'] && is_int( $rp['unit'] ) && is_int( $rp['qty'] ) ) {
			// Single bottles: unchanged, 1..N.
			$slt = $rp['unit'];
			while ( $slt <= $rp['qty'] ) {
				$o = $doc->createElement( 'option', (string) $slt );
				$o->setAttribute( 'value', (string) $slt );
				$select->appendChild( $o );
				$slt += $rp['unit'];
			}
		} else {
			$o = $doc->createElement( 'option', '1' );
			$o->setAttribute( 'value', '1' );
			$select->appendChild( $o );
		}
		$wrap->appendChild( $select );

		$btn = $doc->createElement( 'input' );
		$btn->setAttribute( 'type', 'button' );
		$btn->setAttribute( 'value', 'add' );
		$btn->setAttribute( 'class', 'tbp-woo-btn' );
		$btn->setAttribute( 'onclick', 'tbpwooaddToCart(' . $rp['product_id'] . ',' . $row_idx . ')' );
		$wrap->appendChild( $btn );

		$td->appendChild( $wrap );
		$td->appendChild( $doc->createTextNode( "\n        " ) );
		return $td;
	}

	/* ------------------------------------------------------------------ */

	private function load_html( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return false;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument( '1.0', 'UTF-8' );
		$ok   = $doc->loadHTML(
			'<?xml encoding="utf-8" ?><div id="bvwl-wrapper">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return false;
		}
		$wrapper = $doc->getElementById( 'bvwl-wrapper' );
		if ( ! $wrapper ) {
			$xp  = new DOMXPath( $doc );
			$hit = $xp->query( '//div[@id="bvwl-wrapper"]' );
			if ( ! $hit->length ) {
				return false;
			}
			$wrapper = $hit->item( 0 );
		}
		return array( $doc, $wrapper );
	}

	private function inner_html( DOMNode $node ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= $node->ownerDocument->saveHTML( $child );
		}
		return $out;
	}

	/* =====================================================================
	 * Front end assets (moved out of tablepress/tablepress.php)
	 * ===================================================================== */

	public function print_assets() {
		$site_url = site_url();
		$ajaxurl  = esc_attr( admin_url( 'admin-ajax.php' ) );
		?>
    <script type="text/javascript">

       /* Put the header cart back in step with the server. WooCommerce returns
          "fragments" -- chunks of markup keyed by CSS selector -- and swapping
          them in is exactly what its own add-to-cart button does. Firing
          added_to_cart afterwards is the event the header cart plugin listens
          for to come out of its "empty" state, which is also what fixes the
          cart link pointing at the home page. */
       function bvwlApplyCart(response) {
         if ( response && response.fragments ) {
           jQuery.each( response.fragments, function( key, value ) {
             jQuery( key ).replaceWith( value );
           });
         }
         jQuery( document.body ).trigger( 'wc_fragment_refresh' );
         jQuery( document.body ).trigger( 'added_to_cart', [
           response ? response.fragments : null,
           response ? response.cart_hash : null
         ]);
       }

       function tbpwooaddToCart(p_id,r_idx) {

      var ajaxurl = '<?php echo $ajaxurl; ?>';
      var $row  = jQuery( "#tbp-woo-qty"+r_idx );
      var $cell = $row.closest( 'td' );
      var wine  = jQuery.trim( $row.closest( 'tr' ).find( 'div[onclick*="tbp-woo-desc"]' ).first().text() );
      var qtyEl = jQuery( "#tbp-woo-qt"+r_idx );
      var qty   = qtyEl.val();
      var qtyTx = qtyEl.find( 'option:selected' ).text() || qty;

      <?php if ( 'v2' === get_option( 'bvwl_add_feedback' ) ) : ?>
      /* v2: confirm in place and in a small toast. The page does not move --
         the old version put WooCommerce's green notice above the table and
         scrolled up to it, so you lost your place in a 700-row list. */
      $row.find( '.tbp-woo-btn' ).prop( 'disabled', true ).val( '…' );
      jQuery.post(ajaxurl, { action: 'my_custom_add_to_cart', product_id: p_id, qty: qty ? qty : 1 }, function(response) {
        if ( response && response.success ) {
          bvwlApplyCart( response );
          $row.hide().find( '.tbp-woo-btn' ).prop( 'disabled', false ).val( 'add' );
          $cell.find( '.bvwl-added' ).remove();
          $cell.append( '<span class="bvwl-added">&#10003; Added</span>' );
          bvwlToast( 'ok', 'Added to your basket', ( qtyTx ? qtyTx + ' × ' : '' ) + wine );
        } else {
          $row.find( '.tbp-woo-btn' ).prop( 'disabled', false ).val( 'add' );
          var msg = response && response.message ? jQuery( '<div>' ).html( response.message ).text() : 'Something went wrong, please try again.';
          bvwlToast( 'err', 'Not added', jQuery.trim( msg ) );
        }
      }, 'json').fail(function(){
        $row.find( '.tbp-woo-btn' ).prop( 'disabled', false ).val( 'add' );
        bvwlToast( 'err', 'Not added', 'Something went wrong, please try again.' );
      });
      return;
      <?php endif; ?>

      /* Where to show "added to your cart". This used to be hard-wired to the
         Full List (#tablepress-39-...), so on a producer page the message went
         nowhere. Use the wrapper around the table that was actually clicked. */
      var $box  = $row.closest( '[id$="-scroll-wrapper"]' );
      if ( ! $box.length ) { $box = $row.closest( '.dataTables_wrapper' ); }
      if ( ! $box.length ) { $box = $row.closest( 'table' ).parent(); }

      $row.append( "<p>Processing...</p>" );
      $box.find('.woocommerce-notices-wrapper').remove();
        var data = {
          action     : 'my_custom_add_to_cart',
          product_id : p_id,
          qty: qty ? qty : 1
        };
        jQuery.post(ajaxurl, data, function(response) {
          $box.prepend(response.message);
          bvwlApplyCart(response);
          $row.remove();
          jQuery('html, body').animate({ scrollTop: $box.offset().top }, 2000);
        }, 'json');

      }

      var bvwlToastTimer = null;
      function bvwlToast( kind, title, body ) {
        var $t = jQuery( '#bvwl-toast' );
        if ( ! $t.length ) {
          $t = jQuery( '<div id="bvwl-toast" role="status" aria-live="polite"><div class="bvwl-toast-text"><strong></strong><span></span></div><a class="bvwl-toast-cart" href="<?php echo esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ) ); ?>">View basket</a><button type="button" class="bvwl-toast-x" aria-label="Close">&times;</button></div>' ).appendTo( 'body' );
          $t.on( 'click', '.bvwl-toast-x', function(){ $t.removeClass( 'is-on' ); } );
        }
        $t.toggleClass( 'is-err', 'err' === kind );
        $t.find( 'strong' ).text( title );
        $t.find( '.bvwl-toast-text span' ).text( body );
        $t.find( '.bvwl-toast-cart' ).toggle( 'err' !== kind );
        $t.addClass( 'is-on' );
        clearTimeout( bvwlToastTimer );
        bvwlToastTimer = setTimeout( function(){ $t.removeClass( 'is-on' ); }, 6000 );
      }
    function tbpwooshowAllDescription(p_id,r_idx) {
    }
    </script>
    <style type="text/css">
    .tbp-woo-btn{    background: none repeat scroll 0 0 #EEEEEE !important;border: 1px solid #E4E4E4 !important;border-radius: 3px !important;color: #000000 !important;float: left !important;height: 27px !important;}
    .tbp-woo-qt.bvwl-case{ max-width: none !important; width: auto !important; }
    .tbp-woo-qt{ float: left !important;height: auto !important;max-width: 42px !important;padding: 3px 2px !important;text-align: center !important;margin-right: 2px !important;}
    .tbp-woo-desc{display: none;padding: 20px 0 !important;}
    .tbpwoodesc{float: right; text-align: right;}
    .bvwl-soldout{color:#999;font-size:12px;white-space:nowrap;}
    .bvwl-added{color:#4a7a3a;font-size:12px;white-space:nowrap;}
    /* WooCommerce's default purple (#7f54b3) on "Proceed to checkout" and
       "Place order" -> black, to match the site and the Add to basket button. */
    .woocommerce a.button.alt, .woocommerce button.button.alt, .woocommerce input.button.alt, .woocommerce #respond input#submit.alt,
    .woocommerce-page a.button.alt, .woocommerce-page button.button.alt {
      background-color:#000 !important; color:#fff !important; border-color:#000 !important; border-radius:0 !important;
      text-transform:uppercase; letter-spacing:.12em; font-size:13px !important; }
    .woocommerce a.button.alt:hover, .woocommerce button.button.alt:hover, .woocommerce input.button.alt:hover,
    .woocommerce-page a.button.alt:hover, .woocommerce-page button.button.alt:hover { background-color:#333 !important; border-color:#333 !important; }
    .woocommerce button.button.alt:disabled, .woocommerce button.button.alt.disabled { background-color:#777 !important; border-color:#777 !important; }
    /* ...and the lilac-tinted payment panel at checkout -> neutral grey. */
    .woocommerce-checkout #payment { background:#f4f4f4 !important; }
    .woocommerce-checkout #payment div.payment_box { background-color:#e8e8e8 !important; }
    .woocommerce-checkout #payment div.payment_box::before { border-bottom-color:#e8e8e8 !important; }
    #bvwl-toast{position:fixed;right:24px;bottom:24px;z-index:99999;display:flex;align-items:center;gap:16px;max-width:420px;padding:16px 18px;background:#111;color:#fff;font-size:14px;line-height:1.4;box-shadow:0 8px 24px rgba(0,0,0,.25);transform:translateY(20px);opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;}
    #bvwl-toast.is-on{opacity:1;transform:none;pointer-events:auto;}
    #bvwl-toast.is-err{background:#7a1f1f;}
    #bvwl-toast strong{display:block;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#cfcf8a;margin-bottom:2px;}
    #bvwl-toast.is-err strong{color:#f3c0c0;}
    #bvwl-toast .bvwl-toast-text{flex:1;}
    #bvwl-toast .bvwl-toast-cart{color:#fff;border:1px solid #fff;padding:7px 12px;text-decoration:none;font-size:12px;letter-spacing:.1em;text-transform:uppercase;white-space:nowrap;}
    #bvwl-toast .bvwl-toast-cart:hover{background:#fff;color:#111;}
    #bvwl-toast .bvwl-toast-x{background:none;border:0;color:#aaa;font-size:20px;line-height:1;cursor:pointer;padding:0 0 0 4px;}
    @media (max-width:600px){#bvwl-toast{left:12px;right:12px;bottom:12px;max-width:none;}}
    .bvwl-product-details{margin:0 0 1.5em;}
    .bvwl-product-details .bvwl-facts{margin:0 0 .75em;}
    .tablepress tbody td{cursor: pointer !important;}
    .column-3{width:50% !important; max-width: 50% !important;}

    /* The X theme paints a red focus ring on anything focusable:
         a:focus { outline: rgb(255,42,19) auto 5px }
       That is correct and necessary for anyone navigating by keyboard, but a
       mouse click also sets focus, so clicking a link flashed a red box for as
       long as the page took to move. :focus-visible lets the browser tell the
       two apart -- keyboard keeps the ring, mouse does not.
       Added here rather than in the theme stylesheet so a theme update cannot
       lose it, and because this install ignores the Customizer's Additional CSS. */
    a:focus:not(:focus-visible),
    .x-btn:focus:not(:focus-visible),
    .button:focus:not(:focus-visible),
    [type="submit"]:focus:not(:focus-visible),
    select:focus:not(:focus-visible),
    input[type="file"]:focus:not(:focus-visible),
    input[type="radio"]:focus:not(:focus-visible),
    input[type="checkbox"]:focus:not(:focus-visible) { outline: none; }
    </style>
		<?php
	}

	/*
	 * Removed 2026-10-01: the iframe-embed support that used to live in the X
	 * theme's functions.php.
	 *
	 * It hid the menu and logo whenever the site was displayed inside another
	 * website's frame, and carried a Safari storage-access workaround behind a
	 * "?ifr" switch. Joe confirmed the arrangement it was built for -- his site
	 * embedded in a partner's page -- was retired years ago, nothing on the site
	 * passes ?ifr, and the Opayo checkout is a full redirect to
	 * live.sagepay.com/gateway/service/vspform-register.vsp rather than an
	 * embedded frame, so no part of payment depended on it either.
	 *
	 * It was not free to keep: it hid #menu-primary-menus and .x-brand img in
	 * CSS on every page load and only restored them on window.onload, which
	 * waits for every image. Measured on the live site, that left the logo and
	 * navigation invisible for roughly half a second after the page was
	 * otherwise usable.
	 */

	/* =====================================================================
	 * Add to cart (moved out of the X theme's functions.php)
	 * ===================================================================== */

	public function ajax_add_to_cart() {
		global $woocommerce;

		$retval = array(
			'success' => false,
			'qt'      => '',
			'message' => '',
		);

		if ( ! function_exists( 'WC' ) ) {
			$retval['message'] = 'woocommerce not installed';
		} elseif ( empty( $_POST['product_id'] ) ) {
			$retval['message'] = 'no product id provided';
		} else {
			$product_obj    = wc_get_product( intval( $_POST['product_id'] ) );
			$stock_quantity = $product_obj ? $product_obj->get_stock_quantity() : 0;

			if ( 0 == $stock_quantity ) {
				$retval['message'] = '<div class="woocommerce-notices-wrapper"><div class="woocommerce-error" role="alert">There are no items available at this time.</div></div>';
			} else {
				$cart               = WC()->cart;
				$product_id         = intval( $_POST['product_id'] );
				$quantity           = intval( isset( $_POST['qty'] ) ? $_POST['qty'] : 1 );
				$retval['success']  = $cart->add_to_cart( $product_id, $quantity );
				if ( ! $retval['success'] ) {
					$retval['message'] = '<div class="woocommerce-notices-wrapper"><div class="woocommerce-error" role="alert">Product could not be added to cart.</div></div>';
				} else {
					$retval['success'] = true;
					$retval['message'] = '<div class="woocommerce-notices-wrapper"><div class="woocommerce-message" role="alert">Product successfully added to your cart.</div></div>';

					// Hand back the same cart fragments WooCommerce's own add-to-cart
					// returns. Without them the header cart keeps showing whatever
					// count the page was built with, and because WooCommerce Menu
					// Cart points an EMPTY cart at the shop front rather than the
					// basket, clicking it takes you to the home page instead.
					if ( function_exists( 'woocommerce_mini_cart' ) ) {
						ob_start();
						woocommerce_mini_cart();
						$mini_cart = ob_get_clean();
						$retval['fragments'] = apply_filters(
							'woocommerce_add_to_cart_fragments',
							array(
								'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
							)
						);
						$retval['cart_hash'] = WC()->cart->get_cart_hash();
					}
				}
			}
		}

		if ( isset( $woocommerce->cart ) ) {
			$retval['qt'] = $woocommerce->cart->cart_contents_count;
		}

		echo wp_json_encode( $retval );
		wp_die();
	}

	/* =====================================================================
	 * Import: the "Add Woocommerce Product" tickbox, and what it does
	 *
	 * The tickbox used to be hard-coded into TablePress's own import view. It is
	 * now added to that screen from here, so the view file stays stock.
	 * ===================================================================== */

	public function print_import_checkbox() {
		if ( ! $this->is_tablepress_import_screen() ) {
			return;
		}
		?>
		<script>
		jQuery(function($){
			var row =
				'<tr class="top-border" id="bvwl-woo-row">' +
				'<th class="column-1" scope="row"><label for="bvwl-woo-product">Add Woocommerce Product:</label></th>' +
				'<td class="column-2"><input type="checkbox" id="bvwl-woo-product" name="import[woo_product]" value="1"/>' +
				'<p class="description" style="margin:4px 0 0">Creates or updates the shop product for every row (matched on wine, vintage, unit and status). Wines no longer on the sheet are set to out of stock, not deleted. <strong>No need to delete products first.</strong></p>' +
				'</td></tr>';

			function inject() {
				if ( $('#bvwl-woo-product').length ) { return true; }

				// TablePress 1.x: a row with this id sat on the import screen.
				var $legacy = $('#row-import-existing-table').last();
				if ( $legacy.length ) { $legacy.after(row); return true; }

				// TablePress 3.x rebuilt this screen and renders it with JavaScript
				// after DOM ready, so the rows do not exist yet when we first run.
				// Anchor above the Import button, the one row certain to be there.
				var $form = $('#tablepress-page-form');
				if ( ! $form.length ) { return false; }
				var $submitRow = $form.find('tbody tr').filter(function(){
					return $(this).find('button[type=submit], input[type=submit]').length > 0;
				}).last();
				if ( $submitRow.length ) { $submitRow.before(row); return true; }
				return false;
			}

			if ( inject() ) { return; }

			// Keep looking while the screen builds itself, then give up quietly.
			var tries = 0;
			var timer = setInterval(function(){
				tries++;
				if ( inject() || tries > 60 ) { clearInterval(timer); }
			}, 250);
		});
		</script>
		<?php
	}

	/** True on the TablePress "Import a Table" screen, on 1.x and 3.x alike. */
	private function is_tablepress_import_screen() {
		if ( isset( $_GET['page'] ) && 'tablepress_import' === $_GET['page'] ) {
			return true;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && false !== strpos( (string) $screen->id, 'tablepress_import' );
	}

	/**
	 * A table has just been added or saved. Only do anything if this request was
	 * an import with the "Add Woocommerce Product" box ticked — editing a table
	 * by hand must never silently create a new set of products.
	 */
	public function on_table_written( $table_id ) {
		if ( empty( $_POST['import']['woo_product'] ) ) {
			return;
		}
		$this->sync_products_for_table( (string) $table_id );
	}

	/**
	 * Walk the table's rows and bring the WooCommerce products in line with them.
	 *
	 * Up to 1.0 this created a brand new product for every row, every time, so the
	 * only way to re-import was to delete every product first -- which threw away
	 * each wine's page, its address and whatever search ranking it had built up.
	 *
	 * Now each row is matched to the product that already represents it (by wine +
	 * vintage + unit + status) and that product is updated in place:
	 *
	 *   row matches a product        -> price, stock, description, category updated
	 *   row matches nothing          -> new product, as before
	 *   product no longer on any list -> set out of stock, NOT deleted; its page
	 *                                   stays up and it comes back when re-listed
	 *
	 * The spreadsheet stays the source of truth for everything it has a column for.
	 */
	public function sync_products_for_table( $table_id ) {
		if ( ! class_exists( 'woocommerce' ) ) {
			return null;
		}
		$post_id = $this->table_id_to_post_id( $table_id );
		if ( ! $post_id ) {
			return null;
		}
		$rows = json_decode( get_post( $post_id )->post_content, true );
		if ( ! is_array( $rows ) || empty( $rows[0] ) ) {
			return null;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // a first run creates hundreds of products
		}

		// Products imported before 1.1.0 carry no key yet. Give them one first,
		// or this import could not recognise them and would create duplicates.
		if ( ! get_option( self::ADOPTED_OPTION ) ) {
			$this->adopt_existing_products( false );
		}

		$cols  = self::columns( $rows[0] );
		$index = $this->product_index( true );
		$table = (string) $table_id;

		$old_map = get_post_meta( $post_id, self::META_KEY, true );
		$old_ids = array();
		if ( is_array( $old_map ) ) {
			foreach ( $old_map as $e ) {
				if ( is_array( $e ) && ! empty( $e['prodcut_id'] ) ) {
					$old_ids[ (int) $e['prodcut_id'] ] = true;
				}
			}
		}

		$report = array(
			'table'      => $table,
			'title'      => get_the_title( $post_id ),
			'rows'       => count( $rows ) - 1,
			'created'    => 0,
			'updated'    => 0,
			'unchanged'  => 0,
			'skipped'    => 0,
			'duplicates' => array(),
			'retired'    => 0,
		);

		$seen = array(); // key => product id, within this import
		$map  = array( 0 => 0 );
		for ( $i = 1; $i < count( $rows ); $i++ ) {
			$row  = is_array( $rows[ $i ] ) ? $rows[ $i ] : array();
			$info = array(
				'title'       => trim( self::cell( $row, $cols['wine'] ) ),
				'description' => self::cell( $row, $cols['desc'] ),
				'category'    => trim( self::cell( $row, $cols['cat'] ) ),
				'price'       => trim( str_replace( array( '£', ',' ), '', self::cell( $row, $cols['cost'] ) ) ),
				'note'        => self::cell( $row, $cols['vintage'] ),
				'qty'         => trim( self::cell( $row, $cols['qty'] ) ),
				'unit'        => trim( self::cell( $row, $cols['unit'] ) ),
				'status'      => trim( self::cell( $row, $cols['status'] ) ),
				'code'        => self::row_code( $row, $cols ),
			);
			$key      = self::row_key( $row, $cols );
			$identity = '' !== $info['code'] ? '#' . self::code_id( $info['code'] ) : $key;

			$product_id = 0;
			if ( '' === $info['title'] || '' === $info['price'] ) {
				$report['skipped']++;
			} elseif ( isset( $seen[ $identity ] ) ) {
				// The same wine twice on one sheet: both rows sell the one product.
				$product_id = $seen[ $identity ];
				$report['duplicates'][] = $info['title'] . ( '' !== $info['note'] ? ' ' . $info['note'] : '' );
			} elseif ( $found = self::resolve_row( $row, $cols, $index ) ) {
				$product_id = $found;
				$this->update_product( $product_id, $info ) ? $report['updated']++ : $report['unchanged']++;
			} else {
				$product_id = $this->create_product( $info );
				if ( $product_id ) {
					$report['created']++;
				}
			}

			if ( $product_id ) {
				$seen[ $identity ] = $product_id;
				$this->set_listed_in( $product_id, $table, true );
				// Keep both identities current: the name key follows a corrected
				// spelling, and a code arriving for the first time is attached.
				if ( get_post_meta( $product_id, self::KEY_META, true ) !== $key ) {
					update_post_meta( $product_id, self::KEY_META, $key );
				}
				if ( '' !== $info['code'] && self::code_id( get_post_meta( $product_id, self::CODE_META, true ) ) !== self::code_id( $info['code'] ) ) {
					update_post_meta( $product_id, self::CODE_META, $info['code'] );
					$report['codes'] = ( isset( $report['codes'] ) ? $report['codes'] : 0 ) + 1;
				}
				if ( '' !== $info['code'] ) {
					$this->set_sku( $product_id, $info['code'] );
				}
				// Teach this import's index what it just learned, so a later row
				// with the same name but a different code is not matched to it.
				$cid = self::code_id( $info['code'] );
				$index['by_id'][ $product_id ] = array(
					'status' => isset( $index['by_id'][ $product_id ] ) ? $index['by_id'][ $product_id ]['status'] : 'publish',
					'stock'  => isset( $index['by_id'][ $product_id ] ) ? $index['by_id'][ $product_id ]['stock'] : 'instock',
					'key'    => $key,
					'code'   => '' !== $cid ? $cid : ( isset( $index['by_id'][ $product_id ] ) ? $index['by_id'][ $product_id ]['code'] : '' ),
				);
				if ( ! isset( $index['by_key'][ $key ] ) ) {
					$index['by_key'][ $key ] = $product_id;
				}
				if ( '' !== $cid ) {
					$index['by_code'][ $cid ] = $product_id;
				}
			}

			$map[ $i ] = array(
				'prodcut_id'  => $product_id,               // key spelling kept: the renderer reads it
				'description' => $info['description'],
				'qty'         => is_numeric( $info['qty'] ) ? (int) $info['qty'] : $info['qty'],
				'unit'        => is_numeric( $info['unit'] ) ? (int) $info['unit'] : $info['unit'],
			);
		}

		update_post_meta( $post_id, self::META_KEY, $map );

		// Wines this table listed last time and no longer does.
		$now = array_flip( array_values( $seen ) );
		foreach ( array_keys( $old_ids ) as $gone ) {
			if ( isset( $now[ $gone ] ) ) {
				continue;
			}
			$still_listed = $this->set_listed_in( $gone, $table, false );
			if ( ! $still_listed && $this->retire_product( $gone ) ) {
				$report['retired']++;
			}
		}

		$this->product_index( true );
		$this->schedule_table_cache_flush();
		$report['sku_clashes'] = $this->sku_clashes;
		$report['time'] = time();
		update_option( 'bvwl_last_import_' . $table, $report, false );
		if ( get_current_user_id() ) {
			set_transient( 'bvwl_import_report_' . get_current_user_id(), $report, HOUR_IN_SECONDS );
		}
		return $report;
	}

	/**
	 * Record (or drop) that $table's latest import lists this product.
	 * Returns whether any table still lists it.
	 */
	private function set_listed_in( $product_id, $table, $listed ) {
		$tables = get_post_meta( $product_id, self::TABLES_META, true );
		$tables = is_array( $tables ) ? $tables : array();
		$had    = in_array( $table, $tables, true );
		if ( $listed && ! $had ) {
			$tables[] = $table;
			update_post_meta( $product_id, self::TABLES_META, $tables );
		} elseif ( ! $listed && $had ) {
			$tables = array_values( array_diff( $tables, array( $table ) ) );
			update_post_meta( $product_id, self::TABLES_META, $tables );
		}
		return ! empty( $tables );
	}

	/** Off the list: out of stock, but the page and its address stay. */
	private function retire_product( $product_id ) {
		$p = wc_get_product( $product_id );
		if ( ! $p || '' === (string) get_post_meta( $product_id, self::KEY_META, true ) ) {
			return false; // never touch a product this importer does not manage
		}
		if ( 'outofstock' === $p->get_stock_status() && 0 === (int) $p->get_stock_quantity() ) {
			return false;
		}
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 0 );
		$p->set_stock_status( 'outofstock' );
		$p->save();
		return true;
	}

	private static function stock_from( array $info ) {
		return ( '' !== $info['qty'] && is_numeric( $info['qty'] ) ) ? (int) $info['qty'] : 1;
	}

	private function save_wine_meta( $product_id, array $info ) {
		update_post_meta( $product_id, self::VINTAGE_META, $info['note'] );
		update_post_meta( $product_id, self::UNIT_META, $info['unit'] );
		update_post_meta( $product_id, self::STATUS_META, $info['status'] );
	}

	/** SKU must be unique in WooCommerce; a clash is reported, never fatal. */
	private function set_sku( $product_id, $code ) {
		$p = wc_get_product( $product_id );
		if ( ! $p || (string) $p->get_sku( 'edit' ) === (string) $code ) {
			return;
		}
		try {
			$p->set_sku( $code );
			$p->save();
		} catch ( Exception $e ) {
			$this->sku_clashes[] = $code;
		}
	}

	/** @var string[] codes WooCommerce refused as SKUs during this import */
	private $sku_clashes = array();

	private static function same_text( $a, $b ) {
		$d = function ( $s ) {
			return str_replace( "\r\n", "\n", trim( html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		};
		return $d( $a ) === $d( $b );
	}

	/** Returns true if anything actually changed. */
	private function update_product( $product_id, array $info ) {
		$p = wc_get_product( $product_id );
		if ( ! $p ) {
			return false;
		}
		$changed = false;

		// WordPress may store "&" as "&amp;" (it depends on who runs the import),
		// so compare decoded text -- otherwise a product looks changed every time.
		if ( ! self::same_text( $p->get_name(), $info['title'] ) ) {
			$p->set_name( $info['title'] ); // the address (slug) is deliberately left alone
			$changed = true;
		}
		if ( ! self::same_text( $p->get_description(), $info['description'] ) ) {
			$p->set_description( $info['description'] );
			$changed = true;
		}
		if ( wc_format_decimal( $p->get_regular_price() ) !== wc_format_decimal( $info['price'] ) ) {
			$p->set_regular_price( $info['price'] );
			$changed = true;
		}
		if ( (string) $p->get_purchase_note() !== (string) $info['note'] ) {
			$p->set_purchase_note( $info['note'] );
			$changed = true;
		}
		$stock = self::stock_from( $info );
		if ( ! $p->get_manage_stock() || (int) $p->get_stock_quantity() !== $stock ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( $stock );
			$changed = true;
		}
		if ( $changed ) {
			$p->save(); // WooCommerce sets in-stock / out-of-stock from the quantity
		}

		if ( '' !== $info['category'] ) {
			$names = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
			if ( is_wp_error( $names ) || 1 !== count( $names ) || 0 !== strcasecmp( html_entity_decode( $names[0] ), $info['category'] ) ) {
				wp_set_object_terms( $product_id, $info['category'], 'product_cat' );
				$changed = true;
			}
		}

		if ( get_post_meta( $product_id, self::VINTAGE_META, true ) !== $info['note']
			|| get_post_meta( $product_id, self::UNIT_META, true ) !== $info['unit']
			|| get_post_meta( $product_id, self::STATUS_META, true ) !== $info['status'] ) {
			$this->save_wine_meta( $product_id, $info );
		}
		return $changed;
	}

	private function create_product( array $info ) {
		$p = new WC_Product_Simple();
		$p->set_name( $info['title'] );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( 'visible' );
		$p->set_description( $info['description'] );
		$p->set_regular_price( $info['price'] );
		$p->set_purchase_note( $info['note'] );
		$p->set_backorders( 'no' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( self::stock_from( $info ) );
		$post_id = $p->save();
		if ( ! $post_id ) {
			return 0;
		}
		if ( '' !== $info['category'] ) {
			wp_set_object_terms( $post_id, $info['category'], 'product_cat' );
		}
		$this->save_wine_meta( $post_id, $info );
		return $post_id;
	}

	/**
	 * One-off, for products imported before 1.1.0: work out each one's key from
	 * the table row it was created for, so the next import recognises it.
	 *
	 * Only tables whose products are still published take part -- the producer
	 * tables point at products deleted long ago and are left alone.
	 *
	 * Where two products turn out to be the same wine (the same row imported into
	 * both the Full List and the Transit table, or a row duplicated on one sheet)
	 * the first is kept, the tables are re-pointed at it, and the spare is moved
	 * to Drafts -- hidden, not deleted.
	 */
	public function adopt_existing_products( $dry_run = true ) {
		$opt = json_decode( (string) get_option( 'tablepress_tables' ), true );
		$table_posts = isset( $opt['table_post'] ) && is_array( $opt['table_post'] ) ? $opt['table_post'] : array();
		uksort( $table_posts, function ( $a, $b ) { return (int) $a - (int) $b; } );

		$out = array( 'tables' => array(), 'keyed' => 0, 'merged' => array(), 'drafted' => 0, 'dry_run' => $dry_run );
		$claimed = array(); // key => product id

		foreach ( $table_posts as $table_id => $post_id ) {
			$post = get_post( $post_id );
			$map  = get_post_meta( $post_id, self::META_KEY, true );
			$rows = $post ? json_decode( $post->post_content, true ) : null;
			if ( ! is_array( $map ) || ! is_array( $rows ) || empty( $rows[0] ) ) {
				continue;
			}
			$live = 0;
			foreach ( $map as $e ) {
				if ( is_array( $e ) && ! empty( $e['prodcut_id'] ) && 'publish' === get_post_status( (int) $e['prodcut_id'] ) ) {
					$live++;
				}
			}
			if ( ! $live ) {
				continue;
			}

			$cols = self::columns( $rows[0] );
			$map_changed = false;
			$t = array( 'table' => (string) $table_id, 'live' => $live, 'keyed' => 0, 'repointed' => 0 );

			foreach ( $map as $i => $e ) {
				if ( ! $i || ! is_array( $e ) || empty( $e['prodcut_id'] ) || ! isset( $rows[ $i ] ) || ! is_array( $rows[ $i ] ) ) {
					continue;
				}
				$pid = (int) $e['prodcut_id'];
				if ( 'publish' !== get_post_status( $pid ) ) {
					continue;
				}
				$key = self::row_key( $rows[ $i ], $cols );

				if ( isset( $claimed[ $key ] ) && $claimed[ $key ] !== $pid ) {
					$keep = $claimed[ $key ];
					$out['merged'][ $pid ] = $keep;
					$map[ $i ]['prodcut_id'] = $keep;
					$map_changed = true;
					$t['repointed']++;
					if ( ! $dry_run ) {
						$this->set_listed_in( $keep, (string) $table_id, true );
					}
					continue;
				}

				$claimed[ $key ] = $pid;
				$t['keyed']++;
				if ( ! $dry_run ) {
					update_post_meta( $pid, self::KEY_META, $key );
					$this->set_listed_in( $pid, (string) $table_id, true );
					$this->save_wine_meta(
						$pid,
						array(
							'note'   => self::cell( $rows[ $i ], $cols['vintage'] ),
							'unit'   => trim( self::cell( $rows[ $i ], $cols['unit'] ) ),
							'status' => trim( self::cell( $rows[ $i ], $cols['status'] ) ),
						)
					);
				}
			}

			if ( $map_changed && ! $dry_run ) {
				update_post_meta( $post_id, self::META_KEY, $map );
			}
			$out['keyed'] += $t['keyed'];
			$out['tables'][] = $t;
		}

		// Spare duplicates: hidden, never deleted, and only if no table still uses them.
		foreach ( $out['merged'] as $spare => $keep ) {
			if ( isset( array_flip( $claimed )[ $spare ] ) ) {
				continue;
			}
			if ( ! $dry_run ) {
				wp_update_post( array( 'ID' => $spare, 'post_status' => 'draft' ) );
				update_post_meta( $spare, '_bvwl_merged_into', $keep );
			}
			$out['drafted']++;
		}

		if ( ! $dry_run ) {
			update_option( self::ADOPTED_OPTION, time(), false );
			$this->product_index( true );
			$this->flush_table_caches();
		}
		return $out;
	}

	/** After an import, say what it did -- on the next admin screen Joe sees. */
	public function print_import_report() {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		$r = get_transient( 'bvwl_import_report_' . $uid );
		if ( ! is_array( $r ) ) {
			return;
		}
		delete_transient( 'bvwl_import_report_' . $uid );

		$parts = array(
			sprintf( '%d new', $r['created'] ),
			sprintf( '%d updated', $r['updated'] ),
			sprintf( '%d unchanged', $r['unchanged'] ),
			sprintf( '%d no longer listed (now out of stock, pages kept)', $r['retired'] ),
		);
		echo '<div class="notice notice-success is-dismissible"><p><strong>Wine list products updated</strong> from &ldquo;' . esc_html( $r['title'] ) . '&rdquo; (' . (int) $r['rows'] . ' rows): ' . esc_html( implode( ', ', $parts ) ) . '.</p>';
		if ( ! empty( $r['codes'] ) ) {
			echo '<p>Product codes attached for the first time: ' . (int) $r['codes'] . '.</p>';
		}
		if ( ! empty( $r['sku_clashes'] ) ) {
			echo '<p>These codes are already used by another product, so they could not be saved as its SKU: ' . esc_html( implode( ', ', array_slice( $r['sku_clashes'], 0, 10 ) ) ) . '</p>';
		}
		if ( $r['skipped'] ) {
			echo '<p>' . (int) $r['skipped'] . ' row(s) had no wine name or no price and were skipped.</p>';
		}
		if ( ! empty( $r['duplicates'] ) ) {
			echo '<p>Listed twice on the sheet (both rows sell the same product): ' . esc_html( implode( '; ', array_slice( $r['duplicates'], 0, 10 ) ) ) . '</p>';
		}
		echo '</div>';
	}

	/* =====================================================================
	 * Single product page
	 * ===================================================================== */

	private static function wine_facts( $product_id ) {
		$vintage = (string) get_post_meta( $product_id, self::VINTAGE_META, true );
		$unit    = (string) get_post_meta( $product_id, self::UNIT_META, true );
		$status  = strtoupper( trim( (string) get_post_meta( $product_id, self::STATUS_META, true ) ) );
		return array( $vintage, $unit, $status );
	}

	/**
	 * The theme's product tabs are switched off on this site, and the description
	 * only ever appeared inside them -- so the descriptions written for every wine
	 * were on no page a customer could see. Print them in the summary instead,
	 * with the vintage. Skipped automatically if the tabs are ever switched back
	 * on, so the description never shows twice.
	 */
	public function product_page_details() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		list( $vintage, $unit, $status ) = self::wine_facts( $product->get_id() );

		$facts = array();
		if ( '' !== $vintage ) {
			$facts[] = '<strong>Vintage:</strong> ' . esc_html( $vintage );
		}
		if ( is_numeric( $unit ) && (int) $unit > 1 ) {
			$facts[] = '<strong>Sold in:</strong> ' . (int) $unit . ' bottle units';
		}
		if ( 'IB' === $status ) {
			$facts[] = '<strong>Status:</strong> In bond';
		} elseif ( 'DP' === $status ) {
			$facts[] = '<strong>Status:</strong> Duty paid';
		}

		$tabs_shown  = (bool) has_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs' );
		$description = $tabs_shown ? '' : trim( (string) $product->get_description() );

		if ( ! $facts && '' === $description ) {
			return;
		}
		echo '<div class="bvwl-product-details">';
		if ( $facts ) {
			echo '<p class="bvwl-facts">' . implode( ' &nbsp;&middot;&nbsp; ', $facts ) . '</p>';
		}
		if ( '' !== $description ) {
			echo '<div class="bvwl-desc">' . wpautop( wp_kses_post( $description ) ) . '</div>';
		}
		echo '</div>';
	}

	/* =====================================================================
	 * Product page layout v2 (behind the `bvwl_product_layout` option)
	 *
	 * The stock WooCommerce product page on this theme is a single column of
	 * defaults: purple button, no unit pricing, a bare category link. This
	 * replaces the summary with a two-column layout -- the wine on the left,
	 * a purchase card on the right -- plus "more from this region" below.
	 * Switched on per site with update_option( 'bvwl_product_layout', 'v2' ),
	 * so it can be shipped dormant and turned on once approved.
	 * ===================================================================== */

	public function product_layout_setup() {
		if ( 'v2' !== get_option( 'bvwl_product_layout' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		foreach ( array(
			array( 'woocommerce_template_single_title', 5 ),
			array( 'woocommerce_template_single_rating', 10 ),
			array( 'woocommerce_template_single_price', 10 ),
			array( 'woocommerce_template_single_excerpt', 20 ),
			array( 'woocommerce_template_single_add_to_cart', 30 ),
			array( 'woocommerce_template_single_meta', 40 ),
			array( 'woocommerce_template_single_sharing', 50 ),
		) as $a ) {
			remove_action( 'woocommerce_single_product_summary', $a[0], $a[1] );
		}
		remove_action( 'woocommerce_single_product_summary', array( $this, 'product_page_details' ), 25 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'product_layout_v2' ), 5 );
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'product_more_from_region' ), 30 );
		add_action( 'wp_head', array( $this, 'product_layout_css' ), 99 );
		add_filter( 'body_class', function ( $c ) { $c[] = 'bvwl-pp-v2'; return $c; } );
		add_filter( 'woocommerce_product_single_add_to_cart_text', function () { return 'Add to basket'; } );
	}

	/** "Sold in 12s" products are priced and stocked per case. */
	private static function unit_of( $product_id ) {
		$u = (string) get_post_meta( $product_id, self::UNIT_META, true );
		return ( is_numeric( $u ) && (int) $u > 1 ) ? (int) $u : 1;
	}

	private static function format_label( $name, $unit ) {
		if ( preg_match( '/\((magnum|half|halves|double magnum|jeroboam|[0-9.]+\s?cl)\)/i', $name, $m ) ) {
			$size = ucfirst( strtolower( $m[1] ) );
		} else {
			$size = '75cl';
		}
		return $unit > 1 ? sprintf( 'Case of %d × %s', $unit, $size ) : sprintf( 'Bottle, %s', $size );
	}

	public function product_layout_v2() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$id = $product->get_id();
		list( $vintage, , $status ) = self::wine_facts( $id );
		$unit   = self::unit_of( $id );
		$name   = $product->get_name();
		$cats   = wp_get_post_terms( $id, 'product_cat' );
		$region = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0] : null;
		$price  = (float) $product->get_price();
		$desc   = trim( (string) $product->get_description() );
		$stock  = $product->get_stock_quantity();
		$status_label = 'IB' === $status ? 'In Bond' : ( 'DP' === $status ? 'Duty Paid' : '' );
		$status_note  = 'IB' === $status ? 'Held in a bonded warehouse.' : ( 'DP' === $status ? 'Duty and VAT paid, ready for delivery.' : '' );

		echo '<div class="bvwl-pp">';

		// --- breadcrumb ---------------------------------------------------
		echo '<nav class="bvwl-pp-crumbs"><a href="' . esc_url( home_url( '/list/' ) ) . '">Full List</a>';
		if ( $region ) {
			echo ' <span>/</span> <a href="' . esc_url( get_term_link( $region ) ) . '">' . esc_html( $region->name ) . '</a>';
		}
		echo '</nav>';

		echo '<div class="bvwl-pp-grid">';

		// --- left: the wine (heading and body are separate grid areas so that
		//     on a phone the purchase card can sit between them) ------------
		echo '<div class="bvwl-pp-head">';
		$eyebrow = array_filter( array( $region ? $region->name : '', $vintage ) );
		if ( $eyebrow ) {
			echo '<p class="bvwl-pp-eyebrow">' . esc_html( implode( ' · ', $eyebrow ) ) . '</p>';
		}
		echo '<h1 class="bvwl-pp-title product_title entry-title">' . esc_html( $name ) . '</h1>';
		echo '</div>';
		echo '<div class="bvwl-pp-main">';
		if ( '' !== $desc ) {
			echo '<div class="bvwl-pp-desc">' . wpautop( wp_kses_post( $desc ) ) . '</div>';
		}
		echo '<dl class="bvwl-pp-facts">';
		if ( $region ) {
			echo '<dt>Region</dt><dd>' . esc_html( $region->name ) . '</dd>';
		}
		if ( '' !== $vintage ) {
			echo '<dt>Vintage</dt><dd>' . esc_html( $vintage ) . '</dd>';
		}
		echo '<dt>Format</dt><dd>' . esc_html( self::format_label( $name, $unit ) ) . '</dd>';
		if ( $status_label ) {
			echo '<dt>Status</dt><dd>' . esc_html( $status_label ) . ' <span class="bvwl-pp-note">' . esc_html( $status_note ) . '</span></dd>';
		}
		echo '</dl>';
		echo '</div>';

		// --- right: the purchase card ---------------------------------------
		echo '<aside class="bvwl-pp-buy">';
		echo '<p class="bvwl-pp-price">' . wc_price( $price ) . '</p>';
		if ( $unit > 1 ) {
			echo '<p class="bvwl-pp-per">per case of ' . (int) $unit . ' &middot; ' . wc_price( $price / $unit ) . ' a bottle</p>';
		} else {
			echo '<p class="bvwl-pp-per">per bottle</p>';
		}
		echo '<p class="bvwl-pp-inc">Including duty &amp; VAT</p>';
		if ( $product->is_in_stock() ) {
			if ( null !== $stock ) {
				$what = $unit > 1 ? _n( 'case', 'cases', $stock ) : _n( 'bottle', 'bottles', $stock );
				echo '<p class="bvwl-pp-stock">' . (int) $stock . ' ' . esc_html( $what ) . ' available</p>';
			}
			woocommerce_template_single_add_to_cart();
		} else {
			echo '<p class="bvwl-pp-stock bvwl-pp-out">Sold out</p>';
			echo '<p class="bvwl-pp-note">Get in touch and we\'ll let you know if more becomes available.</p>';
		}
		echo '</aside>';

		echo '</div></div>';
	}

	/** A few in-stock wines from the same region, so a visitor from Google has somewhere to go next. */
	public function product_more_from_region() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$cats = wp_get_post_terms( $product->get_id(), 'product_cat' );
		if ( ! $cats || is_wp_error( $cats ) ) {
			return;
		}
		$region = $cats[0];
		$ids    = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'instock',
				'category'     => array( $region->slug ),
				'exclude'      => array( $product->get_id() ),
				'limit'        => 4,
				'orderby'      => 'rand',
				'return'       => 'ids',
			)
		);
		if ( ! $ids ) {
			return;
		}
		echo '<section class="bvwl-pp-more"><h2>More from ' . esc_html( $region->name ) . '</h2><ul>';
		foreach ( $ids as $rid ) {
			$p = wc_get_product( $rid );
			list( $v ) = self::wine_facts( $rid );
			$u = self::unit_of( $rid );
			echo '<li><a href="' . esc_url( get_permalink( $rid ) ) . '">';
			echo '<span class="bvwl-pp-more-v">' . esc_html( $v ) . '</span>';
			echo '<span class="bvwl-pp-more-n">' . esc_html( $p->get_name() ) . '</span>';
			echo '<span class="bvwl-pp-more-p">' . wc_price( (float) $p->get_price() ) . ( $u > 1 ? ' <small>per case of ' . (int) $u . '</small>' : '' ) . '</span>';
			echo '</a></li>';
		}
		echo '</ul><p class="bvwl-pp-all"><a href="' . esc_url( get_term_link( $region ) ) . '">See all ' . esc_html( $region->name ) . ' &rarr;</a></p></section>';
	}

	public function product_layout_css() {
		?>
<style id="bvwl-pp-css">
.bvwl-pp-v2 .entry-summary, .bvwl-pp-v2 div.product .summary { width:100% !important; float:none !important; margin:0 !important; }
.bvwl-pp { max-width:1080px; margin:0 auto; padding:8px 0 24px; }
.bvwl-pp-crumbs { font-size:13px; letter-spacing:.04em; text-transform:uppercase; color:#888; margin:0 0 28px; }
.bvwl-pp-crumbs a { color:#666; text-decoration:none; } .bvwl-pp-crumbs a:hover { color:#000; }
.bvwl-pp-crumbs span { margin:0 6px; color:#bbb; }
.bvwl-pp-grid { display:grid; grid-template-columns:minmax(0,1fr) 320px; grid-template-areas:"head buy" "main buy"; column-gap:56px; align-items:start; }
.bvwl-pp-head { grid-area:head; } .bvwl-pp-main { grid-area:main; } .bvwl-pp-buy { grid-area:buy; }
.bvwl-pp-buy p.stock { display:none; }
.bvwl-pp-eyebrow { font-size:13px; letter-spacing:.12em; text-transform:uppercase; color:#8a8a2a; margin:0 0 10px; }
.bvwl-pp-title { font-size:34px !important; line-height:1.2 !important; margin:0 0 22px !important; }
.bvwl-pp-desc p { font-size:16px; line-height:1.75; color:#333; margin:0 0 16px; }
.bvwl-pp-facts { display:grid; grid-template-columns:120px 1fr; margin:28px 0 0; border-top:1px solid #e6e6e6; }
.bvwl-pp-facts dt, .bvwl-pp-facts dd { margin:0; padding:12px 0; border-bottom:1px solid #e6e6e6; font-size:14px; }
.bvwl-pp-facts dt { color:#888; text-transform:uppercase; letter-spacing:.06em; font-size:12px; padding-top:14px; }
.bvwl-pp-note { color:#888; font-size:13px; }
.bvwl-pp-buy { border:1px solid #e6e6e6; padding:28px; position:sticky; top:24px; background:#fff; }
.bvwl-pp-price { font-size:32px; line-height:1; margin:0 0 6px; color:#000; }
.bvwl-pp-price .amount { color:#000; }
.bvwl-pp-per { font-size:14px; color:#555; margin:0 0 2px; } .bvwl-pp-per .amount { color:#555; }
.bvwl-pp-inc { font-size:12px; color:#999; margin:0 0 20px; }
.bvwl-pp-stock { font-size:14px; color:#4a7a3a; margin:0 0 14px; } .bvwl-pp-out { color:#a33; }
.bvwl-pp-buy form.cart { display:flex !important; gap:10px; margin:0 !important; width:100% !important; float:none !important; }
.bvwl-pp-buy form.cart .single_add_to_cart_button { display:block !important; float:none !important; max-width:none !important; }
.bvwl-pp-buy form.cart .quantity { margin:0 !important; }
.bvwl-pp-buy form.cart .quantity input.qty { width:64px; height:46px; text-align:center; border:1px solid #ccc; }
.bvwl-pp-buy form.cart .single_add_to_cart_button { flex:1; width:100%; margin:0 !important; height:46px; background:#000 !important; color:#fff !important; border:0 !important; border-radius:0 !important; text-transform:uppercase; letter-spacing:.12em; font-size:13px; }
.bvwl-pp-buy form.cart .single_add_to_cart_button:hover { background:#333 !important; }
.bvwl-pp-more { max-width:1080px; margin:56px auto 0; padding-top:36px; border-top:1px solid #e6e6e6; clear:both; }
.bvwl-pp-more h2 { font-size:22px !important; margin:0 0 20px !important; }
.bvwl-pp-more ul { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(4,1fr); gap:20px; }
.bvwl-pp-more li a { display:flex; flex-direction:column; gap:6px; height:100%; padding:18px; border:1px solid #e6e6e6; text-decoration:none; color:#222; }
.bvwl-pp-more li a:hover { border-color:#000; }
.bvwl-pp-more-v { font-size:12px; letter-spacing:.1em; color:#8a8a2a; }
.bvwl-pp-more-n { font-size:15px; line-height:1.4; flex:1; }
.bvwl-pp-more-p { font-size:15px; color:#000; } .bvwl-pp-more-p small { color:#888; font-size:12px; }
.bvwl-pp-all { margin:18px 0 0; font-size:14px; } .bvwl-pp-all a { color:#000; }
@media (max-width:820px) {
  .bvwl-pp-grid { grid-template-columns:1fr; grid-template-areas:"head" "buy" "main"; row-gap:24px; }
  .bvwl-pp-buy { position:static; }
  .bvwl-pp-more ul { grid-template-columns:1fr 1fr; }
  .bvwl-pp-title { font-size:26px !important; }
}
</style>
		<?php
	}


	/* =====================================================================
	 * Region pages (/product-category/<region>/), behind `bvwl_region_pages`
	 *
	 * The theme's own archive was broken three ways: its landmark heading sat on
	 * top of the menu, the shop was set to ONE product per page (8 wines = 8
	 * pages), and its product cards rely on photos this shop doesn't use, so the
	 * one product per page didn't even show. The product pages link here
	 * ("Full List / Burgundy Red", "See all Burgundy Red"). Show the Full List
	 * filtered to the region instead -- the table customers already know, Buy
	 * buttons and all.
	 * ===================================================================== */

	const FULL_LIST_TABLE = '39';

	public function render_region_page() {
		if ( 'on' !== get_option( 'bvwl_region_pages' ) || ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
			return;
		}
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		$post_id = $this->table_id_to_post_id( self::FULL_LIST_TABLE );
		$rows    = $post_id ? json_decode( get_post( $post_id )->post_content, true ) : null;
		$col     = ( is_array( $rows ) && ! empty( $rows[0] ) ) ? self::find_col( $rows[0], array( 'Region', 'Category' ) ) : false;
		if ( false === $col ) {
			return; // can't find the region column: leave the theme's page alone
		}
		$n = 0;
		foreach ( $rows as $i => $r ) {
			if ( $i && isset( $r[ $col ] ) && 0 === strcasecmp( trim( (string) $r[ $col ] ), trim( html_entity_decode( $term->name ) ) ) ) {
				$n++;
			}
		}
		if ( is_paged() ) { // the old archive paged one wine at a time; those URLs all mean page 1 now
			wp_safe_redirect( get_term_link( $term ), 301 );
			exit;
		}
		status_header( 200 );
		add_filter( 'body_class', function ( $c ) { $c[] = 'bvwl-region-page'; return $c; } );
		// The theme prints its "Category Archive" landmark heading as part of the
		// header. Hiding it with CSS still leaves a second <h1> for search engines
		// to read, so take it out of the markup.
		ob_start();
		get_header();
		echo preg_replace( '#<header class="x-header-landmark[^"]*">.*?</header>#s', '', (string) ob_get_clean(), 1 );
		echo '<div class="x-main x-container-fluid max width offset" role="main"><article class="page type-page hentry bvwl-region"><div class="entry-wrap"><div class="entry-content content">';
		echo '<nav class="bvwl-pp-crumbs"><a href="' . esc_url( home_url( '/list/' ) ) . '">Full List</a> <span>/</span> ' . esc_html( $term->name ) . '</nav>';
		echo '<h1 class="bvwl-region-title">' . esc_html( $term->name ) . '</h1>';
		echo '<p class="bvwl-region-count">' . ( $n ? sprintf( _n( '%d wine on our current list', '%d wines on our current list', $n ), $n ) : 'Nothing from this region on our current list &mdash; have a look at the <a href="' . esc_url( home_url( '/list/' ) ) . '">Full List</a>.' ) . '</p>';
		if ( '' !== trim( (string) $term->description ) ) {
			echo '<div class="bvwl-region-desc">' . wpautop( wp_kses_post( $term->description ) ) . '</div>';
		}
		if ( $n ) {
			echo do_shortcode( sprintf( '[table id=%s filter="%s" filter_columns="%d" filter_full_cell_match="true" responsive=scroll /]', self::FULL_LIST_TABLE, esc_attr( html_entity_decode( $term->name ) ), (int) $col + 1 ) );
		}
		echo '</div></div></article></div>';
		echo '<style>.bvwl-region-page .x-header-landmark{display:none}.bvwl-region .bvwl-pp-crumbs{font-size:13px;letter-spacing:.04em;text-transform:uppercase;color:#888;margin:8px 0 18px}.bvwl-region .bvwl-pp-crumbs a{color:#666;text-decoration:none}.bvwl-region .bvwl-pp-crumbs span{margin:0 6px;color:#bbb}.bvwl-region-title{font-size:36px;margin:0 0 6px}.bvwl-region-count{color:#888;margin:0 0 24px}</style>';
		get_footer();
		exit;
	}

	/** "Alsace White Wines - Blast Vintners" rather than "Alsace White Archives". */
	public function region_seo_title( $title ) {
		if ( 'on' === get_option( 'bvwl_region_pages' ) && function_exists( 'is_product_category' ) && is_product_category() ) {
			$t = get_queried_object();
			if ( $t instanceof WP_Term ) {
				return html_entity_decode( $t->name ) . ' Wines - ' . get_bloginfo( 'name' );
			}
		}
		return $title;
	}

	public function region_seo_description( $desc ) {
		if ( '' === trim( (string) $desc ) && 'on' === get_option( 'bvwl_region_pages' ) && function_exists( 'is_product_category' ) && is_product_category() ) {
			$t = get_queried_object();
			if ( $t instanceof WP_Term ) {
				return 'Our current list of ' . html_entity_decode( $t->name ) . ' wines, with prices and availability. Buy by the bottle or case from Blast Vintners.';
			}
		}
		return $desc;
	}

	/** "Pinot Noir Schueller 2023 - Blast Vintners": people search with the vintage. */
	public function seo_title( $title ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $title;
		}
		$id = get_queried_object_id();
		list( $vintage ) = self::wine_facts( $id );
		$name = get_the_title( $id );
		if ( '' === $vintage || false !== strpos( $title, $vintage ) || '' === $name ) {
			return $title;
		}
		if ( 0 === strpos( $title, $name ) ) {
			return $name . ' ' . $vintage . substr( $title, strlen( $name ) );
		}
		return $title;
	}

	/** The snippet under the link in Google: the first ~155 characters of the description. */
	public function seo_description( $desc ) {
		if ( '' !== trim( (string) $desc ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $desc;
		}
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) get_post_field( 'post_content', get_queried_object_id() ) ) ) );
		if ( '' === $text ) {
			return $desc;
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 155 ) {
			$cut  = mb_substr( $text, 0, 155 );
			$text = preg_replace( '/\s+\S*$/u', '', $cut ) . '…';
		}
		return $text;
	}
}

BVWL_Wine_List::instance();

endif;
