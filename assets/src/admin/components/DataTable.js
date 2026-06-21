import {
	Button,
	CheckboxControl,
	SearchControl,
	Spinner,
	Flex,
	FlexItem,
	FlexBlock,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const PER_PAGE = 20;

export function DataTable( {
	columns,
	items,
	total,
	page,
	loading,
	searchable = false,
	search = '',
	onSearch,
	onPageChange,
	selectable = false,
	selected = [],
	onSelectionChange,
	rowActions,
	bulkActions = [],
	emptyLabel = __( 'Nothing here yet.', 'openseo' ),
} ) {
	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const allChecked = items.length > 0 && selected.length === items.length;

	const toggleAll = ( on ) =>
		onSelectionChange( on ? items.map( ( i ) => i.id ) : [] );

	const toggleOne = ( id, on ) =>
		onSelectionChange(
			on ? [ ...selected, id ] : selected.filter( ( s ) => s !== id )
		);

	return (
		<div className="openseo-datatable">
			<Flex
				className="openseo-datatable__toolbar"
				justify="space-between"
			>
				<FlexItem>
					{ selectable && selected.length > 0 ? (
						<Flex gap={ 2 }>
							{ bulkActions.map( ( a ) => (
								<Button
									key={ a.action }
									variant="secondary"
									isDestructive={ a.destructive }
									onClick={ () => a.onClick( selected ) }
								>
									{ a.label }
								</Button>
							) ) }
							<span>
								{ sprintf(
									/* translators: %d: number of selected rows. */
									__( '%d selected', 'openseo' ),
									selected.length
								) }
							</span>
						</Flex>
					) : null }
				</FlexItem>
				<FlexItem>
					{ searchable ? (
						<SearchControl
							value={ search }
							onChange={ onSearch }
							label={ __( 'Search', 'openseo' ) }
							__nextHasNoMarginBottom
						/>
					) : null }
				</FlexItem>
			</Flex>

			{ loading ? (
				<div className="openseo-datatable__loading">
					<Spinner />
				</div>
			) : (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							{ selectable ? (
								<td className="check-column">
									<CheckboxControl
										__nextHasNoMarginBottom
										checked={ allChecked }
										onChange={ toggleAll }
										label=""
									/>
								</td>
							) : null }
							{ columns.map( ( c ) => (
								<th key={ c.id }>{ c.label }</th>
							) ) }
							{ rowActions ? (
								<th>{ __( 'Actions', 'openseo' ) }</th>
							) : null }
						</tr>
					</thead>
					<tbody>
						{ items.length === 0 ? (
							<tr>
								<td
									colSpan={
										columns.length +
										( selectable ? 1 : 0 ) +
										( rowActions ? 1 : 0 )
									}
								>
									{ emptyLabel }
								</td>
							</tr>
						) : (
							items.map( ( item ) => (
								<tr key={ item.id }>
									{ selectable ? (
										<th
											scope="row"
											className="check-column"
										>
											<CheckboxControl
												__nextHasNoMarginBottom
												checked={ selected.includes(
													item.id
												) }
												onChange={ ( on ) =>
													toggleOne( item.id, on )
												}
												label=""
											/>
										</th>
									) : null }
									{ columns.map( ( c ) => (
										<td key={ c.id }>
											{ c.render
												? c.render( item )
												: item[ c.id ] }
										</td>
									) ) }
									{ rowActions ? (
										<td>{ rowActions( item ) }</td>
									) : null }
								</tr>
							) )
						) }
					</tbody>
				</table>
			) }

			{ totalPages > 1 ? (
				<Flex
					className="openseo-datatable__pager"
					justify="flex-end"
					gap={ 2 }
				>
					<FlexBlock />
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => onPageChange( page - 1 ) }
					>
						{ __( 'Previous', 'openseo' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'openseo' ),
							page,
							totalPages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= totalPages }
						onClick={ () => onPageChange( page + 1 ) }
					>
						{ __( 'Next', 'openseo' ) }
					</Button>
				</Flex>
			) : null }
		</div>
	);
}
