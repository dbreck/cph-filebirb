<?php
/**
 * CSV tests.
 *
 * @package CPH\FileBirb
 */

declare(strict_types=1);

namespace CPH\FileBirb\Tests;

use CPH\FileBirb\Csv;

/**
 * Export / import round trip, FileBird files, formula guard.
 */
class CsvTest extends TestCase {

	public function test_round_trip(): void {
		$ids = $this->attachments( 3 );
		$a   = $this->make( 'Alpha' );
		$b   = $this->make( 'Beta' );
		$a1  = $this->make( 'Alpha Kid', $a );
		$a2  = $this->make( 'Alpha Kid 2', $a );
		$this->folders()->move( $a2, $a, 0 );
		$this->folders()->move( $a1, $a, 1 );
		$this->assignments()->assign( $a1, array( $ids[0], $ids[1] ) );
		$this->assignments()->assign( $b, array( $ids[2] ) );

		$csv   = Csv::get_instance()->export();
		$lines = explode( "\n", trim( $csv ) );
		$this->assertSame( 'id,name,parent,type,ord,created_by,attachment_ids', $lines[0] );
		$this->assertStringContainsString( $ids[0] . '|' . $ids[1], $csv );

		$this->folders()->delete_all();
		$result = Csv::get_instance()->import( $csv );
		$this->assertSame( array( 'folders' => 4, 'assignments' => 3 ), $result );

		$tree = $this->folders()->tree();
		$this->assertSame( array( 'Alpha', 'Beta' ), array_column( $tree, 'name' ) );
		$this->assertSame( array( 'Alpha Kid 2', 'Alpha Kid' ), array_column( $tree[0]['children'], 'name' ) );
		$kid = $tree[0]['children'][1]['id'];
		$this->assertSame( array( $ids[0], $ids[1] ), $this->assignments()->attachment_ids_in( $kid ) );
		$this->assertSame( array( $ids[2] ), $this->assignments()->attachment_ids_in( $tree[1]['id'] ) );

		// Importing again merges by name instead of duplicating.
		Csv::get_instance()->import( $csv );
		$this->assertCount( 4, $this->folders()->all() );
	}

	public function test_imports_filebird_export_with_post_type_column(): void {
		$ids = $this->attachments( 1 );
		$csv = "id,name,parent,type,ord,created_by,attachment_ids,post_type\n"
			. "10,\"Photos\",0,0,0,0,{$ids[0]}|99999999,attachment\n"
			. "11,\"Sub\",10,0,0,0,,attachment\n"
			. "50,\"Page folder\",0,0,0,0,,page\n";
		$this->assertSame( array( 'folders' => 2, 'assignments' => 1 ), Csv::get_instance()->import( $csv ) );
		$tree = $this->folders()->tree();
		$this->assertSame( 'Photos', $tree[0]['name'] );
		$this->assertSame( 'Sub', $tree[0]['children'][0]['name'] );
		$this->assertCount( 2, $this->folders()->all() );
	}

	public function test_formula_guard(): void {
		$this->assertSame( 'SUM(A1)', Csv::sanitize_for_excel( '=+-@|SUM(A1)' ) );
		$this->assertSame( '', Csv::sanitize_for_excel( null ) );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'fbv', array( 'name' => '=HYPERLINK("x")', 'parent' => 0, 'type' => 0, 'ord' => 0, 'created_by' => 0 ) );
		$this->assertStringNotContainsString( '=HYPERLINK', Csv::get_instance()->export() );

		$csv = "id,name,parent\n1,\"@cmd\",0\n";
		Csv::get_instance()->import( $csv );
		$this->assertContains( 'cmd', array_column( $this->folders()->all(), 'name' ) );
	}

	public function test_rejects_foreign_csv(): void {
		$this->assertSame( 'invalid_csv', Csv::get_instance()->import( "foo,bar\n1,2\n" )->get_error_code() );
		$this->assertSame( 'invalid_csv', Csv::get_instance()->import( '' )->get_error_code() );
	}
}
