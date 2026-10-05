<?php
/**
 * List table and attachment field tests.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Tests;

use CPH\FileBird\Admin\AttachmentFields;
use CPH\FileBird\Admin\ListTable;
use CPH\FileBird\Query;

/**
 * Dropdown, column, bulk move, attachment edit field.
 */
class ListTableTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ListTable::get_instance()->reset();
		Query::get_instance()->flush_cache();
		unset( $_GET['fbv'] );
	}

	public function tear_down(): void {
		unset( $_GET['fbv'], $_REQUEST['cphfb_bulk_folder'], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	public function test_filter_dropdown(): void {
		$ids    = $this->attachments( 3 );
		$parent = $this->make( 'Pa<r>ent' );
		$child  = $this->make( 'Child', $parent );
		$this->assignments()->assign( $child, array( $ids[0] ) );
		$_GET['fbv'] = (string) $child;

		ob_start();
		do_action( 'restrict_manage_posts', 'attachment', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<select name="fbv" id="filter-by-fbv" class="attachment-filters">', $html );
		$this->assertStringContainsString( '<option value="-1">All Folders (3)</option>', $html );
		$this->assertStringContainsString( '<option value="0">Uncategorized (2)</option>', $html );
		$this->assertStringContainsString( "<option value=\"{$child}\" selected='selected'>\u{00A0}\u{00A0}\u{00A0}Child (1)</option>", $html );
		$this->assertStringNotContainsString( 'Pa<r>ent', $html );
		$this->assertStringContainsString( 'name="cphfb_bulk_folder"', $html );

		ob_start();
		do_action( 'restrict_manage_posts', 'post', 'top' );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_column(): void {
		$ids = $this->attachments( 2 );
		$a   = $this->make( 'Alpha & Co' );
		$this->assignments()->assign( $a, array( $ids[0] ) );

		$cols = apply_filters( 'manage_media_columns', array() );
		$this->assertSame( 'Folder', $cols[ ListTable::COLUMN ] );

		ob_start();
		do_action( 'manage_media_custom_column', ListTable::COLUMN, $ids[0] );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'upload.php?mode=list&#038;fbv=' . $a, $html );
		$this->assertStringContainsString( '>Alpha &amp; Co</a>', $html );

		ob_start();
		do_action( 'manage_media_custom_column', ListTable::COLUMN, $ids[1] );
		$this->assertSame( 'Uncategorized', ob_get_clean() );
	}

	public function test_bulk_move(): void {
		$ids = $this->attachments( 2 );
		$a   = $this->make( 'A' );

		$this->assertArrayHasKey( ListTable::BULK_ACTION, apply_filters( 'bulk_actions-upload', array() ) );

		$_REQUEST['_wpnonce']          = wp_create_nonce( 'bulk-media' );
		$_REQUEST['cphfb_bulk_folder'] = (string) $a;
		$loc                           = apply_filters( 'handle_bulk_actions-upload', 'upload.php', ListTable::BULK_ACTION, $ids );
		$this->assertStringContainsString( 'cphfb_moved=2', $loc );
		$this->assertSame( $ids, $this->assignments()->attachment_ids_in( $a ) );

		$_REQUEST['cphfb_bulk_folder'] = '0';
		apply_filters( 'handle_bulk_actions-upload', 'upload.php', ListTable::BULK_ACTION, $ids );
		$this->assertSame( array(), $this->assignments()->attachment_ids_in( $a ) );

		$this->assertSame( 'upload.php', apply_filters( 'handle_bulk_actions-upload', 'upload.php', 'other', $ids ) );
	}

	public function test_attachment_field_and_save(): void {
		$ids  = $this->attachments( 1 );
		$a    = $this->make( 'A' );
		$post = get_post( $ids[0] );

		$fields = apply_filters( 'attachment_fields_to_edit', array(), $post );
		$html   = $fields[ AttachmentFields::FIELD ]['html'];
		$this->assertStringContainsString( 'class="cphfb-attachment-folder" data-attachment-id="' . $ids[0] . '" data-folder-id="0"', $html );
		$this->assertStringContainsString( 'name="attachments[' . $ids[0] . '][cphfb_folder]"', $html );

		apply_filters( 'attachment_fields_to_save', array( 'ID' => $ids[0] ), array( 'cphfb_folder' => (string) $a ) );
		$this->assertSame( $a, $this->assignments()->get_folder_id( $ids[0] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$fields = apply_filters( 'attachment_fields_to_edit', array(), $post );
		$this->assertStringContainsString( 'readonly value="A"', $fields[ AttachmentFields::FIELD ]['html'] );
		apply_filters( 'attachment_fields_to_save', array( 'ID' => $ids[0] ), array( 'cphfb_folder' => '0' ) );
		$this->assertSame( $a, $this->assignments()->get_folder_id( $ids[0] ) );
	}
}
