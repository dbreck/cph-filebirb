/**
 * One row in the folder tree (a WAI-ARIA `treeitem` in a flat list with aria-level).
 */
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { memo, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import { ChevronIcon, FolderIcon, MoreIcon } from './icons';

const formatCount = ( n ) => ( typeof n === 'number' ? n.toLocaleString() : '' );

function RenameField( { initial, onSubmit, onCancel } ) {
	const ref = useRef();
	const [ value, setValue ] = useState( initial );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const done = useRef( false );

	useEffect( () => {
		ref.current?.focus();
		ref.current?.select();
	}, [] );

	const submit = async () => {
		if ( busy || done.current ) {
			return;
		}
		setBusy( true );
		const result = await onSubmit( value );
		setBusy( false );
		if ( result === true ) {
			done.current = true;
		} else {
			setError( result );
			ref.current?.focus();
		}
	};

	return (
		<span className="cphfb-rename">
			<input
				ref={ ref }
				className={ 'cphfb-rename__input' + ( error ? ' has-error' : '' ) }
				value={ value }
				maxLength={ 250 }
				aria-label={ __( 'Folder name', 'cph-filebirb' ) }
				aria-invalid={ !! error }
				aria-describedby={ error ? 'cphfb-rename-error' : undefined }
				disabled={ busy }
				onChange={ ( event ) => {
					setValue( event.target.value );
					setError( '' );
				} }
				onKeyDown={ ( event ) => {
					event.stopPropagation();
					if ( event.key === 'Enter' ) {
						event.preventDefault();
						submit();
					} else if ( event.key === 'Escape' ) {
						event.preventDefault();
						done.current = true;
						onCancel();
					}
				} }
				onBlur={ () => {
					if ( ! done.current && ! error ) {
						submit();
					}
				} }
				onClick={ ( event ) => event.stopPropagation() }
				onDoubleClick={ ( event ) => event.stopPropagation() }
				onPointerDown={ ( event ) => event.stopPropagation() }
			/>
			{ error && (
				<span id="cphfb-rename-error" className="cphfb-rename__error" role="alert">
					{ error }
				</span>
			) }
		</span>
	);
}

function TreeRow( {
	row,
	domId,
	selected,
	focused,
	editing,
	drop,
	dragging,
	draggable,
	canManage,
	onSelect,
	onToggle,
	onMenu,
	onStartRename,
	onRename,
	onCancelRename,
	onFocusRow,
} ) {
	const { node, depth, posinset, setsize, hasChildren, expanded } = row;
	const ref = useRef();

	const drag = useDraggable( {
		id: 'folder-' + node.id,
		data: { type: 'folder', id: node.id },
		disabled: ! draggable || !! editing,
	} );
	const dropZone = useDroppable( {
		id: 'folder-' + node.id,
		data: { type: 'folder', id: node.id },
		disabled: ! canManage,
	} );

	useEffect( () => {
		if ( focused && ! editing && ref.current && ref.current.ownerDocument.activeElement?.closest?.( '.cphfb-tree' ) ) {
			ref.current.focus( { preventScroll: true } );
			ref.current.scrollIntoView( { block: 'nearest' } );
		}
	}, [ focused, editing ] );

	const setRefs = ( el ) => {
		ref.current = el;
		drag.setNodeRef( el );
		dropZone.setNodeRef( el );
	};

	const label = sprintf(
		/* translators: 1: folder name, 2: number of files */
		_n( '%1$s, %2$s file', '%1$s, %2$s files', node.count, 'cph-filebirb' ),
		node.name,
		formatCount( node.count )
	);

	const className = [
		'cphfb-row',
		selected && 'is-selected',
		dragging && 'is-dragging',
		drop && 'is-drop-' + drop,
		node.match && 'is-match',
		editing && 'is-editing',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div
			ref={ setRefs }
			{ ...( editing ? {} : drag.listeners ) }
			id={ domId }
			role="treeitem"
			aria-level={ depth + 1 }
			aria-posinset={ posinset }
			aria-setsize={ setsize }
			aria-expanded={ hasChildren ? expanded : undefined }
			aria-selected={ selected }
			aria-label={ editing ? undefined : label }
			tabIndex={ focused ? 0 : -1 }
			className={ className }
			style={ { '--cphfb-depth': depth } }
			data-folder-id={ node.id }
			onClick={ () => {
				onFocusRow( node.id );
				onSelect( node.id );
			} }
			onDoubleClick={ () => canManage && onStartRename( node.id ) }
			onContextMenu={ ( event ) => {
				event.preventDefault();
				onFocusRow( node.id );
				onMenu( node.id, { x: event.clientX, y: event.clientY } );
			} }
		>
			<span className="cphfb-row__toggle">
				{ hasChildren && (
					<button
						type="button"
						tabIndex={ -1 }
						aria-hidden="true"
						className={ 'cphfb-chevron' + ( expanded ? ' is-open' : '' ) }
						onClick={ ( event ) => {
							event.stopPropagation();
							onToggle( node.id, ! expanded );
						} }
						onPointerDown={ ( event ) => event.stopPropagation() }
					>
						<ChevronIcon />
					</button>
				) }
			</span>
			<FolderIcon open={ selected && hasChildren && expanded } color={ node.color } />
			{ editing ? (
				<RenameField
					initial={ node.name }
					onSubmit={ ( value ) => onRename( node.id, value ) }
					onCancel={ () => onCancelRename( node.id ) }
				/>
			) : (
				<span className="cphfb-row__name" title={ node.name }>
					{ node.name }
				</span>
			) }
			{ ! editing && <span className="cphfb-count">{ formatCount( node.count ) }</span> }
			{ ! editing && canManage && (
				<button
					type="button"
					tabIndex={ -1 }
					className="cphfb-row__more"
					aria-label={ sprintf( /* translators: %s: folder name */ __( 'Actions for %s', 'cph-filebirb' ), node.name ) }
					aria-haspopup="menu"
					onPointerDown={ ( event ) => event.stopPropagation() }
					onClick={ ( event ) => {
						event.stopPropagation();
						onFocusRow( node.id );
						const rect = event.currentTarget.getBoundingClientRect();
						onMenu( node.id, { x: rect.left, y: rect.bottom + 4 } );
					} }
				>
					<MoreIcon />
				</button>
			) }
		</div>
	);
}

export default memo( TreeRow );
