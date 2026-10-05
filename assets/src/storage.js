/**
 * Per-site localStorage, namespaced and failure-tolerant.
 */
const data = window.cphfbData || {};
const prefix = 'cphfb:' + ( data.siteKey || window.location.host ) + ':';

export const storage = {
	get( key, fallback = null ) {
		try {
			const raw = window.localStorage.getItem( prefix + key );
			return raw === null ? fallback : JSON.parse( raw );
		} catch ( error ) {
			return fallback;
		}
	},
	set( key, value ) {
		try {
			window.localStorage.setItem( prefix + key, JSON.stringify( value ) );
		} catch ( error ) {}
	},
};
