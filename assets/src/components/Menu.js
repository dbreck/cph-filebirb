/**
 * Small accessible popup menu (context menu, kebab menu, sort menu).
 */
import { createPortal, useEffect, useLayoutEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { CheckIcon } from './icons';

export const COLORS = [
	{ value: '#d63638', name: __( 'Red', 'cph-filebird' ) },
	{ value: '#e26f2a', name: __( 'Orange', 'cph-filebird' ) },
	{ value: '#dba617', name: __( 'Yellow', 'cph-filebird' ) },
	{ value: '#00a32a', name: __( 'Green', 'cph-filebird' ) },
	{ value: '#1aa39a', name: __( 'Teal', 'cph-filebird' ) },
	{ value: '#2271b1', name: __( 'Blue', 'cph-filebird' ) },
	{ value: '#7a4fd6', name: __( 'Purple', 'cph-filebird' ) },
	{ value: '#c43b8d', name: __( 'Pink', 'cph-filebird' ) },
	{ value: '#646970', name: __( 'Gray', 'cph-filebird' ) },
];

/**
 * @param {Object}   props
 * @param {Object}   props.anchor   `{ x, y }` in viewport pixels.
 * @param {Object[]} props.items    `{ key, label, onSelect, danger, checked }`, `{ type: 'separator' }`,
 *                                  or `{ type: 'colors', value, onSelect }`.
 * @param {string}   props.label    Accessible name.
 * @param {Function} props.onClose  Called with no args when the menu should close.
 */
export default function Menu( { anchor, items, label, onClose } ) {
	const ref = useRef();
	const [ pos, setPos ] = useState( { left: anchor.x, top: anchor.y, ready: false } );

	// Keep the menu inside the viewport.
	useLayoutEffect( () => {
		const el = ref.current;
		if ( ! el ) {
			return;
		}
		const rect = el.getBoundingClientRect();
		const left = Math.max( 8, Math.min( anchor.x, window.innerWidth - rect.width - 8 ) );
		const top =
			anchor.y + rect.height > window.innerHeight - 8
				? Math.max( 8, anchor.y - rect.height )
				: anchor.y;
		setPos( { left, top, ready: true } );
	}, [ anchor.x, anchor.y ] );

	useEffect( () => {
		const first = ref.current?.querySelector( '[role^="menuitem"]:not([disabled])' );
		first?.focus();
		const onDown = ( event ) => {
			if ( ref.current && ! ref.current.contains( event.target ) ) {
				onClose();
			}
		};
		const onScroll = ( event ) => {
			if ( ref.current && ! ref.current.contains( event.target ) ) {
				onClose();
			}
		};
		document.addEventListener( 'pointerdown', onDown, true );
		window.addEventListener( 'scroll', onScroll, true );
		window.addEventListener( 'resize', onClose );
		window.addEventListener( 'blur', onClose );
		return () => {
			document.removeEventListener( 'pointerdown', onDown, true );
			window.removeEventListener( 'scroll', onScroll, true );
			window.removeEventListener( 'resize', onClose );
			window.removeEventListener( 'blur', onClose );
		};
	}, [ onClose ] );

	const onKeyDown = ( event ) => {
		const nodes = [ ...ref.current.querySelectorAll( '[role^="menuitem"]:not([disabled])' ) ];
		const i = nodes.indexOf( document.activeElement );
		const move = ( to ) => {
			event.preventDefault();
			nodes[ ( to + nodes.length ) % nodes.length ]?.focus();
		};
		switch ( event.key ) {
			case 'ArrowDown':
				return move( i + 1 );
			case 'ArrowUp':
				return move( i - 1 );
			case 'ArrowRight':
				if ( document.activeElement?.dataset.swatch ) {
					return move( i + 1 );
				}
				return;
			case 'ArrowLeft':
				if ( document.activeElement?.dataset.swatch ) {
					return move( i - 1 );
				}
				return;
			case 'Home':
				return move( 0 );
			case 'End':
				return move( nodes.length - 1 );
			case 'Escape':
				event.preventDefault();
				event.stopPropagation();
				return onClose( true );
			case 'Tab':
				event.preventDefault();
				return onClose( true );
		}
	};

	const run = ( callback ) => () => {
		onClose( true );
		callback();
	};

	return createPortal(
		<div
			ref={ ref }
			className="cphfb-menu"
			role="menu"
			aria-label={ label }
			tabIndex={ -1 }
			style={ { left: pos.left, top: pos.top, visibility: pos.ready ? 'visible' : 'hidden' } }
			onKeyDown={ onKeyDown }
			onContextMenu={ ( event ) => event.preventDefault() }
		>
			{ items.map( ( item, i ) => {
				if ( item.type === 'separator' ) {
					return <div key={ 'sep' + i } className="cphfb-menu__separator" role="separator" />;
				}
				if ( item.type === 'colors' ) {
					return (
						<div key="colors" className="cphfb-menu__colors" role="group" aria-label={ __( 'Folder color', 'cph-filebird' ) }>
							<span className="cphfb-menu__heading" aria-hidden="true">
								{ __( 'Color', 'cph-filebird' ) }
							</span>
							<div className="cphfb-menu__swatches">
								{ COLORS.map( ( { value: color, name } ) => (
									<button
										key={ color }
										title={ name }
										type="button"
										role="menuitemradio"
										data-swatch="1"
										aria-checked={ item.value === color }
										aria-label={ name }
										className="cphfb-swatch"
										style={ { '--cphfb-swatch': color } }
										onClick={ run( () => item.onSelect( color ) ) }
									/>
								) ) }
								<button
									type="button"
									role="menuitemradio"
									data-swatch="1"
									aria-checked={ ! item.value }
									aria-label={ __( 'No color', 'cph-filebird' ) }
									title={ __( 'No color', 'cph-filebird' ) }
									className="cphfb-swatch cphfb-swatch--none"
									onClick={ run( () => item.onSelect( '' ) ) }
								/>
							</div>
						</div>
					);
				}
				const checkable = typeof item.checked === 'boolean';
				return (
					<button
						key={ item.key }
						type="button"
						role={ checkable ? 'menuitemradio' : 'menuitem' }
						aria-checked={ checkable ? item.checked : undefined }
						className={ 'cphfb-menu__item' + ( item.danger ? ' is-danger' : '' ) }
						disabled={ item.disabled }
						onClick={ run( item.onSelect ) }
					>
						{ checkable && (
							<span className="cphfb-menu__check">{ item.checked && <CheckIcon /> }</span>
						) }
						<span className="cphfb-menu__label">{ item.label }</span>
						{ item.shortcut && (
							<kbd className="cphfb-menu__shortcut" aria-hidden="true">
								{ item.shortcut }
							</kbd>
						) }
					</button>
				);
			} ) }
		</div>,
		document.body
	);
}
