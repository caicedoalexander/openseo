import { lengthState } from '../length';

export function LengthIndicator( { value, min, max, hardMax } ) {
	const { count, status, percent } = lengthState( value.length, {
		min,
		max,
		hardMax,
	} );

	return (
		<div className={ `openseo-length openseo-length--${ status }` }>
			<div className="openseo-length__track">
				<div
					className="openseo-length__bar"
					style={ { width: `${ percent }%` } }
				/>
			</div>
			<span className="openseo-length__count">{ `${ count } / ${ max }` }</span>
		</div>
	);
}
