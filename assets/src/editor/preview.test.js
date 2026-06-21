import {
	expandTokens,
	resolveSnippet,
	truncate,
	deriveExcerpt,
	formatBreadcrumb,
} from './preview';

const tokens = {
	'%title%': 'Hello',
	'%excerpt%': 'A summary.',
	'%sitename%': 'My Site',
	'%tagline%': 'Tag',
	'%sep%': '-',
	'%currentyear%': '2026',
};

describe( 'expandTokens', () => {
	it( 'replaces tokens', () => {
		expect( expandTokens( '%title% %sep% %sitename%', tokens ) ).toBe(
			'Hello - My Site'
		);
	} );

	it( 'strips a dangling separator left by an empty token', () => {
		expect( expandTokens( '%title% %sep%', { ...tokens, '%title%': '' } ) ).toBe(
			''
		);
	} );

	it( 'collapses whitespace from empty tokens mid-template', () => {
		expect(
			expandTokens( '%title% %sep% %sitename%', { ...tokens, '%title%': '' } )
		).toBe( 'My Site' );
	} );

	it( 'treats a multi-character separator as a whole string', () => {
		const multi = { ...tokens, '%sep%': '—', '%title%': '' };
		expect( expandTokens( '%title% %sep% %sitename%', multi ) ).toBe( 'My Site' );
	} );

	it( 'escapes regex metacharacters in the separator', () => {
		const meta = { ...tokens, '%sep%': '|', '%title%': '' };
		expect( expandTokens( '%title% %sep% %sitename%', meta ) ).toBe( 'My Site' );
	} );
} );

describe( 'resolveSnippet', () => {
	it( 'uses the override when present', () => {
		expect(
			resolveSnippet( { override: 'Manual', template: '%title%', tokens } )
		).toBe( 'Manual' );
	} );

	it( 'expands the template when there is no override', () => {
		expect(
			resolveSnippet( { override: '', template: '%title% %sep% %sitename%', tokens } )
		).toBe( 'Hello - My Site' );
	} );
} );

describe( 'truncate', () => {
	it( 'adds an ellipsis past the max', () => {
		expect( truncate( 'a'.repeat( 10 ), 5 ) ).toBe( 'aaaaa…' );
	} );

	it( 'leaves short text untouched', () => {
		expect( truncate( 'short', 60 ) ).toBe( 'short' );
	} );
} );

describe( 'deriveExcerpt', () => {
	it( 'strips block comments and tags and collapses whitespace', () => {
		const content =
			'<!-- wp:paragraph --><p>Hello   <strong>world</strong>.</p><!-- /wp:paragraph -->';
		expect( deriveExcerpt( content ) ).toBe( 'Hello world.' );
	} );
} );

describe( 'formatBreadcrumb', () => {
	it( 'drops the protocol and joins path segments with a chevron', () => {
		expect( formatBreadcrumb( 'https://example.com/blog/my-post/' ) ).toBe(
			'example.com › blog › my-post'
		);
	} );

	it( 'shows just the host for a root URL', () => {
		expect( formatBreadcrumb( 'https://example.com/' ) ).toBe( 'example.com' );
	} );
} );
