<?php
/**
 * Dual-fire hook helper: `cphfb_*` first, then the legacy FileBird twin.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird;

defined( 'ABSPATH' ) || exit;

/**
 * The single home of the cphfb → FileBird hook name map.
 *
 * Call with the short name (no prefix): `Hooks::action( 'folder_created', $id, $node )`
 * fires `cphfb_folder_created` and then `fbv_after_folder_created` with the same args.
 */
final class Hooks {

	/**
	 * Prefix for our own hook names.
	 */
	public const PREFIX = 'cphfb_';

	/**
	 * Actions: short name => FileBird name. Args noted per line.
	 *
	 * @var array<string,string>
	 */
	public const ACTIONS = array(
		'folder_created'        => 'fbv_after_folder_created',  // ( int $folder_id, array $node ).
		'folder_renamed'        => 'fbv_after_folder_renamed',  // ( int $folder_id, string $new_name ).
		'folder_deleted'        => 'fbv_after_folder_deleted',  // ( int $folder_id ).
		'delete_all'            => 'fbv_after_delete_all',      // ().
		'folder_parent_updated' => 'fbv_folder_parent_updated', // ( int $folder_id, int $new_parent ).
		'parent_updated'        => 'fbv_after_parent_updated',  // ( int $folder_id, int $new_parent ).
		'before_setting_folder' => 'fbv_before_setting_folder', // ( int $attachment_id, int $folder_id ).
		'after_set_folder'      => 'fbv_after_set_folder',      // ( int $attachment_id, int $folder_id ).
		'after_assign_folder'   => 'fbv_after_assign_folder',   // ( int $folder_id, int[] $attachment_ids ).
	);

	/**
	 * Filters: short name => FileBird name. Value first, extra args noted per line.
	 *
	 * @var array<string,string>
	 */
	public const FILTERS = array(
		'folder_created_by'       => 'fbv_folder_created_by',       // ( int $created_by = 0 ).
		'will_check_author'       => 'fbv_will_check_author',       // ( bool $check = true ). Reserved; shared tree never filters by author.
		'ids_assigned_to_folder'  => 'fbv_ids_assigned_to_folder',  // ( int[] $attachment_ids ).
		'all_folders_and_count'   => 'fbv_all_folders_and_count',   // ( string $sql, string|null $lang ).
		'user_default_folder'     => 'fbv_user_default_folder',     // ( int $folder_id, int $user_id ).
		'query_include_subfolders' => 'fbv_query_include_subfolders', // ( bool $include, int $folder_id ).
		'can_delete_folder'       => 'fbv_can_delete_folder',       // ( bool $can, int $folder_id ).
		'auto_create_folders'     => 'fbv_auto_create_folders',     // ( bool $auto = true ).
		'counter_type'            => 'fbv_counter_type',            // ( string $type ).
		'speedup_get_count_query' => 'fbv_speedup_get_count_query', // ( bool $speedup = false ).
		'download_filename'       => 'fbv_download_filename',       // ( string $zip_name, object $folder ).
		'use_zipstream'           => 'fbv_use_zipstream',           // ( bool $use = true ).
		'post_types'              => 'filebird_post_types',         // ( array $post_types ).
	);

	/**
	 * Fire `cphfb_{$name}` then its legacy twin (if mapped) with identical args.
	 *
	 * @param string $name    Short hook name.
	 * @param mixed  ...$args Hook arguments.
	 * @return void
	 */
	public static function action( string $name, ...$args ): void {
		do_action( self::PREFIX . $name, ...$args );
		if ( isset( self::ACTIONS[ $name ] ) ) {
			do_action( self::ACTIONS[ $name ], ...$args );
		}
	}

	/**
	 * Apply `cphfb_{$name}` then its legacy twin (if mapped); the twin receives the already-filtered value.
	 *
	 * @param string $name    Short hook name.
	 * @param mixed  $value   Value to filter.
	 * @param mixed  ...$args Extra arguments.
	 * @return mixed
	 */
	public static function filter( string $name, $value, ...$args ) {
		$value = apply_filters( self::PREFIX . $name, $value, ...$args );
		if ( isset( self::FILTERS[ $name ] ) ) {
			$value = apply_filters( self::FILTERS[ $name ], $value, ...$args );
		}
		return $value;
	}

	/**
	 * Legacy name for a short name, or null if unmapped.
	 *
	 * @param string $name Short hook name.
	 * @return string|null
	 */
	public static function legacy_name( string $name ): ?string {
		return self::ACTIONS[ $name ] ?? self::FILTERS[ $name ] ?? null;
	}
}
