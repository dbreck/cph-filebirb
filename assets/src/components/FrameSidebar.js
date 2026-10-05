/**
 * The folder column inside one `wp.media` attachments browser. Owns this
 * frame's folder (the library's `fbv` prop); the tree data is shared.
 */
import { useEffect, useMemo, useState, useSyncExternalStore } from '@wordpress/element';

import * as store from '../store';
import { storage } from '../storage';
import { ALL } from '../tree';
import { setFrameFolder } from '../media/context';
import { selectionSource } from '../media/move';
import Sidebar from './Sidebar';

const NARROW = 900;
const toId = ( value ) => ( value === undefined || value === null || value === '' ? ALL : Number( value ) );

export default function FrameSidebar( { browser } ) {
	const library = browser.collection;
	const frameEl = browser.controller?.el;
	const [ selected, setSelected ] = useState( () => toId( library.props.get( 'fbv' ) ) );
	const [ narrow, setNarrow ] = useState( () => browser.el.clientWidth > 0 && browser.el.clientWidth < NARROW );
	const [ preferRail, setPreferRail ] = useState( () => !! storage.get( 'modalRail', false ) );
	const [ narrowOpen, setNarrowOpen ] = useState( false );
	const { tree } = useSyncExternalStore( store.subscribe, store.getState );
	const source = useMemo( () => selectionSource( () => browser.options.selection ), [ browser ] );

	// The library's prop is the source of truth (something else may set it).
	useEffect( () => {
		const sync = () => setSelected( toId( library.props.get( 'fbv' ) ) );
		library.props.on( 'change:fbv', sync );
		return () => library.props.off( 'change:fbv', sync );
	}, [ library ] );

	const select = ( id ) => {
		if ( toId( library.props.get( 'fbv' ) ) !== id ) {
			library.props.set( { fbv: id } );
		}
		setSelected( id );
		store.rememberModalFolder( id );
		if ( frameEl ) {
			setFrameFolder( frameEl, id );
		}
		setNarrowOpen( false );
	};

	// Folder deleted elsewhere.
	useEffect( () => {
		if ( selected !== ALL && ! store.folderExists( selected ) ) {
			select( ALL );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tree, selected ] );

	useEffect( () => {
		const RO = window.ResizeObserver;
		if ( ! RO ) {
			return;
		}
		const observer = new RO( ( [ entry ] ) => {
			const width = entry.contentRect.width;
			if ( width > 0 ) {
				setNarrow( width < NARROW );
			}
		} );
		observer.observe( browser.el );
		return () => observer.disconnect();
	}, [ browser ] );

	useEffect( () => setNarrowOpen( false ), [ narrow ] );

	const rail = narrow ? ! narrowOpen : preferRail;
	const overlay = narrow && narrowOpen;

	useEffect( () => {
		browser.el.classList.toggle( 'cphfb-tree-rail', rail );
		browser.el.classList.toggle( 'cphfb-tree-overlay', overlay );
	}, [ browser, rail, overlay ] );

	const onRail = ( next ) => {
		if ( narrow ) {
			setNarrowOpen( ! next );
		} else {
			setPreferRail( next );
			storage.set( 'modalRail', next );
		}
	};

	return (
		<Sidebar
			collapsible
			selected={ selected }
			onSelect={ select }
			rail={ rail }
			onRail={ onRail }
			selectionSource={ source }
			selectionBar
		/>
	);
}
