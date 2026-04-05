import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';

import { api } from '../api';

const Export = () => {
	const [ scoreMin, setScoreMin ] = useState( '50' );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( null );

	const run = async () => {
		setRunning( true );
		setError( null );
		try {
			const payload = await api.export( [], parseInt( scoreMin, 10 ) || 0 );
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
		} finally {
			setRunning( false );
		}
	};

	return (
		<div className="optrion-export">
			<p>{ __( 'Export options whose score is at least this threshold.', 'optrion' ) }</p>
			<TextControl
				type="number"
				label={ __( 'Score ≥', 'optrion' ) }
				value={ scoreMin }
				onChange={ setScoreMin }
			/>
			<Button variant="primary" onClick={ run } disabled={ running }>
				{ running ? __( 'Exporting…', 'optrion' ) : __( 'Export JSON', 'optrion' ) }
			</Button>
			{ error && <p className="optrion-error">{ error }</p> }
		</div>
	);
};

export default Export;
