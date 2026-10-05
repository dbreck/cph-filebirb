/**
 * Minimal `react/jsx-runtime` over WordPress's `React.createElement`.
 *
 * The `react-jsx-runtime` script handle only exists on WP 6.6+, and bundling
 * React's own runtime breaks when core ships a different React major. This
 * shim works with whatever React core provides.
 */
import { createElement, Fragment } from 'react';

function jsxFn( type, config, key ) {
	const { children, ...props } = config || {};
	if ( key !== undefined ) {
		props.key = key;
	}
	if ( children === undefined ) {
		return createElement( type, props );
	}
	return createElement( type, props, children );
}

function jsxsFn( type, config, key ) {
	const { children, ...props } = config || {};
	if ( key !== undefined ) {
		props.key = key;
	}
	return createElement( type, props, ...( children || [] ) );
}

export { Fragment, jsxFn as jsx, jsxsFn as jsxs, jsxFn as jsxDEV };
