<?php
/**
 * Folder model tests.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Tests;

use CPH\FileBirb\Model\Folder;

/**
 * CRUD, naming, tree, move, reorder, delete, duplicate, colors.
 */
class FolderTest extends TestCase {

	public function test_create_returns_node_and_writes_shared_row(): void {
		$node = $this->folders()->create( 'Hero', 0 );
		$this->assertSame( array( 'id', 'name', 'title', 'parent', 'ord', 'color', 'count', 'children' ), array_keys( $node ) );
		$this->assertSame( 'Hero', $node['name'] );
		$this->assertSame( 'Hero', $node['title'] );
		$this->assertSame( 0, $node['ord'] );

		$row = $this->folders()->get( $node['id'] );
		$this->assertSame( 0, $row->created_by );
		$this->assertSame( 0, $row->type );
		$this->assertTrue( $this->folders()->exists( $node['id'] ) );
		$this->assertNull( $this->folders()->get( 999999 ) );
	}

	public function test_created_by_filter_pair_applies(): void {
		add_filter( 'fbv_folder_created_by', static fn() => 7 );
		$id = $this->make( 'Mine' );
		$this->assertSame( 7, $this->folders()->get( $id )->created_by );
		// Reads never filter by author.
		$this->assertCount( 1, $this->folders()->all() );
	}

	public function test_create_sanitises_and_rejects_bad_input(): void {
		$node = $this->folders()->create( '=cmd<b>Bold</b>', 0 );
		$this->assertSame( 'cmdBold', $node['name'] );
		$this->assertWPError( $this->folders()->create( '   ', 0 ) );
		$this->assertSame( 'invalid_parent', $this->folders()->create( 'x', 424242 )->get_error_code() );
	}

	public function test_ord_increments_and_name_collisions_suffix(): void {
		$a = $this->folders()->create( 'Team' );
		$b = $this->folders()->create( 'Team' );
		$c = $this->folders()->create( 'Team' );
		$this->assertSame( array( 0, 1, 2 ), array( $a['ord'], $b['ord'], $c['ord'] ) );
		$this->assertSame( array( 'Team', 'Team (1)', 'Team (2)' ), array( $a['name'], $b['name'], $c['name'] ) );

		// Same name under a different parent is fine.
		$child = $this->folders()->create( 'Team', $a['id'] );
		$this->assertSame( 'Team', $child['name'] );
		$this->assertSame( 0, $child['ord'] );
	}

	public function test_get_or_create(): void {
		$id = $this->folders()->get_or_create( 'Blog' );
		$this->assertSame( $id, $this->folders()->get_or_create( 'Blog' ) );
		$this->assertNotSame( $id, $this->folders()->get_or_create( 'Blog', $id ) );
	}

	public function test_rename(): void {
		$a = $this->make( 'A' );
		$this->make( 'B' );
		$this->assertTrue( $this->folders()->rename( $a, 'C' ) );
		$this->assertSame( 'C', $this->folders()->get( $a )->name );
		$this->assertSame( 'folder_name_exists', $this->folders()->rename( $a, 'B' )->get_error_code() );
		$this->assertSame( 'folder_not_found', $this->folders()->rename( 99999, 'Z' )->get_error_code() );
		$this->assertTrue( $this->folders()->rename( $a, 'C' ) );
	}

	public function test_all_ordering_and_search(): void {
		$b = $this->make( 'b10' );
		$a = $this->make( 'a2' );
		$c = $this->make( 'b9' );
		$this->assertSame( array( $b, $a, $c ), array_column( $this->folders()->all(), 'id' ) );
		$this->assertSame( array( 'a2', 'b9', 'b10' ), array_column( $this->folders()->all( array( 'orderby' => 'name' ) ), 'name' ) );
		$this->assertSame( array( 'b10', 'b9', 'a2' ), array_column( $this->folders()->all( array( 'orderby' => 'name', 'order' => 'desc' ) ), 'name' ) );
		$this->assertSame( array( 'b10', 'b9' ), array_column( $this->folders()->all( array( 'search' => 'b' ) ), 'name' ) );
		$this->assertIsInt( $this->folders()->all()[0]->ord );
	}

