<?php
class Jetpack_RelatedPosts {
	const VERSION = '20140114';

	/**
	 * Creates and returns a static instance of Jetpack_RelatedPosts.
	 *
	 * @return Jetpack_RelatedPosts
	 */
	public static function init() {
		static $instance = NULL;

		if ( ! $instance ) {
			if ( method_exists( 'WPCOM_RelatedPosts', 'init' ) ) {
				$instance = WPCOM_RelatedPosts::init();
			} else {
				$instance = new Jetpack_RelatedPosts(
					Jetpack::init()->get_option( 'id' )
				);
			}
		}

		return $instance;
	}

	/**
	 * Creates and returns a static instance of Jetpack_RelatedPosts_Raw.
	 *
	 * @return Jetpack_RelatedPosts
	 */
	public static function init_raw() {
		static $instance = NULL;

		if ( ! $instance ) {
			if ( method_exists( 'WPCOM_RelatedPosts', 'init_raw' ) ) {
				$instance = WPCOM_RelatedPosts::init_raw();
			} else {
				$instance = new Jetpack_RelatedPosts_Raw(
					Jetpack::init()->get_option( 'id' )
				);
			}
		}

		return $instance;
	}

	protected $_blog_id;
	protected $_options;
	protected $_blog_charset;
	protected $_convert_charset;

	/**
	 * Constructor for Jetpack_RelatedPosts.
	 *
	 * @param int $blog_id
	 * @uses get_option, add_action
	 * @return null
	 */
	public function __construct( $blog_id ) {
		$this->_blog_id = $blog_id;
		$this->_blog_charset = get_option( 'blog_charset' );
		$this->_convert_charset = ( function_exists( 'iconv' ) && ! preg_match( '/^utf\-?8$/i', $this->_blog_charset ) );

		add_action( 'admin_init', array( $this, 'action_admin_init' ) );
		add_action( 'wp', array( $this, 'action_frontend_init' ) );
	}

	/**
	 * =================
	 * ACTIONS & FILTERS
	 * =================
	 */

	/**
	 * Add a checkbox field to Settings > Reading for enabling related posts.
	 *
	 * @action admin_init
	 * @uses add_settings_field, __, register_setting, add_action
	 * @return null
	 */
	public function action_admin_init() {
		if ( ! $this->_show_config_in_admin( $this->_blog_id ) )
			return;

		// Add the setting field [jetpack_relatedposts] and place it in Settings > Reading
		add_settings_field( 'jetpack_relatedposts', '<span id="jetpack_relatedposts">' . __( 'Related posts', 'jetpack' ) . '</span>', array( $this, 'print_setting_html' ), 'reading' );
		register_setting( 'reading', 'jetpack_relatedposts', array( $this, 'parse_options' ) );
		add_action('admin_head', array( $this, 'print_setting_head' ) );

		// Enqueue style for live preview
		$this->_enqueue_assets( false, true );
	}

	/**
	 * Load related posts assets if it's a elegiable frontend page or execute search and return JSON if it's an endpoint request.
	 *
	 * @global $_GET
	 * @action wp
	 * @returns null
	 */
	public function action_frontend_init() {
		if ( ! $this->_enabled_for_request( $this->_blog_id ) )
			return;

		if ( isset( $_GET['relatedposts_exclude'] ) )
			$exclude_id = (int)$_GET['relatedposts_exclude'];
		else
			$exclude_id = 0;

		if ( isset( $_GET['relatedposts_to'] ) )
			$this->_action_frontend_redirect( $_GET['relatedposts_to'], $_GET['relatedposts_order'] );
		elseif ( isset( $_GET['relatedposts'] ) )
			$this->_action_frontend_init_ajax( $exclude_id );
		else
			$this->_action_frontend_init_page();

	}

