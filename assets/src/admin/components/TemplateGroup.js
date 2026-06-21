import { TextControl, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { setTemplateField } from '../templateFields';

export function TemplateGroup( { types, mapKey, values, change } ) {
	const map = values[ mapKey ] ?? {};

	if ( ! types.length ) {
		return null;
	}

	return (
		<>
			{ types.map( ( type ) => (
				<div key={ type.slug } className="openseo-template-group__item">
					<TextControl
						label={ sprintf(
							/* translators: %s: content type or taxonomy label. */
							__( '%s title', 'openseo' ),
							type.label
						) }
						value={ map[ type.slug ]?.title ?? '' }
						placeholder={ type.defaultTitle }
						onChange={ ( v ) =>
							change(
								mapKey,
								setTemplateField( map, type.slug, 'title', v )
							)
						}
					/>
					<TextareaControl
						label={ sprintf(
							/* translators: %s: content type or taxonomy label. */
							__( '%s description', 'openseo' ),
							type.label
						) }
						value={ map[ type.slug ]?.description ?? '' }
						placeholder={ type.defaultDescription }
						onChange={ ( v ) =>
							change(
								mapKey,
								setTemplateField(
									map,
									type.slug,
									'description',
									v
								)
							)
						}
					/>
				</div>
			) ) }
		</>
	);
}
