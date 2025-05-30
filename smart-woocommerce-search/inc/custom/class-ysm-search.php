<?php
/**
 * Class Ysm_Search
 * Retrieves posts from the database depending on settings
 */
class Ysm_Search {
	/**
	 * Current widget id
	 * @var int
	 */
	public static $w_id = 0;

	/**
	 * List of suggestions
	 * @var array
	 */
	protected static $suggestions = array();

	/**
	 * List of vars
	 * @var array
	 */
	protected static $vars = array();

	/**
	 * List of search terms
	 * @var array
	 */
	protected static $search_terms = array();

	/**
	 * Number of found posts
	 * @var array
	 */
	protected static $found_posts = 0;
	/**
	 * Found post ids
	 * @var array
	 */
	public static $post_ids = [];
	public static $processed = false;

	/**
	 * List of protected vars, that can't be overwritten
	 * @var array
	 */
	protected static $protected_vars = array(
		'post_types',
		'taxonomies',
		'postmeta',
	);
	/**
	 * Debug
	 * @var bool
	 */
	protected static $debug = 0;

	private static $time_start = 0;

	private static $time_end = 0;

	/**
	 * Initial hooks
	 */
	public static function init() {
		self::$time_start = microtime( true );
		add_action( 'pre_get_posts', array(__CLASS__, 'search_filter'), PHP_INT_MAX );
//		add_action( 'woocommerce_product_query', array(__CLASS__, 'wc_product_query'), PHP_INT_MAX );
		add_action( 'wp', array(__CLASS__, 'remove_search_filter'), 9999 );
		add_filter( 'found_posts', array( __CLASS__, 'alter_found_posts' ), PHP_INT_MAX, 2 );

		add_filter( 'the_title', 'ysm_accent_search_term', 9999, 1 );
		add_filter( 'get_the_excerpt', 'ysm_accent_search_term', 9999, 1 );
		add_filter( 'the_content', 'ysm_accent_search_term', 9999, 1 );
	}

