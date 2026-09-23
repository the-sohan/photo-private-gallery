<?php

defined( 'ABSPATH' ) || exit;

/**
 * Connect Directorist orders to per-listing private-gallery entitlements.
 */
class PPG_Entitlements {
	/** @var self|null */
	private static $instance = null;

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
		add_action( 'directorist_before_redirect_checkout', array( $this, 'capture_checkout_order' ), 20, 3 );
		add_action( 'directorist_after_order_update', array( $this, 'sync_order_status' ), 20, 2 );
		add_filter( 'atbdp_payment_receipt_button_link', array( $this, 'receipt_button_link' ), 20, 2 );
		add_filter( 'atbdp_payment_receipt_button_text', array( $this, 'receipt_button_text' ), 20, 2 );
		add_filter( 'directorist_dashboard_tabs', array( $this, 'add_dashboard_tab' ), 30 );
	}

	/**
	 * Save the verified listing-to-order relationship before payment redirect.
	 *
	 * @param object          $order_dto Directorist order DTO.
	 * @param string          $checkout_type Checkout type.
	 * @param WP_REST_Request $request Checkout request.
	 */
	public function capture_checkout_order( $order_dto, $checkout_type, $request ) {
		if ( 'plan' !== $checkout_type || ! is_object( $order_dto ) || ! is_callable( array( $order_dto, 'get_id' ) ) ) {
			return;
		}

		$payload = PPG_Frontend::instance()->get_verified_payload( $request );

		if ( ! $payload ) {
			return;
		}

		$order_id = (int) $order_dto->get_id();
		$user_id  = is_callable( array( $order_dto, 'get_user_id' ) ) ? (int) $order_dto->get_user_id() : 0;
		$plan_id  = is_callable( array( $order_dto, 'get_ref' ) ) ? (int) $order_dto->get_ref() : 0;

		if ( ! $order_id || ! $user_id || $plan_id !== (int) $payload[ PPG_Frontend::PARAM_PLAN ] ) {
			return;
		}

		$this->upsert_pending_entitlement(
			$order_id,
			$user_id,
			(int) $payload[ PPG_Frontend::PARAM_LISTING ],
			$plan_id
		);

		if ( is_callable( array( $order_dto, 'get_status' ) ) && 'paid' === $order_dto->get_status() ) {
			$this->activate_entitlement( $order_id );
		}
	}

	/**
	 * Mirror Directorist order status changes into gallery access.
	 *
	 * @param object $order_dto Updated Directorist order DTO.
	 * @param object $old_order Previous order record.
	 */
	public function sync_order_status( $order_dto, $old_order = null ) {
		if ( ! is_object( $order_dto ) || ! is_callable( array( $order_dto, 'get_id' ) ) || ! is_callable( array( $order_dto, 'get_status' ) ) ) {
			return;
		}

		$order_id = (int) $order_dto->get_id();
		$status   = sanitize_key( $order_dto->get_status() );

		if ( 'paid' === $status ) {
			$this->activate_entitlement( $order_id );
			return;
		}

		if ( in_array( $status, array( 'pending', 'unpaid', 'failed', 'cancelled', 'expired', 'refunded' ), true ) ) {
			$this->set_entitlement_status( $order_id, $status );
		}
	}

	/**
	 * Return to the purchased listing from its receipt.
	 *
	 * @param string $url Existing URL.
	 * @param int    $order_id Order ID.
	 * @return string
	 */
	public function receipt_button_link( $url, $order_id ) {
		$entitlement = $this->get_entitlement_by_order( $order_id );

		return $entitlement ? get_permalink( (int) $entitlement->listing_id ) : $url;
	}

	/**
	 * Customize the receipt button only for private-gallery purchases.
	 *
	 * @param string $text Existing label.
	 * @param int    $order_id Order ID.
	 * @return string
	 */
	public function receipt_button_text( $text, $order_id ) {
		return $this->get_entitlement_by_order( $order_id )
			? __( 'Return to private gallery', 'photo-private-gallery' )
			: $text;
	}

	/**
	 * Add a native Directorist dashboard tab for purchased galleries.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public function add_dashboard_tab( $tabs ) {
		if ( ! is_user_logged_in() ) {
			return $tabs;
		}

		$settings     = ppg_get_settings();
		$entitlements = $this->get_user_entitlements( get_current_user_id() );
		$new_tab      = array(
			'ppg_paid_galleries' => array(
				'title'   => sprintf( '%1$s (%2$d)', $settings['dashboard_label'], count( $entitlements ) ),
				'icon'    => 'las la-lock-open',
				'content' => $this->render_dashboard_content( $entitlements ),
			),
		);

		if ( empty( $tabs ) ) {
			return $new_tab;
		}

		$first = array_slice( $tabs, 0, 1, true );
		$rest  = array_slice( $tabs, 1, null, true );

		return $first + $new_tab + $rest;
	}

	/**
	 * Insert a pending entitlement without downgrading an active one.
	 */
	private function upsert_pending_entitlement( $order_id, $user_id, $listing_id, $plan_id ) {
		global $wpdb;

		$table    = ppg_entitlements_table();
		$existing = $this->get_entitlement_by_order( $order_id );
		$now      = current_time( 'mysql', true );

		if ( $existing ) {
			if ( 'active' === $existing->status ) {
				return;
			}

			$wpdb->update(
				$table,
				array(
					'user_id'   => $user_id,
					'listing_id' => $listing_id,
					'plan_id'    => $plan_id,
					'status'     => 'pending',
					'updated_at' => $now,
				),
				array( 'order_id' => $order_id ),
				array( '%d', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'user_id'   => $user_id,
				'listing_id' => $listing_id,
				'plan_id'    => $plan_id,
				'order_id'   => $order_id,
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Activate access for a paid order.
	 */
	private function activate_entitlement( $order_id ) {
		global $wpdb;

		$entitlement = $this->get_entitlement_by_order( $order_id );

		if ( ! $entitlement ) {
			return;
		}

		$now        = current_time( 'mysql', true );
		$expires_at = $this->calculate_expiration( $order_id, (int) $entitlement->plan_id );
		$data       = array(
			'status'       => 'active',
			'purchased_at' => $entitlement->purchased_at ? $entitlement->purchased_at : $now,
			'updated_at'   => $now,
			'expires_at'   => $expires_at,
		);

		$wpdb->update(
			ppg_entitlements_table(),
			$data,
			array( 'order_id' => absint( $order_id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Revoke or update access when an order leaves paid status.
	 */
	private function set_entitlement_status( $order_id, $status ) {
		global $wpdb;

		$wpdb->update(
			ppg_entitlements_table(),
			array(
				'status'     => sanitize_key( $status ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'order_id' => absint( $order_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Calculate access expiry from the immutable order-plan snapshot.
	 *
	 * @return string|null UTC MySQL datetime, or null for lifetime access.
	 */
	private function calculate_expiration( $order_id, $plan_id ) {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'directorist_plan_order_meta';
		$meta       = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT interval_type, interval_count, current_period_end FROM {$meta_table} WHERE order_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( $meta && ! empty( $meta->current_period_end ) ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( $meta->current_period_end . ' UTC' ) );
		}

		$interval_type  = $meta ? $meta->interval_type : null;
		$interval_count = $meta ? absint( $meta->interval_count ) : 0;

		if ( ! $interval_type && function_exists( 'directorist_get_pricing_plan_by_id' ) ) {
			$plan = directorist_get_pricing_plan_by_id( absint( $plan_id ) );
			if ( $plan ) {
				$interval_type  = $plan->interval_type;
				$interval_count = absint( $plan->interval_count );
			}
		}

		if ( ! $interval_type || 'lifetime' === $interval_type || ! $interval_count ) {
			return null;
		}

		if ( ! in_array( $interval_type, array( 'day', 'week', 'month', 'year' ), true ) ) {
			return null;
		}

		$expiration = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$expiration = $expiration->modify( sprintf( '+%d %s', $interval_count, $interval_type ) );

		return $expiration ? $expiration->format( 'Y-m-d H:i:s' ) : null;
	}

	/**
	 * Fetch one entitlement by Directorist order.
	 *
	 * @return object|null
	 */
	private function get_entitlement_by_order( $order_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . ppg_entitlements_table() . ' WHERE order_id = %d LIMIT 1',
				absint( $order_id )
			)
		);
	}

	/**
	 * Fetch active, non-expired private galleries for a user.
	 *
	 * @return array<int, object>
	 */
	private function get_user_entitlements( $user_id ) {
		global $wpdb;

		$table = ppg_entitlements_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE user_id = %d
				AND status = 'active'
				AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
				ORDER BY purchased_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			)
		);
	}

	/**
	 * Render escaped dashboard cards.
	 *
	 * @param array<int, object> $entitlements Entitlements.
	 * @return string
	 */
	private function render_dashboard_content( $entitlements ) {
		$settings = ppg_get_settings();
		ob_start();
		?>
		<div class="ppg-dashboard">
			<div class="ppg-dashboard__header">
				<h3><?php echo esc_html( $settings['dashboard_label'] ); ?></h3>
				<p><?php esc_html_e( 'Open any private gallery you have purchased.', 'photo-private-gallery' ); ?></p>
			</div>

			<?php if ( empty( $entitlements ) ) : ?>
				<div class="ppg-dashboard__empty">
					<?php esc_html_e( 'You have not purchased access to any private galleries yet.', 'photo-private-gallery' ); ?>
				</div>
			<?php else : ?>
				<div class="ppg-dashboard__grid">
					<?php foreach ( $entitlements as $entitlement ) : ?>
						<?php
						$listing_id = (int) $entitlement->listing_id;
						$thumbnail  = get_the_post_thumbnail_url( $listing_id, 'medium' );
						?>
						<article class="ppg-dashboard-card">
							<?php if ( $thumbnail ) : ?>
								<a class="ppg-dashboard-card__image" href="<?php echo esc_url( get_permalink( $listing_id ) ); ?>">
									<img src="<?php echo esc_url( $thumbnail ); ?>" alt="">
								</a>
							<?php endif; ?>
							<div class="ppg-dashboard-card__body">
								<h4><?php echo esc_html( get_the_title( $listing_id ) ); ?></h4>
								<?php if ( $entitlement->expires_at ) : ?>
									<p><?php echo esc_html( sprintf( __( 'Access until: %s', 'photo-private-gallery' ), wp_date( get_option( 'date_format' ), strtotime( $entitlement->expires_at . ' UTC' ) ) ) ); ?></p>
								<?php endif; ?>
								<a class="directorist-btn directorist-btn-primary" href="<?php echo esc_url( get_permalink( $listing_id ) ); ?>">
									<?php esc_html_e( 'View private gallery', 'photo-private-gallery' ); ?>
								</a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
