/**
 * The folder sidebar: header, search, pinned rows, tree, drag and drop.
 */
import {
	DndContext,
	DragOverlay,
	PointerSensor,
	pointerWithin,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	useSyncExternalStore,
} from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import * as store from '../store';
import {
	ALL,
	UNCATEGORIZED,
	childrenOf,
	filterTree,
	findNode,
	flatten,
	indexTree,
	isDescendant,
	removeNode,
	sortTree,
} from '../tree';
import DeleteDialog from './DeleteDialog';
import Menu from './Menu';
import ResizeHandle from './ResizeHandle';
import TreeRow from './TreeRow';
import {
	AllIcon,
	CloseIcon,
	FolderIcon,
	InboxIcon,
	PanelIcon,
	PlusIcon,
	SearchIcon,
	SortIcon,
} from './icons';

const data = window.cphfbData || {};
let instances = 0;

const useStore = () => useSyncExternalStore( store.subscribe, store.getState );

const SORTS = () => [
	{ key: 'custom', label: __( 'Custom order', 'cph-filebird' ) },
	{ key: 'name-asc', label: __( 'Name A → Z', 'cph-filebird' ) },
	{ key: 'name-desc', label: __( 'Name Z → A', 'cph-filebird' ) },
];

function PinnedRow( { id, label, count, icon, selected, focused, domId, onSelect, onFocusRow, posinset } ) {
	const ref = useRef();
	useEffect( () => {
		if ( focused && ref.current?.ownerDocument.activeElement?.closest?.( '.cphfb-tree' ) ) {
			ref.current.focus( { preventScroll: true } );
		}
	}, [ focused ] );
	return (
		<div
			ref={ ref }
			id={ domId }
			role="treeitem"
			aria-level={ 1 }
			aria-posinset={ posinset }
			aria-setsize={ 2 }
			aria-selected={ selected }
			aria-label={ sprintf(
				/* translators: 1: label, 2: number of files */
				_n( '%1$s, %2$s file', '%1$s, %2$s files', count || 0, 'cph-filebird' ),
				label,
				( count || 0 ).toLocaleString()
			) }
			tabIndex={ focused ? 0 : -1 }
			className={ 'cphfb-row cphfb-row--pinned' + ( selected ? ' is-selected' : '' ) }
			data-folder-id={ id }
			onClick={ () => {
				onFocusRow( id );
				onSelect( id );
			} }
		>
			{ icon }
			<span className="cphfb-row__name">{ label }</span>
			<span className="cphfb-count">
				{ typeof count === 'number' ? count.toLocaleString() : '' }
			</span>
		</div>
	);
}

