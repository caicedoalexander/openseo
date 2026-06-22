import { triValue, isNoindexValue } from './robots';

describe( 'triValue', () => {
	it( 'maps legacy "1" to "on"', () => {
		expect( triValue( '1' ) ).toBe( 'on' );
	} );

	it( 'passes through tri-state values unchanged', () => {
		expect( triValue( 'on' ) ).toBe( 'on' );
		expect( triValue( 'off' ) ).toBe( 'off' );
		expect( triValue( '' ) ).toBe( '' );
	} );
} );

describe( 'isNoindexValue', () => {
	it( 'is true for "on" and legacy "1"', () => {
		expect( isNoindexValue( 'on' ) ).toBe( true );
		expect( isNoindexValue( '1' ) ).toBe( true );
	} );

	it( 'is false for "off" and inherit ("")', () => {
		expect( isNoindexValue( 'off' ) ).toBe( false );
		expect( isNoindexValue( '' ) ).toBe( false );
	} );
} );
