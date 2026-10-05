<?php
/**
 * Assignment model tests.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Tests;

use CPH\FileBirb\Model\Assignment;
use CPH\FileBirb\Model\Settings;

/**
 * Assign, replace, unassign, counts, orphans, cache.
 */
class AssignmentTest extends TestCase {

	public function test_assign_replace_unassign(): void {
		$ids = $this->attachments( 3 );
		$a   = $this->make( 'A' );
		$b   = $this->make( 'B' );

		$this->assertTrue( $this->assignments()->assign( $a, $ids ) );
		$this->assertSame( $ids, $this->assignments()->attachment_ids_in( $a ) );

		$this->assertTrue( $this->assignments()->assign( $b, array( $ids[0] ) ) );
		$this->assertSame( $b, $this->assignments()->get_folder_id( $ids[0] ) );
		$this->assertSame( array( $ids[1], $ids[2] ), $this->assignments()->attachment_ids_in( $a ) );

		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fbv_attachment_folder WHERE attachment_id = %d", $ids[0] ) ), 'One folder per attachment.' );

		$this->assertTrue( $this->assignments()->unassign( array( $ids[1] ) ) );
		$this->assertSame( 0, $this->assignments()->get_folder_id( $ids[1] ) );

		$this->assertTrue( $this->assignments()->assign( 0, array( $ids[2] ) ) );
		$this->assertSame(
			array( $ids[0] => $b, $ids[1] => 0, $ids[2] => 0 ),
			$this->assignments()->get_folder_ids_for( $ids )
		);
	}

	public function test_assign_missing_folder(): void {
		$ids = $this->attachments( 1 );
		$this->assertSame( 'folder_not_found', $this->assignments()->assign( 777777, $ids )->get_error_code() );
	}

	public function test_ids_filter_pair(): void {
		$ids = $this->attachments( 2 );
		$a   = $this->make( 'A' );
		add_filter( 'cphfb_ids_assigned_to_folder', static fn( $list ) => array_slice( $list, 0, 1 ) );
		$this->assignments()->assign( $a, $ids );
		$this->assertSame( array( $ids[0] ), $this->assignments()->attachment_ids_in( $a ) );
	}

	public function test_counts_direct_and_with_subfolders(): void {
		$ids = $this->attachments( 6 );
		$this->attachments( 1, 'trash' );
		$a   = $this->make( 'A' );
		$a1  = $this->make( 'A1', $a );
		$a11 = $this->make( 'A11', $a1 );
		$b   = $this->make( 'B' );

		$this->assignments()->assign( $a, array( $ids[0] ) );
		$this->assignments()->assign( $a1, array( $ids[1], $ids[2] ) );
		$this->assignments()->assign( $a11, array( $ids[3] ) );

		$counts = $this->assignments()->counts();
		$this->assertSame( 6, $counts['all'] );
		$this->assertSame( 2, $counts['uncategorized'] );
		$this->assertSame( array( $a => 1, $a1 => 2, $a11 => 1, $b => 0 ), $counts['folders'] );

		Settings::get_instance()->set( 'include_subfolders_in_count', true );
		$counts = $this->assignments()->counts();
		$this->assertSame( array( $a => 4, $a1 => 3, $a11 => 1, $b => 0 ), $counts['folders'] );
	}

