import {
	setAdvancedRobots,
	SEPARATOR_PRESETS,
	MAX_IMAGE_PREVIEW_VALUES,
} from './advancedRobots';

describe( 'setAdvancedRobots', () => {
	it( 'sets a nested field without mutating the input', () => {
		const map = { max_snippet: { enabled: '', length: '-1' } };
		const next = setAdvancedRobots( map, 'max_snippet', 'enabled', '1' );
		expect( next.max_snippet ).toEqual( { enabled: '1', length: '-1' } );
		expect( map.max_snippet.enabled ).toBe( '' );
	} );

	it( 'creates the block when absent', () => {
		const next = setAdvancedRobots(
			{},
			'max_image_preview',
			'value',
			'none'
		);
		expect( next.max_image_preview ).toEqual( { value: 'none' } );
	} );

	it( 'preserves other blocks', () => {
		const map = {
			max_snippet: { enabled: '1' },
			max_video_preview: { enabled: '' },
		};
		const next = setAdvancedRobots(
			map,
			'max_video_preview',
			'enabled',
			'1'
		);
		expect( next.max_snippet ).toEqual( { enabled: '1' } );
		expect( next.max_video_preview ).toEqual( { enabled: '1' } );
	} );
} );

describe( 'constants', () => {
	it( 'lists the six separator presets', () => {
		expect( SEPARATOR_PRESETS ).toEqual( [ '-', '–', '—', '»', '|', '•' ] );
	} );

	it( 'lists the image preview values', () => {
		expect( MAX_IMAGE_PREVIEW_VALUES ).toEqual( [
			'large',
			'standard',
			'none',
		] );
	} );
} );
