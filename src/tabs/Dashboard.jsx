import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

import { api } from '../api';

const Dashboard = () => {
	const [ stats, setStats ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		api.stats()
			.then( setStats )
			.catch( ( e ) => setError( e.message || String( e ) ) );
	}, [] );

	if ( error ) {
		return <p className="optrion-error">{ error }</p>;
	}
	if ( ! stats ) {
		return <Spinner />;
	}

	const cards = [
		{ label: __( 'Total options', 'optrion' ), value: stats.total_options },
		{ label: __( 'Autoload payload', 'optrion' ), value: stats.autoload_total_size_human },
	];

	return (
		<div className="optrion-dashboard">
			<div className="optrion-cards">
				{ cards.map( ( card ) => (
					<div className="optrion-card" key={ card.label }>
						<div className="optrion-card__label">{ card.label }</div>
						<div className="optrion-card__value">{ card.value }</div>
					</div>
				) ) }
			</div>
		</div>
	);
};

export default Dashboard;
