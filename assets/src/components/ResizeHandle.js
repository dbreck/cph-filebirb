/**
 * Drag handle on the sidebar's inline-end edge. Pointer and keyboard.
 */
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export const MIN_WIDTH = 220;
export const MAX_WIDTH = 520;
export const DEFAULT_WIDTH = 272;

export const clampWidth = ( w ) =>
	Math.round( Math.min( MAX_WIDTH, Math.max( MIN_WIDTH, Number( w ) || DEFAULT_WIDTH ) ) );

export default function ResizeHandle( { initialWidth, onWidth } ) {
	const [ width, setWidth ] = useState( clampWidth( initialWidth ) );
	const start = useRef( null );
	const isRtl = document.documentElement.dir === 'rtl';

	const apply = ( w, commit ) => {
		w = clampWidth( w );
		setWidth( w );
		onWidth?.( w, commit );
	};

	const onPointerDown = ( event ) => {
		if ( event.button !== 0 ) {
			return;
		}
		event.preventDefault();
		event.currentTarget.setPointerCapture( event.pointerId );
		start.current = { x: event.clientX, width };
		document.body.classList.add( 'cphfb-is-resizing' );
	};
	const onPointerMove = ( event ) => {
		if ( ! start.current ) {
			return;
		}
		const dx = ( event.clientX - start.current.x ) * ( isRtl ? -1 : 1 );
		apply( start.current.width + dx, false );
	};
	const onPointerUp = () => {
		if ( ! start.current ) {
			return;
		}
		start.current = null;
		document.body.classList.remove( 'cphfb-is-resizing' );
		apply( width, true );
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			className="cphfb-resize"
			role="separator"
			aria-orientation="vertical"
			aria-label={ __( 'Resize folder panel', 'cph-filebirb' ) }
			aria-valuemin={ MIN_WIDTH }
			aria-valuemax={ MAX_WIDTH }
			aria-valuenow={ width }
			// eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex
			tabIndex={ 0 }
			onPointerDown={ onPointerDown }
			onPointerMove={ onPointerMove }
			onPointerUp={ onPointerUp }
			onPointerCancel={ onPointerUp }
			onDoubleClick={ () => apply( DEFAULT_WIDTH, true ) }
			onKeyDown={ ( event ) => {
				const step = event.shiftKey ? 48 : 16;
				const sign = isRtl ? -1 : 1;
				if ( event.key === 'ArrowRight' ) {
					event.preventDefault();
					apply( width + step * sign, true );
				} else if ( event.key === 'ArrowLeft' ) {
					event.preventDefault();
					apply( width - step * sign, true );
				} else if ( event.key === 'Home' ) {
					event.preventDefault();
					apply( MIN_WIDTH, true );
				} else if ( event.key === 'End' ) {
					event.preventDefault();
					apply( MAX_WIDTH, true );
				}
			} }
		/>
	);
}
