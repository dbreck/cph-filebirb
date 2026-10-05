/**
 * Confirm dialog for deleting a folder. Uses the native <dialog> element
 * for focus trapping, Escape and the backdrop.
 */
import { createPortal, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

function countDescendants( node ) {
	return node.children.reduce( ( sum, child ) => sum + 1 + countDescendants( child ), 0 );
}

export default function DeleteDialog( { node, onConfirm, onClose } ) {
	const ref = useRef();
	const cancelRef = useRef();
	const [ busy, setBusy ] = useState( false );
	const subfolders = countDescendants( node );
	const hasChildren = subfolders > 0;

	useEffect( () => {
		const dialog = ref.current;
		dialog.showModal();
		// Destructive dialog: start on Cancel.
		cancelRef.current?.focus();
		const onCancel = ( event ) => {
			event.preventDefault();
			onClose();
		};
		dialog.addEventListener( 'cancel', onCancel );
		return () => dialog.removeEventListener( 'cancel', onCancel );
	}, [ onClose ] );

	const confirm = async ( mode ) => {
		setBusy( true );
		await onConfirm( mode );
		setBusy( false );
		onClose();
	};

	return createPortal(
		<dialog
			ref={ ref }
			className="cphfb-dialog"
			aria-labelledby="cphfb-delete-title"
			aria-describedby="cphfb-delete-desc"
			onClick={ ( event ) => event.target === ref.current && ! busy && onClose() }
		>
			<div className="cphfb-dialog__body">
				<h2 id="cphfb-delete-title" className="cphfb-dialog__title">
					{ sprintf( /* translators: %s: folder name */ __( 'Delete “%s”?', 'cph-filebird' ), node.name ) }
				</h2>
				<div id="cphfb-delete-desc">
					<p className="cphfb-dialog__note">
						<strong>{ __( 'Your files are safe.', 'cph-filebird' ) }</strong>{ ' ' }
						{ __( 'Deleting a folder never deletes media files. Files in deleted folders move to Uncategorized.', 'cph-filebird' ) }
					</p>
					{ hasChildren && (
						<p>
							{ sprintf(
								/* translators: %d: number of subfolders */
								_n(
									'This folder contains %d subfolder. What should happen to it?',
									'This folder contains %d subfolders. What should happen to them?',
									subfolders,
									'cph-filebird'
								),
								subfolders
							) }
						</p>
					) }
				</div>
			</div>
			<div className={ 'cphfb-dialog__actions' + ( hasChildren ? ' is-stacked' : '' ) }>
				<button
					type="button"
					className="button button-primary cphfb-button-danger"
					onClick={ () => confirm( 'subtree' ) }
					disabled={ busy }
				>
					{ hasChildren
						? __( 'Delete folder and subfolders', 'cph-filebird' )
						: __( 'Delete folder', 'cph-filebird' ) }
				</button>
				{ hasChildren && (
					<button type="button" className="button" onClick={ () => confirm( 'children-up' ) } disabled={ busy }>
						{ __( 'Keep subfolders (move them up)', 'cph-filebird' ) }
					</button>
				) }
				<button type="button" className="button cphfb-dialog__cancel" ref={ cancelRef } onClick={ onClose } disabled={ busy }>
					{ __( 'Cancel', 'cph-filebird' ) }
				</button>
			</div>
		</dialog>,
		document.body
	);
}
