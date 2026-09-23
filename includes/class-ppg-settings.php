<?php

defined( 'ABSPATH' ) || exit;

class PPG_Settings {
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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PPG_FILE ), array( $this, 'settings_link' ) );
	}

	public function register_page() {
		add_submenu_page(
			'edit.php?post_type=at_biz_dir',
			__( 'Private Galleries', 'photo-private-gallery' ),
			__( 'Private Galleries', 'photo-private-gallery' ),
			'manage_options',
			'photo-private-gallery',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'ppg_settings_group',
			'ppg_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => ppg_get_settings(),
			)
		);

		add_settings_section(
			'ppg_general_section',
			__( 'Private gallery access', 'photo-private-gallery' ),
			array( $this, 'render_section' ),
			'photo-private-gallery'
		);

		add_settings_field(
			'ppg_plan_id',
			__( 'Private gallery plan', 'photo-private-gallery' ),
			array( $this, 'render_plan_field' ),
			'photo-private-gallery',
			'ppg_general_section'
		);

		add_settings_field(
			'ppg_button_text',
			__( 'Payment button text', 'photo-private-gallery' ),
			array( $this, 'render_text_field' ),
			'photo-private-gallery',
			'ppg_general_section',
			array(
				'key'         => 'button_text',
				'placeholder' => 'Click here to pay and view this private gallery',
			)
		);

		add_settings_field(
			'ppg_dashboard_label',
			__( 'Dashboard menu label', 'photo-private-gallery' ),
			array( $this, 'render_text_field' ),
			'photo-private-gallery',
			'ppg_general_section',
			array(
				'key'         => 'dashboard_label',
				'placeholder' => 'Paid Private Galleries',
			)
		);
	}

	public function render_section() {
		echo '<p>' . esc_html__( 'Choose the Directorist plan used for per-listing private gallery purchases and customize the visitor-facing labels.', 'photo-private-gallery' ) . '</p>';
	}

	public function render_plan_field() {
		global $wpdb;

		$settings = ppg_get_settings();
		$table    = $wpdb->prefix . 'directorist_plans';
		$plans    = array();

		if ( $this->table_exists( $table ) ) {
			$plans = $wpdb->get_results(
				"SELECT id, title, directory_type_id, price, fee_type, type, is_published
				FROM {$table}
				ORDER BY title ASC, id ASC"
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		?>
		<select name="ppg_settings[plan_id]" id="ppg_plan_id" class="regular-text">
			<option value="0"><?php esc_html_e( 'Select a plan', 'photo-private-gallery' ); ?></option>
			<?php foreach ( $plans as $plan ) : ?>
				<option value="<?php echo esc_attr( $plan->id ); ?>" <?php selected( (int) $settings['plan_id'], (int) $plan->id ); ?>>
					<?php
					echo esc_html(
						sprintf(
							'#%1$d — %2$s — %3$s — %4$s — directory %5$d%6$s',
							(int) $plan->id,
							$plan->title,
							'free' === $plan->fee_type ? __( 'Free', 'photo-private-gallery' ) : number_format_i18n( (float) $plan->price, 2 ),
							'pay_per_listing' === $plan->type ? __( 'Pay per listing', 'photo-private-gallery' ) : __( 'Package', 'photo-private-gallery' ),
							(int) $plan->directory_type_id,
							$plan->is_published ? '' : ' — ' . __( 'Draft', 'photo-private-gallery' )
						)
					);
					?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Use a paid, published Pay Per Listing plan so one customer can unlock multiple listings independently.', 'photo-private-gallery' ); ?>
		</p>
		<?php
	}

	public function render_text_field( $args ) {
		$settings    = ppg_get_settings();
		$key         = sanitize_key( $args['key'] );
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		?>
		<input
			type="text"
			class="regular-text"
			name="ppg_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( isset( $settings[ $key ] ) ? $settings[ $key ] : '' ); ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
		/>
		<?php
	}

	/**
	 * Sanitize and validate settings.
	 *
	 * @param mixed $input Raw settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$current = ppg_get_settings();
		$input   = is_array( $input ) ? $input : array();
		$plan_id = isset( $input['plan_id'] ) ? absint( $input['plan_id'] ) : 0;

		if ( $plan_id && ! $this->plan_exists( $plan_id ) ) {
			add_settings_error( 'ppg_settings', 'invalid_plan', __( 'The selected Directorist plan does not exist.', 'photo-private-gallery' ) );
			$plan_id = (int) $current['plan_id'];
		}

		return array(
			'plan_id'         => $plan_id,
			'button_text'     => isset( $input['button_text'] ) && '' !== trim( $input['button_text'] )
				? sanitize_text_field( $input['button_text'] )
				: $current['button_text'],
			'dashboard_label' => isset( $input['dashboard_label'] ) && '' !== trim( $input['dashboard_label'] )
				? sanitize_text_field( $input['dashboard_label'] )
				: $current['dashboard_label'],
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Private Galleries', 'photo-private-gallery' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'ppg_settings_group' );
				do_settings_sections( 'photo-private-gallery' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$missing = array();

		if ( ! defined( 'ATBDP_VERSION' ) && ! class_exists( 'Directorist_Base' ) ) {
			$missing[] = 'Directorist';
		}

		if ( ! function_exists( 'BD_Gallery' ) ) {
			$missing[] = 'Directorist Gallery';
		}

		if ( ! function_exists( 'directorist_get_pricing_plan_by_id' ) ) {
			$missing[] = 'Directorist Pricing Plans';
		}

		if ( ! $missing ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin names. */
					__( 'Photo Private Gallery is installed, but these required plugins are inactive or unavailable: %s.', 'photo-private-gallery' ),
					implode( ', ', $missing )
				)
			)
		);
	}

	public function settings_link( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'edit.php?post_type=at_biz_dir&page=photo-private-gallery' ) ) . '">' . esc_html__( 'Settings', 'photo-private-gallery' ) . '</a>'
		);

		return $links;
	}

	private function plan_exists( $plan_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'directorist_plans';

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $plan_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private function table_exists( $table ) {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
