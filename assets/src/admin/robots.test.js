import { ROBOTS_DIRECTIVES, setRobotsField } from './robots';

describe( 'ROBOTS_DIRECTIVES', () => {
	it( 'lists the five boolean directives', () => {
		expect( ROBOTS_DIRECTIVES ).toEqual( [
			'noindex',
			'nofollow',
			'noarchive',
			'nosnippet',
			'noimageindex',
		] );
	} );
} );

describe( 'setRobotsField', () => {
	it( 'sets a directive without mutating the input', () => {
		const map = { noindex: 'on' };
		const next = setRobotsField( map, 'nofollow', 'off' );
		expect( next ).toEqual( { noindex: 'on', nofollow: 'off' } );
		expect( map ).toEqual( { noindex: 'on' } );
	} );

	it( 'deletes the directive when value is empty (inherit)', () => {
		const next = setRobotsField( { noindex: 'on' }, 'noindex', '' );
		expect( next ).toEqual( {} );
	} );

	it( 'tolerates a missing map', () => {
		expect( setRobotsField( undefined, 'noindex', 'on' ) ).toEqual( {
			noindex: 'on',
		} );
	} );
} );
