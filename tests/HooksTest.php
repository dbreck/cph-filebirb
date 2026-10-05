<?php
/**
 * Hook dual-firing tests.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Tests;

use CPH\FileBird\Hooks;

/**
 * Every mutation fires cphfb_* first, then the fbv_* twin with identical args.
 */
class HooksTest extends TestCase {

	/**
	 * Recorded calls: [ hook, args ].
	 *
	 * @var array
	 */
	private array $log = array();

	public function set_up(): void {
		parent::set_up();
		$this->log = array();
		foreach ( Hooks::ACTIONS as $short => $legacy ) {
			foreach ( array( Hooks::PREFIX . $short, $legacy ) as $hook ) {
				add_action(
					$hook,
					function ( ...$args ) use ( $hook ) {
						$this->log[] = array( $hook, $args );
					},
					10,
					5
				);
			}
		}
	}

	/**
	 * Assert the log is cphfb/fbv pairs with matching args, and return the short names fired.
	 */
	private function assert_pairs(): array {
		$this->assertSame( 0, count( $this->log ) % 2, 'Hooks fire in pairs.' );
		$fired = array();
		for ( $i = 0; $i < count( $this->log ); $i += 2 ) {
			[ $first, $first_args ]   = $this->log[ $i ];
			[ $second, $second_args ] = $this->log[ $i + 1 ];
			$this->assertStringStartsWith( Hooks::PREFIX, $first );
			$short = substr( $first, strlen( Hooks::PREFIX ) );
			$this->assertSame( Hooks::ACTIONS[ $short ], $second );
			$this->assertSame( $first_args, $second_args );
			$fired[] = $short;
		}
		$this->log = array();
		return $fired;
	}

	public function test_map_covers_appendix_a(): void {
		$legacy = array_merge( array_values( Hooks::ACTIONS ), array_values( Hooks::FILTERS ) );
		foreach ( array(
			'fbv_after_folder_created', 'fbv_after_folder_renamed', 'fbv_after_folder_deleted', 'fbv_after_delete_all',
			'fbv_folder_parent_updated', 'fbv_after_parent_updated', 'fbv_before_setting_folder', 'fbv_after_set_folder',
			'fbv_after_assign_folder', 'fbv_folder_created_by', 'fbv_will_check_author', 'fbv_ids_assigned_to_folder',
			'fbv_all_folders_and_count', 'fbv_user_default_folder', 'fbv_query_include_subfolders', 'fbv_can_delete_folder',
			'fbv_auto_create_folders', 'fbv_counter_type', 'fbv_speedup_get_count_query', 'fbv_download_filename',
			'fbv_use_zipstream', 'filebird_post_types',
		) as $name ) {
			$this->assertContains( $name, $legacy );
		}
	}

	public function test_folder_mutations_fire_pairs(): void {
		$node = $this->folders()->create( 'A' );
		$this->assertSame( array( 'folder_created' ), $this->assert_pairs() );
		$this->assertSame( $node['id'], $this->log[0][1][0] ?? $node['id'] );

		$b = $this->make( 'B' );
		$this->log = array();

		$this->folders()->rename( $node['id'], 'A2' );
		$this->assertSame( array( 'folder_renamed' ), $this->assert_pairs() );

		$this->folders()->move( $node['id'], $b );
		$this->assertSame( array( 'folder_parent_updated', 'parent_updated' ), $this->assert_pairs() );

		$this->folders()->reorder( array( array( $node['id'], 0, 0 ) ) );
		$this->assertSame( array( 'folder_parent_updated', 'parent_updated' ), $this->assert_pairs() );

		$this->folders()->delete( $node['id'] );
		$this->assertSame( array( 'folder_deleted' ), $this->assert_pairs() );

		$this->folders()->delete_all();
		$this->assertSame( array( 'delete_all' ), $this->assert_pairs() );
	}

	public function test_created_args_shape(): void {
		$node = $this->folders()->create( 'A' );
		[ $hook, $args ] = $this->log[1];
		$this->assertSame( 'fbv_after_folder_created', $hook );
		$this->assertSame( $node['id'], $args[0] );
		$this->assertSame( 'A', $args[1]['title'] );
		$this->assertSame( $node['id'], $args[1]['data-id'] );
	}

	public function test_assign_fires_pairs_with_filebird_shapes(): void {
		$ids = $this->attachments( 2 );
		$a   = $this->make( 'A' );
		$this->log = array();

		$this->assignments()->assign( $a, $ids );
		$log = $this->log;
		$this->assertSame(
			array( 'before_setting_folder', 'before_setting_folder', 'after_set_folder', 'after_set_folder', 'after_assign_folder' ),
			$this->assert_pairs()
		);
		$this->assertSame( array( $ids[0], $a ), $log[0][1] );
		$this->assertSame( array( $ids[0], $a ), $log[4][1] );
		$this->assertSame( array( $a, $ids ), $log[8][1] );
	}

	public function test_filter_chains_cphfb_then_fbv(): void {
		add_filter( 'cphfb_can_delete_folder', static fn( $v, $id ) => 'c' . $id, 10, 2 );
		add_filter( 'fbv_can_delete_folder', static fn( $v, $id ) => $v . 'f' . $id, 10, 2 );
		$this->assertSame( 'c3f3', Hooks::filter( 'can_delete_folder', true, 3 ) );
		add_filter( 'filebird_post_types', static fn( $v ) => array_merge( $v, array( 'page' ) ) );
		$this->assertSame( array( 'post', 'page' ), Hooks::filter( 'post_types', array( 'post' ) ) );
	}
}
