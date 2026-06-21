import { listReducer } from './listReducer';

describe( 'listReducer', () => {
	const base = { items: [], total: 0, loading: false, error: '' };

	it( 'sets loading and clears error on LOADING', () => {
		const next = listReducer(
			{ ...base, error: 'x' },
			{ type: 'LOADING' }
		);
		expect( next.loading ).toBe( true );
		expect( next.error ).toBe( '' );
	} );

	it( 'stores items + total and clears loading on LOADED', () => {
		const next = listReducer(
			{ ...base, loading: true },
			{ type: 'LOADED', items: [ { id: 1 } ], total: 5 }
		);
		expect( next.items ).toHaveLength( 1 );
		expect( next.total ).toBe( 5 );
		expect( next.loading ).toBe( false );
	} );

	it( 'records error and stops loading on ERROR', () => {
		const next = listReducer(
			{ ...base, loading: true },
			{ type: 'ERROR', error: 'boom' }
		);
		expect( next.error ).toBe( 'boom' );
		expect( next.loading ).toBe( false );
	} );
} );
