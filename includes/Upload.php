<?php
/**
 * Routes new uploads into folders and keeps assignments tidy on delete/edit.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird;

use CPH\FileBird\Model\Assignment;
use CPH\FileBird\Model\Folder;
use CPH\FileBird\Model\UserSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Target folder order: `X-CPHFB-Folder` header, `cphfb_folder` request var,
 * legacy `fbv` request var (numeric or `12/Sub/Sub2` path form), user default.
 */
final class Upload {

	/**
	 * Request header carrying the target folder.
	 */
	public const HEADER = 'HTTP_X_CPHFB_FOLDER';

	/**
	 * Request var carrying the target folder.
	 */
	public const REQUEST_VAR = 'cphfb_folder';

	/**
	 * Singleton instance.
	 *
	 * @var Upload|null
	 */
	private static ?Upload $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Upload
	 */
	public static function get_instance(): Upload {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'add_attachment', array( $this, 'on_add_attachment' ) );
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
		add_filter( 'wp_edited_image_metadata', array( $this, 'on_edited_image' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
		add_action( 'attachment_updated', array( $this, 'on_attachment_updated' ), 10, 3 );
		add_action( 'trashed_post', array( $this, 'on_trash_toggle' ) );
		add_action( 'untrashed_post', array( $this, 'on_trash_toggle' ) );
		add_action( 'pre-upload-ui', array( $this, 'render_placeholder' ) );
	}

	/**
	 * Assign a new attachment to its resolved folder.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_add_attachment( $attachment_id ): void {
		$folder = $this->resolve_folder( get_current_user_id() );
		if ( $folder > 0 ) {
			Assignment::get_instance()->assign( $folder, array( (int) $attachment_id ) );
		}
	}

	/**
	 * Resolve the target folder for an upload by the given user. 0 = none.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function resolve_folder( int $user_id ): int {
		// Request-supplied targets need a user who may upload; core has already verified the upload nonce.
		if ( user_can( $user_id, 'upload_files' ) ) {
			$from_request = $this->folder_from_request();
			if ( null !== $from_request ) {
				return $from_request;
			}
		}

		// UserSettings::get() already applies the `user_default_folder` filter pair.
		return $this->existing( (int) UserSettings::get_instance()->get( 'default_upload_folder', null, $user_id ) );
	}

	/**
	 * Folder from header / request vars, or null when none was sent.
	 *
	 * @return int|null
	 */
	private function folder_from_request(): ?int {
		// phpcs:disable WordPress.Security.NonceVerification -- upload endpoints verify their own nonces.
		if ( isset( $_SERVER[ self::HEADER ] ) && '' !== trim( (string) $_SERVER[ self::HEADER ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return $this->existing( (int) sanitize_text_field( wp_unslash( $_SERVER[ self::HEADER ] ) ) );
		}
		if ( isset( $_REQUEST[ self::REQUEST_VAR ] ) && is_scalar( $_REQUEST[ self::REQUEST_VAR ] ) && '' !== $_REQUEST[ self::REQUEST_VAR ] ) {
			return $this->existing( (int) $_REQUEST[ self::REQUEST_VAR ] );
		}
		if ( isset( $_REQUEST[ Query::VAR ] ) && is_scalar( $_REQUEST[ Query::VAR ] ) && '' !== $_REQUEST[ Query::VAR ] ) {
			return $this->legacy( sanitize_text_field( wp_unslash( (string) $_REQUEST[ Query::VAR ] ) ) );
		}
		// phpcs:enable
		return null;
	}

	/**
	 * Resolve the legacy `fbv` value: `12`, or `12/Sub/Sub2` (auto-creating `Sub/Sub2` under 12).
	 * A negative first segment means the root.
	 *
	 * @param string $raw Raw value.
	 * @return int
	 */
	public function legacy( string $raw ): int {
		$raw = trim( $raw );
		if ( preg_match( '/^-?\d+$/', $raw ) ) {
			return $this->existing( (int) $raw );
		}

		$parts  = array_values( array_filter( array_map( 'trim', explode( '/', trim( $raw, '/' ) ) ), 'strlen' ) );
		$parent = (int) array_shift( $parts );
		if ( $parent < 0 ) {
			$parent = 0;
		} elseif ( $parent > 0 && ! Folder::get_instance()->exists( $parent ) ) {
			return 0;
		}

		if ( ! Hooks::filter( 'auto_create_folders', true ) ) {
			return $parent;
		}

		foreach ( $parts as $name ) {
			$id = Folder::get_instance()->get_or_create( $name, $parent );
			if ( is_wp_error( $id ) ) {
				return 0;
			}
			$parent = $id;
		}
		return $parent;
	}

	/**
	 * Drop assignments of a deleted attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_delete_attachment( $attachment_id ): void {
		Assignment::get_instance()->delete_for_attachment( (int) $attachment_id );
	}

	/**
	 * An image edited into a new attachment inherits the original's folder.
	 *
	 * @param array $meta              New attachment metadata.
	 * @param int   $new_attachment_id New attachment ID.
	 * @param int   $attachment_id     Original attachment ID.
	 * @return array
	 */
	public function on_edited_image( $meta, $new_attachment_id, $attachment_id ) {
		$folder = Assignment::get_instance()->get_folder_id( (int) $attachment_id );
		if ( $folder > 0 && (int) $new_attachment_id !== (int) $attachment_id ) {
			Assignment::get_instance()->assign( $folder, array( (int) $new_attachment_id ) );
		}
		return $meta;
	}

	/**
	 * Counts depend on post status; drop them when an attachment's status changes.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function on_status_change( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
			Assignment::get_instance()->invalidate_counts();
		}
	}

	/**
	 * Attachments skip status transitions in wp_insert_post(); compare before/after instead.
	 *
	 * @param int      $post_id     Attachment ID.
	 * @param \WP_Post $post_after  Attachment after the update.
	 * @param \WP_Post $post_before Attachment before the update.
	 * @return void
	 */
	public function on_attachment_updated( $post_id, $post_after, $post_before ): void {
		if ( $post_after instanceof \WP_Post && $post_before instanceof \WP_Post && $post_after->post_status !== $post_before->post_status ) {
			Assignment::get_instance()->invalidate_counts();
		}
	}

	/**
	 * Drop counts when an attachment is trashed or restored (media trash bypasses transitions).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_trash_toggle( $post_id ): void {
		if ( 'attachment' === get_post_type( (int) $post_id ) ) {
			Assignment::get_instance()->invalidate_counts();
		}
	}

	/**
	 * Mount point for the upload folder picker.
	 *
	 * @return void
	 */
	public function render_placeholder(): void {
		echo '<div class="cphfb-upload-folder" hidden></div>';
	}

	/**
	 * The ID when it names an existing folder, else 0.
	 *
	 * @param int $id Folder ID.
	 * @return int
	 */
	private function existing( int $id ): int {
		return $id > 0 && Folder::get_instance()->exists( $id ) ? $id : 0;
	}
}
