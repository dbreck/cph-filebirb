<?php
/**
 * Upload routing tests.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Tests;

use CPH\FileBird\Model\Assignment;
use CPH\FileBird\Model\UserSettings;
use CPH\FileBird\Upload;

/**
 * Routing precedence, path-form auto-create, delete cleanup, edited-image inheritance.
 */
class UploadTest extends TestCase {

	private int $user;

	public function set_up(): void {
		parent::set_up();
		$this->user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user );
		$this->clear_request();
	}

	public function tear_down(): void {
		$this->clear_request();
		parent::tear_down();
	}

	private function clear_request(): void {
		unset( $_SERVER[ Upload::HEADER ], $_REQUEST['cphfb_folder'], $_REQUEST['fbv'] );
	}

	private function upload(): int {
		return $this->attachments( 1 )[0];
	}

	public function test_no_source_means_no_assignment(): void {
		$this->make( 'A' );
		$this->assertSame( 0, $this->assignments()->get_folder_id( $this->upload() ) );
	}

	public function test_precedence(): void {
		$h = $this->make( 'Header' );
		$r = $this->make( 'Request' );
		$l = $this->make( 'Legacy' );
		$d = $this->make( 'Default' );
		UserSettings::get_instance()->set( 'default_upload_folder', $d, $this->user );

		$this->assertSame( $d, $this->assignments()->get_folder_id( $this->upload() ) );

		$_REQUEST['fbv'] = (string) $l;
		$this->assertSame( $l, $this->assignments()->get_folder_id( $this->upload() ) );

		$_REQUEST['cphfb_folder'] = (string) $r;
		$this->assertSame( $r, $this->assignments()->get_folder_id( $this->upload() ) );

		$_SERVER[ Upload::HEADER ] = (string) $h;
		$this->assertSame( $h, $this->assignments()->get_folder_id( $this->upload() ) );
	}

	public function test_invalid_values_mean_no_assignment(): void {
		$_REQUEST['cphfb_folder'] = '999999';
		$this->assertSame( 0, $this->assignments()->get_folder_id( $this->upload() ) );
		$_REQUEST['cphfb_folder'] = '-1';
		$this->assertSame( 0, $this->assignments()->get_folder_id( $this->upload() ) );
	}

	public function test_request_ignored_without_upload_cap(): void {
		$a = $this->make( 'A' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['cphfb_folder'] = (string) $a;
		$this->assertSame( 0, $this->assignments()->get_folder_id( $this->upload() ) );
	}

	public function test_user_default_folder_filter(): void {
		$a   = $this->make( 'A' );
		$cb  = static fn() => $a;
		add_filter( 'fbv_user_default_folder', $cb );
		$this->assertSame( $a, $this->assignments()->get_folder_id( $this->upload() ) );
		remove_filter( 'fbv_user_default_folder', $cb );
	}

	public function test_path_form_auto_creates(): void {
		$base            = $this->make( 'Base' );
		$_REQUEST['fbv'] = "{$base}/Sub/Sub2";
		$id              = $this->upload();
		$sub             = $this->folders()->get_or_create( 'Sub', $base );
		$sub2            = $this->folders()->get_or_create( 'Sub2', $sub );
		$this->assertSame( $sub2, $this->assignments()->get_folder_id( $id ) );

		// Reuses existing folders.
		$count = count( $this->folders()->all() );
		$this->upload();
		$this->assertCount( $count, $this->folders()->all() );

		// Negative first segment = root.
		$_REQUEST['fbv'] = '-1/Rooted';
		$id              = $this->upload();
		$folder          = $this->folders()->get( $this->assignments()->get_folder_id( $id ) );
		$this->assertSame( 'Rooted', $folder->name );
		$this->assertSame( 0, $folder->parent );
	}

	public function test_path_form_respects_auto_create_filter(): void {
		$base            = $this->make( 'Base' );
		$off             = static fn() => false;
		add_filter( 'fbv_auto_create_folders', $off );
		$_REQUEST['fbv'] = "{$base}/Nope";
		$this->assertSame( $base, $this->assignments()->get_folder_id( $this->upload() ) );
		remove_filter( 'fbv_auto_create_folders', $off );
		$this->assertCount( 1, $this->folders()->all() );
	}

	public function test_delete_cleans_up(): void {
		global $wpdb;
		$a  = $this->make( 'A' );
		$id = $this->upload();
		$this->assignments()->assign( $a, array( $id ) );
		$this->assignments()->counts();
		wp_delete_attachment( $id, true );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->assignments()->table()} WHERE attachment_id = %d", $id ) ) );
		$this->assertFalse( get_transient( Assignment::COUNTS_TRANSIENT ) );
	}

	public function test_trash_and_status_change_invalidate_counts(): void {
		$id = $this->upload();
		$this->assignments()->counts();
		wp_update_post( array( 'ID' => $id, 'post_status' => 'private' ) );
		$this->assertFalse( get_transient( Assignment::COUNTS_TRANSIENT ) );

		$this->assignments()->counts();
		do_action( 'trashed_post', $id );
		$this->assertFalse( get_transient( Assignment::COUNTS_TRANSIENT ) );
	}

	public function test_edited_image_inherits_folder(): void {
		$a                 = $this->make( 'A' );
		[ $orig, $edited ] = $this->attachments( 2 );
		$this->assignments()->assign( $a, array( $orig ) );
		apply_filters( 'wp_edited_image_metadata', array(), $edited, $orig );
		$this->assertSame( $a, $this->assignments()->get_folder_id( $edited ) );
	}

	public function test_placeholder(): void {
		ob_start();
		do_action( 'pre-upload-ui' );
		$this->assertStringContainsString( '<div class="cphfb-upload-folder" hidden></div>', (string) ob_get_clean() );
	}
}
