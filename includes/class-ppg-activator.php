<?php

defined( 'ABSPATH' ) || exit;

class PPG_Activator {
	/**
	 * Create the entitlement table and seed defaults.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = ppg_entitlements_table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			listing_id bigint(20) unsigned NOT NULL,
			plan_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			status varchar(24) NOT NULL DEFAULT 'pending',
			purchased_at datetime NULL,
			expires_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY user_listing_status (user_id, listing_id, status),
			KEY listing_id (listing_id),
			KEY plan_id (plan_id)
		) {$charset_collate};";

		dbDelta( $sql );

		if ( false === get_option( 'ppg_settings', false ) ) {
			add_option(
				'ppg_settings',
				array(
					'plan_id'         => 0,
					'button_text'     => 'Click here to pay and view this private gallery',
					'dashboard_label' => 'Paid Private Galleries',
				)
			);
		}

		update_option( 'ppg_db_version', PPG_VERSION );
	}
}

