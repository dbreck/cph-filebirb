/**
 * Pure helpers over the nested folder tree (`Node[]`).
 */

export const ALL = -1;
export const UNCATEGORIZED = 0;

/**
 * Normalise a server node (and its children) to the shape the UI expects.
 *
 * @param {Object} node Server node.
 * @return {Object} Node.
 */
export function normalizeNode( node ) {
	return {
		id: Number( node.id ),
		name: String( node.name ?? node.title ?? '' ),
		parent: Number( node.parent || 0 ),
		ord: Number( node.ord || 0 ),
		color: node.color || '',
		count: Number( node.count || 0 ),
		children: ( node.children || [] ).map( normalizeNode ),
	};
}

/**
 * Index the tree by id: `{ id: { node, parent, depth } }`.
 *
 * @param {Object[]} tree Nested nodes.
 * @return {Map<number, Object>} Index.
 */
export function indexTree( tree ) {
	const map = new Map();
	const walk = ( nodes, parent, depth ) => {
		nodes.forEach( ( node ) => {
			map.set( node.id, { node, parent, depth } );
			walk( node.children, node.id, depth + 1 );
		} );
	};
	walk( tree, 0, 0 );
	return map;
}

export function findNode( tree, id ) {
	for ( const node of tree ) {
		if ( node.id === id ) {
			return node;
		}
		const found = findNode( node.children, id );
		if ( found ) {
			return found;
		}
	}
	return null;
}

/**
 * Ids from the root down to (not including) `id`.
 *
 * @param {Map}    index indexTree() result.
 * @param {number} id    Folder id.
 * @return {number[]} Ancestor ids.
 */
export function ancestorsOf( index, id ) {
	const out = [];
	let entry = index.get( id );
	while ( entry && entry.parent ) {
		out.unshift( entry.parent );
		entry = index.get( entry.parent );
	}
	return out;
}

export function isDescendant( index, id, ancestorId ) {
	return ancestorsOf( index, id ).includes( ancestorId );
}

/**
 * Immutable map over every node.
 *
 * @param {Object[]} tree Nested nodes.
 * @param {Function} fn   node => node.
 * @return {Object[]} New tree.
 */
export function mapTree( tree, fn ) {
	return tree.map( ( node ) =>
		fn( { ...node, children: mapTree( node.children, fn ) } )
	);
}

export function updateNode( tree, id, patch ) {
	return mapTree( tree, ( node ) =>
		node.id === id ? { ...node, ...patch } : node
	);
}

export function removeNode( tree, id ) {
	return tree
		.filter( ( node ) => node.id !== id )
		.map( ( node ) => ( {
			...node,
			children: removeNode( node.children, id ),
		} ) );
}

/**
 * Remove `id` and splice its children into its place (delete "children-up").
 *
 * @param {Object[]} tree Nested nodes.
 * @param {number}   id   Folder id.
 * @return {Object[]} New tree.
 */
export function removeNodeKeepChildren( tree, id ) {
	const out = [];
	tree.forEach( ( node ) => {
		if ( node.id === id ) {
			node.children.forEach( ( child ) =>
				out.push( { ...child, parent: node.parent } )
			);
		} else {
			out.push( {
				...node,
				children: removeNodeKeepChildren( node.children, id ),
			} );
		}
	} );
	return out;
}

export function insertNode( tree, parent, node, index = Infinity ) {
	if ( ! parent ) {
		const copy = [ ...tree ];
		copy.splice( Math.min( index, copy.length ), 0, node );
		return copy;
	}
	return tree.map( ( item ) => {
		if ( item.id === parent ) {
			const children = [ ...item.children ];
			children.splice( Math.min( index, children.length ), 0, node );
			return { ...item, children };
		}
		return { ...item, children: insertNode( item.children, parent, node, index ) };
	} );
}

export function childrenOf( tree, parent ) {
	if ( ! parent ) {
		return tree;
	}
	const node = findNode( tree, parent );
	return node ? node.children : [];
}

