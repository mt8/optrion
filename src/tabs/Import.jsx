import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, CheckboxControl } from '@wordpress/components';

import { api } from '../api';

const Import = () => {
	const [ payload, setPayload ] = useState( '' );
	const [ overwrite, setOverwrite ] = useState( false );
	const [ preview, setPreview ] = useState( null );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const onFile = async ( event ) => {
		const file = event.target.files[ 0 ];
		if ( ! file ) return;
		const text = await file.text();
		setPayload( text );
		setPreview( null );
		setResult( null );
		setError( null );
	};

	const runPreview = async () => {
		setBusy( true );
		setError( null );
		try {
			setPreview( await api.importPreview( payload ) );
		} catch ( e ) {
			setError( e.message || String( e ) );
		} finally {
			setBusy( false );
		}
	};

	const runImport = async () => {
		setBusy( true );
		setError( null );
		try {
			setResult( await api.import( payload, overwrite ) );
		} catch ( e ) {
			setError( e.message || String( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="optrion-import">
			<p>
				<input type="file" accept=".json,application/json" onChange={ onFile } />
			</p>
			<CheckboxControl
				label={ __( 'Overwrite existing rows', 'optrion' ) }
				checked={ overwrite }
				onChange={ setOverwrite }
			/>
			<div className="optrion-import__actions">
				<Button variant="secondary" onClick={ runPreview } disabled={ ! payload || busy }>
					{ __( 'Dry run', 'optrion' ) }
				</Button>
				<Button variant="primary" onClick={ runImport } disabled={ ! payload || busy }>
					{ __( 'Import', 'optrion' ) }
				</Button>
			</div>
			{ error && <p className="optrion-error">{ error }</p> }
			{ preview && (
				<p>
					{ sprintf(
						__( 'Preview — add: %1$d, overwrite: %2$d', 'optrion' ),
						preview.add,
						preview.overwrite
					) }
				</p>
			) }
			{ result && (
				<p>
					{ sprintf(
						__( 'Import complete — added: %1$d, overwritten: %2$d, skipped: %3$d', 'optrion' ),
						result.added,
						result.overwritten,
						result.skipped
					) }
				</p>
			) }
		</div>
	);
};

export default Import;
