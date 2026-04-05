import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, SelectControl, Spinner } from '@wordpress/components';

import { api } from '../api';

const Quarantine = () => {
	const [ rows, setRows ] = useState( [] );
	const [ status, setStatus ] = useState( 'active' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const load = useCallback( () => {
		setLoading( true );
		setError( null );
		api
			.listQuarantine( status )
			.then( ( res ) => setRows( res.items || [] ) )
			.catch( ( e ) => setError( e.message || String( e ) ) )
			.finally( () => setLoading( false ) );
	}, [ status ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const restore = ( id ) => {
		api.restoreQuarantine( [ id ] ).then( load );
	};
	const destroy = ( id ) => {
		if ( ! window.confirm( __( 'Permanently delete this quarantined option?', 'optrion' ) ) ) {
			return;
		}
		api.deleteQuarantine( [ id ] ).then( load );
	};

	return (
		<div className="optrion-quarantine">
			<SelectControl
				label={ __( 'Status', 'optrion' ) }
				value={ status }
				options={ [
					{ label: __( 'Active', 'optrion' ), value: 'active' },
					{ label: __( 'Restored', 'optrion' ), value: 'restored' },
					{ label: __( 'Deleted', 'optrion' ), value: 'deleted' },
				] }
				onChange={ setStatus }
			/>
			{ error && <p className="optrion-error">{ error }</p> }
			{ loading ? (
				<Spinner />
			) : (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Original name', 'optrion' ) }</th>
							<th>{ __( 'Quarantined at', 'optrion' ) }</th>
							<th>{ __( 'Expires at', 'optrion' ) }</th>
							<th>{ __( 'Score', 'optrion' ) }</th>
							<th>{ __( 'Actions', 'optrion' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.id }>
								<td>
									<code>{ row.original_name }</code>
								</td>
								<td>{ row.quarantined_at }</td>
								<td>{ row.expires_at }</td>
								<td>{ row.score_at_quarantine }</td>
								<td>
									{ 'active' === status && (
										<>
											<Button variant="secondary" onClick={ () => restore( row.id ) }>
												{ __( 'Restore', 'optrion' ) }
											</Button>
											<Button isDestructive onClick={ () => destroy( row.id ) }>
												{ __( 'Delete', 'optrion' ) }
											</Button>
										</>
									) }
								</td>
							</tr>
						) ) }
						{ rows.length === 0 && (
							<tr>
								<td colSpan="5">{ __( 'No entries.', 'optrion' ) }</td>
							</tr>
						) }
					</tbody>
				</table>
			) }
		</div>
	);
};

export default Quarantine;