	/**
	 * Adds a target to the post content to load related posts into.
	 *
	 * @filter the_content
	 * @param string $content
	 * @uses esc_html__, apply_filters
	 * @returns string
	 */
	public function filter_add_target_to_dom( $content ) {
		$options = $this->get_options();

		if ( $options['show_headline'] ) {
			$headline = sprintf(
				'<p class="jp-relatedposts-headline"><em>%s</em></p>',
				esc_html__( 'Related', 'jetpack' )
			);
		} else {
			$headline = '';
		}

		$headline = apply_filters( 'jetpack_relatedposts_filter_headline', $headline );

		return <<<EOT
$content
<div id='jp-relatedposts' class='jp-relatedposts'>
	$headline
</div>
EOT;
	}

	/**
	 * ========================
	 * PUBLIC UTILITY FUNCTIONS
	 * ========================
	 */

	/**
	 * Gets options set for Jetpack_RelatedPosts and merge with defaults.
	 *
	 * @uses get_option, apply_filters
	 * @return array
	 */
	public function get_options() {
		if ( null === $this->_options ) {
			$this->_options = get_option( 'jetpack_relatedposts' );
			if ( !is_array( $this->_options ) )
				$this->_options = array();
			if ( !isset( $this->_options['enabled'] ) )
				$this->_options['enabled'] = true;
			if ( !isset( $this->_options['show_headline'] ) )
				$this->_options['show_headline'] = true;
			if ( !isset( $this->_options['show_thumbnails'] ) )
				$this->_options['show_thumbnails'] = false;
			if ( empty( $this->_options['size'] ) || (int)$this->_options['size'] < 1 )
				$this->_options['size'] = 3;

			$this->_options = apply_filters( 'jetpack_relatedposts_filter_options', $this->_options );
		}

		return $this->_options;
	}

	/**
	 * Parses input and returnes normalized options array.
	 *
	 * @param array $input
	 * @uses self::get_options
	 * @return array
	 */
	public function parse_options( $input ) {
		$current = $this->get_options();

		if ( !is_array( $input ) )
			$input = array();

		if ( isset( $input['enabled'] ) && '1' == $input['enabled'] ) {
			$current['enabled'] = true;
			$current['show_headline'] = ( isset( $input['show_headline'] ) && '1' == $input['show_headline'] );
			$current['show_thumbnails'] = ( isset( $input['show_thumbnails'] ) && '1' == $input['show_thumbnails'] );
		} else {
			$current['enabled'] = false;
		}

		if ( isset( $input['size'] ) && (int)$input['size'] > 0 )
			$current['size'] = (int)$input['size'];
		else
			$current['size'] = null;

		return $current;
	}

	/**
	 * HTML for admin settings page.
	 *
	 * @uses self::get_options, checked, __
	 * @returns null
	 */
	public function print_setting_html() {
		$options = $this->get_options();
		$template = <<<EOT
<ul id="settings-reading-relatedposts">
	<li>
        <label><input type="radio" name="jetpack_relatedposts[enabled]" value="0" class="tog" %s /> %s</label>
	</li>
	<li>
        <label><input type="radio" name="jetpack_relatedposts[enabled]" value="1" class="tog" %s /> %s</label>
		<ul id="settings-reading-relatedposts-customize">
			<li>
				<label><input name="jetpack_relatedposts[show_headline]" type="checkbox" value="1" %s /> %s</label>
			</li>
			<li>
				<label><input name="jetpack_relatedposts[show_thumbnails]" type="checkbox" value="1" %s /> %s</label>
			</li>
		</ul>
		<div id='settings-reading-relatedposts-preview'>
			%s
			<div class='jp-relatedposts'></div>
		</div>
	</li>
</ul>
EOT;
		printf(
			$template,
			checked( $options['enabled'], false, false ),
			__( 'Hide related content after posts', 'jetpack' ),
			checked( $options['enabled'], true, false ),
			__( 'Show related content after posts', 'jetpack' ),
			checked( $options['show_headline'], true, false ),
			__( 'Show a "Related" header to more clearly separate the related section from posts', 'jetpack' ),
			checked( $options['show_thumbnails'], true, false ),
			__( 'Show thumbnails for related posts', 'jetpack' ),
			__( 'Preview:', 'jetpack' )
		);
	}

