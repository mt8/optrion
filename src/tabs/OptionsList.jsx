import { useEffect, useState, useCallback, useMemo, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextControl,
	SelectControl,
	Spinner,
	CheckboxControl,
	Notice,
} from '@wordpress/components';

import { api } from '../api';

const PER_PAGE_CHOICES = [ 25, 50, 100, 200 ];
const DEFAULT_PER_PAGE = 50;

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

const COLUMNS = [
	{ key: 'name', label: __( 'option_name', 'optrion' ), sortable: true },
	{ key: 'accessor', label: __( 'Accessor', 'optrion' ), sortable: true },
	{ key: 'autoload', label: __( 'Autoload', 'optrion' ), sortable: false },
	{ key: 'size', label: __( 'Size', 'optrion' ), sortable: true },
	{ key: 'last_read', label: __( 'Last accessed', 'optrion' ), sortable: true },
];

const OptionsList = () => {
	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ selected, setSelected ] = useState( () => new Set() );
	const [ page, setPage ] = useState( 1 );
	const [ perPage, setPerPage ] = useState( DEFAULT_PER_PAGE );
	const [ pageInput, setPageInput ] = useState( '1' );
	const [ search, setSearch ] = useState( '' );
	const [ accessorType, setAccessorType ] = useState( '' );
	const [ inactiveOnly, setInactiveOnly ] = useState( false );
	const [ autoloadOnly, setAutoloadOnly ] = useState( false );
	const [ orderby, setOrderby ] = useState( 'name' );
	const [ order, setOrder ] = useState( 'asc' );
	const [ showCore, setShowCore ] = useState( true );

	const load = useCallback( () => {
		setLoading( true );
		setError( null );
		api.listOptions( {
			page,
			per_page: perPage,
			search,
			accessor_type: accessorType,
			inactive_only: inactiveOnly,
			autoload_only: autoloadOnly,
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
	}, [ page, perPage, search, accessorType, inactiveOnly, autoloadOnly, orderby, order ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const totalPages = Math.max( 1, Math.ceil( total / perPage ) );

	useEffect( () => {
		setPageInput( String( page ) );
	}, [ page ] );

	const goToPage = ( raw ) => {
		const parsed = parseInt( String( raw ), 10 );
		if ( Number.isNaN( parsed ) ) {
			setPageInput( String( page ) );
			return;
		}
		const clamped = Math.max( 1, Math.min( totalPages, parsed ) );
		setPage( clamped );
		setPageInput( String( clamped ) );
	};

	const isProtected = ( item ) => item && item.accessor && item.accessor.type === 'core';

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

	const selectableNames = useMemo(
		() =>
			visibleItems
				.filter( ( item ) => ! isProtected( item ) )
				.map( ( item ) => item.option_name ),
		[ visibleItems ]
	);

	const selectedSelectableCount = useMemo(
		() => selectableNames.filter( ( name ) => selected.has( name ) ).length,
		[ selectableNames, selected ]
	);

	const allSelectableChecked =
		selectableNames.length > 0 &&
		selectedSelectableCount === selectableNames.length;
	const someSelectableChecked =
		selectedSelectableCount > 0 && ! allSelectableChecked;

	const selectAllRef = useRef( null );
	useEffect( () => {
		if ( selectAllRef.current ) {
			selectAllRef.current.indeterminate = someSelectableChecked;
		}
	}, [ someSelectableChecked ] );

	const toggleAllVisible = () => {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( allSelectableChecked ) {
				selectableNames.forEach( ( name ) => next.delete( name ) );
			} else {
				selectableNames.forEach( ( name ) => next.add( name ) );
			}
			return next;
		} );
	};

	const bulkDelete = () => {
		if ( ! selected.size ) return;
		if (
			! window.confirm(
				sprintf(
					/* translators: %d: number of options about to be deleted. */
					__(
						'Permanently delete %d option(s)?\n\nOptrion does NOT write a server-side backup. If you want a restore copy, cancel this dialog, use "Export selected" to download a JSON file to your machine, then come back and delete.',
						'optrion'
					),
					selected.size
				)
			)
		) {
			return;
		}
		api.deleteOptions( Array.from( selected ) )
			.then( ( res ) => {
				const count = res && typeof res.deleted === 'number' ? res.deleted : selected.size;
				setNotice( {
					type: 'success',
					/* translators: %d: number of deleted options. */
					message: sprintf( __( '%d option(s) deleted.', 'optrion' ), count ),
				} );
				load();
			} )
			.catch( ( e ) => setNotice( { type: 'error', message: e.message || String( e ) } ) );
	};

	const bulkQuarantine = () => {
		if ( ! selected.size ) return;
		api.createQuarantine( Array.from( selected ), 0 )
			.then( ( res ) => {
				const count = res && res.quarantined ? res.quarantined.length : selected.size;
				setNotice( {
					type: 'success',
					/* translators: %d: number of quarantined options. */
					message: sprintf( __( '%d option(s) quarantined.', 'optrion' ), count ),
				} );
				load();
			} )
			.catch( ( e ) => setNotice( { type: 'error', message: e.message || String( e ) } ) );
	};

	const bulkExport = async () => {
		if ( ! selected.size ) return;
		try {
			const payload = await api.export( Array.from( selected ) );
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
			setNotice( {
				type: 'success',
				/* translators: %d: number of exported options. */
				message: sprintf( __( '%d option(s) exported.', 'optrion' ), selected.size ),
			} );
		} catch ( e ) {
			setNotice( { type: 'error', message: e.message || String( e ) } );
		}
	};

	const changeSort = ( key ) => {
		setPage( 1 );
		if ( key === orderby ) {
			setOrder( ( v ) => ( v === 'asc' ? 'desc' : 'asc' ) );
		} else {
			setOrderby( key );
			setOrder(
				key === 'size' || key === 'last_read' ? 'desc' : 'asc'
			);
		}
	};

	const sortIndicator = ( key ) => {
		if ( key !== orderby ) {
			return '';
		}
		return order === 'asc' ? ' ▲' : ' ▼';
	};

	const renderAccessorCell = ( item ) => {
		const accessor = item.accessor || {};
		const name     = accessor.name || accessor.slug || '—';
		const type     = accessor.type || 'unknown';
		const isActive = accessor.active === true;
		const showInactive =
			type === 'plugin' || type === 'theme' ? ! isActive : false;
		return (
			<>
				{ name }
				<span className="optrion-accessor-type"> ({ type })</span>
				{ showInactive && (
					<span
						className="optrion-badge optrion-badge--inactive"
						title={ __(
							'Accessor plugin/theme is currently inactive.',
							'optrion'
						) }
					>
						{ __( 'inactive', 'optrion' ) }
					</span>
				) }
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
			</>
		);
	};


	const bulkActions = (
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
					/* translators: 1: selected rows, 2: matching rows. */
					__( '%1$d selected · %2$d matches', 'optrion' ),
					selected.size,
					total
				) }
			</span>
		</div>
	);

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
					label={ __( 'Accessor', 'optrion' ) }
					value={ accessorType }
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
						setAccessorType( v );
					} }
				/>
				<CheckboxControl
					label={ __( 'Inactive accessors only', 'optrion' ) }
					checked={ inactiveOnly }
					onChange={ ( v ) => {
						setPage( 1 );
						setInactiveOnly( v );
					} }
				/>
				<CheckboxControl
					label={ __( 'Autoload only', 'optrion' ) }
					checked={ autoloadOnly }
					onChange={ ( v ) => {
						setPage( 1 );
						setAutoloadOnly( v );
					} }
				/>
				<CheckboxControl
					label={ __( 'Show WordPress-Core', 'optrion' ) }
					checked={ showCore }
					onChange={ setShowCore }
				/>
			</div>
			{ bulkActions }
			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ error && <p className="optrion-error">{ error }</p> }
			{ loading ? (
				<Spinner />
			) : (
				<div className="optrion-table-scroll">
				<table className="widefat striped">
					<thead>
						<tr>
							<th style={ { width: 32 } }>
								<input
									type="checkbox"
									ref={ selectAllRef }
									checked={ allSelectableChecked }
									disabled={ selectableNames.length === 0 }
									onChange={ toggleAllVisible }
									aria-label={ __(
										'Select all options on this page',
										'optrion'
									) }
								/>
							</th>
							{ COLUMNS.map( ( col ) =>
								col.sortable ? (
									<th key={ col.key }>
										<button
											type="button"
											className="optrion-sort-button"
											onClick={ () => changeSort( col.key ) }
											aria-label={ sprintf(
												/* translators: %s: column heading label (e.g. "Size"). */
												__( 'Sort by %s', 'optrion' ),
												col.label
											) }
										>
											{ col.label }
											{ sortIndicator( col.key ) }
										</button>
									</th>
								) : (
									<th key={ col.key }>{ col.label }</th>
								)
							) }
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
								<td>{ renderAccessorCell( item ) }</td>
								<td>
									{ item.is_autoload ? (
										<span
											className="optrion-badge optrion-badge--autoload"
											title={ item.autoload }
										>
											{ __( 'autoload', 'optrion' ) }
										</span>
									) : (
										<span className="optrion-muted">
											{ item.autoload || 'no' }
										</span>
									) }
								</td>
								<td>{ item.size_human }</td>
								<td>
									{ formatLastRead(
										item.tracking &&
											item.tracking.last_read_at
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				</div>
			) }
			{ bulkActions }
			<div className="optrion-pager">
				<Button
					disabled={ page <= 1 }
					variant="secondary"
					onClick={ () => goToPage( page - 1 ) }
				>
					{ __( 'Previous', 'optrion' ) }
				</Button>
				<span className="optrion-pager__jump">
					<label>
						{ __( 'Page', 'optrion' ) }
						<input
							type="number"
							min="1"
							max={ totalPages }
							className="optrion-pager__input"
							value={ pageInput }
							onChange={ ( e ) => setPageInput( e.target.value ) }
							onBlur={ () => goToPage( pageInput ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' ) {
									e.preventDefault();
									goToPage( pageInput );
								}
							} }
							aria-label={ __( 'Jump to page', 'optrion' ) }
						/>
					</label>
					<span>
						{ sprintf(
							/* translators: %d: total number of pages. */
							__( 'of %d', 'optrion' ),
							totalPages
						) }
					</span>
				</span>
				<Button
					disabled={ page >= totalPages }
					variant="secondary"
					onClick={ () => goToPage( page + 1 ) }
				>
					{ __( 'Next', 'optrion' ) }
				</Button>
				<SelectControl
					className="optrion-pager__per-page"
					label={ __( 'Per page', 'optrion' ) }
					hideLabelFromVision
					value={ String( perPage ) }
					options={ PER_PAGE_CHOICES.map( ( n ) => ( {
						label: sprintf(
							/* translators: %d: rows per page. */
							__( '%d / page', 'optrion' ),
							n
						),
						value: String( n ),
					} ) ) }
					onChange={ ( v ) => {
						setPage( 1 );
						setPerPage( parseInt( v, 10 ) || DEFAULT_PER_PAGE );
					} }
					__nextHasNoMarginBottom
				/>
			</div>
		</div>
	);
};

export default OptionsList;
