import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, SelectControl, Spinner, Notice } from '@wordpress/components';

import { api } from '../api';

const Quarantine = () => {
	const [ rows, setRows ] = useState( [] );
	const [ status, setStatus ] = useState( 'active' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );

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
		api.restoreQuarantine( [ id ] )
			.then( () => {
				setNotice( { type: 'success', message: __( 'Option restored.', 'optrion' ) } );
				load();
			} )
			.catch( ( e ) => setNotice( { type: 'error', message: e.message || String( e ) } ) );
	};
	const destroy = ( id ) => {
		if (
			! window.confirm(
				__(
					'Permanently delete this quarantined option?',
					'optrion'
				)
			)
		) {
			return;
		}
		api.deleteQuarantine( [ id ] )
			.then( () => {
				setNotice( { type: 'success', message: __( 'Option permanently deleted.', 'optrion' ) } );
				load();
			} )
			.catch( ( e ) => setNotice( { type: 'error', message: e.message || String( e ) } ) );
	};

	return (
		<div className="optrion-quarantine">
			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<SelectControl
				label={ __( 'Status', 'optrion' ) }
				value={ status }
				options={ [
					{ label: __( 'Active', 'optrion' ), value: 'active' },
					{
						label: __( 'Restored', 'optrion' ),
						value: 'restored',
					},
					{ label: __( 'Deleted', 'optrion' ), value: 'deleted' },
				] }
				onChange={ setStatus }
			/>
			{ error && <p className="optrion-error">{ error }</p> }
			{ loading ? (
				<Spinner />
			) : (
				<div className="optrion-table-scroll">
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Original name', 'optrion' ) }</th>
							<th>{ __( 'Quarantined at', 'optrion' ) }</th>
							<th>{ __( 'Expires at', 'optrion' ) }</th>
							<th>{ __( 'Last accessed', 'optrion' ) }</th>
							<th>{ __( 'Access count', 'optrion' ) }</th>
							<th>{ __( 'Accessor', 'optrion' ) }</th>
							<th>{ __( 'Actions', 'optrion' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.id }>
								<td>
									<code>{ row.original_name }</code>
									{ row.still_accessed && (
										<span
											className="optrion-badge optrion-badge--warning"
											title={ __(
												'This option was accessed after quarantine. Restore it to keep the site working; auto-expiry is paused until you restore.',
												'optrion'
											) }
										>
											{ __( 'in use — restore', 'optrion' ) }
										</span>
									) }
								</td>
								<td>{ row.quarantined_at }</td>
								<td>{ row.expires_at }</td>
								<td>{ row.last_accessed_at || '—' }</td>
								<td>
									{ row.access_count_during_quarantine > 0
										? row.access_count_during_quarantine
										: '—' }
								</td>
								<td>
									{ row.accessor_during_quarantine ? (
										<>
											{ row.accessor_during_quarantine }
											{ row.accessor_type_during_quarantine && (
												<span className="optrion-accessor-type">
													{ ' ' }
													({ row.accessor_type_during_quarantine })
												</span>
											) }
										</>
									) : (
										'—'
									) }
								</td>
								<td>
									{ 'active' === status && (
										<>
											<Button
												variant="secondary"
												onClick={ () =>
													restore( row.id )
												}
											>
												{ __( 'Restore', 'optrion' ) }
											</Button>
											{ ! row.still_accessed && (
												<Button
													isDestructive
													onClick={ () =>
														destroy( row.id )
													}
												>
													{ __(
														'Delete',
														'optrion'
													) }
												</Button>
											) }
										</>
									) }
								</td>
							</tr>
						) ) }
						{ rows.length === 0 && (
							<tr>
								<td colSpan="7">
									{ __( 'No entries.', 'optrion' ) }
								</td>
							</tr>
						) }
					</tbody>
				</table>
				</div>
			) }
		</div>
	);
};

export default Quarantine;