	public function test_tree_and_flat(): void {
		$root  = $this->make( 'Root' );
		$child = $this->make( 'Child', $root );
		$grand = $this->make( 'Grand', $child );
		$other = $this->make( 'Other' );

		$tree = $this->folders()->tree();
		$this->assertSame( array( $root, $other ), array_column( $tree, 'id' ) );
		$this->assertSame( $child, $tree[0]['children'][0]['id'] );
		$this->assertSame( $grand, $tree[0]['children'][0]['children'][0]['id'] );
		$this->assertSame( array(), $tree[1]['children'] );

		$flat = $this->folders()->flat();
		$this->assertSame( array( $root, $child, $grand, $other ), array_column( $flat, 'id' ) );
		$this->assertSame( array( 0, 1, 2, 0 ), array_column( $flat, 'depth' ) );

		$this->assertSame( array( $child, $grand ), $this->folders()->descendant_ids( $root ) );
		$this->assertSame( array(), $this->folders()->descendant_ids( $other ) );
	}

	public function test_tree_shows_orphans_at_root(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'fbv', array( 'name' => 'Lost', 'parent' => 987654, 'type' => 0, 'ord' => 0, 'created_by' => 0 ) );
		$this->assertSame( array( 'Lost' ), array_column( $this->folders()->tree(), 'name' ) );
	}

	public function test_tree_does_not_query_per_node(): void {
		global $wpdb;
		$parent = 0;
		for ( $i = 0; $i < 20; $i++ ) {
			$parent = $this->make( "F{$i}", $parent );
		}
		$before = $wpdb->num_queries;
		$this->folders()->tree();
		$this->assertLessThanOrEqual( 3, $wpdb->num_queries - $before );
	}

	public function test_move_and_cycle_rejection(): void {
		$a  = $this->make( 'A' );
		$b  = $this->make( 'B' );
		$a1 = $this->make( 'A1', $a );

		$this->assertSame( 'invalid_parent', $this->folders()->move( $a, $a )->get_error_code() );
		$this->assertSame( 'invalid_parent', $this->folders()->move( $a, $a1 )->get_error_code() );
		$this->assertSame( 'invalid_parent', $this->folders()->move( $a, 55555 )->get_error_code() );

		$this->assertTrue( $this->folders()->move( $a1, $b ) );
		$this->assertSame( $b, $this->folders()->get( $a1 )->parent );

		// Collision in the new parent auto-renames.
		$this->make( 'A1', $a );
		$this->assertTrue( $this->folders()->move( $a1, $a ) );
		$this->assertSame( 'A1 (1)', $this->folders()->get( $a1 )->name );

		$this->assertTrue( $this->folders()->move( $a1, 0, 9 ) );
		$this->assertSame( 9, $this->folders()->get( $a1 )->ord );
	}

	public function test_reorder(): void {
		$a = $this->make( 'A' );
		$b = $this->make( 'B' );
		$c = $this->make( 'C' );

		$this->assertTrue(
			$this->folders()->reorder(
				array(
					array( $c, 0, 0 ),
					array( 'id' => $a, 'parent' => 0, 'ord' => 1 ),
					array( $b, $c, 0 ),
				)
			)
		);
		$this->assertSame( array( $c, $a ), array_column( $this->folders()->tree(), 'id' ) );
		$this->assertSame( $c, $this->folders()->get( $b )->parent );

		// A → B and B → A is a cycle.
		$this->assertSame( 'invalid_parent', $this->folders()->reorder( array( array( $c, $b, 0 ) ) )->get_error_code() );
		$this->assertSame( 0, $this->folders()->get( $c )->parent, 'Rejected reorder writes nothing.' );
		$this->assertSame( 'folder_not_found', $this->folders()->reorder( array( array( 8888, 0, 0 ) ) )->get_error_code() );
	}

	public function test_delete_subtree(): void {
		$ids  = $this->attachments( 3 );
		$a    = $this->make( 'A' );
		$a1   = $this->make( 'A1', $a );
		$a11  = $this->make( 'A11', $a1 );
		$keep = $this->make( 'Keep' );
		$this->assignments()->assign( $a, array( $ids[0] ) );
		$this->assignments()->assign( $a11, array( $ids[1] ) );
		$this->assignments()->assign( $keep, array( $ids[2] ) );

		$this->assertTrue( $this->folders()->delete( $a ) );
		$this->assertSame( array( $keep ), array_column( $this->folders()->all(), 'id' ) );
		$this->assertSame( 0, $this->assignments()->get_folder_id( $ids[0] ) );
		$this->assertSame( 0, $this->assignments()->get_folder_id( $ids[1] ) );
		$this->assertSame( $keep, $this->assignments()->get_folder_id( $ids[2] ) );
		$this->assertSame( 'folder_not_found', $this->folders()->delete( $a )->get_error_code() );
	}

	public function test_delete_children_up(): void {
		$ids = $this->attachments( 2 );
		$p   = $this->make( 'P' );
		$a   = $this->make( 'A', $p );
		$a1  = $this->make( 'A1', $a );
		$a2  = $this->make( 'A2', $a );
		$this->make( 'A1', $p );
		$this->assignments()->assign( $a, array( $ids[0] ) );
		$this->assignments()->assign( $a1, array( $ids[1] ) );

		$this->assertTrue( $this->folders()->delete( $a, 'children-up' ) );
		$this->assertFalse( $this->folders()->exists( $a ) );
		$this->assertSame( $p, $this->folders()->get( $a1 )->parent );
		$this->assertSame( $p, $this->folders()->get( $a2 )->parent );
		$this->assertSame( 'A1 (1)', $this->folders()->get( $a1 )->name );
		$this->assertSame( 0, $this->assignments()->get_folder_id( $ids[0] ) );
		$this->assertSame( $a1, $this->assignments()->get_folder_id( $ids[1] ) );

		$this->assertSame( 'invalid_mode', $this->folders()->delete( $p, 'nope' )->get_error_code() );
	}

	public function test_can_delete_filter_blocks(): void {
		$a = $this->make( 'A' );
		add_filter( 'fbv_can_delete_folder', '__return_false' );
		$this->assertSame( 'cannot_delete', $this->folders()->delete( $a )->get_error_code() );
		$this->assertTrue( $this->folders()->exists( $a ) );
	}

	public function test_delete_cleans_colors(): void {
		$a  = $this->make( 'A' );
		$a1 = $this->make( 'A1', $a );
		$b  = $this->make( 'B' );
		$this->folders()->set_color( $a, '#ff0000' );
		$this->folders()->set_color( $a1, '#00ff00' );
		$this->folders()->set_color( $b, '#0000ff' );

		$this->folders()->delete( $a );
		$this->assertSame( array( $b => '#0000ff' ), get_option( Folder::COLORS_OPTION ) );
	}

	public function test_colors(): void {
		$a = $this->make( 'A' );
		$this->assertTrue( $this->folders()->set_color( $a, '#abcdef' ) );
		$this->assertSame( '#abcdef', $this->folders()->tree()[0]['color'] );
		$this->assertSame( 'invalid_color', $this->folders()->set_color( $a, 'red' )->get_error_code() );
		$this->assertTrue( $this->folders()->set_color( $a, '' ) );
		$this->assertSame( array(), $this->folders()->get_colors() );
		$this->assertSame( 'folder_not_found', $this->folders()->set_color( 1234567, '#fff' )->get_error_code() );
	}

	public function test_duplicate(): void {
		$ids = $this->attachments( 1 );
		$a   = $this->make( 'A' );
		$a1  = $this->make( 'A1', $a );
		$this->make( 'A11', $a1 );
		$this->folders()->set_color( $a1, '#123456' );
		$this->assignments()->assign( $a, $ids );

		$copy = $this->folders()->duplicate( $a );
		$this->assertSame( 'A (Copy)', $copy['name'] );
		$this->assertSame( 0, $copy['parent'] );
		$this->assertSame( 'A1', $copy['children'][0]['name'] );
		$this->assertSame( '#123456', $copy['children'][0]['color'] );
		$this->assertSame( 'A11', $copy['children'][0]['children'][0]['name'] );
		$this->assertSame( array(), $this->assignments()->attachment_ids_in( $copy['id'] ), 'Attachments are not copied.' );

		$this->assertSame( 'A (Copy) 1', $this->folders()->duplicate( $a )['name'] );
		$this->assertCount( 9, $this->folders()->all() );
	}

	public function test_delete_all(): void {
		$ids = $this->attachments( 1 );
		$a   = $this->make( 'A' );
		$this->assignments()->assign( $a, $ids );
		$this->folders()->delete_all();
		$this->assertSame( array(), $this->folders()->all() );
		$this->assertSame( 0, $this->assignments()->get_folder_id( $ids[0] ) );
	}

	public function test_reassign_author(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'fbv', array( 'name' => 'U', 'parent' => 0, 'type' => 0, 'ord' => 0, 'created_by' => 5 ) );
		$id = (int) $wpdb->insert_id;
		$this->folders()->reassign_author( 5, 0 );
		$this->assertSame( 0, $this->folders()->get( $id )->created_by );
	}
}
