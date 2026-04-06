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

const formatLastRead = ( iso ) => {
	if ( ! iso ) {
		return '—';
	}
	// Backend returns MySQL UTC `Y-m-d H:i:s`; render in site locale.
	const normalized = String( iso ).replace( ' ', 'T' ) + 'Z';
	const d = new Date( normalized );
	if ( Number.isNaN( d.getTime() ) ) {
		return String( iso );
	}
	return d.toLocaleString();
};

const SORTABLE_COLUMNS = [
	{ key: 'name', label: __( 'option_name', 'optrion' ) },
	{ key: 'owner', label: __( 'Owner', 'optrion' ) },
	{ key: 'autoload', label: __( 'Autoload', 'optrion' ) },
	{ key: 'size', label: __( 'Size', 'optrion' ) },
	{ key: 'last_read', label: __( 'Last accessed', 'optrion' ) },
	{ key: 'score', label: __( 'Score', 'optrion' ) },
];

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
	const [ orderby, setOrderby ] = useState( 'score' );
	const [ order, setOrder ] = useState( 'desc' );
	const [ showCore, setShowCore ] = useState( false );

	const load = useCallback( () => {
		setLoading( true );
		setError( null );
		api.listOptions( {
			page,
			per_page: PER_PAGE,
			search,
			score_min: scoreMin,
			owner_type: ownerType,
			orderby,
			order,
		} )
			.then( ( data ) => {
				setItems( data.items || [] );
				setTotal( data.total || 0 );
				setSelected( new Set() );
			} )
			.catch( ( e ) => setError( e.message || String( e ) ) )
			.finally( () => setLoading( false ) );
	}, [ page, search, scoreMin, ownerType, orderby, order ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const isProtected = ( item ) => item && item.owner && item.owner.type === 'core';

	const visibleItems = showCore
		? items
		: items.filter( ( item ) => ! isProtected( item ) );

	const toggle = ( item ) => {
		if ( isProtected( item ) ) {
			return;
		}
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( item.option_name ) ) {
				next.delete( item.option_name );
			} else {
				next.add( item.option_name );
			}
			return next;
		} );
	};

	const bulkDelete = () => {
		if ( ! selected.size ) return;
		if (
			! window.confirm(
				sprintf(
					__(
						'Delete %d option(s)? (automatic backup will be made)',
						'optrion'
					),
					selected.size
				)
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

	const bulkExport = async () => {
		if ( ! selected.size ) return;
		try {
			const payload = await api.export( Array.from( selected ), 0 );
			const blob = new Blob( [ JSON.stringify( payload, null, 2 ) ], {
				type: 'application/json',
			} );
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = 'optrion-export.json';
			document.body.appendChild( a );
			a.click();
			a.remove();
			URL.revokeObjectURL( url );
		} catch ( e ) {
			setError( e.message || String( e ) );
		}
	};

	const changeSort = ( key ) => {
		setPage( 1 );
		if ( key === orderby ) {
			setOrder( ( v ) => ( v === 'asc' ? 'desc' : 'asc' ) );
		} else {
			setOrderby( key );
			setOrder(
				key === 'name' || key === 'owner' || key === 'autoload'
					? 'asc'
					: 'desc'
			);
		}
	};

	const sortIndicator = ( key ) => {
		if ( key !== orderby ) {
			return '';
		}
		return order === 'asc' ? ' ▲' : ' ▼';
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
				<SelectControl
					label={ __( 'Score', 'optrion' ) }
					value={ scoreMin }
					options={ [
						{ label: __( 'All scores', 'optrion' ), value: '' },
						{ label: __( '≥20 — Review (may be unused)', 'optrion' ), value: '20' },
						{ label: __( '≥50 — Likely unused', 'optrion' ), value: '50' },
						{ label: __( '≥80 — Almost certainly unused', 'optrion' ), value: '80' },
					] }
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
						{ label: 'widget', value: 'widget' },
						{ label: 'core', value: 'core' },
						{ label: 'unknown', value: 'unknown' },
					] }
					onChange={ ( v ) => {
						setPage( 1 );
						setOwnerType( v );
					} }
				/>
				<CheckboxControl
					label={ __( 'Show WordPress-Core', 'optrion' ) }
					checked={ showCore }
					onChange={ setShowCore }
				/>
			</div>
			<div className="optrion-bulk">
				<Button
					variant="primary"
					onClick={ bulkQuarantine }
					disabled={ ! selected.size }
				>
					{ __( 'Quarantine selected', 'optrion' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ bulkExport }
					disabled={ ! selected.size }
				>
					{ __( 'Export selected', 'optrion' ) }
				</Button>
				<Button
					variant="secondary"
					isDestructive
					onClick={ bulkDelete }
					disabled={ ! selected.size }
				>
					{ __( 'Delete selected', 'optrion' ) }
				</Button>
				<span className="optrion-bulk__hint">
					{ sprintf(
						__( '%1$d selected · %2$d matches', 'optrion' ),
						selected.size,
						total
					) }
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
							{ SORTABLE_COLUMNS.map( ( col ) => (
								<th key={ col.key }>
									<button
										type="button"
										className="optrion-sort-button"
										onClick={ () => changeSort( col.key ) }
										aria-label={ sprintf(
											/* translators: %s: column heading label (e.g. "Size", "Score"). */
											__( 'Sort by %s', 'optrion' ),
											col.label
										) }
									>
										{ col.label }
										{ sortIndicator( col.key ) }
									</button>
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ visibleItems.map( ( item ) => (
							<tr
								key={ item.option_name }
								className={
									isProtected( item )
										? 'optrion-row--protected'
										: ''
								}
							>
								<td>
									<CheckboxControl
										checked={ selected.has(
											item.option_name
										) }
										disabled={ isProtected( item ) }
										onChange={ () => toggle( item ) }
										aria-label={
											isProtected( item )
												? __(
														'WordPress core option — protected',
														'optrion'
												  )
												: undefined
										}
									/>
								</td>
								<td>
									<code>{ item.option_name }</code>
								</td>
								<td>
									{ item.owner.name || item.owner.slug || '—' }
									<span className="optrion-owner-type">
										{ ' ' }
										({ item.owner.type })
									</span>
									{ isProtected( item ) && (
										<span
											className="optrion-badge optrion-badge--protected"
											title={ __(
												'WordPress core option — cannot be selected or deleted.',
												'optrion'
											) }
										>
											{ __( 'protected', 'optrion' ) }
										</span>
									) }
								</td>
								<td>{ item.autoload }</td>
								<td>{ item.size_human }</td>
								<td>
									{ formatLastRead(
										item.tracking &&
											item.tracking.last_read_at
									) }
								</td>
								<td
									className={ labelClass( item.score.label ) }
								>
									{ item.score.total }
								</td>
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
