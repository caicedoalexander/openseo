import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { deriveExcerpt } from './preview';
import { recordName, recordTitle, formatTokenDate } from './tokens';

/**
 * Assemble the live token map for the editor SERP preview. Mirrors the tokens
 * that Variables::replace expands server-side. Records resolved via core-data
 * are '' until they load, so the preview never shows a raw token.
 *
 * @return {Object} Map of token -> replacement string.
 */
export function useTemplateTokens() {
	return useSelect( ( select ) => {
		const editor = select( editorStore );
		const core = select( coreStore );
		const cfg = window.openseoEditor ?? {};

		const content = editor.getEditedPostContent() || '';
		const postType = editor.getCurrentPostType();

		const authorId = editor.getEditedPostAttribute( 'author' );
		const author = authorId
			? recordName( core.getEntityRecord( 'root', 'user', authorId ) )
			: '';

		const catIds = editor.getEditedPostAttribute( 'categories' ) || [];
		const category = catIds.length
			? recordName(
					core.getEntityRecord( 'taxonomy', 'category', catIds[ 0 ] )
			  )
			: '';

		const tagIds = editor.getEditedPostAttribute( 'tags' ) || [];
		const tag = tagIds.length
			? recordName(
					core.getEntityRecord( 'taxonomy', 'post_tag', tagIds[ 0 ] )
			  )
			: '';

		const parentId = editor.getEditedPostAttribute( 'parent' );
		const parentTitle = parentId
			? recordTitle(
					core.getEntityRecord( 'postType', postType, parentId )
			  )
			: '';

		return {
			'%title%': editor.getEditedPostAttribute( 'title' ) || '',
			'%excerpt%':
				editor.getEditedPostAttribute( 'excerpt' ) ||
				deriveExcerpt( content ),
			'%sitename%': cfg.siteName ?? '',
			'%tagline%': cfg.tagline ?? '',
			'%sep%': cfg.separator ?? '-',
			'%currentyear%': String( new Date().getUTCFullYear() ),
			'%date%': formatTokenDate(
				editor.getEditedPostAttribute( 'date' )
			),
			'%modified%': formatTokenDate(
				editor.getEditedPostAttribute( 'modified' )
			),
			'%author%': author,
			'%category%': category,
			'%tag%': tag,
			'%parent_title%': parentTitle,
		};
	}, [] );
}
