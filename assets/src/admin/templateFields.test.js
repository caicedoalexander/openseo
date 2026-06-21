import { setTemplateField } from './templateFields';

describe( 'setTemplateField', () => {
	it( 'sets a field without mutating the original map', () => {
		const map = { post: { title: 'A', description: 'B' } };
		const next = setTemplateField( map, 'post', 'title', 'New' );

		expect( next.post.title ).toBe( 'New' );
		expect( map.post.title ).toBe( 'A' );
	} );

	it( 'preserves the other field of the same slug', () => {
		const map = { post: { title: 'A', description: 'B' } };
		const next = setTemplateField( map, 'post', 'title', 'New' );

		expect( next.post.description ).toBe( 'B' );
	} );

	it( 'preserves other slugs', () => {
		const map = { post: { title: 'A' }, page: { title: 'P' } };
		const next = setTemplateField( map, 'post', 'title', 'New' );

		expect( next.page.title ).toBe( 'P' );
	} );

	it( 'creates the slug entry when missing', () => {
		const next = setTemplateField( {}, 'post', 'title', 'New' );

		expect( next.post ).toEqual( { title: 'New' } );
	} );
} );