	/**
	 * Head JS/CSS for admin settings page.
	 *
	 * @uses esc_html__
	 * @returns null
	 */
	public function print_setting_head() {
		$related_headline = sprintf(
			'<p class="jp-relatedposts-headline"><em>%s</em></p>',
			esc_html__( 'Related', 'jetpack' )
		);
		$related_with_images = '<p class="jp-relatedposts-post jp-relatedposts-post0" data-post-format="false"><img width="48" src="http://en.blog.files.wordpress.com/2012/08/1-wpios-ipad-3-1-viewsite.png?w=48&amp;h=48&amp;crop=1" alt="Big iPhone/iPad Update Now&nbsp;Available" scale="0"><strong><a href="#" rel="nofollow">Big iPhone/iPad Update Now Available</a></strong><br><span>In "Mobile"</span></p><p class="jp-relatedposts-post jp-relatedposts-post1" data-post-format="false"><img width="48" src="http://en.blog.files.wordpress.com/2013/04/wordpress-com-news-wordpress-for-android-ui-update2.jpg?w=48&amp;h=48&amp;crop=1" alt="The WordPress for Android App Gets a Big&nbsp;Facelift" scale="0"><strong><a href="#" rel="nofollow">The WordPress for Android App Gets a Big Facelift</a></strong><br><span>In "Mobile"</span></p><p class="jp-relatedposts-post jp-relatedposts-post2" data-post-format="false"><img width="48" src="http://en.blog.files.wordpress.com/2013/01/videopresswedding.jpg?w=48&amp;h=48&amp;crop=1" alt="Upgrade Focus: VideoPress For&nbsp;Weddings" scale="0"><strong><a href="#" rel="nofollow">Upgrade Focus: VideoPress For Weddings</a></strong><br><span>In "Upgrade"</span></p>';
		$related_without_images = '<p class="jp-relatedposts-post jp-relatedposts-post0" data-post-format="false"><strong><a href="#" rel="nofollow">Big iPhone/iPad Update Now Available</a></strong><br><span>In "Mobile"</span></p><p class="jp-relatedposts-post jp-relatedposts-post1" data-post-format="false"><strong><a href="#" rel="nofollow">The WordPress for Android App Gets a Big Facelift</a></strong><br><span>In "Mobile"</span></p><p class="jp-relatedposts-post jp-relatedposts-post2" data-post-format="false"><strong><a href="#" rel="nofollow">Upgrade Focus: VideoPress For Weddings</a></strong><br><span>In "Upgrade"</span></p>';
		echo <<<EOT
<style type="text/css">
	#settings-reading-relatedposts-customize { padding-left:2em; margin-top:.5em; }
	#settings-reading-relatedposts .disabled { opacity:.5; filter:Alpha(opacity=50); }
	#settings-reading-relatedposts-preview .jp-relatedposts { background:#fff; padding:.5em; width:75%; }
</style>
<script type="text/javascript">
	jQuery( document ).ready( function($) {
		var update_ui = function() {
			if ( '1' == $( 'input[name="jetpack_relatedposts[enabled]"]:checked' ).val() ) {
				$( '#settings-reading-relatedposts-customize' )
					.removeClass( 'disabled' )
					.find( 'input' )
					.attr( 'disabled', false );
				$( '#settings-reading-relatedposts-preview' )
					.removeClass( 'disabled' );
			} else {
				$( '#settings-reading-relatedposts-customize' )
					.addClass( 'disabled' )
					.find( 'input' )
					.attr( 'disabled', true );
				$( '#settings-reading-relatedposts-preview' )
					.addClass( 'disabled' );
			}
		};

		var update_preview = function() {
			var html = '';
			if ( $( 'input[name="jetpack_relatedposts[show_headline]"]:checked' ).size() ) {
				html += '$related_headline';
			}
			if ( $( 'input[name="jetpack_relatedposts[show_thumbnails]"]:checked' ).size() ) {
				html += '$related_with_images';
			} else {
				html += '$related_without_images';
			}
			$( '#settings-reading-relatedposts-preview .jp-relatedposts' )
				.html( html )
				.show();
		};

		// Update on load
		update_preview();
		update_ui();

		// Update on change
		$( '#settings-reading-relatedposts-customize input' )
			.change( update_preview );
		$( '#settings-reading-relatedposts' )
			.find( 'input.tog' )
			.change( update_ui );
	});
</script>
EOT;
	}