	/**
	 * Parse widget settings to define search behavior
	 */
	public static function parse_settings() {
		static $static_vars;
		$widget_id = self::get_widget_id();
		if ( !empty( $static_vars[$widget_id] ) ) {
			self::$vars = $static_vars[$widget_id];
			return;
		}
		$registered_pt = array('post', 'page');
		if ( class_exists( 'WooCommerce' ) ) {
			$registered_pt[] = 'product';
			$registered_pt[] = 'product_variation';
		}
		if ( in_array( $widget_id, ysm_get_default_widgets_ids(), true ) ) {
			$widgets = ysm_get_default_widgets();
		} else {
			$widgets = ysm_get_custom_widgets();
		}
		$settings = $widgets[$widget_id]['settings'];
		if ( !empty( $settings['order_by'] ) && is_array( $settings['order_by'] ) ) {
			$settings['order_by'] = $settings['order_by'][0];
		} else {
			$settings['order_by'] = '';
		}
		if ( !empty( $settings['order'] ) && is_array( $settings['order'] ) ) {
			$settings['order'] = $settings['order'][0];
		} else {
			$settings['order'] = '';
		}
		$settings['post_types'] = array();
		if ( 'product' === $widget_id ) {
			$settings['post_types']['product'] = 'product';
		}
		foreach ( $registered_pt as $type ) {
			if ( !empty( $settings['post_type_' . $type] ) ) {
				$settings['post_types'][$type] = $type;
			}
		}

		// posts_per_page
		if ( empty( $settings['max_post_count'] ) ) {
			$settings['max_post_count'] = 10;
		}
		if ( empty( $settings['char_count'] ) ) {
			$settings['char_count'] = 3;
		}
		if ( !empty( $settings['allowed_product_cat'] ) ) {
			if ( !is_array( $settings['allowed_product_cat'] ) ) {
				$settings['allowed_product_cat'] = explode( ',', $settings['allowed_product_cat'] );
			}
			$allowed_cats = array();
			foreach ( $settings['allowed_product_cat'] as $dis_cat ) {
				$dis_cat = intval( trim( $dis_cat ) );
				if ( $dis_cat ) {
					$allowed_cats[] = $dis_cat;
				}
			}
			$settings['allowed_product_cat'] = $allowed_cats;
		}
		if ( !empty( $settings['disallowed_product_cat'] ) ) {
			if ( !is_array( $settings['disallowed_product_cat'] ) ) {
				$settings['disallowed_product_cat'] = explode( ',', $settings['disallowed_product_cat'] );
			}
			$disallowed_cats = array();
			foreach ( $settings['disallowed_product_cat'] as $dis_cat ) {
				$dis_cat = intval( trim( $dis_cat ) );
				if ( $dis_cat ) {
					$disallowed_cats[] = $dis_cat;
				}
			}
			$settings['disallowed_product_cat'] = $disallowed_cats;
		}
		// taxonomies
		$settings['taxonomies'] = array();
		if ( !empty( $settings['field_tag'] ) ) {
			$settings['taxonomies']['post_tag'] = 'post_tag';
		}
		if ( !empty( $settings['field_category'] ) ) {
			$settings['taxonomies']['category'] = 'category';
		}
		if ( !empty( $settings['field_product_tag'] ) ) {
			$settings['taxonomies']['product_tag'] = 'product_tag';
		}
		if ( !empty( $settings['field_product_cat'] ) ) {
			$settings['taxonomies']['product_cat'] = 'product_cat';
		}

		if ( !empty( $settings['enable_fuzzy_search'] ) && is_array( $settings['enable_fuzzy_search'] ) ) {
			$settings['enable_fuzzy_search'] = $settings['enable_fuzzy_search'][0];
		}
		if ( !empty( $settings['popup_desc_pos'] ) ) {
			if ( is_array( $settings['popup_desc_pos'] ) ) {
				$settings['popup_desc_pos'] = $settings['popup_desc_pos'][0];
			}
		} else {
			$settings['popup_desc_pos'] = 'below_image';
		}
		/* post meta */
		$settings['postmeta'] = array();
		if ( !empty( $settings['field_product_sku'] ) ) {
			$settings['postmeta']['_sku'] = '_sku';
		}

		self::$vars = array_merge( self::$vars, $settings );
		$static_vars[$widget_id] = self::$vars;
	}

