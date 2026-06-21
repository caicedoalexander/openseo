import { insertAtCursor } from './cursor';

describe( 'insertAtCursor', () => {
	it( 'inserts at the caret position', () => {
		const r = insertAtCursor( 'ab', '%x%', 1, 1 );
		expect( r.value ).toBe( 'a%x%b' );
		expect( r.cursor ).toBe( 4 );
	} );

	it( 'appends at the end', () => {
		const r = insertAtCursor( 'ab', '%x%', 2, 2 );
		expect( r.value ).toBe( 'ab%x%' );
		expect( r.cursor ).toBe( 5 );
	} );

	it( 'replaces a selection', () => {
		const r = insertAtCursor( 'abcd', '%x%', 1, 3 );
		expect( r.value ).toBe( 'a%x%d' );
		expect( r.cursor ).toBe( 4 );
	} );

	it( 'clamps out-of-range positions and appends', () => {
		const r = insertAtCursor( 'ab', '%x%', null, null );
		expect( r.value ).toBe( 'ab%x%' );
		expect( r.cursor ).toBe( 5 );
	} );
} );
