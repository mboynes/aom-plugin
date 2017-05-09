<?php
/**
 * Plugin Name:     Alliance of Magicians
 * Author:          Tony Wonder, Rollo
 * Version:         0.1.0
 *
 * @package         AOM_Plugin
 */

define( 'AOM_PLUGIN_DIR', __DIR__ );

/**
 * Main plugin class.
 */
class Alliance_Of_Magicians {

	/**
	 * Setup hooks for the plugin. This occurs on after_setup_theme by default.
	 */
	public static function setup_hooks() {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_shortcode( 'magician', [ __CLASS__, 'shortcode' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest_route' ] );
		add_filter( 'single_template', [ __CLASS__, 'template' ] );
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_aom_featured', [ __CLASS__, 'save_settings' ] );
		add_filter( 'user_has_cap', [ __CLASS__, 'custom_capabilities' ] );
		add_filter( 'the_content', [ __CLASS__, 'illusions_dangit' ] );
	}

	/**
	 * Add the magician post type.
	 */
	public static function register_post_type() {
		register_post_type( 'magician', [
			'public' => true,
			'label' => __( 'Magicians', 'aom-plugin' ),
			'menu_icon' => 'dashicons-businessman',
			'supports' => [ 'title', 'editor', 'thumbnail' ],
			'rewrite' => [
				'slug' => 'alliance-approved-magician',
			],
		] );
	}

	/**
	 * Do we have any magicians?
	 *
	 * @return bool
	 */
	public static function have_magicians() {
		$query = new \WP_Query( [
			'post_type' => 'magician',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'orderby' => 'ID',
		] );

		return $query->have_posts();
	}

	/**
	 * Add a magician shortcode.
	 *
	 * @param  array $atts {
	 *     Shortcode attributes.
	 *     @type int $id Optional. ID of the magician to render. If absent, the
	 *                   currently-featured magician will be rendered.
	 * }
	 * @return string Rendered shortcode content.
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( [
			'id' => get_option( 'featured_magician', 0 ),
		], $atts );

		$magician = self::get_valid_magician_by_id( $atts['id'] );

		if ( $magician ) {
			/**
			 * Filter the magician shortcode output to allow modifications.
			 *
			 * @param string   $escaped_output Rendered HTML from the shortcode.
			 * @param \WP_Post $magician       The Magician being rendered in the
			 *                                 shortcode.
			 * @param array    $atts           Shortcode attributes.
			 */
			return apply_filters(
				'aom_magician_shortcode',
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( get_permalink( $magician ) ),
					esc_html( get_the_title( $magician ) )
				),
				$magician,
				$atts
			);
		}
	}

	/**
	 * Get a random magician.
	 *
	 * @return \WP_Post|false Post object on success, false on failure.
	 */
	public static function get_random_magician() {
		$magicians = get_posts( [
			'post_type' => 'magician',
			'post_status' => 'publish',
			'orderby' => 'rand',
			'posts_per_page' => 1,
			'suppress_filters' => false,
			'ignore_sticky_posts' => true,
		] );
		if ( ! empty( $magicians ) ) {
			return reset( $magicians );
		}
		return false;
	}

	/**
	 * Get the featured magician.
	 *
	 * @return \WP_Post|false Post object on success, false on failure.
	 */
	public static function get_featured_magician() {
		$magician_id = get_option( 'featured_magician', 0 );
		return self::get_valid_magician_by_id( $magician_id );
	}

	/**
	 * Get a magician by ID but only if it's a valid, published magician.
	 *
	 * @return \WP_Post|false Post object on success, false on failure.
	 */
	public static function get_valid_magician_by_id( $magician_id ) {
		if ( $magician_id ) {
			$magician = get_post( $magician_id );
			if (
				$magician
				&& 'magician' === $magician->post_type
				&& 'publish' === $magician->post_status
			) {
				return $magician;
			}
		}
		return false;
	}

	/**
	 * Add a custom REST route.
	 */
	public static function rest_route() {
		register_rest_route( 'magicians/v1', '/random', [
			'methods' => \WP_REST_Server::READABLE,
			'callback' => function( $request ) {
				$magician = self::get_random_magician();
				$data = [];
				if ( ! empty( $magician ) ) {
					$data = [
						'name' => $magician->post_title,
						'url' => get_permalink( $magician ),
						'photo' => get_the_post_thumbnail( $magician, 'medium' ),
					];
				}

				// Send the response.
				return rest_ensure_response( $data );
			},
		] );
	}

	/**
	 * Add template support to the plugin. The plugin's template will be loaded
	 * if single.php, singular.php, or index.php would otherwise be loaded. A
	 * theme can override this with single-magician.php or
	 * single-magician-{$slug}.php (or by unhooking this filter, of course).
	 *
	 * @param  string $template The template to be loaded.
	 * @return string           Template to be loaded.
	 */
	public static function template( $template ) {
		if (
			is_singular( 'magician' )
			&& ( '' === $template || 'single.php' === substr( $template, -10 ) )
		) {
			return AOM_PLUGIN_DIR . '/templates/single-magician.php';
		}

		return $template;
	}

	/**
	 * Add a settings screen.
	 */
	public static function admin_menu() {
		add_options_page(
			__( 'Featured Magician', 'aom-plugin' ),
			__( 'Featured Magician', 'aom-plugin' ),
			'manage_magicians',
			'featured-magician',
			[ __CLASS__, 'settings_page' ]
		);
	}

	/**
	 * Ensure that if a user can manage_options, they can manage magicians.
	 *
	 * @param array $caps User capabilities.
	 */
	public static function custom_capabilities( $caps ) {
	    if ( ! empty( $caps['manage_options'] ) ) {
	        $caps['manage_magicians'] = true;
	    }
	    return $caps;
	}

	/**
	 * Render the settings screen.
	 */
	public static function settings_page() {
		if ( ! current_user_can( 'manage_magicians' ) ) {
			wp_die( esc_html__( 'Nice trick!', 'aom-plugin' ) );
		}
		?>

		<div class="wrap">
			<h1><?php esc_html_e( 'Featured Magician', 'aom-plugin' ); ?></h1>

			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div id="message" class="updated notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings Updated', 'aom-plugin' ) ?></p></div>
			<?php endif ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ) ?>" method="POST">
				<input type="hidden" name="action" value="aom_featured" />
				<p><label for="magician_id"><?php esc_html_e( 'Select a Magician to Feature', 'aom-plugin' ); ?></label></p>

				<?php
				self::dropdown_magicians();
				wp_nonce_field( 'aom-featured-magician-nonce', 'aom_nonce' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Output a dropdowns of magicians.
	 */
	public static function dropdown_magicians() {
		$magicians = get_posts( [
			'post_type' => 'magician',
			'nopaging' => true,
			'ignore_sticky_posts' => true,
			'suppress_filters' => false,
			'orderby' => 'title',
			'order' => 'ASC',
		] );
		$selected = get_option( 'featured_magician' );
		if ( ! empty( $magicians ) ) :
			?>
			<select name="magician" id="magician_id">
				<option value=""></option>
				<?php foreach ( $magicians as $magician ) : ?>
					<option
						value="<?php echo intval( $magician->ID ) ?>"
						<?php selected( $magician->ID, $selected ) ?>
					>
						<?php echo esc_html( $magician->post_title ) ?>
 					</option>
				<?php endforeach; ?>
			</select>
			<?php
		endif;
	}

	/**
	 * Save settings data and redirect back to the settings screen.
	 */
	public static function save_settings() {
		if (
			empty( $_POST['aom_nonce'] )
			|| empty( $_POST['magician'] )
			|| ! wp_verify_nonce( $_POST['aom_nonce'], 'aom-featured-magician-nonce' )
		) {
			wp_die( esc_html__( 'Could not verify the request, please try again.', 'aom-plugin' ) );
		}

		$sanitized_id = absint( $_POST['magician'] );
		update_option( 'featured_magician', $sanitized_id );

		wp_safe_redirect( admin_url( 'options-general.php?page=featured-magician&saved=1' ) );
	}

	/**
	 * Ensure that illusions aren't referenced incorrectly.
	 *
	 * @param  string $content Content being filtered.
	 * @return string
	 */
	public static function illusions_dangit( $content ) {
		return preg_replace_callback(
			'/\btrick(?=s|\b)(?!s? for money)/i',
			function( $matches ) {
				return ctype_lower($matches[0][0]) ? 'illusion' : 'Illusion';
			},
			$content
		);
	}
}
add_action( 'after_setup_theme', [ 'Alliance_Of_Magicians', 'setup_hooks' ] );