	/**
	 * Gets an array of related posts that match the given post_id.
	 *
	 * @param int $post_id
	 * @param array $args - params to use when building ElasticSearch filters to narrow down the search domain.
	 * @uses self::get_options, get_post_type, wp_parse_args, apply_filters
	 * @return array
	 */
	public function get_for_post_id( $post_id, array $args ) {
		$options = $this->get_options();

		if ( ! $options['enabled'] || 0 == (int)$post_id )
			return array();

		$defaults = array(
			'size' => (int)$options['size'],
			'post_type' => get_post_type( $post_id ),
			'has_terms' => array(),
			'date_range' => array(
				'from' => strtotime( '-2 year' ),
				'to' => time()
			),
			'exclude_post_id' => 0,
		);
		$args = wp_parse_args( $args, $defaults );
		$args = apply_filters( 'jetpack_relatedposts_filter_args', $args, $post_id );

		$filters = $this->_get_es_filters_from_args( $post_id, $args );
		$filters = apply_filters( 'jetpack_relatedposts_filter_filters', $filters, $post_id );

		$results = $this->_get_related_posts( $this->_blog_id, $post_id, $args['size'], $filters );
		return apply_filters( 'jetpack_relatedposts_returned_results', $results, $post_id );
	}

	/**
	 * =========================
	 * PRIVATE UTILITY FUNCTIONS
	 * =========================
	 */

	/**
	 * Creates an array of ElasticSearch filters based on the post_id and args.
	 *
	 * @param int $post_id
	 * @param array $args
	 * @uses apply_filters, get_post_types
	 * @return array
	 */
	protected function _get_es_filters_from_args( $post_id, array $args ) {
		$filters = array();

		$args['has_terms'] = apply_filters( 'jetpack_relatedposts_filter_has_terms', $args['has_terms'], $post_id );
		if ( ! empty( $args['has_terms'] ) ) {
			foreach( (array)$args['has_terms'] as $term ) {
				if ( mb_strlen( $term->taxonomy ) ) {
					switch ( $term->taxonomy ) {
						case 'post_tag':
							$tax_fld = 'tag.slug';
							break;
						case 'category':
							$tax_fld = 'category.slug';
							break;
						default:
							$tax_fld = 'taxonomy.' . $term->taxonomy . '.slug';
							break;
					}
					$filters[] = array( 'term' => array( $tax_fld => $term->slug ) );
				}
			}
		}

		$args['post_type'] = apply_filters( 'jetpack_relatedposts_filter_post_type', $args['post_type'], $post_id );
		$valid_post_types = get_post_types();
		if ( is_array( $args['post_type'] ) ) {
			$sanitized_post_types = array();
			foreach ( $args['post_type'] as $pt ) {
				if ( in_array( $pt, $valid_post_types ) )
					$sanitized_post_types[] = $pt;
			}
			if ( ! empty( $sanitized_post_types ) )
				$filters[] = array( 'terms' => array( 'post_type' => $sanitized_post_types ) );
		} else if ( in_array( $args['post_type'], $valid_post_types ) && 'all' != $args['post_type'] ) {
			$filters[] = array( 'term' => array( 'post_type' => $args['post_type'] ) );
		}

		$args['date_range'] = apply_filters( 'jetpack_relatedposts_filter_date_range', $args['date_range'], $post_id );
		if ( is_array( $args['date_range'] ) ) {
			$args['date_range'] = array_map( 'intval', $args['date_range'] );
			if ( !empty( $args['date_range']['from'] ) && !empty( $args['date_range']['to'] ) ) {
				$filters[] = array(
					'range' => array(
						'date_gmt' => $this->_get_coalesced_range( $args['date_range'] ),
					)
				);
			}
		}

		$args['exclude_post_id'] = apply_filters( 'jetpack_relatedposts_filter_exclude_post_id', $args['exclude_post_id'], $post_id );
		if ( !empty( $args['exclude_post_id'] ) ) {
			$filters[] = array( 'not' => array( 'term' => array( 'post_id' => (int)$args['exclude_post_id'] ) ) );
		}

		return $filters;
	}

