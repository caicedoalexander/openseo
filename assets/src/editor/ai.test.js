jest.mock( '@wordpress/i18n' );

import { aiErrorMessage } from './ai';

describe( 'aiErrorMessage', () => {
	it( 'gives a connector hint for openseo_no_connector', () => {
		const message = aiErrorMessage( { code: 'openseo_no_connector' } );

		expect( message ).toContain( 'Connectors' );
	} );

	it( 'returns the provider message for other errors', () => {
		expect( aiErrorMessage( { code: 'x', message: 'Boom' } ) ).toBe(
			'Boom'
		);
	} );

	it( 'falls back to a generic message when none is given', () => {
		expect( aiErrorMessage( {} ) ).toMatch( /failed/i );
	} );
} );
