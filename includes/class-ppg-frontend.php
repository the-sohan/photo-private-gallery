<?php

defined( 'ABSPATH' ) || exit;

/**
 * Front-end private gallery access and checkout hand-off.
 */
class PPG_Frontend {
	const PARAM_LISTING = 'ppg_listing';
	const PARAM_PLAN    = 'ppg_plan';
	const PARAM_EXPIRES = 'ppg_expires';
	const PARAM_SIG     = 'ppg_sig';

	/** @var self|null */
	private static $instance = null;

	/** @var bool */
	private $capturing_gallery = false;

	/** @var int */
	private $gallery_listing_id = 0;

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'directorist_single_item_template', array( $this, 'start_gallery_capture' ), 9, 2 );
		add_filter( 'directorist_single_item_template', array( $this, 'finish_gallery_capture' ), 11, 2 );
		add_filter( 'atbdp_login_redirection_page_url', array( $this, 'filter_login_redirect' ), 20 );
		add_filter( 'directorist_account_shortcode_atts', array( $this, 'filter_account_attributes' ), 20 );
		add_action( 'atbdp_after_checkout_form_start', array( $this, 'print_checkout_fields' ) );
	}

	/**
	 * Load the small lock-overlay stylesheet on Directorist single listings.
	 */
	public function enqueue_assets() {
		$post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';

		$dashboard_page_id = function_exists( 'get_directorist_option' ) ? absint( get_directorist_option( 'user_dashboard' ) ) : 0;

		if ( is_singular( $post_type ) || ( $dashboard_page_id && is_page( $dashboard_page_id ) ) ) {
			wp_enqueue_style( 'ppg-frontend', PPG_URL . 'assets/css/frontend.css', array(), PPG_VERSION );
		}
	}

	/**
	 * Start buffering immediately before Directorist Gallery echoes its template.
	 *
	 * @param string $template Template path.
	 * @param array  $field_data Directorist field data.
	 * @return string
	 */
	public function start_gallery_capture( $template, $field_data ) {
		if ( $this->capturing_gallery || ! $this->is_gallery_field( $field_data ) ) {
			return $template;
		}

		$listing_id = get_the_ID();

		if ( ! $this->should_lock_gallery( $listing_id, $field_data ) ) {
			return $template;
		}

		$this->capturing_gallery  = true;
		$this->gallery_listing_id = (int) $listing_id;
		ob_start();

		return $template;
	}

	/**
	 * Capture the Gallery add-on markup and wrap it with the lock UI.
	 *
	 * @param string $template Template path.
	 * @param array  $field_data Directorist field data.
	 * @return string
	 */
	public function finish_gallery_capture( $template, $field_data ) {
		if ( ! $this->capturing_gallery || ! $this->is_gallery_field( $field_data ) ) {
			return $template;
		}

		$gallery_html             = ob_get_clean();
		$listing_id              = $this->gallery_listing_id;
		$this->capturing_gallery  = false;
		$this->gallery_listing_id = 0;

		if ( '' === trim( $gallery_html ) ) {
			return $template;
		}

		$settings = ppg_get_settings();
		$cta_url  = $this->get_cta_url( $listing_id );

		if ( ! $cta_url ) {
			echo $gallery_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted markup emitted by Directorist Gallery.
			return $template;
		}

		printf(
			'<div class="ppg-locked-gallery" aria-label="%1$s"><div class="ppg-locked-gallery__content" aria-hidden="true">%2$s</div><div class="ppg-locked-gallery__overlay"><span class="ppg-locked-gallery__icon" aria-hidden="true">&#128274;</span><a class="ppg-locked-gallery__button" href="%3$s">%4$s</a></div></div>',
			esc_attr__( 'Locked private gallery', 'photo-private-gallery' ),
			$gallery_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted markup emitted by Directorist Gallery.
			esc_url( $cta_url ),
			esc_html( $settings['button_text'] )
		);

		return $template;
	}

	/**
	 * Send a successful Directorist login back to the signed checkout URL.
	 *
	 * @param string $redirect Existing redirect URL.
	 * @return string
	 */
	public function filter_login_redirect( $redirect ) {
		$checkout_url = $this->checkout_url_from_request();

		return $checkout_url ? $checkout_url : $redirect;
	}

	/**
	 * Send a successful Directorist registration back to checkout.
	 *
	 * @param array $atts Account shortcode attributes.
	 * @return array
	 */
	public function filter_account_attributes( $atts ) {
		$checkout_url = $this->checkout_url_from_request();

		if ( $checkout_url ) {
			$atts['signin_after_signup'] = 'yes';
			$atts['signup_redirect_url'] = $checkout_url;
		}

		return $atts;
	}

	/**
	 * Keep the verified listing payload in the checkout REST submission.
	 */
	public function print_checkout_fields() {
		$payload = $this->get_verified_payload();

		if ( ! $payload ) {
			return;
		}

		foreach ( $payload as $name => $value ) {
			printf(
				'<input type="hidden" name="%1$s" value="%2$s">',
				esc_attr( $name ),
				esc_attr( $value )
			);
		}
	}

	/**
	 * Determine whether this gallery should be visually locked.
	 *
	 * @param int   $listing_id Listing ID.
	 * @param array $field_data Directorist field data.
	 * @return bool
	 */
	private function should_lock_gallery( $listing_id, $field_data ) {
		if ( ! $listing_id || empty( $field_data['value'] ) || ! is_array( $field_data['value'] ) ) {
			return false;
		}

		$post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';

		if ( $post_type !== get_post_type( $listing_id ) || 'publish' !== get_post_status( $listing_id ) ) {
			return false;
		}

		return ! $this->user_has_access( get_current_user_id(), $listing_id );
	}

	/**
	 * Check the plugin's per-user, per-listing entitlement table.
	 *
	 * @param int $user_id User ID.
	 * @param int $listing_id Listing ID.
	 * @return bool
	 */
	public function user_has_access( $user_id, $listing_id ) {
		global $wpdb;

		if ( ! $user_id ) {
			return false;
		}

		$settings = ppg_get_settings();
		$table    = ppg_entitlements_table();
		$exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $table !== $exists ) {
			return false;
		}

		$sql = "SELECT id FROM {$table}
			WHERE user_id = %d
			AND listing_id = %d
			AND plan_id = %d
			AND status = 'active'
			AND ( expires_at IS NULL OR expires_at > UTC_TIMESTAMP() )
			LIMIT 1";

		return (bool) $wpdb->get_var(
			$wpdb->prepare( $sql, $user_id, $listing_id, absint( $settings['plan_id'] ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Build either a direct checkout URL or a Directorist login URL.
	 *
	 * @param int $listing_id Listing ID.
	 * @return string
	 */
	private function get_cta_url( $listing_id ) {
		$payload = $this->create_payload( $listing_id );

		if ( ! $payload ) {
			return '';
		}

		$checkout_url = $this->build_checkout_url( $payload );

		if ( is_user_logged_in() ) {
			return $checkout_url;
		}

		if ( class_exists( 'ATBDP_Permalink' ) ) {
			return ATBDP_Permalink::get_login_page_url( $payload );
		}

		return wp_login_url( $checkout_url );
	}

	/**
	 * Create an expiring signed payload for a listing and the selected plan.
	 *
	 * @param int $listing_id Listing ID.
	 * @return array<string, int|string>|false
	 */
	private function create_payload( $listing_id ) {
		$settings = ppg_get_settings();
		$plan_id  = absint( $settings['plan_id'] );

		if ( ! $this->listing_matches_plan( $listing_id, $plan_id ) ) {
			return false;
		}

		$expires = time() + ( 2 * HOUR_IN_SECONDS );
		$payload = array(
			self::PARAM_LISTING => absint( $listing_id ),
			self::PARAM_PLAN    => $plan_id,
			self::PARAM_EXPIRES => $expires,
		);
		$payload[ self::PARAM_SIG ] = $this->sign( $payload );

		return $payload;
	}

	/**
	 * Read and verify a payload from the current request.
	 *
	 * @return array<string, int|string>|false
	 */
	public function get_verified_payload( $request = null ) {
		$get_value = static function ( $key ) use ( $request ) {
			if ( is_object( $request ) && is_callable( array( $request, 'get_param' ) ) ) {
				return $request->get_param( $key );
			}

			return isset( $_REQUEST[ $key ] ) ? wp_unslash( $_REQUEST[ $key ] ) : null;
		};

		$payload = array(
			self::PARAM_LISTING => absint( $get_value( self::PARAM_LISTING ) ),
			self::PARAM_PLAN    => absint( $get_value( self::PARAM_PLAN ) ),
			self::PARAM_EXPIRES => absint( $get_value( self::PARAM_EXPIRES ) ),
		);
		$signature = sanitize_text_field( (string) $get_value( self::PARAM_SIG ) );

		if (
			empty( $payload[ self::PARAM_LISTING ] ) ||
			empty( $payload[ self::PARAM_PLAN ] ) ||
			empty( $payload[ self::PARAM_EXPIRES ] ) ||
			$payload[ self::PARAM_EXPIRES ] < time() ||
			! preg_match( '/^[a-f0-9]{64}$/', $signature ) ||
			! hash_equals( $this->sign( $payload ), $signature ) ||
			! $this->listing_matches_plan( $payload[ self::PARAM_LISTING ], $payload[ self::PARAM_PLAN ] )
		) {
			return false;
		}

		$payload[ self::PARAM_SIG ] = $signature;

		return $payload;
	}

	/**
	 * Convert a verified request into the checkout URL.
	 *
	 * @return string
	 */
	private function checkout_url_from_request() {
		$payload = $this->get_verified_payload();

		return $payload ? $this->build_checkout_url( $payload ) : '';
	}

	/**
	 * Build the selected plan's checkout URL with its directory slug.
	 *
	 * @param array<string, int|string> $payload Signed payload.
	 * @return string
	 */
	private function build_checkout_url( $payload ) {
		$plan = $this->get_plan( $payload[ self::PARAM_PLAN ] );
		$term = $plan ? get_term( (int) $plan->directory_type_id, $this->directory_taxonomy() ) : null;

		if ( ! $plan || ! $term || is_wp_error( $term ) ) {
			return '';
		}

		$args = array_merge(
			array(
				'plan_id'        => (int) $plan->id,
				'directory_type' => $term->slug,
			),
			$payload
		);

		if ( function_exists( 'directorist_get_checkout_page_url' ) ) {
			return directorist_get_checkout_page_url( 'plan', $args );
		}

		$args['checkout_type'] = 'plan';
		return add_query_arg( $args, home_url( '/checkout/' ) );
	}

	/**
	 * Verify that a plan is usable and belongs to the listing's directory type.
	 *
	 * @param int $listing_id Listing ID.
	 * @param int $plan_id Plan ID.
	 * @return bool
	 */
	private function listing_matches_plan( $listing_id, $plan_id ) {
		$settings  = ppg_get_settings();
		$post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';
		$plan      = $this->get_plan( $plan_id );

		if (
			! $listing_id ||
			! $plan_id ||
			$plan_id !== absint( $settings['plan_id'] ) ||
			$post_type !== get_post_type( $listing_id ) ||
			'publish' !== get_post_status( $listing_id ) ||
			! $plan ||
			empty( $plan->is_published ) ||
			'paid' !== $plan->fee_type
		) {
			return false;
		}

		return has_term( (int) $plan->directory_type_id, $this->directory_taxonomy(), $listing_id );
	}

	/**
	 * Fetch one Directorist Pricing Plan.
	 *
	 * @param int $plan_id Plan ID.
	 * @return stdClass|null
	 */
	private function get_plan( $plan_id ) {
		if ( ! function_exists( 'directorist_get_pricing_plan_by_id' ) ) {
			return null;
		}

		$plan = directorist_get_pricing_plan_by_id( absint( $plan_id ) );

		return is_object( $plan ) ? $plan : null;
	}

	/**
	 * Sign only canonical integer fields, never arbitrary request data.
	 *
	 * @param array<string, int|string> $payload Payload.
	 * @return string
	 */
	private function sign( $payload ) {
		$message = implode(
			'|',
			array(
				absint( $payload[ self::PARAM_LISTING ] ),
				absint( $payload[ self::PARAM_PLAN ] ),
				absint( $payload[ self::PARAM_EXPIRES ] ),
			)
		);

		return hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );
	}

	/**
	 * Whether the current Directorist item is the Gallery add-on field.
	 *
	 * @param mixed $field_data Field data.
	 * @return bool
	 */
	private function is_gallery_field( $field_data ) {
		return is_array( $field_data ) && isset( $field_data['widget_name'] ) && 'gallery' === $field_data['widget_name'];
	}

	/**
	 * Return the Directorist directory-type taxonomy name.
	 *
	 * @return string
	 */
	private function directory_taxonomy() {
		return defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types';
	}
}