	/**
	 * Takes a range and coalesces it into a month interval bracketed by a time as determined by the blog_id to enhance caching.
	 *
	 * @param array $date_range
	 * @return array
	 */
	protected function _get_coalesced_range( array $date_range ) {
		$now = time();
		$coalesce_time = $this->_blog_id % 86400;
		$current_time = $now - strtotime( 'today', $now );

		if ( $current_time < $coalesce_time && '01' == date( 'd', $now ) ) {
			// Move back 1 period
			return array(
				'from' => date( 'Y-m-01', strtotime( '-1 month', $date_range['from'] ) ) . ' ' . date( 'H:i:s', $coalesce_time ),
				'to'   => date( 'Y-m-01', $date_range['to'] ) . ' ' . date( 'H:i:s', $coalesce_time ),
			);
		} else {
			// Use current period
			return array(
				'from' => date( 'Y-m-01', $date_range['from'] ) . ' ' . date( 'H:i:s', $coalesce_time ),
				'to'   => date( 'Y-m-01', strtotime( '+1 month', $date_range['to'] ) ) . ' ' . date( 'H:i:s', $coalesce_time ),
			);
		}
	}

	/**
	 * Generate and output ajax response for related posts API call.
	 * NOTE: Calls exit() to end all further processing after payload has been outputed.
	 *
	 * @param int $exclude_id
	 * @uses send_nosniff_header, self::get_for_post_id, get_the_ID
	 * @return null
	 */
	protected function _action_frontend_init_ajax( $exclude_id ) {
		define( 'DOING_AJAX', true );

		header( 'Content-type: application/json; charset=utf-8' ); // JSON can only be UTF-8
		send_nosniff_header();

		$related_posts = $this->get_for_post_id( get_the_ID(), array( 'exclude_post_id' => $exclude_id ) );

		$options = $this->get_options();
		if ( count( $related_posts ) != $options['size'] )
			echo '[]';
		else
			echo json_encode( $related_posts );

		exit();
	}

	/**
	 * Blog reader has clicked on a related link, log this action for future relevance analysis and redirect the user to their post.
	 * NOTE: Calls exit() to end all further processing after redirect has been outputed.
	 *
	 * @param int $to_post_id
	 * @param int $link_position
	 * @uses get_the_ID, add_query_arg, get_permalink, wp_safe_redirect
	 * @return null
	 */
	protected function _action_frontend_redirect( $to_post_id, $link_position ) {
		$post_id = get_the_ID();

		$this->_log_click( $post_id, $to_post_id, $link_position );

		wp_safe_redirect( add_query_arg( array( 'relatedposts_exclude' => $post_id ), get_permalink( $to_post_id ) ) );

		exit();
	}

