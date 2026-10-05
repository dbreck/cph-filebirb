<?php
/**
 * Folder model over FileBird's `{prefix}fbv` table.
 *
 * @package CPH\FileBird
 */

declare(strict_types=1);

namespace CPH\FileBird\Model;

use CPH\FileBird\Csv;
use CPH\FileBird\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * One shared folder tree. Writes `created_by = 0` (filterable), reads never filter by author.
 */
final class Folder {

	public const ALL           = -1;
	public const UNCATEGORIZED = 0;

	/**
	 * Option holding `[ folder_id => hex ]`, shared with FileBird.
	 */
	public const COLORS_OPTION = 'fbv_folder_colors';

	/**
	 * Singleton instance.
	 *
	 * @var Folder|null
	 */
	private static ?Folder $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Folder
	 */
	public static function get_instance(): Folder {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Folder table name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fbv';
	}

	/**
	 * All folders as objects with int-cast fields, ordered by `ord` then `id`.
	 *
	 * @param array $args { search?: string, orderby?: 'ord'|'name', order?: 'asc'|'desc' }.
	 * @return object[]
	 */
	public function all( array $args = array() ): array {
		global $wpdb;

		$table  = $this->table();
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		if ( '' !== $search ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, parent, type, ord, created_by FROM {$table} WHERE name LIKE %s ORDER BY ord ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'%' . $wpdb->esc_like( $search ) . '%'
				)
			);
		} else {
			$rows = $wpdb->get_results( "SELECT id, name, parent, type, ord, created_by FROM {$table} ORDER BY ord ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$rows = array_map( array( $this, 'cast_row' ), (array) $rows );

		$orderby = $args['orderby'] ?? 'ord';
		$desc    = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) );
		if ( 'name' === $orderby ) {
			usort(
				$rows,
				static fn( $a, $b ) => ( $desc ? -1 : 1 ) * strnatcasecmp( $a->name, $b->name )
			);
		} elseif ( $desc ) {
			$rows = array_reverse( $rows );
		}

		return $rows;
	}

	/**
	 * One folder row, or null.
	 *
	 * @param int $id Folder ID.
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, parent, type, ord, created_by FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->cast_row( $row ) : null;
	}

	/**
	 * Whether a folder exists.
	 *
	 * @param int $id Folder ID.
	 * @return bool
	 */
	public function exists( int $id ): bool {
		return null !== $this->get( $id );
	}

	/**
	 * Create a folder. A sibling name collision gets " (1)", " (2)"… appended.
	 *
	 * @param string $name   Folder name.
	 * @param int    $parent Parent folder ID (0 = root).
	 * @return array|\WP_Error Node array.
	 */
	public function create( string $name, int $parent = 0 ): array|\WP_Error {
		$name = $this->sanitize_name( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_name', __( 'Folder name cannot be empty.', 'cph-filebird' ) );
		}
		if ( $parent < 0 || ( $parent > 0 && ! $this->exists( $parent ) ) ) {
			return new \WP_Error( 'invalid_parent', __( 'Parent folder does not exist.', 'cph-filebird' ) );
		}

		$name = $this->unique_name( $name, $parent );
		$ord  = $this->next_ord( $parent );
		$id   = $this->insert( $name, $parent, $ord );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->node(
			(object) array(
				'id'     => $id,
				'name'   => $name,
				'parent' => $parent,
				'ord'    => $ord,
			)
		);
	}

	/**
	 * Return the ID of the folder named `$name` under `$parent`, creating it if needed.
	 *
	 * @param string $name   Folder name.
	 * @param int    $parent Parent folder ID.
	 * @return int|\WP_Error
	 */
	public function get_or_create( string $name, int $parent = 0 ): int|\WP_Error {
		global $wpdb;

		$clean = $this->sanitize_name( $name );
		if ( '' !== $clean ) {
			$table = $this->table();
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s AND parent = %d ORDER BY id ASC LIMIT 1", $clean, $parent ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null !== $found ) {
				return (int) $found;
			}
		}

		$node = $this->create( $name, $parent );
		return is_wp_error( $node ) ? $node : $node['id'];
	}

	/**
	 * Rename a folder.
	 *
	 * @param int    $id   Folder ID.
	 * @param string $name New name.
	 * @return true|\WP_Error `folder_name_exists` on sibling collision.
	 */
	public function rename( int $id, string $name ): bool|\WP_Error {
		global $wpdb;

		$folder = $this->get( $id );
		if ( ! $folder ) {
			return $this->not_found();
		}
		$name = $this->sanitize_name( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_name', __( 'Folder name cannot be empty.', 'cph-filebird' ) );
		}
		if ( $name === $folder->name ) {
			return true;
		}
		if ( in_array( $name, $this->sibling_names( $folder->parent, $id ), true ) ) {
			return new \WP_Error( 'folder_name_exists', __( 'A folder with that name already exists here.', 'cph-filebird' ) );
		}

		$wpdb->update( $this->table(), array( 'name' => $name ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		Hooks::action( 'folder_renamed', $id, $name );

		return true;
	}

	/**
	 * Move a folder under a new parent. Auto-renames on a name collision there.
	 *
	 * @param int      $id     Folder ID.
	 * @param int      $parent New parent ID (0 = root).
	 * @param int|null $ord    New position; null appends (or keeps the current ord if the parent is unchanged).
	 * @return true|\WP_Error `invalid_parent` when moving into itself or a descendant.
	 */
	public function move( int $id, int $parent, ?int $ord = null ): bool|\WP_Error {
		global $wpdb;

		$folder = $this->get( $id );
		if ( ! $folder ) {
			return $this->not_found();
		}
		if ( $parent < 0 || $parent === $id || ( $parent > 0 && ! $this->exists( $parent ) ) || in_array( $parent, $this->descendant_ids( $id ), true ) ) {
			return new \WP_Error( 'invalid_parent', __( 'A folder cannot be moved into itself, one of its subfolders, or a missing folder.', 'cph-filebird' ) );
		}

		$changed_parent = $parent !== $folder->parent;
		if ( null === $ord ) {
			$ord = $changed_parent ? $this->next_ord( $parent ) : $folder->ord;
		}

		$data = array(
			'parent' => $parent,
			'ord'    => $ord,
		);
		$name = $folder->name;
		if ( $changed_parent ) {
			$name = $this->unique_name( $folder->name, $parent, $id );
			if ( $name !== $folder->name ) {
				$data['name'] = $name;
			}
		}

		$wpdb->update( $this->table(), $data, array( 'id' => $id ), null, array( '%d' ) );

		if ( $name !== $folder->name ) {
			Hooks::action( 'folder_renamed', $id, $name );
		}
		Hooks::action( 'folder_parent_updated', $id, $parent );
		Hooks::action( 'parent_updated', $id, $parent );
		$this->invalidate();

		return true;
	}

	/**
	 * Bulk reorder / reparent in one validated pass.
	 *
	 * @param array $items List of `[ id, parent, ord ]` (or `[ 'id' =>, 'parent' =>, 'ord' => ]`).
	 * @return true|\WP_Error
	 */
	public function reorder( array $items ): bool|\WP_Error {
		global $wpdb;

		$parents = array();
		foreach ( $this->all() as $row ) {
			$parents[ $row->id ] = $row->parent;
		}
		$original = $parents;

		$updates = array();
		foreach ( $items as $item ) {
			$item = (array) $item;
			$id     = (int) ( $item['id'] ?? $item[0] ?? 0 );
			$parent = (int) ( $item['parent'] ?? $item[1] ?? 0 );
			$ord    = (int) ( $item['ord'] ?? $item[2] ?? 0 );

			if ( ! isset( $parents[ $id ] ) ) {
				return $this->not_found();
			}
			if ( $parent < 0 || ( $parent > 0 && ! isset( $parents[ $parent ] ) ) ) {
				return new \WP_Error( 'invalid_parent', __( 'Parent folder does not exist.', 'cph-filebird' ) );
			}
			$parents[ $id ] = $parent;
			$updates[ $id ] = array( $parent, $ord );
		}

		if ( empty( $updates ) ) {
			return true;
		}

		// Every touched folder must still reach the root.
		foreach ( array_keys( $updates ) as $id ) {
			$seen = array();
			$cur  = $id;
			while ( 0 !== $cur ) {
				if ( isset( $seen[ $cur ] ) || ! isset( $parents[ $cur ] ) ) {
					return new \WP_Error( 'invalid_parent', __( 'That order would put a folder inside itself.', 'cph-filebird' ) );
				}
				$seen[ $cur ] = true;
				$cur          = $parents[ $cur ];
			}
		}

		$table       = $this->table();
		$parent_case = '';
		$ord_case    = '';
		$values_p    = array();
		$values_o    = array();
		foreach ( $updates as $id => [ $parent, $ord ] ) {
			$parent_case .= ' WHEN %d THEN %d';
			$ord_case    .= ' WHEN %d THEN %d';
			array_push( $values_p, $id, $parent );
			array_push( $values_o, $id, $ord );
		}
		$ids          = array_keys( $updates );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET parent = CASE id{$parent_case} END, ord = CASE id{$ord_case} END WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $values_p, $values_o, $ids )
			)
		);

		foreach ( $updates as $id => [ $parent ] ) {
			if ( $original[ $id ] !== $parent ) {
				Hooks::action( 'folder_parent_updated', $id, $parent );
				Hooks::action( 'parent_updated', $id, $parent );
			}
		}
		$this->invalidate();

		return true;
	}

	/**
	 * Delete a folder.
	 *
	 * @param int    $id   Folder ID.
	 * @param string $mode `subtree` deletes descendants and their assignments; `children-up` reparents
	 *                     direct children to this folder's parent and leaves its attachments uncategorized.
	 * @return true|\WP_Error
	 */
	public function delete( int $id, string $mode = 'subtree' ): bool|\WP_Error {
		global $wpdb;

		$folder = $this->get( $id );
		if ( ! $folder ) {
			return $this->not_found();
		}
		if ( ! in_array( $mode, array( 'subtree', 'children-up' ), true ) ) {
			return new \WP_Error( 'invalid_mode', __( 'Delete mode must be "subtree" or "children-up".', 'cph-filebird' ) );
		}

		$ids = array( $id );
		if ( 'subtree' === $mode ) {
			$ids = array_merge( $ids, $this->descendant_ids( $id ) );
		}
		foreach ( $ids as $check ) {
			if ( ! Hooks::filter( 'can_delete_folder', true, $check ) ) {
				return new \WP_Error( 'cannot_delete', __( 'This folder cannot be deleted.', 'cph-filebird' ) );
			}
		}

		if ( 'children-up' === $mode ) {
			$table    = $this->table();
			$children = $wpdb->get_results( $wpdb->prepare( "SELECT id, ord FROM {$table} WHERE parent = %d ORDER BY ord ASC, id ASC", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $children as $child ) {
				$moved = $this->move( (int) $child->id, $folder->parent, (int) $child->ord );
				if ( is_wp_error( $moved ) ) {
					return $moved;
				}
			}
		}

		$this->delete_rows( $ids );

		return true;
	}

	/**
	 * Delete every folder and every assignment.
	 *
	 * @return void
	 */
	public function delete_all(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}fbv_attachment_folder" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( self::COLORS_OPTION, array() );
		Hooks::action( 'delete_all' );
		$this->invalidate();
	}

	/**
	 * Duplicate a folder and its descendants (and colors). Attachments are not copied.
	 *
	 * The copy is named "Name (Copy)", then "Name (Copy) 1", "Name (Copy) 2"… like FileBird.
	 *
	 * @param int $id Folder ID.
	 * @return array|\WP_Error Node of the new root, with nested children.
	 */
	public function duplicate( int $id ): array|\WP_Error {
		$folder = $this->get( $id );
		if ( ! $folder ) {
			return $this->not_found();
		}

		$children = array();
		foreach ( $this->all() as $row ) {
			$children[ $row->parent ][] = $row;
		}

		$siblings = $this->sibling_names( $folder->parent );
		/* translators: Suffix appended to a duplicated folder's name. */
		$base = $folder->name . ' ' . __( '(Copy)', 'cph-filebird' );
		$name = $base;
		for ( $i = 1; in_array( $name, $siblings, true ); $i++ ) {
			$name = $base . ' ' . $i;
		}

		$colors  = get_option( self::COLORS_OPTION, array() );
		$colors  = is_array( $colors ) ? $colors : array();
		$new_ids = array();

		$copy = function ( object $source, string $name, int $parent, int $ord ) use ( &$copy, &$colors, &$new_ids, $children ) {
			$new_id = $this->insert( $name, $parent, $ord );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			$new_ids[] = $new_id;
			if ( isset( $colors[ $source->id ] ) ) {
				$colors[ $new_id ] = $colors[ $source->id ];
			}
			foreach ( $children[ $source->id ] ?? array() as $child ) {
				if ( in_array( $child->id, $new_ids, true ) ) {
					continue;
				}
				$res = $copy( $child, $child->name, $new_id, $child->ord );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			return $new_id;
		};

		$root = $copy( $folder, $name, $folder->parent, $this->next_ord( $folder->parent ) );
		update_option( self::COLORS_OPTION, $colors );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$tree = $this->tree();
		$node = $this->find_node( $tree, $root );
		return $node ?? $this->not_found();
	}

	/**
	 * All descendant folder IDs (children, grandchildren…) from one query.
	 *
	 * @param int $id Folder ID.
	 * @return int[]
	 */
	public function descendant_ids( int $id ): array {
		global $wpdb;

		$children = array();
		$rows     = $wpdb->get_results( "SELECT id, parent FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$children[ (int) $row->parent ][] = (int) $row->id;
		}

		$out   = array();
		$seen  = array( $id => true );
		$queue = $children[ $id ] ?? array();
		while ( $queue ) {
			$cur = array_shift( $queue );
			if ( isset( $seen[ $cur ] ) ) {
				continue;
			}
			$seen[ $cur ] = true;
			$out[]        = $cur;
			foreach ( $children[ $cur ] ?? array() as $child ) {
				$queue[] = $child;
			}
		}
		return $out;
	}

	/**
	 * Nested tree of nodes. With `search`, matching folders are returned as a flat list of roots.
	 *
	 * Folders whose parent no longer exists are shown at the root so they stay reachable.
	 *
	 * @param array $args Same as all().
	 * @return array[]
	 */
	public function tree( array $args = array() ): array {
		$rows = $this->all( $args );
		$nodes = array_map( array( $this, 'node' ), $rows );

		if ( '' !== trim( (string) ( $args['search'] ?? '' ) ) ) {
			return $nodes;
		}

		$groups = $this->group_by_parent( $nodes );
		$seen   = array();
		$build  = function ( int $parent ) use ( &$build, &$seen, $groups ): array {
			$out = array();
			foreach ( $groups[ $parent ] ?? array() as $node ) {
				if ( isset( $seen[ $node['id'] ] ) ) {
					continue;
				}
				$seen[ $node['id'] ] = true;
				$node['children']    = $build( $node['id'] );
				$out[]               = $node;
			}
			return $out;
		};

		$tree  = $build( 0 );
		$by_id = array_column( $nodes, null, 'id' );
		foreach ( $nodes as $node ) {
			if ( ! isset( $seen[ $node['id'] ] ) && ! isset( $by_id[ $node['parent'] ] ) ) {
				$seen[ $node['id'] ] = true;
				$node['children']    = $build( $node['id'] );
				$tree[]              = $node;
			}
		}

		return $tree;
	}

	/**
	 * Depth-first flat list of nodes, each with `depth` (0 = root) and empty `children`.
	 *
	 * @param array $args Same as all().
	 * @return array[]
	 */
	public function flat( array $args = array() ): array {
		$out  = array();
		$walk = function ( array $nodes, int $depth ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $node ) {
				$children         = $node['children'];
				$node['children'] = array();
				$node['depth']    = $depth;
				$out[]            = $node;
				$walk( $children, $depth + 1 );
			}
		};
		$walk( $this->tree( $args ), 0 );
		return $out;
	}

	/**
	 * Set (or clear, with '') a folder's color.
	 *
	 * @param int    $id  Folder ID.
	 * @param string $hex Hex color like `#ff0000`, or '' to clear.
	 * @return true|\WP_Error
	 */
	public function set_color( int $id, string $hex ): bool|\WP_Error {
		if ( ! $this->exists( $id ) ) {
			return $this->not_found();
		}
		$colors = $this->get_colors();
		if ( '' === $hex ) {
			unset( $colors[ $id ] );
		} else {
			$clean = sanitize_hex_color( $hex );
			if ( ! $clean ) {
				return new \WP_Error( 'invalid_color', __( 'Color must be a hex value like #ff0000.', 'cph-filebird' ) );
			}
			$colors[ $id ] = $clean;
		}
		update_option( self::COLORS_OPTION, $colors );
		return true;
	}

	/**
	 * All folder colors, `[ folder_id => hex ]`.
	 *
	 * @return array<int,string>
	 */
	public function get_colors(): array {
		$colors = get_option( self::COLORS_OPTION, array() );
		return is_array( $colors ) ? $colors : array();
	}

	/**
	 * Move `created_by` from one user to another (for `deleted_user`).
	 *
	 * @param int $from Old author ID.
	 * @param int $to   New author ID (0 = shared).
	 * @return void
	 */
	public function reassign_author( int $from, int $to ): void {
		global $wpdb;
		if ( $from <= 0 || $from === $to ) {
			return;
		}
		$wpdb->update( $this->table(), array( 'created_by' => max( 0, $to ) ), array( 'created_by' => $from ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Strip formula-leading characters and markup from a name, like FileBird.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public function sanitize_name( string $name ): string {
		return trim( sanitize_text_field( wp_unslash( wp_kses_post( Csv::sanitize_for_excel( trim( $name ) ) ) ) ) );
	}

	/**
	 * Build a node array from a row.
	 *
	 * @param object $row Folder row.
	 * @return array
	 */
	public function node( object $row ): array {
		$colors = $this->get_colors();
		$id     = (int) $row->id;
		$color  = isset( $colors[ $id ] ) ? (string) sanitize_hex_color( (string) $colors[ $id ] ) : '';
		return array(
			'id'       => $id,
			'name'     => (string) $row->name,
			'title'    => (string) $row->name,
			'parent'   => (int) $row->parent,
			'ord'      => (int) ( $row->ord ?? 0 ),
			'color'    => $color,
			'count'    => 0,
			'children' => array(),
		);
	}

	/**
	 * Find a node by ID anywhere in a nested tree.
	 *
	 * @param array $tree Nested nodes.
	 * @param int   $id   Folder ID.
	 * @return array|null
	 */
	public function find_node( array $tree, int $id ): ?array {
		foreach ( $tree as $node ) {
			if ( $node['id'] === $id ) {
				return $node;
			}
			$found = $this->find_node( $node['children'], $id );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Insert a row and fire the created hooks.
	 *
	 * @param string $name   Clean name.
	 * @param int    $parent Parent ID.
	 * @param int    $ord    Position.
	 * @return int|\WP_Error New ID.
	 */
	private function insert( string $name, int $parent, int $ord ): int|\WP_Error {
		global $wpdb;

		$created_by = (int) Hooks::filter( 'folder_created_by', 0 );
		$inserted   = $wpdb->insert(
			$this->table(),
			array(
				'name'       => $name,
				'parent'     => $parent,
				'type'       => 0,
				'ord'        => $ord,
				'created_by' => $created_by,
			),
			array( '%s', '%d', '%d', '%d', '%d' )
		);
		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', __( 'Could not create the folder.', 'cph-filebird' ) );
		}

		$id   = (int) $wpdb->insert_id;
		$node = $this->node(
			(object) array(
				'id'     => $id,
				'name'   => $name,
				'parent' => $parent,
				'ord'    => $ord,
			)
		);
		// FileBird's created payload keys, kept for legacy listeners.
		$payload = $node + array(
			'key'        => $id,
			'type'       => 0,
			'data-count' => 0,
			'data-id'    => $id,
		);
		Hooks::action( 'folder_created', $id, $payload );
		$this->invalidate();

		return $id;
	}

	/**
	 * Delete folder rows, their assignments and colors, then fire hooks.
	 *
	 * @param int[] $ids Folder IDs.
	 * @return void
	 */
	private function delete_rows( array $ids ): void {
		global $wpdb;

		$ids          = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}fbv_attachment_folder WHERE folder_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// FileBird drops these from a by-value copy and never saves; persist the cleanup.
		$colors = $this->get_colors();
		$before = count( $colors );
		foreach ( $ids as $id ) {
			unset( $colors[ $id ] );
		}
		if ( count( $colors ) !== $before ) {
			update_option( self::COLORS_OPTION, $colors );
		}

		foreach ( $ids as $id ) {
			Hooks::action( 'folder_deleted', $id );
		}
		$this->invalidate();
	}

	/**
	 * Next `ord` among a parent's children.
	 *
	 * @param int $parent Parent ID.
	 * @return int
	 */
	private function next_ord( int $parent ): int {
		global $wpdb;
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(ord) FROM {$this->table()} WHERE parent = %d", $parent ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $max ? 0 : (int) $max + 1;
	}

	/**
	 * Names of a parent's children.
	 *
	 * @param int $parent  Parent ID.
	 * @param int $exclude Folder ID to leave out.
	 * @return string[]
	 */
	private function sibling_names( int $parent, int $exclude = 0 ): array {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT name FROM {$this->table()} WHERE parent = %d AND id != %d", $parent, $exclude ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * `$name`, or `$name (1)`, `$name (2)`… whichever is free under `$parent`.
	 *
	 * @param string $name    Clean name.
	 * @param int    $parent  Parent ID.
	 * @param int    $exclude Folder ID to ignore.
	 * @return string
	 */
	private function unique_name( string $name, int $parent, int $exclude = 0 ): string {
		$taken = array_flip( $this->sibling_names( $parent, $exclude ) );
		if ( ! isset( $taken[ $name ] ) ) {
			return $name;
		}
		$i = 1;
		while ( isset( $taken[ "{$name} ({$i})" ] ) ) {
			++$i;
		}
		return "{$name} ({$i})";
	}

	/**
	 * Group nodes by parent ID.
	 *
	 * @param array[] $nodes Nodes.
	 * @return array<int,array[]>
	 */
	private function group_by_parent( array $nodes ): array {
		$groups = array();
		foreach ( $nodes as $node ) {
			$groups[ $node['parent'] ][] = $node;
		}
		return $groups;
	}

	/**
	 * Cast a DB row's numeric fields.
	 *
	 * @param object $row Raw row.
	 * @return object
	 */
	private function cast_row( object $row ): object {
		return (object) array(
			'id'         => (int) $row->id,
			'name'       => (string) $row->name,
			'parent'     => (int) $row->parent,
			'type'       => (int) $row->type,
			'ord'        => (int) $row->ord,
			'created_by' => (int) $row->created_by,
		);
	}

	/**
	 * Standard not-found error.
	 *
	 * @return \WP_Error
	 */
	private function not_found(): \WP_Error {
		return new \WP_Error( 'folder_not_found', __( 'Folder not found.', 'cph-filebird' ), array( 'status' => 404 ) );
	}

	/**
	 * Drop cached counts.
	 *
	 * @return void
	 */
	private function invalidate(): void {
		Assignment::get_instance()->invalidate_counts();
	}
}