	public function test_counts_ignore_trashed_and_dangling_rows(): void {
		global $wpdb;
		$ids = $this->attachments( 2 );
		$a   = $this->make( 'A' );
		$this->assignments()->assign( $a, $ids );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $ids[0] ) );
		// Row pointing at a folder that no longer exists counts as uncategorized.
		$extra = $this->attachments( 1 );
		$wpdb->insert( $wpdb->prefix . 'fbv_attachment_folder', array( 'folder_id' => 999999, 'attachment_id' => $extra[0] ) );
		$this->assignments()->invalidate_counts();

		$counts = $this->assignments()->counts();
		$this->assertSame( 1, $counts['folders'][ $a ] );
		$this->assertSame( 2, $counts['all'] );
		$this->assertSame( 1, $counts['uncategorized'] );
	}

	public function test_counts_cached_and_invalidated(): void {
		$ids = $this->attachments( 2 );
		$a   = $this->make( 'A' );
		$this->assignments()->counts();
		$this->assertIsArray( get_transient( Assignment::COUNTS_TRANSIENT ) );

		$this->assignments()->assign( $a, $ids );
		$this->assertFalse( get_transient( Assignment::COUNTS_TRANSIENT ), 'assign() invalidates.' );
		$this->assertSame( 2, $this->assignments()->counts()['folders'][ $a ] );

		$b = $this->make( 'B' );
		$this->assertFalse( get_transient( Assignment::COUNTS_TRANSIENT ), 'create() invalidates.' );
		$this->assignments()->counts();
		$this->folders()->delete( $b );
		$this->assertFalse( get_transient( Assignment::COUNTS_TRANSIENT ), 'delete() invalidates.' );
		$this->assignments()->counts();
		$this->folders()->move( $a, 0, 3 );
		$this->assertFalse( get_transient( Assignment::COUNTS_TRANSIENT ), 'move() invalidates.' );
	}

	public function test_counts_query_count_does_not_grow_with_folders(): void {
		global $wpdb;
		Settings::get_instance()->set( 'include_subfolders_in_count', true );
		$measure = function (): int {
			$this->assignments()->invalidate_counts();
			$before = $GLOBALS['wpdb']->num_queries;
			$this->assignments()->counts();
			return $GLOBALS['wpdb']->num_queries - $before;
		};
		$parent = $this->make( 'Root' );
		$measure(); // Warm the transient option rows.
		$small = $measure();
		for ( $i = 0; $i < 25; $i++ ) {
			$parent = $this->make( "F{$i}", $parent );
		}
		$this->assertSame( $small, $measure() );
	}

	public function test_all_folders_and_count_filter_pair(): void {
		$seen = array();
		add_filter(
			'cphfb_all_folders_and_count',
			static function ( $sql ) use ( &$seen ) {
				$seen[] = 'cphfb';
				return $sql;
			}
		);
		add_filter(
			'fbv_all_folders_and_count',
			static function ( $sql, $lang ) use ( &$seen ) {
				$seen[] = 'fbv';
				return $sql;
			},
			10,
			2
		);
		$this->assignments()->counts();
		$this->assertSame( array( 'cphfb', 'fbv' ), $seen );
	}

	public function test_cleanup_orphans(): void {
		global $wpdb;
		$ids = $this->attachments( 1 );
		$a   = $this->make( 'A' );
		$this->assignments()->assign( $a, $ids );
		$wpdb->insert( $wpdb->prefix . 'fbv_attachment_folder', array( 'folder_id' => $a, 'attachment_id' => 99999999 ) );
		$wpdb->insert( $wpdb->prefix . 'fbv_attachment_folder', array( 'folder_id' => 88888888, 'attachment_id' => $ids[0] ) );

		$this->assertSame( 2, $this->assignments()->cleanup_orphans() );
		$this->assertSame( $ids, $this->assignments()->attachment_ids_in( $a ) );
	}

	public function test_delete_for_attachment(): void {
		$ids = $this->attachments( 1 );
		$a   = $this->make( 'A' );
		$this->assignments()->assign( $a, $ids );
		$this->assignments()->delete_for_attachment( $ids[0] );
		$this->assertSame( array(), $this->assignments()->attachment_ids_in( $a ) );
	}

	public function test_attachment_ids_in_with_subfolders_and_specials(): void {
		$ids = $this->attachments( 3 );
		$a   = $this->make( 'A' );
		$a1  = $this->make( 'A1', $a );
		$this->assignments()->assign( $a, array( $ids[0] ) );
		$this->assignments()->assign( $a1, array( $ids[1] ) );

		$this->assertSame( array( $ids[0], $ids[1] ), $this->assignments()->attachment_ids_in( $a, true ) );
		$this->assertSame( array( $ids[2] ), $this->assignments()->attachment_ids_in( 0 ) );
		$this->assertSame( $ids, $this->assignments()->attachment_ids_in( -1 ) );
	}

	public function test_uncategorized_where_fragment(): void {
		global $wpdb;
		$ids = $this->attachments( 2 );
		$this->assignments()->assign( $this->make( 'A' ), array( $ids[0] ) );
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND " . $this->assignments()->uncategorized_where( "{$wpdb->posts}.ID" );
		$this->assertSame( array( (string) $ids[1] ), $wpdb->get_col( $sql ) );
		$this->assertStringStartsWith( 'NOT EXISTS (', $this->assignments()->uncategorized_where( 'p.ID' ) );
	}
}