	/**
	 * Returns a UTF-8 encoded array of post information for the given post_id
	 *
	 * @param int $blog_id
	 * @param int $post_id
	 * @param int $position
	 * @uses get_post, add_query_arg, remove_query_arg, get_post_format
	 * @return array
	 */
	protected function _get_related_post_data_for_post( $blog_id, $post_id, $position ) {
		$post = get_post( $post_id );

		return array(
			'id' => $post->ID,
			'url' => add_query_arg(
				array( 'relatedposts_to' => $post->ID, 'relatedposts_order' => $position ),
				remove_query_arg( array( 'relatedposts', 'relatedposts_exclude' ) )
			),
			'title' => $this->_to_utf8( $this->_get_title( $post->post_title, $post->post_content ) ),
			'format' => get_post_format( $post->ID ),
			'excerpt' => $this->_to_utf8( $this->_get_excerpt( $post->post_excerpt, $post->post_content ) ),
			'context' => $this->_to_utf8( $this->_generate_related_post_context( $this->_blog_id, $post->ID ) ),
			'thumbnail' => $this->_to_utf8( $this->_generate_related_post_image( $this->_blog_id, $post->ID ) ),
		);
	}

	/**
	 * Returns either the title or a small excerpt to use as title for post.
	 *
	 * @param string $post_title
	 * @param string $post_content
	 * @uses strip_shortcodes, wp_trim_words
	 * @return string
	 */
	protected function _get_title( $post_title, $post_content ) {
		if ( ! empty( $post_title ) )
			return $post_title;
		else
			return wp_trim_words( strip_shortcodes( $post_content ), 5 );
	}

	/**
	 * Returns a plain text post excerpt for title attribute of links.
	 *
	 * @param string $post_excerpt
	 * @param string $post_content
	 * @uses strip_shortcodes, wp_trim_words
	 * @return string
	 */
	protected function _get_excerpt( $post_excerpt, $post_content ) {
		if ( empty( $post_excerpt ) )
			$excerpt = $post_content;
		else
			$excerpt = $post_excerpt;

		return wp_trim_words( strip_shortcodes( $excerpt ) );
	}

	/**
	 * Generates the thumbnail image to be used for the post.
	 * Order of importance:
	 *   - Featured image
	 *   - First image extracted from the post (WPCOM/Jetpack Only)
	 *   - Author avatar if more then 10 authors on site
	 *   - mShot of site as fallback
	 *
	 * @param int $blog_id
	 * @param int $post_id
	 * @uses self::get_options, apply_filters, has_post_thumbnail, get_the_post_thumbnail, Jetpack_Media_Meta_Extractor::extract, add_query_arg, jetpack_photon_url, get_the_title, count_users, get_post, get_avatar, get_permalink, get_the_title
	 * @return string
	 */
	protected function _generate_related_post_image( $blog_id, $post_id ) {
		$options = $this->get_options();

		if ( ! $options['show_thumbnails'] ) {
			return '';
		}

		$thumbnail_size = 48;
		$thumbnail_size = apply_filters( 'jetpack_relatedposts_filter_thumbnail_size', $thumbnail_size );

		// Try for featured image
		if ( has_post_thumbnail( $post_id ) ) {
			return get_the_post_thumbnail( $post_id, array( $thumbnail_size, $thumbnail_size ) );
		}

		// Try for first image on post
		if ( class_exists( 'Jetpack_Media_Meta_Extractor' ) ) {
			$images = Jetpack_Media_Meta_Extractor::extract( $blog_id, $post_id, Jetpack_Media_Meta_Extractor::IMAGES );
			if ( !empty( $images['image'] ) && is_array( $images['image'] ) ) {
				$img_url = $images['image'][0]['url'];
				$img_host = parse_url( $img_url, PHP_URL_HOST );

				if ( '.files.wordpress.com' == substr( $img_host, -20 ) ) {
					$img_url = add_query_arg( array( 'w' => $thumbnail_size, 'h' => $thumbnail_size, 'crop' => 1 ), $img_url );
				} elseif( function_exists( 'jetpack_photon_url' ) ) {
					$img_url = jetpack_photon_url( $img_url, array( 'lb' => "$thumbnail_size,$thumbnail_size" ) );
				} else {
					// Arg... no way to resize image using WordPress.com infrastructure!
				}

				return sprintf(
					'<img width="%u" src="%s" alt="%s" />',
					$thumbnail_size,
					$img_url,
					get_the_title( $post_id )
				);
			}
		}

		// Use gravatar as final fallback.
		$post = get_post( $post_id );
		return get_avatar( $post->post_author, $thumbnail_size );
	}

