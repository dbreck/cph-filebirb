/**
 * A small transient message with an optional action ("Undo"). One at a time.
 */
import { __ } from '@wordpress/i18n';

let current = null;

export function dismissToast() {
	if ( ! current ) {
		return;
	}
	clearTimeout( current.timer );
	current.el.remove();
	current = null;
}

/**
 * @param {string}   message          Text.
 * @param {Object}   options          Options.
 * @param {string}   options.action   Action button label.
 * @param {Function} options.onAction Action callback.
 * @param {number}   options.duration Milliseconds before it hides.
 */
export function toast( message, { action, onAction, duration = 7000 } = {} ) {
	dismissToast();
	const el = document.createElement( 'div' );
	el.className = 'cphfb-toast';
	el.setAttribute( 'role', 'status' );

	const text = document.createElement( 'span' );
	text.className = 'cphfb-toast__message';
	text.textContent = message;
	el.appendChild( text );

	if ( action && onAction ) {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'cphfb-toast__action';
		button.textContent = action;
		button.addEventListener( 'click', () => {
			dismissToast();
			onAction();
		} );
		el.appendChild( button );
	}

	const close = document.createElement( 'button' );
	close.type = 'button';
	close.className = 'cphfb-toast__close';
	close.setAttribute( 'aria-label', __( 'Dismiss', 'cph-filebird' ) );
	close.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 10.94 17.47 5.47l1.06 1.06L13.06 12l5.47 5.47-1.06 1.06L12 13.06l-5.47 5.47-1.06-1.06L10.94 12 5.47 6.53l1.06-1.06z"/></svg>';
	close.addEventListener( 'click', dismissToast );
	el.appendChild( close );

	document.body.appendChild( el );
	const entry = { el, timer: null };
	const arm = () => {
		clearTimeout( entry.timer );
		entry.timer = setTimeout( () => current === entry && dismissToast(), duration );
	};
	// Keep it while the pointer or focus is on it.
	el.addEventListener( 'mouseenter', () => clearTimeout( entry.timer ) );
	el.addEventListener( 'mouseleave', arm );
	el.addEventListener( 'focusin', () => clearTimeout( entry.timer ) );
	el.addEventListener( 'focusout', arm );
	current = entry;
	arm();
}
