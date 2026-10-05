<?php
/**
 * Shared test helpers.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Tests;

use CPH\FileBird\Model\Assignment;
use CPH\FileBird\Model\Folder;

/**
 * Base case: clean tables and caches per test.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Reset state.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}fbv" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}fbv_attachment_folder" );
		delete_option( Folder::COLORS_OPTION );
		delete_option( 'cphfb_settings' );
		Assignment::get_instance()->invalidate_counts();
	}

	/**
	 * Folder model.
	 */
	protected function folders(): Folder {
		return Folder::get_instance();
	}

	/**
	 * Assignment model.
	 */
	protected function assignments(): Assignment {
		return Assignment::get_instance();
	}

	/**
	 * Create a folder and return its ID.
	 */
	protected function make( string $name, int $parent = 0 ): int {
		$node = $this->folders()->create( $name, $parent );
		$this->assertIsArray( $node );
		return $node['id'];
	}

	/**
	 * Create attachments and return their IDs.
	 *
	 * @return int[]
	 */
	protected function attachments( int $n, string $status = 'inherit' ): array {
		$ids = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$ids[] = self::factory()->attachment->create_object(
				array(
					'file'           => "file-{$i}.jpg",
					'post_mime_type' => 'image/jpeg',
					'post_status'    => $status,
				)
			);
		}
		return $ids;
	}
}
