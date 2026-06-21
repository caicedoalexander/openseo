import { useRef, useEffect } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { VariableInserter } from './VariableInserter';
import { insertAtCursor } from '../cursor';

export function TemplateField( {
	label,
	value,
	placeholder,
	multiline,
	scope,
	catalog,
	onChange,
} ) {
	const inputRef = useRef( null );
	const pendingCursor = useRef( null );
	const instanceId = useInstanceId( TemplateField );
	const fieldId = `openseo-tf-${ instanceId }`;

	useEffect( () => {
		if ( pendingCursor.current !== null && inputRef.current ) {
			const pos = pendingCursor.current;
			inputRef.current.focus();
			inputRef.current.setSelectionRange( pos, pos );
			pendingCursor.current = null;
		}
	} );

	const handleInsert = ( token ) => {
		const el = inputRef.current;
		const start = el ? el.selectionStart : value.length;
		const end = el ? el.selectionEnd : value.length;
		const result = insertAtCursor( value, token, start, end );
		pendingCursor.current = result.cursor;
		onChange( result.value );
	};

	const sharedProps = {
		id: fieldId,
		ref: inputRef,
		className: 'components-text-control__input',
		value,
		placeholder,
		onChange: ( e ) => onChange( e.target.value ),
	};

	return (
		<div className="openseo-template-field">
			<div className="openseo-template-field__header">
				<label
					htmlFor={ fieldId }
					className="openseo-template-field__label"
				>
					{ label }
				</label>
				<VariableInserter
					catalog={ catalog }
					scope={ scope }
					onInsert={ handleInsert }
				/>
			</div>
			{ multiline ? (
				<textarea { ...sharedProps } rows={ 3 } />
			) : (
				<input type="text" { ...sharedProps } />
			) }
		</div>
	);
}
