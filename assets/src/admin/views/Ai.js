import { TextControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function Ai() {
	const connector = window.openseoAdmin?.connector ?? {
		available: false,
		url: '',
	};

	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<>
					<Notice
						status={ connector.available ? 'success' : 'warning' }
						isDismissible={ false }
					>
						{ connector.available ? (
							__(
								'An AI connector is configured. The editor can generate titles and descriptions.',
								'openseo'
							)
						) : (
							<>
								{ __(
									'No AI connector is configured.',
									'openseo'
								) }{ ' ' }
								{ connector.url && (
									<a href={ connector.url }>
										{ __(
											'Settings → Connectors',
											'openseo'
										) }
									</a>
								) }
							</>
						) }
					</Notice>
					<TextControl
						label={ __(
							'AI model (optional override)',
							'openseo'
						) }
						value={ values.ai_model }
						onChange={ ( v ) => change( 'ai_model', v ) }
					/>
				</>
			) }
		</SettingsPanel>
	);
}
