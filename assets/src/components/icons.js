/**
 * Inline SVG icons (24px grid, drawn for this plugin).
 */
const Svg = ( { children, size = 20, ...props } ) => (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width={ size }
		height={ size }
		aria-hidden="true"
		focusable="false"
		{ ...props }
	>
		{ children }
	</svg>
);

export const FolderIcon = ( { open = false, color = '', ...props } ) => (
	<Svg { ...props } className="cphfb-icon cphfb-icon--folder">
		{ open ? (
			<path
				d="M3.5 6.5A1.5 1.5 0 0 1 5 5h4.2l2 2H19a1.5 1.5 0 0 1 1.5 1.5V10H7.4a1.5 1.5 0 0 0-1.42 1.02L3.5 18.2V6.5Zm2.2 5.1a.75.75 0 0 1 .7-.5h15.1a.6.6 0 0 1 .57.78l-2.1 6.3a1.5 1.5 0 0 1-1.42 1.02H4.3a.6.6 0 0 1-.57-.79l1.97-6.81Z"
				fill={ color || 'currentColor' }
			/>
		) : (
			<path
				d="M3.5 6.5A1.5 1.5 0 0 1 5 5h4.2l2 2H19a1.5 1.5 0 0 1 1.5 1.5v9A1.5 1.5 0 0 1 19 19H5a1.5 1.5 0 0 1-1.5-1.5v-11Z"
				fill={ color || 'currentColor' }
			/>
		) }
	</Svg>
);

export const AllIcon = ( props ) => (
	<Svg { ...props } className="cphfb-icon">
		<path
			d="M5 4.5h5.5V10H5zM13.5 4.5H19V10h-5.5zM5 14h5.5v5.5H5zM13.5 14H19v5.5h-5.5z"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
	</Svg>
);

export const InboxIcon = ( props ) => (
	<Svg { ...props } className="cphfb-icon">
		<path
			d="M4.5 13.5 6.6 6.2A1.5 1.5 0 0 1 8 5h8a1.5 1.5 0 0 1 1.4 1.2l2.1 7.3v4.5A1.5 1.5 0 0 1 18 19.5H6A1.5 1.5 0 0 1 4.5 18v-4.5Zm0 0h4.25l1 2h4.5l1-2h4.25"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
	</Svg>
);

export const ChevronIcon = ( props ) => (
	<Svg size={ 16 } { ...props } className="cphfb-icon cphfb-icon--chevron">
		<path
			d="m9.5 7 5 5-5 5"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</Svg>
);

export const PlusIcon = ( props ) => (
	<Svg size={ 18 } { ...props } className="cphfb-icon">
		<path d="M12 5v14M5 12h14" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
	</Svg>
);

export const MoreIcon = ( props ) => (
	<Svg size={ 18 } { ...props } className="cphfb-icon">
		<circle cx="12" cy="6" r="1.6" fill="currentColor" />
		<circle cx="12" cy="12" r="1.6" fill="currentColor" />
		<circle cx="12" cy="18" r="1.6" fill="currentColor" />
	</Svg>
);

export const SearchIcon = ( props ) => (
	<Svg size={ 16 } { ...props } className="cphfb-icon">
		<circle cx="11" cy="11" r="6" fill="none" stroke="currentColor" strokeWidth="1.8" />
		<path d="m15.5 15.5 4 4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
	</Svg>
);

export const CloseIcon = ( props ) => (
	<Svg size={ 14 } { ...props } className="cphfb-icon">
		<path d="m6 6 12 12M18 6 6 18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
	</Svg>
);

export const SortIcon = ( props ) => (
	<Svg size={ 18 } { ...props } className="cphfb-icon">
		<path
			d="M8 5v14m0 0-3-3m3 3 3-3M16 19V5m0 0-3 3m3-3 3 3"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</Svg>
);

export const PanelIcon = ( { collapsed, ...props } ) => (
	<Svg size={ 18 } { ...props } className="cphfb-icon cphfb-icon--panel">
		<rect x="4" y="5" width="16" height="14" rx="2" fill="none" stroke="currentColor" strokeWidth="1.6" />
		<path d="M9.5 5v14" stroke="currentColor" strokeWidth="1.6" />
		<path
			d={ collapsed ? 'm13 10 2 2-2 2' : 'm16 10-2 2 2 2' }
			fill="none"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</Svg>
);

export const CheckIcon = ( props ) => (
	<Svg size={ 16 } { ...props } className="cphfb-icon">
		<path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
	</Svg>
);

export const MoveIcon = ( props ) => (
	<Svg size={ 18 } { ...props } className="cphfb-icon cphfb-icon--move">
		<path
			d="M3.5 7.5A1.5 1.5 0 0 1 5 6h4l2 2h8a1.5 1.5 0 0 1 1.5 1.5v8A1.5 1.5 0 0 1 19 19H5a1.5 1.5 0 0 1-1.5-1.5z"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
		<path d="M9 13.5h6m-2.5-2.5 2.5 2.5-2.5 2.5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
	</Svg>
);