export default function Sidebar( { layout = false, onWidth, onRail, initialWidth, initialRail = false } ) {
	const state = useStore();
	const { tree, selected, collapsed, sort, editing, counts, notice } = state;
	const canManage = !! data.canManage;
	const uid = useMemo( () => 'cphfb-' + ++instances, [] );
	const treeRef = useRef();

	const [ search, setSearch ] = useState( '' );
	const [ focusedId, setFocusedId ] = useState( selected );
	const [ menu, setMenu ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );
	const [ rail, setRail ] = useState( initialRail );
	const [ drag, setDrag ] = useState( null ); // { id, over: { id, position } }

	const searching = search.trim() !== '';
	const view = useMemo( () => filterTree( sortTree( tree, sort ), search ), [ tree, sort, search ] );
	const rows = useMemo( () => flatten( view, collapsed, searching ), [ view, collapsed, searching ] );
	const index = useMemo( () => indexTree( tree ), [ tree ] );
	const order = useMemo( () => [ ALL, UNCATEGORIZED, ...rows.map( ( r ) => r.node.id ) ], [ rows ] );
	const rowById = useMemo( () => new Map( rows.map( ( r ) => [ r.node.id, r ] ) ), [ rows ] );

	// Keep the roving focus on something that exists.
	useEffect( () => {
		if ( ! order.includes( focusedId ) ) {
			setFocusedId( order.includes( selected ) ? selected : ALL );
		}
	}, [ order, focusedId, selected ] );

	useEffect( () => {
		if ( editing ) {
			setFocusedId( editing.id );
		}
	}, [ editing ] );

	const domId = ( id ) => `${ uid }-item-${ id < 0 ? 'all' : id }`;

	/* Actions -------------------------------------------------------------- */

	const select = useCallback( ( id ) => store.selectFolder( id ), [] );
	const toggle = useCallback( ( id, open ) => store.setExpanded( id, open ), [] );
	const startRename = useCallback( ( id ) => canManage && store.setEditing( { id } ), [ canManage ] );
	const cancelRename = useCallback( () => {
		store.setEditing( null );
		focusTree();
	}, [] );
	const rename = useCallback( async ( id, name ) => {
		const result = await store.renameFolder( id, name );
		if ( result === true ) {
			store.setEditing( null );
			focusTree();
		}
		return result;
	}, [] );
	const openMenu = useCallback( ( id, anchor ) => setMenu( { id, anchor } ), [] );

	function focusTree() {
		window.requestAnimationFrame( () => {
			treeRef.current?.querySelector( '[role="treeitem"][tabindex="0"]' )?.focus();
		} );
	}

	const newFolder = async ( parent ) => {
		setSearch( '' );
		const node = await store.createFolder( parent );
		if ( node ) {
			setFocusedId( node.id );
		}
	};

	const parentForNew = selected > 0 ? selected : 0;

	/* Keyboard (WAI-ARIA tree pattern) -------------------------------------- */

	const onTreeKeyDown = ( event ) => {
		if ( editing || event.target.tagName === 'INPUT' ) {
			return;
		}
		const i = order.indexOf( focusedId );
		const row = rowById.get( focusedId );
		const go = ( id ) => {
			event.preventDefault();
			if ( id !== undefined ) {
				setFocusedId( id );
			}
		};
		switch ( event.key ) {
			case 'ArrowDown':
				return go( order[ Math.min( i + 1, order.length - 1 ) ] );
			case 'ArrowUp':
				return go( order[ Math.max( i - 1, 0 ) ] );
			case 'Home':
				return go( order[ 0 ] );
			case 'End':
				return go( order[ order.length - 1 ] );
			case 'ArrowRight':
				if ( ! row?.hasChildren ) {
					return;
				}
				if ( ! row.expanded ) {
					event.preventDefault();
					return toggle( row.node.id, true );
				}
				return go( row.node.children[ 0 ]?.id );
			case 'ArrowLeft':
				if ( row?.expanded && ! searching ) {
					event.preventDefault();
					return toggle( row.node.id, false );
				}
				return row?.parent ? go( row.parent ) : undefined;
			case 'Enter':
			case ' ':
				event.preventDefault();
				return select( focusedId );
			case 'F2':
				if ( focusedId > 0 ) {
					event.preventDefault();
					startRename( focusedId );
				}
				return;
			case 'Delete':
				if ( focusedId > 0 && canManage ) {
					event.preventDefault();
					setDeleting( focusedId );
				}
				return;
			case 'ContextMenu':
			case 'F10':
				if ( ( event.key === 'ContextMenu' || event.shiftKey ) && focusedId > 0 && canManage ) {
					event.preventDefault();
					const el = document.getElementById( domId( focusedId ) );
					const rect = el.getBoundingClientRect();
					openMenu( focusedId, { x: rect.left + 24, y: rect.bottom } );
				}
				return;
			case '*': {
				// Expand all siblings of the focused row.
				const siblings = row ? childrenOf( tree, row.parent ) : tree;
				siblings.forEach( ( s ) => s.children.length && toggle( s.id, true ) );
				return go();
			}
			default:
				// Type-ahead: jump to the next folder starting with this letter.
				if ( event.key.length === 1 && /\S/.test( event.key ) && ! event.ctrlKey && ! event.metaKey && ! event.altKey ) {
					const ch = event.key.toLocaleLowerCase();
					const names = order.map( ( id ) => ( id > 0 ? rowById.get( id ).node.name : store.folderLabel( id ) ).toLocaleLowerCase() );
					for ( let n = 1; n <= order.length; n++ ) {
						const j = ( i + n ) % order.length;
						if ( names[ j ].startsWith( ch ) ) {
							return go( order[ j ] );
						}
					}
				}
		}
	};

	/* Drag and drop -------------------------------------------------------- */

	const sensors = useSensors( useSensor( PointerSensor, { activationConstraint: { distance: 6 } } ) );
	const hoverTimer = useRef( null );
	const pointerY = useRef( 0 );

	useEffect( () => {
		const track = ( event ) => {
			pointerY.current = event.clientY;
		};
		window.addEventListener( 'pointermove', track, { passive: true } );
		return () => window.removeEventListener( 'pointermove', track );
	}, [] );

	const dropTarget = ( activeId, over ) => {
		if ( ! over || over.data.current?.type !== 'folder' ) {
			return null;
		}
		const overId = over.data.current.id;
		if ( overId === activeId || isDescendant( index, overId, activeId ) ) {
			return null;
		}
		const rect = over.rect;
		const rel = ( pointerY.current - rect.top ) / rect.height;
		let position = 'inside';
		if ( sort === 'custom' && ! searching ) {
			if ( rel < 0.28 ) {
				position = 'before';
			} else if ( rel > 0.72 ) {
				position = 'after';
			}
		}
		return { id: overId, position };
	};

	const onDragStart = ( event ) => {
		setMenu( null );
		setDrag( { id: event.active.data.current.id, over: null } );
	};

	const onDragMove = ( event ) => {
		const activeId = event.active.data.current.id;
		const over = dropTarget( activeId, event.over );
		setDrag( ( d ) =>
			d && ( d.over?.id !== over?.id || d.over?.position !== over?.position ) ? { ...d, over } : d
		);
		// Auto-expand a collapsed folder after hovering "inside" it.
		const row = over && rowById.get( over.id );
		const key = over && over.position === 'inside' && row?.hasChildren && ! row.expanded ? over.id : null;
		if ( hoverTimer.current?.key !== key ) {
			clearTimeout( hoverTimer.current?.timer );
			hoverTimer.current = key
				? { key, timer: setTimeout( () => toggle( key, true ), 650 ) }
				: null;
		}
	};

	const endDrag = () => {
		clearTimeout( hoverTimer.current?.timer );
		hoverTimer.current = null;
		setDrag( null );
	};

	const onDragEnd = ( event ) => {
		const activeId = event.active.data.current.id;
		const target = dropTarget( activeId, event.over );
		endDrag();
		if ( ! target ) {
			return;
		}
		const overRow = rowById.get( target.id );
		let parent;
		let position;
		if ( target.position === 'inside' ) {
			parent = target.id;
			position = Infinity;
			if ( sort !== 'custom' && overRow ) {
				position = Infinity;
			}
		} else if ( target.position === 'after' && overRow?.expanded ) {
			parent = target.id;
			position = 0;
		} else {
			parent = index.get( target.id )?.parent || 0;
			const siblings = childrenOf( removeNode( tree, activeId ), parent );
			position = siblings.findIndex( ( s ) => s.id === target.id ) + ( target.position === 'after' ? 1 : 0 );
		}
		const current = index.get( activeId );
		if ( parent === current?.parent && target.position === 'inside' ) {
			return;
		}
		store.moveFolder( activeId, parent, position );
	};

	const activeNode = drag ? findNode( tree, drag.id ) : null;

	/* Menus ---------------------------------------------------------------- */

	const menuNode = menu ? findNode( tree, menu.id ) : null;
	const closeMenu = useCallback( ( refocus ) => {
		setMenu( null );
		if ( refocus ) {
			focusTree();
		}
	}, [] );

	const menuItems = menuNode
		? [
				{ key: 'new', label: __( 'New subfolder', 'cph-filebird' ), onSelect: () => newFolder( menuNode.id ) },
				{ key: 'rename', label: __( 'Rename', 'cph-filebird' ), shortcut: 'F2', onSelect: () => startRename( menuNode.id ) },
				{ key: 'duplicate', label: __( 'Duplicate', 'cph-filebird' ), onSelect: () => store.duplicateFolder( menuNode.id ) },
				{ type: 'separator' },
				{ type: 'colors', value: menuNode.color, onSelect: ( color ) => store.setColor( menuNode.id, color ) },
				{ type: 'separator' },
				{ key: 'delete', label: __( 'Delete…', 'cph-filebird' ), danger: true, shortcut: 'Del', onSelect: () => setDeleting( menuNode.id ) },
		  ]
		: [];

	const [ sortMenu, setSortMenu ] = useState( null );
	const deletingNode = deleting ? findNode( tree, deleting ) : null;

	/* Layout --------------------------------------------------------------- */

	const toggleRail = () => {
		const next = ! rail;
		setRail( next );
		onRail?.( next );
	};

	if ( rail ) {
		return (
			<div className="cphfb-sidebar is-rail">
				<button
					type="button"
					className="cphfb-icon-button"
					aria-label={ __( 'Show folders', 'cph-filebird' ) }
					title={ __( 'Show folders', 'cph-filebird' ) }
					aria-expanded="false"
					onClick={ toggleRail }
				>
					<PanelIcon collapsed />
				</button>
				<span className="cphfb-rail__current" title={ store.folderLabel( selected ) }>
					<FolderIcon size={ 18 } color={ findNode( tree, selected )?.color } />
				</span>
			</div>
		);
	}

	const treeEmpty = ! tree.length;
	const noMatches = searching && ! rows.length;

	return (
		<div className="cphfb-sidebar">
			<div className="cphfb-header">
				<h2 className="cphfb-header__title" id={ uid + '-title' }>
					{ __( 'Folders', 'cph-filebird' ) }
				</h2>
				<div className="cphfb-header__actions">
					{ canManage && (
						<button
							type="button"
							className="cphfb-new"
							onClick={ () => newFolder( parentForNew ) }
							title={
								parentForNew
									? sprintf( /* translators: %s: folder name */ __( 'New folder inside %s', 'cph-filebird' ), store.folderLabel( parentForNew ) )
									: __( 'New folder', 'cph-filebird' )
							}
						>
							<PlusIcon />
							<span>{ __( 'New folder', 'cph-filebird' ) }</span>
						</button>
					) }
					{ layout && (
						<button
							type="button"
							className="cphfb-icon-button"
							aria-label={ __( 'Hide folders', 'cph-filebird' ) }
							title={ __( 'Hide folders', 'cph-filebird' ) }
							aria-expanded="true"
							onClick={ toggleRail }
						>
							<PanelIcon />
						</button>
					) }
				</div>
			</div>

			<div className="cphfb-toolbar">
				<div className="cphfb-search">
					<SearchIcon />
					<input
						type="search"
						className="cphfb-search__input"
						placeholder={ __( 'Search folders', 'cph-filebird' ) }
						aria-label={ __( 'Search folders', 'cph-filebird' ) }
						value={ search }
						onChange={ ( event ) => setSearch( event.target.value ) }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Escape' && search ) {
								event.preventDefault();
								setSearch( '' );
							} else if ( event.key === 'ArrowDown' ) {
								event.preventDefault();
								setFocusedId( rows[ 0 ]?.node.id ?? ALL );
								focusTree();
							}
						} }
					/>
					{ search && (
						<button
							type="button"
							className="cphfb-search__clear"
							aria-label={ __( 'Clear search', 'cph-filebird' ) }
							onClick={ () => setSearch( '' ) }
						>
							<CloseIcon />
						</button>
					) }
				</div>
				<button
					type="button"
					className={ 'cphfb-icon-button' + ( sort !== 'custom' ? ' is-active' : '' ) }
					aria-label={ __( 'Sort folders', 'cph-filebird' ) }
					title={ __( 'Sort folders', 'cph-filebird' ) }
					aria-haspopup="menu"
					aria-expanded={ !! sortMenu }
					onClick={ ( event ) => {
						const rect = event.currentTarget.getBoundingClientRect();
						setSortMenu( { x: rect.left, y: rect.bottom + 4 } );
					} }
				>
					<SortIcon />
				</button>
			</div>

			<DndContext
				sensors={ sensors }
				collisionDetection={ pointerWithin }
				onDragStart={ onDragStart }
				onDragMove={ onDragMove }
				onDragOver={ onDragMove }
				onDragEnd={ onDragEnd }
				onDragCancel={ endDrag }
				accessibility={ { restoreFocus: false } }
			>
				<div
					ref={ treeRef }
					className={ 'cphfb-tree' + ( drag ? ' is-dragging' : '' ) }
					role="tree"
					aria-labelledby={ uid + '-title' }
					aria-activedescendant={ undefined }
					onKeyDown={ onTreeKeyDown }
				>
					<div role="none" className="cphfb-tree__pinned">
						<PinnedRow
							id={ ALL }
							posinset={ 1 }
							domId={ domId( ALL ) }
							label={ __( 'All files', 'cph-filebird' ) }
							count={ counts?.all }
							icon={ <AllIcon /> }
							selected={ selected === ALL }
							focused={ focusedId === ALL }
							onSelect={ select }
							onFocusRow={ setFocusedId }
						/>
						<PinnedRow
							id={ UNCATEGORIZED }
							posinset={ 2 }
							domId={ domId( UNCATEGORIZED ) }
							label={ __( 'Uncategorized', 'cph-filebird' ) }
							count={ counts?.uncategorized }
							icon={ <InboxIcon /> }
							selected={ selected === UNCATEGORIZED }
							focused={ focusedId === UNCATEGORIZED }
							onSelect={ select }
							onFocusRow={ setFocusedId }
						/>
					</div>
					<div role="none" className="cphfb-tree__divider" />
					<div role="none" className="cphfb-tree__folders">
						{ rows.map( ( row ) => (
							<TreeRow
								key={ row.node.id }
								row={ row }
								domId={ domId( row.node.id ) }
								selected={ selected === row.node.id }
								focused={ focusedId === row.node.id }
								editing={ editing?.id === row.node.id }
								drop={ drag?.over?.id === row.node.id ? drag.over.position : null }
								dragging={ drag?.id === row.node.id }
								draggable={ canManage }
								canManage={ canManage }
								onSelect={ select }
								onToggle={ toggle }
								onMenu={ openMenu }
								onStartRename={ startRename }
								onRename={ rename }
								onCancelRename={ cancelRename }
								onFocusRow={ setFocusedId }
							/>
						) ) }
					</div>
					{ treeEmpty && (
						<div className="cphfb-empty" role="none">
							<p>{ __( 'No folders yet.', 'cph-filebird' ) }</p>
							{ canManage && (
								<button type="button" className="button" onClick={ () => newFolder( 0 ) }>
									{ __( 'Create your first folder', 'cph-filebird' ) }
								</button>
							) }
						</div>
					) }
					{ noMatches && (
						<div className="cphfb-empty" role="none">
							<p>{ sprintf( /* translators: %s: search term */ __( 'No folders match “%s”.', 'cph-filebird' ), search.trim() ) }</p>
						</div>
					) }
				</div>
				<DragOverlay dropAnimation={ null }>
					{ activeNode && (
						<div className="cphfb-drag-preview">
							<FolderIcon size={ 18 } color={ activeNode.color } />
							<span>{ activeNode.name }</span>
						</div>
					) }
				</DragOverlay>
			</DndContext>

			{ sort !== 'custom' && canManage && (
				<p className="cphfb-hint">{ __( 'Sorted by name. Switch to custom order to rearrange folders.', 'cph-filebird' ) }</p>
			) }

			<div className="cphfb-notice-region" aria-live="polite">
				{ notice && (
					<div className={ 'cphfb-notice is-' + notice.type }>
						<span>{ notice.message }</span>
						<button type="button" aria-label={ __( 'Dismiss', 'cph-filebird' ) } onClick={ () => store.notify( null ) }>
							<CloseIcon />
						</button>
					</div>
				) }
			</div>

			{ layout && <ResizeHandle initialWidth={ initialWidth } onWidth={ onWidth } /> }

			{ menuNode && (
				<Menu
					anchor={ menu.anchor }
					items={ menuItems }
					label={ sprintf( /* translators: %s: folder name */ __( 'Actions for %s', 'cph-filebird' ), menuNode.name ) }
					onClose={ closeMenu }
				/>
			) }
			{ sortMenu && (
				<Menu
					anchor={ sortMenu }
					label={ __( 'Sort folders', 'cph-filebird' ) }
					items={ SORTS().map( ( s ) => ( {
						...s,
						checked: sort === s.key,
						onSelect: () => store.setSort( s.key ),
					} ) ) }
					onClose={ () => setSortMenu( null ) }
				/>
			) }
			{ deletingNode && (
				<DeleteDialog
					node={ deletingNode }
					onConfirm={ ( mode ) => store.deleteFolder( deletingNode.id, mode ) }
					onClose={ () => {
						setDeleting( null );
						focusTree();
					} }
				/>
			) }
		</div>
	);
}