/**
 * Move a node under `parent` at sibling position `index` and renumber `ord`
 * for the new siblings.
 *
 * @param {Object[]} tree   Nested nodes.
 * @param {number}   id     Folder id.
 * @param {number}   parent New parent id (0 = root).
 * @param {number}   index  Position among the new siblings.
 * @return {{tree: Object[], items: Object[]}} New tree and `/folders/order` items.
 */
export function moveNode( tree, id, parent, index ) {
	const node = findNode( tree, id );
	if ( ! node ) {
		return { tree, items: [] };
	}
	let next = removeNode( tree, id );
	next = insertNode( next, parent, { ...node, parent }, index );
	const siblings = childrenOf( next, parent );
	const items = siblings.map( ( sibling, ord ) => ( {
		id: sibling.id,
		parent,
		ord,
	} ) );
	const ords = new Map( items.map( ( item ) => [ item.id, item.ord ] ) );
	next = mapTree( next, ( item ) =>
		ords.has( item.id ) ? { ...item, ord: ords.get( item.id ) } : item
	);
	return { tree: next, items };
}

export function applyCounts( tree, counts ) {
	if ( ! counts || ! counts.folders ) {
		return tree;
	}
	return mapTree( tree, ( node ) => ( {
		...node,
		count: Number( counts.folders[ node.id ] ?? counts.folders[ String( node.id ) ] ?? 0 ),
	} ) );
}

export function sortTree( tree, sort ) {
	if ( sort !== 'name-asc' && sort !== 'name-desc' ) {
		return tree;
	}
	const dir = sort === 'name-desc' ? -1 : 1;
	const collator = new Intl.Collator( undefined, {
		numeric: true,
		sensitivity: 'base',
	} );
	const sortLevel = ( nodes ) =>
		[ ...nodes ]
			.sort( ( a, b ) => dir * collator.compare( a.name, b.name ) )
			.map( ( node ) => ( { ...node, children: sortLevel( node.children ) } ) );
	return sortLevel( tree );
}

/**
 * Keep nodes whose name matches `term`, plus their ancestors.
 *
 * @param {Object[]} tree Nested nodes.
 * @param {string}   term Search term.
 * @return {Object[]} Filtered tree; matching nodes get `match: true`.
 */
export function filterTree( tree, term ) {
	const needle = term.trim().toLocaleLowerCase();
	if ( ! needle ) {
		return tree;
	}
	const walk = ( nodes ) =>
		nodes.reduce( ( out, node ) => {
			const children = walk( node.children );
			const match = node.name.toLocaleLowerCase().includes( needle );
			if ( match || children.length ) {
				out.push( { ...node, children, match } );
			}
			return out;
		}, [] );
	return walk( tree );
}

/**
 * Visible rows in display order, honouring collapsed state.
 *
 * @param {Object[]} tree      Nested nodes (already sorted/filtered).
 * @param {Set}      collapsed Collapsed folder ids.
 * @param {boolean}  expandAll Ignore `collapsed` (search mode).
 * @return {Object[]} `{ node, depth, parent, posinset, setsize, expanded }`.
 */
export function flatten( tree, collapsed, expandAll = false ) {
	const rows = [];
	const walk = ( nodes, depth, parent ) => {
		nodes.forEach( ( node, i ) => {
			const hasChildren = node.children.length > 0;
			const expanded = hasChildren && ( expandAll || ! collapsed.has( node.id ) );
			rows.push( {
				node,
				depth,
				parent,
				posinset: i + 1,
				setsize: nodes.length,
				hasChildren,
				expanded,
			} );
			if ( expanded ) {
				walk( node.children, depth + 1, node.id );
			}
		} );
	};
	walk( tree, 0, 0 );
	return rows;
}

/**
 * A name not used by any sibling: "New folder", "New folder (2)", ...
 *
 * @param {Object[]} siblings Sibling nodes.
 * @param {string}   base     Base name.
 * @return {string} Name.
 */
export function uniqueName( siblings, base ) {
	const taken = new Set( siblings.map( ( node ) => node.name.toLocaleLowerCase() ) );
	if ( ! taken.has( base.toLocaleLowerCase() ) ) {
		return base;
	}
	let n = 2;
	while ( taken.has( `${ base } (${ n })`.toLocaleLowerCase() ) ) {
		n++;
	}
	return `${ base } (${ n })`;
}
