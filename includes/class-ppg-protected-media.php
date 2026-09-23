<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keep private-gallery media behind an entitlement-aware streaming endpoint.
 */
class PPG_Protected_Media {
	const ATTACHMENT_LISTINGS_META = '_ppg_private_gallery_listings';
	const MIGRATION_VERSION        = 1;

	/** @var self|null */
	private static $instance = null;

	/** @var array<int, int> */
	private $pending_listing_ids = array();

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
		add_action( 'init', array( $this, 'maybe_migrate_existing_media' ), 30 );
		add_action( 'ppg_migrate_listing_media', array( $this, 'migrate_listing_media' ) );
		add_action( 'added_post_meta', array( $this, 'schedule_gallery_migration' ), 20, 4 );
		add_action( 'updated_post_meta', array( $this, 'schedule_gallery_migration' ), 20, 4 );
		add_action( 'shutdown', array( $this, 'migrate_pending_listing_media' ), 20 );
		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 5, 3 );
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 5, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'disable_private_srcset' ), 10, 5 );
		add_action( 'admin_post_ppg_private_media', array( $this, 'serve_media' ) );
		add_action( 'admin_post_nopriv_ppg_private_media', array( $this, 'serve_media' ) );
	}

	/**
	 * One-time migration for gallery media that predates this protection layer.
	 */
	public function maybe_migrate_existing_media() {
		if ( (int) get_option( 'ppg_media_migration_version', 0 ) >= self::MIGRATION_VERSION ) {
			return;
		}

		$post_type   = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';
		$listing_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_gallery_img',
			)
		);

		$success = true;
		foreach ( $listing_ids as $listing_id ) {
			$success = $this->migrate_listing_media( $listing_id ) && $success;
		}

		if ( $success ) {
			update_option( 'ppg_media_migration_version', self::MIGRATION_VERSION, false );
		}
	}

	/**
	 * Schedule migration after Directorist has finished background thumbnails.
	 */
	public function schedule_gallery_migration( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( '_gallery_img' !== $meta_key ) {
			return;
		}

		$this->pending_listing_ids[] = absint( $object_id );

		$timestamp = time() + MINUTE_IN_SECONDS;
		if ( ! wp_next_scheduled( 'ppg_migrate_listing_media', array( (int) $object_id ) ) ) {
			wp_schedule_single_event( $timestamp, 'ppg_migrate_listing_media', array( (int) $object_id ) );
		}
	}

	/**
	 * Protect newly assigned gallery images before the saving request finishes.
	 * The scheduled event remains as a fallback for late-generated derivatives.
	 */
	public function migrate_pending_listing_media() {
		foreach ( array_unique( array_filter( $this->pending_listing_ids ) ) as $listing_id ) {
			$this->migrate_listing_media( $listing_id );
		}
	}

	/**
	 * Move all files for the listing's gallery attachments into the denied folder.
	 *
	 * @param int $listing_id Listing ID.
	 * @return bool
	 */
	public function migrate_listing_media( $listing_id ) {
		$attachment_ids = array_filter( wp_parse_id_list( (array) get_post_meta( $listing_id, '_gallery_img', true ) ) );
		$success        = true;

		foreach ( $attachment_ids as $attachment_id ) {
			$success = $this->migrate_attachment( $attachment_id, $listing_id ) && $success;
		}

		return $success;
	}

	/**
	 * Return a protected endpoint URL instead of a direct uploads URL.
	 */
	public function filter_attachment_url( $url, $attachment_id ) {
		if ( ! $this->is_private_attachment( $attachment_id ) ) {
			return $url;
		}

		return $this->media_url( $attachment_id, 'full' );
	}

	/**
	 * Supply protected URLs for every requested image size.
	 */
	public function filter_image_downsize( $downsize, $attachment_id, $size ) {
		if ( ! $this->is_private_attachment( $attachment_id ) ) {
			return $downsize;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			return $downsize;
		}

		$size_key   = $this->size_key( $size );
		$dimensions = $this->size_dimensions( $metadata, $size_key );

		return array(
			$this->media_url( $attachment_id, $size_key ),
			$dimensions[0],
			$dimensions[1],
			'full' !== $size_key,
		);
	}

	/**
	 * Prevent direct private-upload URLs from leaking through srcset.
	 */
	public function disable_private_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		return $this->is_private_attachment( $attachment_id ) ? false : $sources;
	}

	/**
	 * Stream the requested file only to an entitled user; everyone else gets a degraded preview.
	 */
	public function serve_media() {
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;
		$size          = isset( $_GET['ppg_size'] ) ? sanitize_key( wp_unslash( $_GET['ppg_size'] ) ) : 'full';
		$listings      = $this->validated_attachment_listings( $attachment_id );

		if ( ! $attachment_id || empty( $listings ) ) {
			status_header( 404 );
			exit;
		}

		$allowed = current_user_can( 'manage_options' );
		if ( ! $allowed && is_user_logged_in() ) {
			foreach ( $listings as $listing_id ) {
				if ( PPG_Frontend::instance()->user_has_access( get_current_user_id(), $listing_id ) ) {
					$allowed = true;
					break;
				}
			}
		}

		$file = $allowed
			? $this->attachment_file_for_size( $attachment_id, $size )
			: $this->preview_file( $attachment_id );

		if ( ! $file || ! is_readable( $file ) || ! $this->path_is_in_uploads( $file ) ) {
			status_header( 404 );
			exit;
		}

		$mime = wp_check_filetype( $file );
		header( 'Content-Type: ' . ( $mime['type'] ? $mime['type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( wp_basename( $file ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Pragma: no-cache' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Move one attachment and all registered derivatives.
	 */
	private function migrate_attachment( $attachment_id, $listing_id ) {
		$uploads  = wp_get_upload_dir();
		$relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! $relative || ! is_array( $metadata ) ) {
			return false;
		}

		if ( 0 === strpos( $relative, 'ppg-private/' ) ) {
			$this->mark_attachment( $attachment_id, $listing_id );
			return true;
		}

		$old_directory    = dirname( $relative );
		$target_directory = 'ppg-private/' . ltrim( $old_directory, './' );
		$target_path      = trailingslashit( $uploads['basedir'] ) . $target_directory;

		if ( ! wp_mkdir_p( $target_path ) ) {
			return false;
		}

		$files = array( wp_basename( $relative ) );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $image_size ) {
				if ( ! empty( $image_size['file'] ) ) {
					$files[] = wp_basename( $image_size['file'] );
				}
			}
		}
		if ( ! empty( $metadata['original_image'] ) ) {
			$files[] = wp_basename( $metadata['original_image'] );
		}

		// Some themes create image derivatives without registering them in the
		// attachment metadata. Move those files too so an old direct URL cannot
		// bypass the protected endpoint.
		$source_directory = trailingslashit( $uploads['basedir'] ) . trailingslashit( $old_directory );
		$path_info        = pathinfo( wp_basename( $relative ) );
		$extra_files      = glob(
			$source_directory . $path_info['filename'] . '-*.' . $path_info['extension'],
			GLOB_NOSORT
		);
		foreach ( (array) $extra_files as $extra_file ) {
			$files[] = wp_basename( $extra_file );
		}

		$files = array_unique( $files );
		foreach ( $files as $filename ) {
			$source = $source_directory . $filename;
			$target = trailingslashit( $target_path ) . $filename;

			if ( file_exists( $target ) || ! file_exists( $source ) ) {
				continue;
			}

			if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				// Another request may have completed the same one-time migration
				// between the existence check and rename.
				if ( file_exists( $target ) ) {
					continue;
				}
				if ( ! file_exists( $source ) || ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return false;
				}
				@unlink( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$new_relative    = trailingslashit( $target_directory ) . wp_basename( $relative );
		$metadata['file'] = $new_relative;

		update_post_meta( $attachment_id, '_wp_attached_file', $new_relative );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		wp_update_post(
			array(
				'ID'   => $attachment_id,
				'guid' => trailingslashit( $uploads['baseurl'] ) . $new_relative,
			)
		);
		$this->mark_attachment( $attachment_id, $listing_id );

		return file_exists( trailingslashit( $uploads['basedir'] ) . $new_relative );
	}

	/**
	 * Associate a protected attachment with its listing.
	 */
	private function mark_attachment( $attachment_id, $listing_id ) {
		$listings   = (array) get_post_meta( $attachment_id, self::ATTACHMENT_LISTINGS_META, true );
		$listings[] = absint( $listing_id );
		update_post_meta( $attachment_id, self::ATTACHMENT_LISTINGS_META, array_values( array_unique( array_filter( $listings ) ) ) );
	}

	/**
	 * Confirm both the marker and the current listing relationship.
	 *
	 * @return array<int, int>
	 */
	private function validated_attachment_listings( $attachment_id ) {
		$listings = array_filter( wp_parse_id_list( (array) get_post_meta( $attachment_id, self::ATTACHMENT_LISTINGS_META, true ) ) );
		$valid    = array();

		foreach ( $listings as $listing_id ) {
			$gallery = array_filter( wp_parse_id_list( (array) get_post_meta( $listing_id, '_gallery_img', true ) ) );
			if ( in_array( (int) $attachment_id, $gallery, true ) ) {
				$valid[] = (int) $listing_id;
			}
		}

		return $valid;
	}

	private function is_private_attachment( $attachment_id ) {
		return (bool) get_post_meta( $attachment_id, self::ATTACHMENT_LISTINGS_META, true );
	}

	private function media_url( $attachment_id, $size ) {
		return add_query_arg(
			array(
				'action'        => 'ppg_private_media',
				'attachment_id' => absint( $attachment_id ),
				'ppg_size'      => sanitize_key( $size ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	private function size_key( $size ) {
		if ( is_array( $size ) && 2 === count( $size ) ) {
			return 'custom-' . absint( $size[0] ) . 'x' . absint( $size[1] );
		}

		return sanitize_key( (string) $size ) ?: 'full';
	}

	private function size_dimensions( $metadata, $size_key ) {
		if ( 'full' !== $size_key && isset( $metadata['sizes'][ $size_key ] ) ) {
			return array( (int) $metadata['sizes'][ $size_key ]['width'], (int) $metadata['sizes'][ $size_key ]['height'] );
		}

		if ( preg_match( '/^custom-(\d+)x(\d+)$/', $size_key, $matches ) ) {
			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $image_size ) {
				if ( (int) $image_size['width'] === (int) $matches[1] && (int) $image_size['height'] === (int) $matches[2] ) {
					return array( (int) $image_size['width'], (int) $image_size['height'] );
				}
			}
		}

		return array( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ) );
	}

	private function attachment_file_for_size( $attachment_id, $size_key ) {
		$full     = get_attached_file( $attachment_id, true );
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! $full || ! is_array( $metadata ) || 'full' === $size_key ) {
			return $full;
		}

		$filename = '';
		if ( isset( $metadata['sizes'][ $size_key ]['file'] ) ) {
			$filename = $metadata['sizes'][ $size_key ]['file'];
		} elseif ( preg_match( '/^custom-(\d+)x(\d+)$/', $size_key, $matches ) ) {
			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $image_size ) {
				if ( (int) $image_size['width'] === (int) $matches[1] && (int) $image_size['height'] === (int) $matches[2] ) {
					$filename = $image_size['file'];
					break;
				}
			}
		}

		return $filename ? trailingslashit( dirname( $full ) ) . wp_basename( $filename ) : $full;
	}

	private function preview_file( $attachment_id ) {
		$source  = get_attached_file( $attachment_id, true );
		$preview = trailingslashit( dirname( $source ) ) . '.ppg-preview-' . absint( $attachment_id ) . '.jpg';

		if ( file_exists( $preview ) ) {
			return $preview;
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return '';
		}

		$editor->resize( 48, 48, false );
		$editor->set_quality( 25 );
		$result = $editor->save( $preview, 'image/jpeg' );

		return is_wp_error( $result ) ? '' : $preview;
	}

	private function path_is_in_uploads( $path ) {
		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$file    = realpath( $path );

		return $base && $file && 0 === strpos( $file, trailingslashit( $base ) );
	}
}
