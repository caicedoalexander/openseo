import { Card, CardHeader, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function Dashboard() {
	const d = window.openseoAdmin?.dashboard ?? { redirects: 0, notfound: 0 };
	const c = window.openseoAdmin?.connector ?? { available: false, url: '' };

	return (
		<div className="openseo-dashboard">
			<Card>
				<CardHeader>{ __( 'AI connector', 'openseo' ) }</CardHeader>
				<CardBody>
					{ c.available ? (
						__( 'Connected.', 'openseo' )
					) : (
						<>
							{ __( 'Not configured.', 'openseo' ) }{ ' ' }
							<a href={ c.url }>{ __( 'Connect', 'openseo' ) }</a>
						</>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardHeader>{ __( 'Active redirects', 'openseo' ) }</CardHeader>
				<CardBody>{ d.redirects }</CardBody>
			</Card>
			<Card>
				<CardHeader>{ __( 'Logged 404s', 'openseo' ) }</CardHeader>
				<CardBody>{ d.notfound }</CardBody>
			</Card>
		</div>
	);
}
