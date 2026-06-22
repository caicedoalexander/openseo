import { recordName, recordTitle, formatTokenDate } from './tokens';

describe( 'recordName', () => {
	it( 'returns the name of a record', () => {
		expect( recordName( { name: 'News' } ) ).toBe( 'News' );
	} );
	it( 'returns empty string for undefined or nameless records', () => {
		expect( recordName( undefined ) ).toBe( '' );
		expect( recordName( {} ) ).toBe( '' );
	} );
} );

describe( 'recordTitle', () => {
	it( 'returns the rendered title of a record', () => {
		expect( recordTitle( { title: { rendered: 'Parent' } } ) ).toBe(
			'Parent'
		);
	} );
	it( 'returns empty string when missing', () => {
		expect( recordTitle( undefined ) ).toBe( '' );
		expect( recordTitle( { title: {} } ) ).toBe( '' );
	} );
} );

describe( 'formatTokenDate', () => {
	it( 'returns empty string for falsy input', () => {
		expect( formatTokenDate( '' ) ).toBe( '' );
		expect( formatTokenDate( undefined ) ).toBe( '' );
		expect( formatTokenDate( null ) ).toBe( '' );
	} );
} );
