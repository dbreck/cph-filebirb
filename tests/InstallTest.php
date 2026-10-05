<?php
/**
 * Install tests.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Tests;

use CPH\FileBirb\Install;
use CPH\FileBirb\Model\Settings;

/**
 * Schema must match FileBird's exactly.
 */
class InstallTest extends TestCase {

	/**
	 * Drop int display widths on servers that no longer report them (MySQL 8.0.19+).
	 */
	private function normalize( string $type ): string {
		global $wpdb;
		$version = (string) $wpdb->get_var( 'SELECT VERSION()' );
		if ( false === stripos( $version, 'mariadb' ) && version_compare( $version, '8.0.19', '>=' ) ) {
			return (string) preg_replace( '/^(int|bigint)\(\d+\)/', '$1', $type );
		}
		return $type;
	}

	/**
	 * Columns as [ Field, Type, Null, Key, Default, Extra ].
	 */
	private function columns( string $table ): array {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ) as $col ) {
			$out[] = array( $col->Field, $this->normalize( $col->Type ), $col->Null, $col->Key, $col->Default, $col->Extra );
		}
		return $out;
	}

	/**
	 * Indexes as [ Key_name, Seq, Column, Non_unique ].
	 */
	private function indexes( string $table ): array {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( "SHOW INDEX FROM {$table}" ) as $idx ) {
			$out[] = array( $idx->Key_name, (int) $idx->Seq_in_index, $idx->Column_name, (int) $idx->Non_unique );
		}
		return $out;
	}

	public function test_fbv_table_matches_filebird(): void {
		global $wpdb;
		$expected = array(
			array( 'id', $this->normalize( 'int(11) unsigned' ), 'NO', 'PRI', null, 'auto_increment' ),
			array( 'name', 'varchar(250)', 'NO', '', null, '' ),
			array( 'parent', $this->normalize( 'int(11)' ), 'NO', '', '0', '' ),
			array( 'type', $this->normalize( 'int(2)' ), 'NO', '', '0', '' ),
			array( 'ord', $this->normalize( 'int(11)' ), 'YES', '', '0', '' ),
			array( 'created_by', $this->normalize( 'int(11)' ), 'YES', '', '0', '' ),
		);
		$this->assertSame( $expected, $this->columns( $wpdb->prefix . 'fbv' ) );
		$this->assertSame(
			array(
				array( 'PRIMARY', 1, 'id', 0 ),
				array( 'id', 1, 'id', 0 ),
			),
			$this->indexes( $wpdb->prefix . 'fbv' )
		);
	}

	public function test_relation_table_matches_filebird(): void {
		global $wpdb;
		$expected = array(
			array( 'folder_id', $this->normalize( 'int(11) unsigned' ), 'NO', 'PRI', null, '' ),
			array( 'attachment_id', $this->normalize( 'bigint(20) unsigned' ), 'NO', 'PRI', null, '' ),
		);
		$this->assertSame( $expected, $this->columns( $wpdb->prefix . 'fbv_attachment_folder' ) );
		$this->assertSame(
			array(
				array( 'PRIMARY', 1, 'folder_id', 0 ),
				array( 'PRIMARY', 2, 'attachment_id', 0 ),
			),
			$this->indexes( $wpdb->prefix . 'fbv_attachment_folder' )
		);
	}

	public function test_create_tables_is_idempotent_and_sets_version(): void {
		delete_option( Install::VERSION_OPTION );
		Install::create_tables();
		Install::create_tables();
		$this->assertSame( Install::DB_VERSION, get_option( Install::VERSION_OPTION ) );
	}

	public function test_seeds_settings_from_filebird_once(): void {
		update_option( 'fbv_settings', array( 'folder_counter_type' => 'counter_file_in_folder_and_sub', 'user_mode' => false ) );
		Install::seed_settings();
		$this->assertTrue( Settings::get_instance()->get( 'include_subfolders_in_count' ) );

		update_option( 'fbv_settings', array( 'folder_counter_type' => 'counter_file_in_folder' ) );
		Install::seed_settings();
		$this->assertTrue( Settings::get_instance()->get( 'include_subfolders_in_count' ), 'Seeding runs only once.' );
	}

	public function test_seed_without_filebird_uses_defaults(): void {
		delete_option( 'fbv_settings' );
		Install::seed_settings();
		$this->assertSame( Settings::DEFAULTS, get_option( Settings::OPTION ) );
	}
}
