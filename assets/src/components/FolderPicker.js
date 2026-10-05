/**
 * "Move to folder…" popover: search + listbox of folders. Opened imperatively
 * with openFolderPicker(); renders into its own root on <body>.
 */
import {
	createRoot,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
	useSyncExternalStore,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import * as store from '../store';
import { UNCATEGORIZED, filterTree, flatten, sortTree } from '../tree';
import { FolderIcon, InboxIcon, SearchIcon } from './icons';

let uid = 0;

function FolderPicker( { anchor, count, current, onPick, onClose } ) {
	const { tree, sort } = useSyncExternalStore( store.subscribe, store.getState );
	const ref = useRef();
	const listRef = useRef();
	const id = useMemo( () => 'cphfb-picker-' + ++uid, [] );
	const [ search, setSearch ] = useState( '' );
	// null = "not moved yet": the first folder the files could move to.
	const [ picked, setActive ] = useState( null );
	const [ pos, setPos ] = useState( null );

	const options = useMemo( () => {
		const rows = flatten( filterTree( sortTree( tree, sort ), search ), new Set(), true ).map( ( row ) => ( {
			id: row.node.id,
			name: row.node.name,
			depth: search.trim() ? 0 : row.depth,
			color: row.node.color,
		} ) );
		const uncategorized = { id: UNCATEGORIZED, name: __( 'Uncategorized', 'cph-filebirb' ), depth: 0, pinned: true };
		const showUnc = ! search.trim() || uncategorized.name.toLocaleLowerCase().includes( search.trim().toLocaleLowerCase() );
		return showUnc ? [ uncategorized, ...rows ] : rows;
	}, [ tree, sort, search ] );

	// Derived during render, never in an effect: Enter right after typing must
	// act on the list the user sees, not on an index from the previous list.
	const active =
		picked === null
			? Math.max(
					0,
					options.findIndex( ( option ) => option.id !== current )
			  )
			: Math.min( picked, options.length - 1 );

	useLayoutEffect( () => {
		const rect = ref.current.getBoundingClientRect();
		const left = Math.max( 8, Math.min( anchor.x, window.innerWidth - rect.width - 8 ) );
		const below = anchor.y + rect.height <= window.innerHeight - 8;
		setPos( { left, top: below ? anchor.y : Math.max( 8, anchor.top - rect.height - 4 ) } );
	}, [ anchor ] );

	// Focus once positioned (a hidden element cannot take focus).
	const placed = !! pos;
	useEffect( () => {
		if ( placed ) {
			ref.current.querySelector( 'input' )?.focus();
		}
	}, [ placed ] );

	useEffect( () => {
		const onDown = ( event ) => ref.current && ! ref.current.contains( event.target ) && onClose();
		document.addEventListener( 'pointerdown', onDown, true );
		window.addEventListener( 'resize', onClose );
		return () => {
			document.removeEventListener( 'pointerdown', onDown, true );
			window.removeEventListener( 'resize', onClose );
		};
	}, [ onClose ] );

	useEffect( () => {
		listRef.current?.querySelector( '[aria-selected="true"]' )?.scrollIntoView( { block: 'nearest' } );
	}, [ active ] );

	const choose = ( option ) => {
		if ( option && option.id !== current ) {
			onClose( true );
			onPick( option.id );
		}
	};

	const onKeyDown = ( event ) => {
		switch ( event.key ) {
			case 'ArrowDown':
				event.preventDefault();
				return setActive( Math.min( active + 1, options.length - 1 ) );
			case 'ArrowUp':
				event.preventDefault();
				return setActive( Math.max( active - 1, 0 ) );
			case 'PageDown':
				event.preventDefault();
				return setActive( Math.min( active + 8, options.length - 1 ) );
			case 'PageUp':
				event.preventDefault();
				return setActive( Math.max( active - 8, 0 ) );
			case 'Home':
				if ( ! search ) {
					event.preventDefault();
					setActive( 0 );
				}
				return;
			case 'End':
				if ( ! search ) {
					event.preventDefault();
					setActive( options.length - 1 );
				}
				return;
			case 'Enter':
				event.preventDefault();
				return choose( options[ active ] );
			case 'Escape':
				event.preventDefault();
				event.stopPropagation();
				return onClose( true );
			case 'Tab':
				event.preventDefault();
				return onClose( true );
		}
	};

	const title = sprintf(
		/* translators: %s: number of files */
		_n( 'Move %s file to', 'Move %s files to', count, 'cph-filebirb' ),
		count.toLocaleString()
	);

	return (
		<div
			ref={ ref }
			className="cphfb-picker"
			role="dialog"
			aria-label={ title }
			style={ pos ? { left: pos.left, top: pos.top } : { visibility: 'hidden', left: 0, top: 0 } }
			onKeyDown={ onKeyDown }
		>
			<div className="cphfb-picker__title" aria-hidden="true">
				{ title }
			</div>
			<div className="cphfb-search">
				<SearchIcon />
				<input
					type="search"
					className="cphfb-search__input"
					placeholder={ __( 'Search folders', 'cph-filebirb' ) }
					aria-label={ __( 'Search folders', 'cph-filebirb' ) }
					role="combobox"
					aria-expanded="true"
					aria-controls={ id + '-list' }
					aria-activedescendant={ options[ active ] ? `${ id }-${ options[ active ].id }` : undefined }
					aria-autocomplete="list"
					value={ search }
					onChange={ ( event ) => {
						setSearch( event.target.value );
						setActive( null );
					} }
				/>
			</div>
			<div ref={ listRef } id={ id + '-list' } className="cphfb-picker__list" role="listbox" aria-label={ __( 'Folders', 'cph-filebirb' ) }>
				{ options.map( ( option, i ) => (
					<div
						key={ option.id }
						id={ `${ id }-${ option.id }` }
						role="option"
						aria-selected={ i === active }
						aria-disabled={ option.id === current || undefined }
						className={
							'cphfb-picker__option' +
							( i === active ? ' is-active' : '' ) +
							( option.id === current ? ' is-current' : '' )
						}
						style={ { '--cphfb-depth': option.depth } }
						onPointerMove={ () => setActive( i ) }
						onClick={ () => choose( option ) }
					>
						{ option.pinned ? <InboxIcon /> : <FolderIcon size={ 18 } color={ option.color } /> }
						<span className="cphfb-row__name">{ option.name }</span>
						{ option.id === current && <span className="cphfb-picker__here">{ __( 'Current', 'cph-filebirb' ) }</span> }
					</div>
				) ) }
				{ ! options.length && <p className="cphfb-picker__empty">{ __( 'No folders found.', 'cph-filebirb' ) }</p> }
			</div>
		</div>
	);
}

let open = null;

/**
 * Open the picker under an element.
 *
 * @param {Object}   options         Options.
 * @param {Element}  options.anchor  Trigger element (focus returns to it).
 * @param {number}   options.count   Number of files being moved.
 * @param {number}   options.current Folder to mark as current (all items already there).
 * @param {Function} options.onPick  Called with the folder id.
 */
export function openFolderPicker( { anchor, count, current = null, onPick } ) {
	closeFolderPicker();
	const rect = anchor.getBoundingClientRect();
	const container = document.createElement( 'div' );
	container.className = 'cphfb-root cphfb-picker-root';
	document.body.appendChild( container );
	const root = createRoot( container );
	const close = ( refocus ) => {
		if ( open?.root !== root ) {
			return;
		}
		open = null;
		// Unmount after the current event finishes.
		setTimeout( () => {
			root.unmount();
			container.remove();
		} );
		anchor.setAttribute( 'aria-expanded', 'false' );
		if ( refocus && anchor.isConnected ) {
			anchor.focus();
		}
	};
	open = { root, close };
	anchor.setAttribute( 'aria-expanded', 'true' );
	root.render(
		<FolderPicker
			anchor={ { x: rect.left, y: rect.bottom + 4, top: rect.top } }
			count={ count }
			current={ current }
			onPick={ onPick }
			onClose={ close }
		/>
	);
}

export function closeFolderPicker() {
	open?.close( false );
}
