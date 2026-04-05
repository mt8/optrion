import { useEffect, useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextControl,
	SelectControl,
	Spinner,
	CheckboxControl,
} from '@wordpress/components';

import { api } from '../api';

const PER_PAGE = 50;

const labelClass = ( label ) => {
	switch ( label ) {
		case 'almost_unused':
			return 'optrion-score--red';
		case 'likely_unused':
			return 'optrion-score--orange';
		case 'review':
			return 'optrion-score--yellow';
		default:
			return 'optrion-score--green';
	}
};

const OptionsList = () => {
	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( () => new Set() );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ scoreMin, setScoreMin ] = useState( '' );
	const [ ownerType, setOwnerType ] = useState( '' );

	const load = useCallback( () => {
		setLoading( true );
		setError( null );
		api
			.listOptions( {
				page,
				per_page: PER_PAGE,
				search,
				score_min: scoreMin,
				owner_type: ownerType,
				orderby: 'score',
				order: 'desc',
			} )
			.then( ( data ) => {
				setItems( data.items || [] );
				setTotal( data.total || 0 );
				setSelected( new Set() );
			} )
			.catch( ( e ) => setError( e.message || String( e ) ) )
			.finally( () => setLoading( false ) );
	}, [ page, search, scoreMin, ownerType ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const toggle = ( name ) => {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( name ) ) {
				next.delete( name );
			} else {
				next.add( name );
			}
			return next;
		} );
	};

	const bulkDelete = () => {
		if ( ! selected.size ) return;
		if (
			! window.confirm(
				sprintf( __( 'Delete %d option(s)? (automatic backup will be made)', 'optrion' ), selected.size )
			)
		) {
			return;
		}
		api.deleteOptions( Array.from( selected ) ).then( load );
	};

	const bulkQuarantine = () => {
		if ( ! selected.size ) return;
		api.createQuarantine( Array.from( selected ), 0 ).then( load );
	};

	return (
		<div className="optrion-options-list">
			<div className="optrion-filters">
				<TextControl
					label={ __( 'Search', 'optrion' ) }
					value={ search }
					onChange={ ( v ) => {
						setPage( 1 );
						setSearch( v );
					} }
				/>
				<TextControl
					type="number"
					label={ __( 'Score ≥', 'optrion' ) }
					value={ scoreMin }
					onChange={ ( v ) => {
						setPage( 1 );
						setScoreMin( v );
					} }
				/>
				<SelectControl
					label={ __( 'Owner', 'optrion' ) }
					value={ ownerType }
					options={ [
						{ label: __( 'All', 'optrion' ), value: '' },
						{ label: 'plugin', value: 'plugin' },
						{ label: 'theme', value: 'theme' },
						{ label: 'core', value: 'core' },
						{ label: 'unknown', value: 'unknown' },
					] }
					onChange={ ( v ) => {
						setPage( 1 );
						setOwnerType( v );
					} }
				/>
			</div>
			<div className="optrion-bulk">
				<Button variant="primary" onClick={ bulkQuarantine } disabled={ ! selected.size }>
					{ __( 'Quarantine selected', 'optrion' ) }
				</Button>
				<Button variant="secondary" isDestructive onClick={ bulkDelete } disabled={ ! selected.size }>
					{ __( 'Delete selected', 'optrion' ) }
				</Button>
				<span className="optrion-bulk__hint">
					{ sprintf( __( '%1$d selected · %2$d matches', 'optrion' ), selected.size, total ) }
				</span>
			</div>
			{ error && <p className="optrion-error">{ error }</p> }
			{ loading ? (
				<Spinner />
			) : (
				<table className="widefat striped">
					<thead>
						<tr>
							<th style={ { width: 32 } }></th>
							<th>{ __( 'option_name', 'optrion' ) }</th>
							<th>{ __( 'Owner', 'optrion' ) }</th>
							<th>{ __( 'Autoload', 'optrion' ) }</th>
							<th>{ __( 'Size', 'optrion' ) }</th>
							<th>{ __( 'Score', 'optrion' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( item ) => (
							<tr key={ item.option_name }>
								<td>
									<CheckboxControl
										checked={ selected.has( item.option_name ) }
										onChange={ () => toggle( item.option_name ) }
									/>
								</td>
								<td>
									<code>{ item.option_name }</code>
								</td>
								<td>
									{ item.owner.slug || '—' }
									<span className="optrion-owner-type"> ({ item.owner.type })</span>
								</td>
								<td>{ item.autoload }</td>
								<td>{ item.size_human }</td>
								<td className={ labelClass( item.score.label ) }>{ item.score.total }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			<div className="optrion-pager">
				<Button
					disabled={ page <= 1 }
					variant="secondary"
					onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
				>
					{ __( 'Previous', 'optrion' ) }
				</Button>
				<span>{ sprintf( __( 'Page %d', 'optrion' ), page ) }</span>
				<Button
					disabled={ items.length < PER_PAGE }
					variant="secondary"
					onClick={ () => setPage( ( p ) => p + 1 ) }
				>
					{ __( 'Next', 'optrion' ) }
				</Button>
			</div>
		</div>
	);
};

export default OptionsList;
