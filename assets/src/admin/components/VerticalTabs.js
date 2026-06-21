import { useRef } from '@wordpress/element';

export function VerticalTabs( { groups, active, onSelect, children } ) {
	const allTabs = groups.flatMap( ( g ) => g.tabs );
	const tabRefs = useRef( {} );

	const onKeyDown = ( e ) => {
		const idx = allTabs.findIndex( ( t ) => t.name === active );
		if ( idx < 0 ) {
			return;
		}
		const count = allTabs.length;
		let next = null;
		if ( e.key === 'ArrowDown' ) {
			next = ( idx + 1 ) % count;
		} else if ( e.key === 'ArrowUp' ) {
			next = ( idx - 1 + count ) % count;
		} else if ( e.key === 'Home' ) {
			next = 0;
		} else if ( e.key === 'End' ) {
			next = count - 1;
		}
		if ( next !== null ) {
			e.preventDefault();
			const name = allTabs[ next ].name;
			onSelect( name );
			tabRefs.current[ name ]?.focus();
		}
	};

	return (
		<div className="openseo-vtabs">
			<div
				className="openseo-vtabs__nav"
				role="tablist"
				aria-orientation="vertical"
				onKeyDown={ onKeyDown }
				tabIndex={ -1 }
			>
				{ groups.map( ( group ) => (
					<div
						key={ group.label ?? 'general' }
						className="openseo-vtabs__group"
					>
						{ group.label && (
							<div
								className="openseo-vtabs__group-label"
								role="presentation"
							>
								{ group.label }
							</div>
						) }
						{ group.tabs.map( ( tab ) => {
							const selected = tab.name === active;
							return (
								<button
									key={ tab.name }
									ref={ ( el ) => {
										tabRefs.current[ tab.name ] = el;
									} }
									type="button"
									role="tab"
									id={ `openseo-tab-${ tab.name }` }
									aria-selected={ selected }
									aria-controls={ `openseo-panel-${ tab.name }` }
									tabIndex={ selected ? 0 : -1 }
									className={ `openseo-vtabs__tab${
										selected ? ' is-active' : ''
									}` }
									onClick={ () => onSelect( tab.name ) }
								>
									{ tab.title }
								</button>
							);
						} ) }
					</div>
				) ) }
			</div>
			<div
				className="openseo-vtabs__panel"
				role="tabpanel"
				id={ `openseo-panel-${ active }` }
				aria-labelledby={ `openseo-tab-${ active }` }
				tabIndex={ 0 }
			>
				{ children( active ) }
			</div>
		</div>
	);
}
