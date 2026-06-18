import { buildSnippetPreview } from './preview';

describe( 'buildSnippetPreview', () => {
	it( 'joins title and site name with the separator', () => {
		const result = buildSnippetPreview( {
			title: 'My Post',
			description: 'Short.',
			separator: '-',
			siteName: 'My Site',
		} );

		expect( result.title ).toBe( 'My Post - My Site' );
		expect( result.description ).toBe( 'Short.' );
	} );

	it( 'truncates long descriptions to 160 characters with an ellipsis', () => {
		const long = 'a'.repeat( 200 );

		const result = buildSnippetPreview( {
			title: '',
			description: long,
			separator: '-',
			siteName: 'Site',
		} );

		expect( result.description.length ).toBe( 161 ); // 160 chars + ellipsis
		expect( result.description.endsWith( '…' ) ).toBe( true );
	} );
} );
