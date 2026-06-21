import { Button, Dropdown, SearchControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { variablesForScope, filterVariables } from '../variables';

export function VariableInserter( { catalog, scope, onInsert } ) {
	const [ query, setQuery ] = useState( '' );
	const scoped = variablesForScope( catalog, scope );
	const list = filterVariables( scoped, query );

	return (
		<Dropdown
			className="openseo-var-inserter"
			popoverProps={ { placement: 'bottom-end' } }
			onClose={ () => setQuery( '' ) }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="secondary"
					size="small"
					icon="insert"
					label={ __( 'Insert variable', 'openseo' ) }
					aria-expanded={ isOpen }
					onClick={ onToggle }
				/>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="openseo-var-inserter__panel">
					<SearchControl
						__nextHasNoMarginBottom
						value={ query }
						onChange={ setQuery }
						label={ __( 'Search variables', 'openseo' ) }
					/>
					{ scoped.length === 0 && (
						<p className="openseo-var-inserter__empty">
							{ __( 'No variables', 'openseo' ) }
						</p>
					) }
					{ scoped.length > 0 && list.length === 0 && (
						<p className="openseo-var-inserter__empty">
							{ __( 'No results', 'openseo' ) }
						</p>
					) }
					<ul className="openseo-var-inserter__list">
						{ list.map( ( v ) => (
							<li key={ v.token }>
								<Button
									variant="tertiary"
									className="openseo-var-inserter__item"
									onClick={ () => {
										onInsert( v.token );
										onClose();
									} }
								>
									<span className="openseo-var-inserter__token">
										{ v.token }
									</span>
									<span className="openseo-var-inserter__label">
										{ v.label }
									</span>
								</Button>
							</li>
						) ) }
					</ul>
				</div>
			) }
		/>
	);
}
