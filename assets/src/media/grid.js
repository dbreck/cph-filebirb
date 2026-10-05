/**
 * Media Library grid (`upload.php?mode=grid`): filter the Manage frame's library by folder.
 *
 * Our script loads in the footer after `media-grid`, but before DOM ready, which
 * is when media-grid builds the frame. So we can wrap the Manage frame's
 * initialize and put `fbv` into the very first query: no unfiltered flash.
 */
import { ALL } from '../tree';
import * as store from '../store';

let frame = null;

/**
 * The library collection of a Manage frame.
 *
 * @param {Object} f Frame.
 * @return {Object|null} wp.media.model.Attachments.
 */
function libraryOf( f ) {
	if ( ! f ) {
		return null;
	}
	const fromState = f.state?.()?.get?.( 'library' );
	if ( fromState?.props ) {
		return fromState;
	}
	return f.content?.get?.()?.collection || null;
}

export function getLibrary() {
	return libraryOf( frame || window.wp?.media?.frame );
}

/**
 * Point the grid at a folder. Setting a prop re-queries the mirrored collection.
 *
 * @param {number} id Folder id.
 */
export function filterGrid( id ) {
	const library = getLibrary();
	if ( ! library ) {
		return;
	}
	if ( Number( library.props.get( 'fbv' ) ) === id ) {
		return;
	}
	library.props.set( { fbv: id } );
}

/**
 * Re-run the current query (bypassing wp.media's per-props query cache).
 * Stage B calls this after moving attachments between folders.
 */
export function requery() {
	const library = getLibrary();
	if ( ! library ) {
		return;
	}
	if ( typeof library._requery === 'function' ) {
		library._requery( true );
	} else {
		library.props.trigger( 'change', library.props );
	}
}

function watchFrame( f ) {
	frame = f;
	const library = libraryOf( f );
	if ( library ) {
		// Attachment deleted from the grid or details modal.
		library.on( 'remove', ( model, collection, options ) => {
			if ( ! options || ! options.silent ) {
				store.refreshCounts();
			}
		} );
	}
}

/**
 * Install the grid hooks. Call synchronously at script load.
 *
 * @param {Function} initialFolder Returns the folder to start in.
 */
export function installGrid( initialFolder ) {
	const media = window.wp?.media;
	const Manage = media?.view?.MediaFrame?.Manage;
	if ( ! Manage || Manage.prototype.__cphfb ) {
		return;
	}
	const initialize = Manage.prototype.initialize;
	Manage.prototype.__cphfb = true;
	Manage.prototype.initialize = function ( options, ...rest ) {
		const id = initialFolder();
		if ( this.options && id !== ALL ) {
			this.options.library = { ...( this.options.library || {} ), fbv: id };
		}
		const result = initialize.call( this, options, ...rest );
		watchFrame( this );
		return result;
	};

	// The frame might already exist if something else built it early.
	if ( media.frame instanceof Manage ) {
		watchFrame( media.frame );
		const id = initialFolder();
		if ( id !== ALL ) {
			filterGrid( id );
		}
	}

	// Uploads finished: the queue resets once all files are done.
	const queue = window.wp?.Uploader?.queue;
	queue?.on?.( 'reset', () => store.refreshCounts() );

	store.on( 'select', filterGrid );
}
