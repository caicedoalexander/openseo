import { lengthState } from './length';

const TITLE = { min: 30, max: 60, hardMax: 70 };

describe( 'lengthState', () => {
	it( 'is over when empty', () => {
		expect( lengthState( 0, TITLE ).status ).toBe( 'over' );
	} );

	it( 'warns below the minimum', () => {
		expect( lengthState( 20, TITLE ).status ).toBe( 'warn' );
	} );

	it( 'is ok inside the range', () => {
		expect( lengthState( 45, TITLE ).status ).toBe( 'ok' );
	} );

	it( 'warns between max and hardMax', () => {
		expect( lengthState( 65, TITLE ).status ).toBe( 'warn' );
	} );

	it( 'is over past hardMax', () => {
		expect( lengthState( 80, TITLE ).status ).toBe( 'over' );
	} );

	it( 'reports count and a capped percent', () => {
		const result = lengthState( 90, TITLE );
		expect( result.count ).toBe( 90 );
		expect( result.percent ).toBe( 100 );
	} );

	const DESC = { min: 120, max: 160, hardMax: 180 };

	it( 'applies the description bounds: ok inside range', () => {
		expect( lengthState( 140, DESC ).status ).toBe( 'ok' );
	} );

	it( 'applies the description bounds: over past hardMax', () => {
		expect( lengthState( 200, DESC ).status ).toBe( 'over' );
	} );
} );
