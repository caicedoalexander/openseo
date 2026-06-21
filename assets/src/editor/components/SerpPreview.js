import { __ } from '@wordpress/i18n';
import { truncate } from '../preview';

const TITLE_MAX = 60;
const DESC_MAX = 160;

export function SerpPreview( {
	title,
	description,
	url,
	favicon,
	device,
	isNoindex,
} ) {
	return (
		<div className={ `openseo-serp is-${ device }` }>
			{ isNoindex && (
				<p className="openseo-serp__noindex">
					{ __(
						'This page is set to noindex — it will not appear in search results.',
						'openseo'
					) }
				</p>
			) }
			<div className="openseo-serp__url">
				{ favicon && (
					<img
						className="openseo-serp__favicon"
						src={ favicon }
						alt=""
						width="16"
						height="16"
					/>
				) }
				<span className="openseo-serp__breadcrumb">{ url }</span>
			</div>
			<div className="openseo-serp__title">
				{ truncate( title, TITLE_MAX ) }
			</div>
			<div className="openseo-serp__desc">
				{ truncate( description, DESC_MAX ) }
			</div>
		</div>
	);
}