	/**
	 * Hook for changing posts set on search results page
	 * @param $query
	 * @return mixed
	 */
	public static function search_filter( $query ) {
		$s = ysm_get_s();
		if ( !is_admin() && !empty( $query->query_vars['s'] ) && !empty( $s ) && !(defined( 'DOING_AJAX' ) && DOING_AJAX) ) {
			$w_id = filter_input( INPUT_GET, 'search_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			if ( !$w_id ) {
				$w_id = apply_filters( 'ysm_search_page_default_widget', 0 );
			}
			if ( !in_array( $w_id, ysm_get_default_widgets_ids(), true ) ) {
				$w_id = (int) $w_id;
			}
			if ( $w_id ) {
				self::set_widget_id( $w_id );
				self::parse_settings();
				if ( self::get_var( 'search_page_default_output' ) ) {
					return $query;
				}
				if ( !self::get_post_types() ) {
					return $query;
				}
				self::set_var( 'max_post_count', -1 );
				self::set_s( $s );
				$post_type = filter_input( INPUT_GET, 'post_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
				$per_page = get_option( 'posts_per_page' );
				if ( $post_type && 'product' === $post_type ) {
					$columns = get_option( 'woocommerce_catalog_columns', 4 );
					if ( function_exists( 'wc_get_default_products_per_row' ) ) {
						$columns = wc_get_default_products_per_row();
					}
					if ( function_exists( 'wc_get_default_product_rows_per_page' ) ) {
						$per_page = $columns * wc_get_default_product_rows_per_page();
					}
					$posts_count = apply_filters( 'loop_shop_per_page', $per_page );
				} else {
					$posts_count = $per_page;
				}

//				$paged = $query->query_vars['paged'];
//				if ( !$paged || 1 === $paged ) {
//					if ( isset( $_GET['fwp_paged'] ) ) {
//						$paged = (int) filter_input( INPUT_GET, 'fwp_paged', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
//					}
//				}

				$post_ids = self::search_posts( $posts_count );

				if ( empty( $post_ids ) ) {
					$post_ids[] = 0;
				}

				$query->set( 's', '' );
				$query->set( 'post__in', $post_ids );
				$query->set( 'post_type', self::get_post_types() );
				$query->set( 'ysm_found_posts', self::$found_posts );
				$query->set( 'posts_per_page', $posts_count );

				if ( self::get_var( 'search_page_suppress_filters' ) ) {
					$query->set( 'meta_query', [] );
					$query->set( 'date_query', [] );
					$query->set( 'tax_query', [] );

					if ( $query->get('tag__in') ) {
						$query->set( 'tag__in', [] );
					}
					if ( $query->get('tag_id') ) {
						$query->set( 'tag_id', 0 );
					}
					if ( $query->get('tag') ) {
						$query->set( 'tag', '' );
					}
					if ( $query->get('cat') ) {
						$query->set( 'cat', 0 );
					}
					if ( $query->get('category_name') ) {
						$query->set( 'category_name', '' );
					}
					if ( $query->get('category__in') ) {
						$query->set( 'category__in', [] );
					}

					if ( $query->get('author') ) {
						$query->set( 'author', 0 );
					}
					if ( $query->get('author_name') ) {
						$query->set( 'author_name', '' );
					}
					if ( $query->get('author__in') ) {
						$query->set( 'author__in', [] );
					}

					if ( $query->get('post_parent') ) {
						$query->set( 'post_parent', 0 );
					}
					if ( $query->get('post_parent__in') ) {
						$query->set( 'post_parent__in', [] );
					}
					if ( $query->get('post__not_in') ) {
						$query->set( 'post__not_in', [] );
					}

					if ( $query->get('meta_key') ) {
						$query->set( 'meta_key', '' );
						$query->set( 'meta_value', '' );
					}
				}

				$orderby_param = filter_input( INPUT_GET, 'product_orderby', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
				if ( ! $orderby_param ) {
					$orderby_param = filter_input( INPUT_GET, 'orderby', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
				}
				if ( ! $orderby_param || 'relevance' === $orderby_param ) {
					$query->set( 'orderby', 'post__in' );
					$query->set( 'order', 'ASC' );
				}
				// woof plugin
				if ( isset( $query->query_vars['woof_text_filter'] ) ) {
					$query->query_vars['woof_text_filter'] = '';
				}
			}
		}
	}

	public static function alter_found_posts( $found_posts, $query ) {
		if ( !empty( $query->query_vars['ysm_found_posts'] ) ) {
			$found_posts = $query->query_vars['ysm_found_posts'];
		}
		return $found_posts;
	}

	/**
	 * Remove hook that change posts set on search results page
	 */
	public static function remove_search_filter() {
		if ( !is_admin() && !empty( filter_input( INPUT_GET, 's', FILTER_DEFAULT ) ) && !(defined( 'DOING_AJAX' ) && DOING_AJAX) ) {
			remove_action( 'pre_get_posts', array(__CLASS__, 'search_filter'), PHP_INT_MAX );
		}
	}

	/**
	 * Generate a main query to retrieve posts from database
	 * @return array|null|object
	 */
	public static function search_posts( $posts_count = 0, $offset = 0 ) {

		self::set_search_terms();

		if ( !self::get_search_terms() ) {
			return [];
		}

		if ( self::$processed ) {
			/**
			 * Modify the array of post ids
			 *
			 * @param array $post_ids List of post ids.
			 */
			return apply_filters( 'sws_search_result_post_ids', self::$post_ids );
		}

		$limit = self::get_var( 'max_post_count' );

		$posts = array();
		$postmeta = self::get_var( 'postmeta' );

		if ( -1 === $limit || count( $posts ) < $limit ) {
			Ysm_DB::init( array(
				'posts_per_page' => $limit,
				'post_type'      => self::get_post_types(),
				'order'          => 'ASC',
				'orderby'        => 'title',
			) );
			Ysm_DB::set_relevance( [
				'post_title' => 30,
				'post_content' => 10,
				'post_excerpt' => 10,
			] );
			$the_query = Ysm_DB::do_query();
			$posts = array_unique( array_merge( $posts, $the_query->posts ) );
		}
		if ( $postmeta && (-1 === $limit || count( $posts ) < $limit) ) {
			if ( -1 !== $limit ) {
				$limit = $limit - count( $posts );
			}
			Ysm_DB::init( array(
				'posts_per_page' => $limit,
				'post_type'      => self::get_post_types(),
				'order'          => 'ASC',
				'orderby'        => 'title',
			) );
			Ysm_DB::is_postmeta_only( true );
			$meta_query = array(
				'relation' => 'OR',
			);
			foreach ( $postmeta as $item ) {
				$meta_query[] = array(
					'key'     => $item,
					'value'   => 'ysm-meta-query-placeholder',
					'compare' => 'LIKE',
				);
			}
			Ysm_DB::add_meta_query( $meta_query );
			$the_query = Ysm_DB::do_query();
			$posts = array_unique( array_merge( $posts, $the_query->posts ) );
		}

		$resulted_posts = array();
		if ( $posts ) {
			self::$found_posts = count( $posts );
			foreach ( $posts as $q_post_id ) {
				$resulted_posts[ $q_post_id ] = $q_post_id;
			}
			if ( ! $posts_count ) {
				$posts_count = self::get_var( 'max_post_count' );
				if ( -1 === $posts_count ) {
					$posts_count = ysm_get_option( self::get_widget_id(), 'max_post_count' );
				}
			}
		}

		self::$post_ids = $resulted_posts;
		self::$processed = true;

		/**
		 * Modify the array of post ids
		 *
		 * @param array $post_ids List of post ids.
		 */
		return apply_filters( 'sws_search_result_post_ids', $resulted_posts );
	}

	/**
	 * Prepare suggestions list
	 * @param $post_ids
	 */
	public static function get_suggestions( $post_ids ) {
		$ii = 0;
		foreach ( $post_ids as $post_id ) {
			$output = '';
			$post = get_post( $post_id );
			$product = null;
			$post_classes = array('smart-search-post', 'post-' . intval( $post->ID ));
			// is WooCommerce product or variation
			if ( ('product' === $post->post_type || 'product_variation' === $post->post_type) && class_exists( 'WooCommerce' ) ) {
				$wc_product = wc_get_product( $post->ID );
				if ( $wc_product ) {
					global $product;
					$product = $wc_product;
				}
			}

			// thumbnail
			$thumbnail = \YSWS\Elements\thumbnail( $post );
			if ( !$thumbnail ) {
				$post_classes[] = 'smart-search-no-thumbnail';
			}
			// wrapper link open
			$output .= '<a href="' . esc_url( get_the_permalink( $post->ID ) ) . '">';
			$output .= '<div class="' . esc_attr( implode( ' ', $post_classes ) ) . '">';
			// thumbnail
			if ( $thumbnail ) {
				$output .= $thumbnail;
			}
			// holder open
			$output .= '<div class="smart-search-post-holder">';
			// category
			$output .= \YSWS\Elements\category( $post );
			// title
			$output .= \YSWS\Elements\title( $post );
			// excerpt
			$post_excerpt = \YSWS\Elements\excerpt( $post );
			if ( 'below_title' === self::get_var( 'popup_desc_pos' ) ) {
				$output .= $post_excerpt;
			}
			if ( $product ) {
				$price = \YSWS\Elements\product_price( $product );
				$sku = \YSWS\Elements\product_sku( $product );
				$output .= '<div class="smart-search-post-price-holder">';
				// product price
				if ( $price ) {
					$output .= $price;
				}
				// product sku
				if ( $sku ) {
					$output .= $sku;
				}
				$output .= '</div>';
				$sws_product_rating = '';
				$sws_product_rating = \YSWS\Elements\rating( $product );
				if ( $sws_product_rating ) {
					$output .= $sws_product_rating;
				}
			}
			if ( 'below_price' === self::get_var( 'popup_desc_pos' ) ) {
				$output .= $post_excerpt;
			}
			$output .= '<div class="smart-search-clear"></div>';
			$output .= '</div><!--.smart-search-post-holder-->';
			// holder closed
			$output .= '<div class="smart-search-clear"></div>';
			if ( 'below_image' === self::get_var( 'popup_desc_pos' ) ) {
				$output .= $post_excerpt;
			}
			$output .= '</div>';
			$output .= '</a>';
			
			// wrapper link closed
			self::$suggestions[] = array(
				'value'     => esc_js( $post->post_title ),
				'data'      => $output,
				'url'       => get_permalink( $post->ID ),
			);
			$ii++;
			if ( $ii == self::get_var( 'max_post_count' ) ) {
				break;
			}
		}
		wp_reset_postdata();
		return self::$suggestions;
	}

	/**
	 * Output suggestions
	 */
	public static function output() {
		$res = [
			'suggestions'   => self::$suggestions,
			'view_all_link' => \YSWS\Elements\view_all_button(),
            'fullscreen_popup' => \YSWS\Elements\fullscreen_popup(),
		];
		//debug output
		if ( self::$debug ) {
			self::$time_end = microtime( true );
			$res['time'] = self::$time_end - self::$time_start;
		}
		echo json_encode( $res );
		exit;
	}

	/**
	 * Compare by title
	 * @param $a
	 * @param $b
	 * @return int
	 */
	public static function cmp_title( $a, $b ) {
		return strcasecmp( $a->post_title, $b->post_title );
	}

	/**
	 * Compare by relevance
	 * @param $a
	 * @param $b
	 * @return int
	 */
	public static function cmp_relevance( $a, $b ) {
		if ( $a->relevance == $b->relevance ) {
			return 0;
		}
		return ( $a->relevance < $b->relevance ? 1 : -1 );
	}

	/**
	 * Get current widget id
	 * @return int|string
	 */
	public static function get_widget_id() {
		return self::$w_id;
	}

	/**
	 * Set current widget id
	 * @param $new_widget_id
	 */
	public static function set_widget_id( $new_widget_id ) {
		if ( !in_array( $new_widget_id, ysm_get_default_widgets_ids(), true ) ) {
			$new_widget_id = (int) $new_widget_id;
		}
		self::$w_id = $new_widget_id;
	}

	/**
	 * Set search string
	 * @param $s
	 */
	public static function set_s( $s = '' ) {
		$s = strip_tags( trim( strval( $s ) ) );
		if ( $s ) {
			$s = ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s ) );
			self::$vars['s'] = $s;
		}
	}

	/**
	 * Get post types
	 * @param string $type
	 * @return array|bool
	 */
	public static function get_post_types( $type = '' ) {
		$post_types = (array) self::get_var( 'post_types' );
		if ( $type ) {
			return isset( $post_types[$type] );
		}
		return $post_types;
	}

	/**
	 * Get search terms
	 * @return array
	 */
	public static function get_search_terms() {
		return (array) self::$search_terms;
	}

	/**
	 * Get found posts count
	 * @return int
	 */
	public static function get_found_posts_count() {
		return (int) self::$found_posts;
	}

	/**
	 * Set search terms
	 * @return void
	 */
	protected static function set_search_terms() {
		self::$search_terms = [];
		$search_terms = self::get_var( 'enable_fuzzy_search' ) ? explode( ' ', self::get_var( 's' ) ) : (array) self::get_var( 's' );
		/**
		 * Modify the array of search terms
		 *
		 * @param array $search_terms List of search terms.
		 */
		$search_terms = (array) apply_filters( 'sws_filter_search_terms', $search_terms );
		foreach ( $search_terms as $search_term ) {
			if ( strlen( $search_term ) >= self::get_var( 'char_count' ) ) {
				self::$search_terms[] = $search_term;
			}
		}
	}

	/**
	 * Get var
	 * @param $name
	 * @return mixed|null
	 */
	public static function get_var( $name ) {
		return ( isset( self::$vars[$name] ) ? self::$vars[$name] : null );
	}

	/**
	 * Set var
	 * @param $name
	 * @param $value
	 * @return void
	 */
	public static function set_var( $name, $value ) {
		if ( !in_array( $name, self::$protected_vars, true ) ) {
			self::$vars[$name] = $value;
		}
	}

}
