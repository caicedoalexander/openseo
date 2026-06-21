import { variablesForScope, filterVariables } from './variables';

const catalog = [
	{
		token: '%sitename%',
		label: 'Site title',
		description: "Your site's name",
		scope: 'global',
	},
	{
		token: '%title%',
		label: 'Title',
		description: 'The entry title',
		scope: 'singular',
	},
	{
		token: '%term%',
		label: 'Term name',
		description: 'The taxonomy term name',
		scope: 'taxonomy',
	},
];

describe( 'variablesForScope', () => {
	it( 'returns global + singular for the singular scope, in order', () => {
		const r = variablesForScope( catalog, 'singular' );
		expect( r.map( ( v ) => v.token ) ).toEqual( [
			'%sitename%',
			'%title%',
		] );
	} );

	it( 'returns global + taxonomy for the taxonomy scope', () => {
		const r = variablesForScope( catalog, 'taxonomy' );
		expect( r.map( ( v ) => v.token ) ).toEqual( [
			'%sitename%',
			'%term%',
		] );
	} );

	it( 'returns only global for the global scope', () => {
		const r = variablesForScope( catalog, 'global' );
		expect( r.map( ( v ) => v.token ) ).toEqual( [ '%sitename%' ] );
	} );

	it( 'tolerates a missing catalog', () => {
		expect( variablesForScope( undefined, 'global' ) ).toEqual( [] );
	} );
} );

describe( 'filterVariables', () => {
	it( 'returns the whole list for an empty query', () => {
		expect( filterVariables( catalog, '' ) ).toHaveLength( 3 );
	} );

	it( 'matches label, token, or description case-insensitively', () => {
		expect(
			filterVariables( catalog, 'TERM' ).map( ( v ) => v.token )
		).toEqual( [ '%term%' ] );
		expect(
			filterVariables( catalog, '%title%' ).map( ( v ) => v.token )
		).toEqual( [ '%title%' ] );
		expect(
			filterVariables( catalog, 'your site' ).map( ( v ) => v.token )
		).toEqual( [ '%sitename%' ] );
	} );

	it( 'returns empty when nothing matches', () => {
		expect( filterVariables( catalog, 'zzz' ) ).toEqual( [] );
	} );
} );
