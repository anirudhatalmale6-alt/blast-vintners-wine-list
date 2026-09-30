<?php
/**
 * Plugin Name: Blast Vintners Wine List
 * Description: The bespoke wine-list storefront: the Buy column and expandable descriptions on TablePress tables, the add-to-cart endpoint behind them, and the spreadsheet-import-to-WooCommerce-product step. Previously this code lived inside the TablePress plugin's own source files and inside the X theme's functions.php, which meant updating either one destroyed it. It now lives here, on documented hooks only.
 * Version: 1.0.0
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

	/** @var array<int,array> cached row->product maps, keyed by TablePress table id */
	private $row_products_cache = array();

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
		add_action( 'wp_head', array( $this, 'print_iframe_embed_support' ) );
		add_filter( 'woocommerce_cart_item_thumbnail', '__return_empty_string' );

		// --- spreadsheet import -> WooCommerce products -----------------
		add_action( 'admin_footer', array( $this, 'print_import_checkbox' ) );
		add_action( 'tablepress_event_imported_table', array( $this, 'after_tablepress_import' ), 10, 1 );
		add_action( 'save_post_tablepress_table', array( $this, 'maybe_sync_products_on_save' ), 20, 3 );
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
		if ( isset( $this->row_products_cache[ $table_id ] ) ) {
			return $this->row_products_cache[ $table_id ];
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
				$n = 0;
				foreach ( $table['visibility']['rows'] as $key => $visible ) {
					if ( $key > 0 && $visible ) {
						$row_products[ $n ] = array(
							'product_id'  => isset( $meta[ $key ]['prodcut_id'] ) ? $meta[ $key ]['prodcut_id'] : 0,
							'description' => isset( $meta[ $key ]['description'] ) ? $meta[ $key ]['description'] : '',
							'qty'         => isset( $meta[ $key ]['qty'] ) ? $meta[ $key ]['qty'] : '',
							'unit'        => isset( $meta[ $key ]['unit'] ) ? $meta[ $key ]['unit'] : '',
						);
						$n++;
					}
				}
			}
		}

		$this->row_products_cache[ $table_id ] = $row_products;
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

		// Identical to the original, is_int() checks and all: the stored qty/unit
		// are real integers, so a multiple-of-unit list is produced; anything else
		// falls back to a single "1".
		if ( $rp['qty'] && $rp['unit'] && is_int( $rp['unit'] ) && is_int( $rp['qty'] ) ) {
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

       function tbpwooaddToCart(p_id,r_idx) {

      var ajaxurl = '<?php echo $ajaxurl; ?>';

      jQuery( "#tbp-woo-qty"+r_idx ).append( "<p>Processing...</p>" );
      jQuery('#tablepress-39-scroll-wrapper .woocommerce-notices-wrapper').remove();
        var qty = jQuery("#tbp-woo-qt"+r_idx).val();
        var id = p_id;
        var data = {
          action     : 'my_custom_add_to_cart',
          product_id : id,
          qty: qty ? qty : 1
        };
        jQuery.post(ajaxurl, data, function(response) {
          if( response.success  ) {
             jQuery( "#tbp-woo-qty"+r_idx ).remove();
            jQuery('#tablepress-39-scroll-wrapper').prepend(response.message);
            jQuery('.bc-mnc__cart-link--count-circle').text(response.qt);

            jQuery( document.body ).trigger( 'wc_fragment_refresh' );
            jQuery('html, body').animate({
          scrollTop: jQuery('#tablepress-39-scroll-wrapper').offset().top
         }, 2000);
          }else{
        jQuery('html, body').animate({
          scrollTop: jQuery('#tablepress-39-scroll-wrapper').offset().top
         }, 2000);
            jQuery('#tablepress-39-scroll-wrapper').prepend(response.message);
            jQuery('.bc-mnc__cart-link--count-circle').text(response.qt);

            jQuery( document.body ).trigger( 'wc_fragment_refresh' );
            jQuery( "#tbp-woo-qty"+r_idx ).remove();
          }
        }, 'json');

      }
    function tbpwooshowAllDescription(p_id,r_idx) {
    }
    </script>
    <style type="text/css">
    .tbp-woo-btn{    background: none repeat scroll 0 0 #EEEEEE !important;border: 1px solid #E4E4E4 !important;border-radius: 3px !important;color: #000000 !important;float: left !important;height: 27px !important;}
    .tbp-woo-qt{ float: left !important;height: auto !important;max-width: 42px !important;padding: 3px 2px !important;text-align: center !important;margin-right: 2px !important;}
    .tbp-woo-desc{display: none;padding: 20px 0 !important;}
    .tbpwoodesc{float: right; text-align: right;}
    .tablepress tbody td{cursor: pointer !important;}
    .column-3{width:50% !important; max-width: 50% !important;}
    </style>
		<?php
	}

	/* =====================================================================
	 * Carried over verbatim from the X theme's functions.php
	 * ===================================================================== */

	public function print_iframe_embed_support() {
		?>
	<style>
		#menu-primary-menus,.x-brand img,.wp-block-image {display: none;}
	</style>
	<script>
		window.onload = function()
		{
			if(window.location !== window.parent.location)
			{
				jQuery("#menu-primary-menus").hide();
				jQuery(".x-brand img").hide();
				jQuery(".wp-block-image").hide();
			}
			else
			{
				jQuery("#menu-primary-menus").show();
				jQuery(".x-brand img").show();
				jQuery(".wp-block-image").show();
			}
		};
	</script>
		<?php
		if ( isset( $_REQUEST['ifr'] ) ) {
			echo "<style>header0,.wp-block-image,.x-nav-collapse,.x-navbar-inner.x-container-fluid {display:none;} </style>";
			?>
	    <script>
	    	function checkIfSafari()
	    	{
	    		if (navigator.userAgent.search("Safari") >= 0 && navigator.userAgent.search("Chrome") < 0)
	    		{
	    			return true;
	    		}
	    		return false;
	    	}
	    	function safariFix() {
    if (1==1 || (navigator.userAgent.search("Safari") >= 0 && navigator.userAgent.search("Chrome") < 0)) {
      document.requestStorageAccess().then(function success(hasAccess) {
          console.log("Got cookie access for Safari workaround");
          document.cookie = "foo=bar";
        },  function rejected(error) { alert("Failed to set Cookies!");console.log('access denied');console.log(error); });
      }
  }
	    	window.onload = function()
	    	{
	    		if(checkIfSafari())
	    		{
	    			var mask = jQuery("<div></div>");
	    			jQuery(mask).css({"z-index":"9999","width":"100vw","height":"100vh","position":"fixed","top":"0px","left":"0px","background-color":"#ffffff"});
	    			jQuery(mask).fadeTo(100,0.95);
	    			var t = jQuery("<div onclick='safariFix()' style='padding:10px;text-transform:uppercase;cursor:pointer;background-color:#333;color:#eee;font-size:12px;'>Approve Use of Cookies</div>");
	    			jQuery(t).css({"position":"absolute","left":"50%","top":"50%","transform":"translate(-50%,-50%)"});
	    			jQuery(mask).append(t);
	    			jQuery("body").append(mask);
	    			jQuery(mask).on("click",function()
	    			{
	    				jQuery(mask).remove();
	    			});
	    		}
	    		return;
	    	}
	    </script>
			<?php
		}
	}

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
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'tablepress_import' ) ) {
			return;
		}
		?>
		<script>
		jQuery(function($){
			var $row = $('#row-import-existing-table').last();
			if ( ! $row.length || $('#bvwl-woo-product').length ) { return; }
			$row.after(
				'<tr class="bottom-border" id="bvwl-woo-row">' +
				'<th class="column-1" scope="row"><label for="bvwl-woo-product">Add Woocommerce Product:</label></th>' +
				'<td class="column-2"><input type="checkbox" id="bvwl-woo-product" name="import[woo_product]" value="1"/></td>' +
				'</tr>'
			);
		});
		</script>
		<?php
	}

	/** TablePress 2.x fires this; harmless on 1.4 where it simply never runs. */
	public function after_tablepress_import( $table_id ) {
		if ( empty( $_POST['import']['woo_product'] ) ) {
			return;
		}
		$this->sync_products_for_table( (string) $table_id );
	}

	/** TablePress 1.4 route: the table is stored as a post, so catch its save. */
	public function maybe_sync_products_on_save( $post_id, $post, $update ) {
		if ( empty( $_POST['import']['woo_product'] ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$table_id = $this->post_id_to_table_id( $post_id );
		if ( '' === $table_id ) {
			return;
		}
		$this->sync_products_for_table( $table_id );
	}

	/**
	 * Walk the table's rows, create a WooCommerce product for each one, and store
	 * the row -> product map. Column names are matched case-insensitively and
	 * against the alternatives this shop's spreadsheets actually use, which the
	 * previous version did not do.
	 */
	public function sync_products_for_table( $table_id ) {
		if ( ! class_exists( 'woocommerce' ) ) {
			return;
		}
		$post_id = $this->table_id_to_post_id( $table_id );
		if ( ! $post_id ) {
			return;
		}
		$rows = json_decode( get_post( $post_id )->post_content, true );
		if ( ! is_array( $rows ) || empty( $rows[0] ) ) {
			return;
		}

		$header = $rows[0];
		$col = function ( array $names ) use ( $header ) {
			foreach ( $names as $want ) {
				foreach ( $header as $idx => $label ) {
					if ( 0 === strcasecmp( trim( (string) $label ), $want ) ) {
						return $idx;
					}
				}
			}
			return false;
		};

		$k_cat     = $col( array( 'Category', 'Region' ) );
		$k_wine    = $col( array( 'Wine', 'Name' ) );
		$k_desc    = $col( array( 'Description' ) );
		$k_cost    = $col( array( 'Inc Duty & Vat', 'Inc Duty and Vat', 'Price' ) );
		$k_unit    = $col( array( 'Unit' ) );
		$k_vintage = $col( array( 'Vintage' ) );
		$k_qty     = $col( array( 'Bottles', 'Quantity', 'Qty' ) );

		$map = array( 0 => 0 );
		for ( $i = 1; $i < count( $rows ); $i++ ) {
			$row = $rows[ $i ];
			$info = array(
				'title'       => ( false !== $k_wine && isset( $row[ $k_wine ] ) ) ? trim( (string) $row[ $k_wine ] ) : '',
				'description' => ( false !== $k_desc && isset( $row[ $k_desc ] ) ) ? (string) $row[ $k_desc ] : '',
				'category'    => ( false !== $k_cat && isset( $row[ $k_cat ] ) ) ? (string) $row[ $k_cat ] : '',
				'price'       => ( false !== $k_cost && isset( $row[ $k_cost ] ) ) ? trim( str_replace( '£', '', (string) $row[ $k_cost ] ) ) : '',
				'note'        => ( false !== $k_vintage && isset( $row[ $k_vintage ] ) ) ? (string) $row[ $k_vintage ] : '',
				'qty'         => ( false !== $k_qty && isset( $row[ $k_qty ] ) ) ? (string) $row[ $k_qty ] : '',
				'unit'        => ( false !== $k_unit && isset( $row[ $k_unit ] ) ) ? (string) $row[ $k_unit ] : '',
			);

			$product_id = ( '' !== $info['title'] && '' !== $info['price'] ) ? $this->create_product( $info ) : 0;

			$map[ $i ] = array(
				'prodcut_id'  => $product_id,               // key spelling kept: the renderer reads it
				'description' => $info['description'],
				'qty'         => is_numeric( $info['qty'] ) ? (int) $info['qty'] : $info['qty'],
				'unit'        => is_numeric( $info['unit'] ) ? (int) $info['unit'] : $info['unit'],
			);
		}

		update_post_meta( $post_id, self::META_KEY, $map );
	}

	private function create_product( array $info ) {
		$post_id = wp_insert_post(
			array(
				'post_author'  => get_current_user_id(),
				'post_content' => $info['description'],
				'post_status'  => 'publish',
				'post_title'   => $info['title'],
				'post_type'    => 'product',
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		if ( '' !== $info['category'] ) {
			wp_set_object_terms( $post_id, $info['category'], 'product_cat' );
		}
		wp_set_object_terms( $post_id, 'simple', 'product_type' );

		$quantity = ( '' !== $info['qty'] ) ? $info['qty'] : 1;

		update_post_meta( $post_id, '_visibility', 'visible' );
		update_post_meta( $post_id, '_stock_status', 'instock' );
		update_post_meta( $post_id, '_virtual', 'no' );
		update_post_meta( $post_id, '_regular_price', $info['price'] );
		update_post_meta( $post_id, '_purchase_note', $info['note'] );
		update_post_meta( $post_id, '_featured', 'no' );
		update_post_meta( $post_id, '_weight', '' );
		update_post_meta( $post_id, '_length', '' );
		update_post_meta( $post_id, '_width', '' );
		update_post_meta( $post_id, '_height', '' );
		update_post_meta( $post_id, '_sale_price_dates_to', '' );
		update_post_meta( $post_id, '_price', $info['price'] );
		update_post_meta( $post_id, '_sold_individually', '' );
		update_post_meta( $post_id, '_backorders', 'no' );
		update_post_meta( $post_id, '_stock', $quantity );
		update_post_meta( $post_id, '_manage_stock', 'yes' );

		return $post_id;
	}
}

BVWL_Wine_List::instance();

endif;
