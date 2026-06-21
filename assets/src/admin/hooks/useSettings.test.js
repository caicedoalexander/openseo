jest.mock( '../api' );

import { settingsReducer } from './useSettings';

describe( 'settingsReducer', () => {
	const base = { values: { a: '1' }, dirty: false, saving: false, error: '' };

	it( 'updates a value and marks dirty on CHANGE', () => {
		const next = settingsReducer( base, {
			type: 'CHANGE',
			key: 'a',
			value: '2',
		} );
		expect( next.values.a ).toBe( '2' );
		expect( next.dirty ).toBe( true );
	} );

	it( 'sets saving on SAVING', () => {
		const next = settingsReducer( base, { type: 'SAVING' } );
		expect( next.saving ).toBe( true );
	} );

	it( 'replaces values and clears flags on SAVED', () => {
		const next = settingsReducer(
			{ values: {}, dirty: true, saving: true, error: 'x' },
			{ type: 'SAVED', values: { a: '9' } }
		);
		expect( next.values.a ).toBe( '9' );
		expect( next.dirty ).toBe( false );
		expect( next.saving ).toBe( false );
	} );

	it( 'records an error and stops saving on ERROR', () => {
		const next = settingsReducer(
			{ ...base, saving: true },
			{ type: 'ERROR', error: 'boom' }
		);
		expect( next.error ).toBe( 'boom' );
		expect( next.saving ).toBe( false );
	} );
} );
