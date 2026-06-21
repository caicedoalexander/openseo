import { useSettings } from '../hooks/useSettings';
import { SaveBar } from './SaveBar';

export function SettingsPanel( { children } ) {
	const settings = useSettings( window.openseoAdmin?.settings ?? {} );

	return (
		<div className="openseo-panel">
			<div className="openseo-panel__fields">
				{ children( settings ) }
			</div>
			<SaveBar
				dirty={ settings.dirty }
				saving={ settings.saving }
				error={ settings.error }
				onSave={ settings.save }
			/>
		</div>
	);
}
