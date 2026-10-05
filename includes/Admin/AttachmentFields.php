<?php
/**
 * Folder field on the attachment edit screens.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Admin;

use CPH\FileBirb\Model\Assignment;
use CPH\FileBirb\Model\Folder;

defined( 'ABSPATH' ) || exit;

/**
 * `attachments[ID][cphfb_folder]` select, saved through Assignment::assign.
 */
final class AttachmentFields {

	/**
	 * Field key.
	 */
	public const FIELD = 'cphfb_folder';

	/**
	 * Singleton instance.
	 *
	 * @var AttachmentFields|null
	 */
	private static ?AttachmentFields $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return AttachmentFields
	 */
	public static function get_instance(): AttachmentFields {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_field' ), 10, 2 );
	}

	/**
	 * Add the Folder field.
	 *
	 * @param array    $fields Form fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array
	 */
	public function add_field( $fields, $post ) {
		if ( ! is_array( $fields ) || ! $post instanceof \WP_Post ) {
			return $fields;
		}

		$id      = (int) $post->ID;
		$current = Assignment::get_instance()->get_folder_id( $id );
		$nodes   = Folder::get_instance()->flat();

		$html = sprintf(
			'<div class="cphfb-attachment-folder" data-attachment-id="%1$s" data-folder-id="%2$s">',
			esc_attr( (string) $id ),
			esc_attr( (string) $current )
		);

		if ( current_user_can( 'edit_post', $id ) && current_user_can( 'upload_files' ) ) {
			$html .= sprintf( '<select name="attachments[%1$s][%2$s]" id="attachments-%1$s-%2$s">', esc_attr( (string) $id ), esc_attr( self::FIELD ) );
			$html .= sprintf( '<option value="0"%s>%s</option>', selected( 0, $current, false ), esc_html__( 'Uncategorized', 'cph-filebirb' ) );
			foreach ( $nodes as $node ) {
				$html .= sprintf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( (string) $node['id'] ),
					selected( $node['id'], $current, false ),
					esc_html( str_repeat( "\u{00A0}\u{00A0}\u{00A0}", (int) $node['depth'] ) . $node['name'] )
				);
			}
			$html .= '</select>';
		} else {
			$name = __( 'Uncategorized', 'cph-filebirb' );
			foreach ( $nodes as $node ) {
				if ( $node['id'] === $current ) {
					$name = $node['name'];
					break;
				}
			}
			$html .= '<input type="text" readonly value="' . esc_attr( $name ) . '" />';
		}
		$html .= '</div>';

		$fields[ self::FIELD ] = array(
			'label' => __( 'Folder', 'cph-filebirb' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	/**
	 * Save the Folder field. Core verifies the nonce for both save paths (post.php and save-attachment-compat).
	 *
	 * @param array $post       Post data.
	 * @param array $attachment Submitted attachment fields.
	 * @return array
	 */
	public function save_field( $post, $attachment ) {
		if ( ! is_array( $post ) || ! is_array( $attachment ) || ! isset( $attachment[ self::FIELD ] ) || ! is_scalar( $attachment[ self::FIELD ] ) ) {
			return $post;
		}
		$id     = (int) ( $post['ID'] ?? 0 );
		$folder = (int) $attachment[ self::FIELD ];
		if ( $id <= 0 || $folder < 0 || ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'upload_files' ) ) {
			return $post;
		}
		if ( Assignment::get_instance()->get_folder_id( $id ) !== $folder ) {
			Assignment::get_instance()->assign( $folder, array( $id ) );
		}
		return $post;
	}
}
