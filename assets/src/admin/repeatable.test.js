import { addRow, removeRow, updateCell } from './repeatable';

describe( 'repeatable helpers', () => {
	it( 'addRow appends a copy of emptyRow without mutating', () => {
		const rows = [ { a: '1' } ];
		const next = addRow( rows, { a: '' } );
		expect( next ).toEqual( [ { a: '1' }, { a: '' } ] );
		expect( rows ).toHaveLength( 1 );
	} );

	it( 'removeRow drops the row at index immutably', () => {
		const rows = [ { a: '1' }, { a: '2' }, { a: '3' } ];
		const next = removeRow( rows, 1 );
		expect( next ).toEqual( [ { a: '1' }, { a: '3' } ] );
		expect( rows ).toHaveLength( 3 );
	} );

	it( 'updateCell sets one cell of one row immutably', () => {
		const rows = [
			{ a: '1', b: 'x' },
			{ a: '2', b: 'y' },
		];
		const next = updateCell( rows, 0, 'b', 'z' );
		expect( next[ 0 ] ).toEqual( { a: '1', b: 'z' } );
		expect( next[ 1 ] ).toBe( rows[ 1 ] );
		expect( rows[ 0 ].b ).toBe( 'x' );
	} );

	it( 'tolerates a missing rows array', () => {
		expect( addRow( undefined, { a: '' } ) ).toEqual( [ { a: '' } ] );
		expect( removeRow( undefined, 0 ) ).toEqual( [] );
		expect( updateCell( undefined, 0, 'a', '1' ) ).toEqual( [] );
	} );
} );