	/**
	 * Returns the string UTF-8 encoded
	 *
	 * @param string $text
	 * @return string
	 */
	protected function _to_utf8( $text ) {
		if ( $this->_convert_charset ) {
			return iconv( $this->_blog_charset, 'UTF-8', $text );
		} else {
			return $text;
		}
	}

	/**
	 * =============================================
	 * PROTECTED UTILITY FUNCTIONS EXTENDED BY WPCOM
	 * =============================================
	 */

	/**
	 * Workhorse method to return array of related posts matched by ElasticSearch.
	 *
	 * @param int $blog_id
	 * @param int $post_id
	 * @param int $size
	 * @param array $filters
	 * @uses wp_remote_post, is_wp_error, get_option, wp_remote_retrieve_body, get_post, add_query_arg, remove_query_arg, get_permalink, get_post_format
	 * @return array
	 */
	protected function _get_related_posts( $blog_id, $post_id, $size, array $filters ) {
		$hits = $this->_get_related_post_ids( $blog_id, $post_id, $size, $filters );

		$related_posts = array();
		foreach ( $hits as $i => $hit ) {
			$related_posts[] = $this->_get_related_post_data_for_post( $blog_id, $hit['id'], $i );
		}
		return $related_posts;
	}

	/**
	 * Get array of related posts matched by ElasticSearch.
	 *
	 * @param int $blog_id
	 * @param int $post_id
	 * @param int $size
	 * @param array $filters
	 * @uses wp_remote_post, is_wp_error, wp_remote_retrieve_body
	 * @return array
	 */
	protected function _get_related_post_ids( $blog_id, $post_id, $size, array $filters ) {
		$body = array(
			'size' => (int) $size,
		);

		if ( !empty( $filters ) )
			$body['filter'] = array( 'and' => $filters );

		$response = wp_remote_post(
			"https://public-api.wordpress.com/rest/v1/sites/$blog_id/posts/$post_id/related/",
			array(
				'timeout' => 10,
				'user-agent' => 'jetpack_related_posts',
				'sslverify' => true,
				'body' => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$results = json_decode( wp_remote_retrieve_body( $response ), true );
		$related_posts = array();
		if ( is_array( $results ) && !empty( $results['results']['hits'] ) ) {
			foreach( $results['results']['hits'] as $hit ) {
				$related_posts[] = array(
					'id' => $hit['fields']['post_id'],
				);
			}
		}
		return $related_posts;
	}

	/**
	 * Generates a context for the related content (second line in related post output).
	 * Order of importance:
	 *   - First category (Not 'Uncategorized')
	 *   - First post tag
	 *   - Number of comments
	 *
	 * @param int $blog_id
	 * @param int $post_id
	 * @uses get_the_category, get_the_terms, get_comments_number, number_format_i18n, __, _n
	 * @return string
	 */
	protected function _generate_related_post_context( $blog_id, $post_id ) {
		$categories = get_the_category( $post_id );
		if ( is_array( $categories ) ) {
			foreach ( $categories as $category ) {
				if ( 'uncategorized' != $category->slug && '' != trim( $category->name ) ) {
					return sprintf(
						_x( 'In "%s"', 'in {category/tag name}', 'jetpack' ),
						$category->name
					);
				}
			}
		}

		$tags = get_the_terms( $post_id, 'post_tag' );
		if ( is_array( $tags ) ) {
			foreach ( $tags as $tag ) {
				if ( '' != trim( $tag->name ) ) {
					return sprintf(
						_x( 'In "%s"', 'in {category/tag name}', 'jetpack' ),
						$tag->name
					);
				}
			}
		}

		$comment_count = get_comments_number( $post_id );
		if ( $comment_count > 0 ) {
			return sprintf(
				_n( 'With 1 comment', 'With %s comments', $comment_count, 'jetpack' ),
				number_format_i18n( $comment_count )
			);
		}

		return '';
	}

	/**
	 * Logs clicks for clickthrough analysis and related result tuning.
	 *
	 * @return null
	 */
	protected function _log_click( $post_id, $to_post_id, $link_position ) {

	}

	/**
	 * Determines if we should show config in admin dashboard to turn on related posts.
	 *
	 * @param int $blog_id
	 * @return bool
	 */
	protected function _show_config_in_admin( $blog_id ) {
		return true;
	}

	/**
	 * Determines if the current post is able to use related posts.
	 *
	 * @param int $blog_id
	 * @uses self::get_options, is_admin, is_single, wp_count_posts, get_post
	 * @return bool
	 */
	protected function _enabled_for_request( $blog_id ) {
		// Must have feature enabled
		$options = $this->get_options();
		if ( ! $options['enabled'] )
			return false;

		// Only run for frontend pages
		if ( is_admin() )
			return false;

		// Only run for standalone posts
		if ( ! is_single() )
			return false;

		// Don't run if blog is very sparse
		$post_count = wp_count_posts();
		if ( $post_count->publish < 10 )
			return false;

		return true;
	}

	/**
	 * Adds filters and enqueues assets.
	 *
	 * @uses self::_enqueue_assets, add_filter
	 * @return null
	 */
	protected function _action_frontend_init_page() {
		$this->_enqueue_assets( true, true );

		add_filter( 'the_content', array( $this, 'filter_add_target_to_dom' ), 40 );
	}

	/**
	 * Enqueues assets needed to do async loading of related posts.
	 *
	 * @uses wp_enqueue_script, wp_enqueue_style, plugins_url
	 * @return null
	 */
	protected function _enqueue_assets( $script, $style ) {
		if ( $script )
			wp_enqueue_script( 'jetpack_related-posts', plugins_url( 'related-posts.js', __FILE__ ), array( 'jquery' ), self::VERSION );
		if ( $style )
			wp_enqueue_style( 'jetpack_related-posts', plugins_url( 'related-posts.css', __FILE__ ), array(), self::VERSION );
	}
}

class Jetpack_RelatedPosts_Raw extends Jetpack_RelatedPosts {
	protected $_query_name;

	/**
	 * Allows callers of this class to tag each query with a unique name for tracking purposes.
	 *
	 * @param string $name
	 * @return Jetpack_RelatedPosts_Raw
	 */
	public function set_query_name( $name ) {
		$this->_query_name = (string) $name;
		return $this;
	}

	/**
	 * The raw related posts class can be used by other plugins or themes
	 * to get related content. This class wraps the existing RelatedPosts
	 * logic thus we never want to add anything to the DOM or do anything
	 * for event hooks. We will also not present any settings for this
	 * class and keep it enabled as calls to this class is done
	 * programmatically.
	 */
	public function action_admin_init() {}
	public function action_frontend_init() {}
	public function get_options() {
		return array(
			'enabled' => true,
		);
	}

	/**
	 * Workhorse method to return array of related posts ids matched by ElasticSearch.
	 *
	 * @param int $blog_id
	 * @param int $post_id
	 * @param int $size
	 * @param array $filters
	 * @uses wp_remote_post, is_wp_error, wp_remote_retrieve_body
	 * @return array
	 */
	protected function _get_related_posts( $blog_id, $post_id, $size, array $filters ) {
		$hits = $this->_get_related_post_ids( $blog_id, $post_id, $size, $filters );

		return $hits;
	}
}
