/**
 * "Move to folder…" toolbar button for an attachments browser (grid Bulk
 * select toolbar, modal library toolbar).
 */
import { __ } from '@wordpress/i18n';

import { openFolderPicker } from '../components/FolderPicker';
import { commonFolder, idsOf, moveAttachments } from './move';

/**
 * @param {Object}   toolbar         wp.media.view.Toolbar of the browser.
 * @param {Object}   selection       wp.media.model.Selection.
 * @param {Object}   options         Options.
 * @param {number}   options.priority Toolbar priority (< 0 = secondary side).
 * @param {Function} options.visible  Extra visibility condition.
 * @param {Array}    options.events   `[ emitter, 'events' ]` pairs that should re-check visibility.
 * @param {boolean}  options.hideEmpty Hide (rather than disable) while nothing is selected.
 * @return {Object|null} The button view.
 */
export function addMoveButton( toolbar, selection, { priority = -10, visible = () => true, events = [], hideEmpty = true } = {} ) {
	const Button = window.wp?.media?.view?.Button;
	if ( ! toolbar || ! selection || ! Button ) {
		return null;
	}
	const button = new Button( {
		text: __( 'Move to folder…', 'cph-filebird' ),
		classes: [ 'cphfb-move-button' ],
		priority,
		click() {
			const ids = idsOf( selection );
			if ( ! ids.length ) {
				return;
			}
			openFolderPicker( {
				anchor: button.el,
				count: ids.length,
				current: commonFolder( ids ),
				onPick: ( folder ) => moveAttachments( folder, ids ),
			} );
		},
	} ).render();
	button.$el.attr( { 'aria-haspopup': 'dialog', 'aria-expanded': 'false' } );

	const update = () => {
		const count = selection.length;
		// Button re-renders (and resets `class`) on model changes: toggle after.
		button.model.set( 'disabled', ! count );
		button.$el.toggleClass( 'hidden', ( hideEmpty && ! count ) || ! visible() );
	};
	button.listenTo( selection, 'add remove reset', update );
	events.forEach( ( [ emitter, names ] ) => button.listenTo( emitter, names, update ) );
	toolbar.set( 'cphfbMove', button );
	update();
	return button;
}
