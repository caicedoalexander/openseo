<?php
/**
 * Builds the prompts OpenSEO sends to the AI Client.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Ai;

/**
 * Pure prompt construction — no WordPress calls, so it unit-tests in isolation.
 *
 * Instructions are written in English and ask the model to reply in the
 * content's own language, so a single prompt works for any site locale.
 */
final class Prompts {

	public const META_DESCRIPTION_MAX_CHARS = 155;

	public const TITLE_MAX_CHARS = 60;

	/**
	 * System instruction for the meta-description ability.
	 */
	public static function system_meta_description(): string {
		return sprintf(
			'You are an SEO expert. Write a single, compelling meta description of at most %d characters for the article below. Write in the same language as the article. Return only the description text, with no surrounding quotes or labels.',
			self::META_DESCRIPTION_MAX_CHARS
		);
	}

	/**
	 * System instruction for the schema-type recommendation ability.
	 */
	public static function system_schema_type(): string {
		return 'You are an SEO expert. Analyze the article below and recommend the single most fitting schema.org type from this list: Article, BlogPosting, NewsArticle, WebPage, FAQPage, HowTo, Recipe, Product. Reply as JSON with two keys: "type" (exactly one value from the list) and "reason" (one short sentence explaining why, in the same language as the article).';
	}

	/**
	 * System instruction for the title ability.
	 */
	public static function system_title(): string {
		return sprintf(
			'You are an SEO expert. Write a single, concise SEO title of at most %d characters for the article below. Write in the same language as the article. Return only the title text, with no surrounding quotes or labels.',
			self::TITLE_MAX_CHARS
		);
	}

	/**
	 * User prompt assembled from a post's title and (stripped) content.
	 *
	 * @param string $title   Post title.
	 * @param string $content Plain-text, length-trimmed post content.
	 */
	public static function user_for_post( string $title, string $content ): string {
		return sprintf( "Title: %s\n\nContent:\n%s", $title, $content );
	}
}
