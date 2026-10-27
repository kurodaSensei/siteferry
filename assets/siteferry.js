/**
 * Siteferry: submits the form and advances the job step by step.
 * The cursor lives on the server, so retrying a step is safe.
 */
( function () {
	'use strict';

	const data = window.siteferryData;
	const api = window.wp.apiFetch;
	const { __, sprintf } = window.wp.i18n;
	const NS = '/siteferry/v1';
	const $ = ( id ) => document.getElementById( id );
	const form = $( 'siteferry-form' );
	const panel = $( 'siteferry-progress' );
	const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
	// Lists here never have more than two items.
	/* translators: 1: first item, 2: second item */
	const list = ( items ) => ( items.length > 1 ? sprintf( __( '%1$s and %2$s', 'siteferry' ), items[ 0 ], items[ 1 ] ) : items[ 0 ] || '' );

	// The compatibility mu-plugin only acts when this header is present.
	api.use( ( options, next ) => {
		options.headers = Object.assign( {}, options.headers, { 'X-Siteferry-Compat': data.compatKey } );
		return next( options );
	} );

	const STATUS = {
		pending: __( 'Queued…', 'siteferry' ),
		running: __( 'Working…', 'siteferry' ),
		done: __( 'Done', 'siteferry' ),
		failed: __( 'Failed', 'siteferry' ),
		cancelled: __( 'Cancelled', 'siteferry' ),
	};
	const INTENT = {
		export: __( 'Export', 'siteferry' ),
		replace: __( 'Search and replace', 'siteferry' ),
		import: __( 'Import', 'siteferry' ),
		pull: __( 'Pull', 'siteferry' ),
		push: __( 'Push', 'siteferry' ),
		undo: __( 'Undo', 'siteferry' ),
	};

	// --- Form ---------------------------------------------------------------

	const intent = () => form.querySelector( 'input[name="intent"]:checked' ).value;

	function toggleSections() {
		const current = intent();
		form.querySelectorAll( '[data-show]' ).forEach( ( el ) => {
			el.hidden = ! el.dataset.show.split( ' ' ).includes( current );
		} );
		form.querySelectorAll( '[data-hide]' ).forEach( ( el ) => {
			el.hidden = el.dataset.hide.split( ' ' ).includes( current );
		} );
		// A pull/push without the database has no tables or replacements to choose.
		const noDatabase = remoteIntent() && ! form.querySelector( 'input[name="database"]' ).checked;
		tableList.closest( 'details' ).hidden = current === 'import' || noDatabase;
		showTables();
	}

	// --- Tables: this site's, or the remote's on a pull ----------------------

	const tableList = $( 'siteferry-table-list' );
	const localTables = [ ...tableList.children ];
	const tablesTitle = $( 'siteferry-tables-title' );
	let remote = null; // The remote site's "hello" response.

	function showTables() {
		if ( intent() !== 'pull' ) {
			tableList.replaceChildren( ...localTables );
		} else if ( remote ) {
			tableList.replaceChildren(
				...Object.entries( remote.tables ).map( ( [ name, info ] ) => tableItem( name, info ) )
			);
		} else {
			const li = document.createElement( 'li' );
			li.className = 'description';
			li.textContent = __( 'Connect to the remote site to see its tables.', 'siteferry' );
			tableList.replaceChildren( li );
		}
		/* translators: %d: number of tables */
		tablesTitle.textContent = sprintf( __( 'Tables (%d)', 'siteferry' ), tableList.querySelectorAll( 'input' ).length );
	}

	function tableItem( name, info ) {
		const li = document.createElement( 'li' );
		const label = document.createElement( 'label' );
		const box = document.createElement( 'input' );
		box.type = 'checkbox';
		box.name = 'tables';
		box.value = name;
		box.checked = true;
		const code = document.createElement( 'code' );
		code.textContent = name;
		const meta = document.createElement( 'span' );
		meta.className = 'description';
		meta.textContent = `${ size( info.bytes ) } · ~${ info.rows }`;
		label.append( box, ' ', code, ' ', meta );
		li.append( label );
		return li;
	}

	$( 'siteferry-connect' ).addEventListener( 'click', async () => {
		const status = $( 'siteferry-remote-info' );
		status.textContent = __( 'Connecting…', 'siteferry' );
		remote = null;
		try {
			remote = await api( { path: `${ NS }/remote/hello`, method: 'POST', data: { connection: $( 'siteferry-connection' ).value } } );
			const allowed = list( [ remote.allow_pull ? 'pull' : '', remote.allow_push ? 'push' : '' ].filter( Boolean ) ) || __( 'nothing (enable it there)', 'siteferry' );
			const yes = ( v ) => ( v ? __( 'yes', 'siteferry' ) : __( 'no', 'siteferry' ) );
			status.textContent = sprintf(
				/* translators: 1: remote URL, 2: table prefix, 3: number of tables, 4: allowed operations, 5: file pull yes/no, 6: code push yes/no */
				__( 'Connected to %1$s · prefix %2$s · %3$d tables · allows %4$s · file pull: %5$s · code push: %6$s.', 'siteferry' ),
				remote.home,
				remote.prefix,
				Object.keys( remote.tables ).length,
				allowed,
				yes( remote.allow_pull_files ),
				yes( remote.allow_push_code )
			);
		} catch ( err ) {
			status.textContent = ( err && err.message ) || __( 'Could not connect.', 'siteferry' );
		}
		showTables();
	} );
	$( 'siteferry-connection' ).addEventListener( 'input', () => {
		remote = null;
		$( 'siteferry-remote-info' ).textContent = '';
		showTables();
	} );
	toggleSections();
	form.addEventListener( 'change', ( e ) => {
		if ( e.target.name === 'intent' || e.target.name === 'database' ) {
			toggleSections();
		}
	} );
	function remoteIntent() {
		return intent() === 'pull' || intent() === 'push';
	}

	const pairsBody = form.querySelector( '.siteferry-pairs tbody' );
	$( 'siteferry-add-pair' ).addEventListener( 'click', () => {
		const row = pairsBody.rows[ 0 ].cloneNode( true );
		row.querySelectorAll( 'input' ).forEach( ( input ) => ( input.value = '' ) );
		pairsBody.appendChild( row );
		row.querySelector( 'input' ).focus();
	} );
	pairsBody.addEventListener( 'click', ( e ) => {
		if ( ! e.target.closest( '.siteferry-remove' ) ) {
			return;
		}
		const row = e.target.closest( 'tr' );
		if ( pairsBody.rows.length > 1 ) {
			row.remove();
		} else {
			row.querySelectorAll( 'input' ).forEach( ( input ) => ( input.value = '' ) );
		}
	} );
	$( 'siteferry-toggle-tables' ).addEventListener( 'click', () => {
		const boxes = [ ...form.querySelectorAll( 'input[name="tables"]' ) ];
		const all = boxes.every( ( b ) => b.checked );
		boxes.forEach( ( b ) => ( b.checked = ! all ) );
	} );

	function payload() {
		const checked = ( name ) => form.querySelector( `input[name="${ name }"]` ).checked;
		const pairs = [ ...pairsBody.rows ]
			.map( ( row ) => ( {
				find: row.querySelector( '[name="find"]' ).value,
				replace: row.querySelector( '[name="replace"]' ).value,
			} ) )
			// The row prefilled with the current URL and no replacement is not a pair: ignore it.
			.filter( ( p ) => p.find !== '' && ! ( p.replace === '' && p.find === data.home ) );
		return {
			intent: intent(),
			pairs,
			tables: [ ...form.querySelectorAll( 'input[name="tables"]:checked' ) ].map( ( b ) => b.value ),
			case_sensitive: checked( 'case_sensitive' ),
			regex: checked( 'regex' ),
			replace_guids: checked( 'replace_guids' ),
			backup: checked( 'backup' ),
			auto_url: checked( 'auto_url' ),
			source_prefix: form.querySelector( 'input[name="source_prefix"]' ).value.trim(),
			connection: $( 'siteferry-connection' ).value.trim(),
			database: checked( 'database' ),
			files: [ ...form.querySelectorAll( intent() === 'export' ? 'input[name="export_files"]:checked' : 'input[name="files"]:checked' ) ].map( ( b ) => b.value ),
			delete_files: checked( 'delete_files' ),
		};
	}

	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		const body = payload();

		if ( body.intent === 'replace' && ! body.pairs.length ) {
			return alert( __( 'Enter at least one text to find.', 'siteferry' ) );
		}
		if ( body.intent === 'import' ) {
			const file = $( 'siteferry-file' ).files[ 0 ];
			if ( ! file ) {
				return alert( __( 'Choose the file to import.', 'siteferry' ) );
			}
			if ( ! confirm( __( 'This site’s database will be replaced with the one in the file. Continue?', 'siteferry' ) ) ) {
				return;
			}
			try {
				Object.assign( body, await upload( file ) );
			} catch ( err ) {
				return showError( err );
			}
		} else if ( body.intent === 'replace' && ! confirm( __( 'This site’s data will be modified. Continue?', 'siteferry' ) ) ) {
			return;
		} else if ( body.intent === 'pull' || body.intent === 'push' ) {
			if ( ! body.connection ) {
				return alert( __( 'Paste the other site’s connection info.', 'siteferry' ) );
			}
			if ( ! body.database && ! body.files.length ) {
				return alert( __( 'Choose the database, files, or both.', 'siteferry' ) );
			}
			const code = body.files.filter( ( f ) => f !== 'uploads' );
			if ( body.intent === 'pull' && body.files.length && remote && ! remote.allow_pull_files ) {
				return alert( __( 'The other site does not allow file pulls. Enable it there or clear the file checkboxes.', 'siteferry' ) );
			}
			if ( body.intent === 'push' && code.length && remote && ! remote.allow_push_code ) {
				return alert( __( 'The other site does not allow code pushes (themes and plugins). Enable it there or clear those checkboxes.', 'siteferry' ) );
			}
			const other = remote ? remote.home : __( 'the other site', 'siteferry' );
			const what = list( [
				body.database ? __( 'the database', 'siteferry' ) : '',
				/* translators: %s: list of folders */
				body.files.length ? sprintf( __( 'files (%s)', 'siteferry' ), body.files.join( ', ' ) ) : '',
			].filter( Boolean ) );
			const question = body.intent === 'pull'
				? sprintf(
					/* translators: 1: what gets replaced, 2: other site URL */
					__( '%1$s on THIS site will be replaced with the ones from %2$s. Continue?', 'siteferry' ),
					what,
					other
				)
				: sprintf(
					/* translators: 1: what gets replaced, 2: other site URL */
					__( '%1$s on %2$s will be replaced with the ones from this site. Continue?', 'siteferry' ),
					what,
					other
				);
			if ( ! confirm( question ) ) {
				return;
			}
		}

		try {
			const job = await api( { path: `${ NS }/jobs`, method: 'POST', data: body } );
			run( job );
		} catch ( err ) {
			showError( err );
		}
	} );

	// --- Chunked upload -----------------------------------------------------

	async function upload( file ) {
		showPanel( __( 'Uploading file…', 'siteferry' ) );
		let token = '';
		let ext = '';
		for ( let offset = 0; offset < file.size; ) {
			const fd = new FormData();
			fd.append( 'chunk', file.slice( offset, offset + data.maxChunk ), file.name );
			fd.append( 'name', file.name );
			fd.append( 'offset', String( offset ) );
			fd.append( 'upload', token );
			const res = await retry( () => api( { path: `${ NS }/uploads`, method: 'POST', body: fd } ) );
			token = res.upload;
			ext = res.ext;
			offset = res.size;
			$( 'siteferry-bar' ).value = Math.floor( ( offset * 100 ) / file.size );
			/* translators: 1: bytes uploaded, 2: file size */
			$( 'siteferry-step' ).textContent = sprintf( __( '%1$s of %2$s', 'siteferry' ), size( offset ), size( file.size ) );
		}
		return { upload: token, ext };
	}

	// --- Step loop ----------------------------------------------------------

	let running = false;

	async function run( job ) {
		running = true;
		render( job );
		while ( running && job && ( job.status === 'pending' || job.status === 'running' ) ) {
			try {
				job = await retry( () => api( { path: `${ NS }/jobs/${ job.id }/step`, method: 'POST' } ) );
			} catch ( err ) {
				running = false;
				render( job );
				return showError( err, true );
			}
			render( job );
		}
		running = false;
		render( job );
	}

	// Retries network drops and "another step in progress"; operation errors are not retried.
	async function retry( fn ) {
		for ( let attempt = 1; ; attempt++ ) {
			try {
				return await fn();
			} catch ( err ) {
				const transient = ! err || err.code === 'fetch_error' || err.code === 'siteferry_locked' || err.code === 'invalid_json';
				if ( ! transient || attempt >= 6 ) {
					throw err;
				}
				await sleep( 1500 * attempt );
			}
		}
	}

	function render( job ) {
		if ( ! job ) {
			return;
		}
		showPanel( `${ INTENT[ job.intent ] }: ${ STATUS[ job.status ] }` );
		const open = job.status === 'pending' || job.status === 'running';
		$( 'siteferry-bar' ).value = job.percent;
		$( 'siteferry-step' ).textContent = open ? `${ job.percent }% · ${ job.step || '' }` : '';
		$( 'siteferry-log' ).textContent = job.log.join( '\n' );
		$( 'siteferry-resume' ).hidden = ! open || running;
		$( 'siteferry-cancel' ).hidden = ! ( open || job.status === 'failed' );
		$( 'siteferry-new' ).hidden = open;

		const msg = $( 'siteferry-message' );
		msg.replaceChildren();
		if ( job.status === 'failed' ) {
			notice( 'error', job.error + ' ' + __( 'The site’s tables were not modified; “Cancel” cleans up the temporary data.', 'siteferry' ) );
		}
		job.warnings.forEach( ( w ) => notice( 'warning', w ) );
		if ( ! open ) {
			refreshUndo();
		}
		if ( job.status === 'done' && ( job.intent === 'import' || job.intent === 'undo' || ( job.intent === 'pull' && job.database ) ) ) {
			notice( 'success', __( 'Done. If you were logged out, log in again with a user from the source site.', 'siteferry' ) );
		}

		const files = $( 'siteferry-files' );
		files.replaceChildren();
		Object.entries( job.files ).forEach( ( [ key, file ] ) => {
			const a = document.createElement( 'a' );
			a.className = key === 'export' ? 'button button-primary' : 'button';
			a.href = restUrl( `jobs/${ job.id }/download`, { file: key, _wpnonce: data.nonce } );
			const label = key === 'export'
				? /* translators: 1: file name, 2: file size */ __( 'Download (%1$s, %2$s)', 'siteferry' )
				: /* translators: 1: file name, 2: file size */ __( 'Download backup (%1$s, %2$s)', 'siteferry' );
			a.textContent = sprintf( label, file.name, size( file.size ) );
			files.append( a, ' ' );
		} );
	}

	// Works with pretty permalinks (/wp-json/…) and without them (?rest_route=…).
	function restUrl( path, params ) {
		const url = new URL( data.root );
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ) + path );
		} else {
			url.pathname += path;
		}
		Object.entries( params ).forEach( ( [ k, v ] ) => url.searchParams.set( k, v ) );
		return url.toString();
	}

	function notice( type, text ) {
		const div = document.createElement( 'div' );
		div.className = `notice notice-${ type } inline`;
		const p = document.createElement( 'p' );
		p.textContent = text;
		div.appendChild( p );
		$( 'siteferry-message' ).appendChild( div );
	}

	function showPanel( title ) {
		form.hidden = true;
		panel.hidden = false;
		$( 'siteferry-status' ).textContent = title;
	}

	function showError( err, keepPanel ) {
		const text = ( err && err.message ) || __( 'Unknown error.', 'siteferry' );
		if ( ! keepPanel ) {
			form.hidden = false;
			panel.hidden = true;
			return alert( text );
		}
		notice( 'error', text + ' ' + __( 'You can resume once it is fixed.', 'siteferry' ) );
		$( 'siteferry-resume' ).hidden = false;
	}

	function size( bytes ) {
		return bytes > 1048576 ? `${ ( bytes / 1048576 ).toFixed( 1 ) } MB` : `${ Math.ceil( bytes / 1024 ) } KB`;
	}

	$( 'siteferry-resume' ).addEventListener( 'click', async () => {
		run( await api( { path: `${ NS }/jobs/current` } ) );
	} );
	$( 'siteferry-cancel' ).addEventListener( 'click', async () => {
		if ( ! confirm( __( 'Cancel the operation? Temporary tables and files will be deleted.', 'siteferry' ) ) ) {
			return;
		}
		running = false;
		const current = await api( { path: `${ NS }/jobs/current` } );
		try {
			render( await retry( () => api( { path: `${ NS }/jobs/${ current.id }/cancel`, method: 'POST' } ) ) );
		} catch ( err ) {
			showError( err, true );
		}
	} );
	$( 'siteferry-new' ).addEventListener( 'click', () => {
		panel.hidden = true;
		form.hidden = false;
	} );

	// --- Undo ---------------------------------------------------------------

	const undoCard = $( 'siteferry-undo' );
	async function refreshUndo() {
		const point = await api( { path: `${ NS }/undo` } ).catch( () => null );
		undoCard.hidden = ! point;
		if ( point ) {
			const what = list( [ point.db_backup ? __( 'database', 'siteferry' ) : '', point.roots.length ? `${ __( 'files', 'siteferry' ) } (${ point.roots.join( ', ' ) })` : '' ].filter( Boolean ) );
			/* translators: 1: operation, 2: date, 3: what gets restored */
			$( 'siteferry-undo-info' ).textContent = sprintf( __( '%1$s · %2$s · restores: %3$s.', 'siteferry' ), point.label, new Date( point.time * 1000 ).toLocaleString(), what );
		}
	}
	$( 'siteferry-undo-run' ).addEventListener( 'click', async () => {
		if ( ! confirm( __( 'The site will be restored to its state before the last operation. Anything changed on this site since then is lost, and this cannot itself be undone. Continue?', 'siteferry' ) ) ) {
			return;
		}
		try {
			run( await api( { path: `${ NS }/undo`, method: 'POST' } ) );
		} catch ( err ) {
			showError( err );
		}
	} );

	// --- This site's connection ---------------------------------------------

	const settings = $( 'siteferry-settings' );
	const settingsStatus = $( 'siteferry-settings-status' );
	settings.addEventListener( 'change', async ( e ) => {
		if ( e.target.type !== 'checkbox' ) {
			return;
		}
		try {
			await api( { path: `${ NS }/settings`, method: 'POST', data: { [ e.target.name ]: e.target.checked } } );
			settingsStatus.textContent = __( 'Saved.', 'siteferry' );
		} catch ( err ) {
			e.target.checked = ! e.target.checked;
			settingsStatus.textContent = ( err && err.message ) || __( 'Could not save.', 'siteferry' );
		}
	} );
	$( 'siteferry-copy-connection' ).addEventListener( 'click', async () => {
		const text = $( 'siteferry-own-connection' );
		try {
			await navigator.clipboard.writeText( text.value );
		} catch ( err ) {
			text.select(); // No clipboard permission: leave it selected for Ctrl+C.
		}
		settingsStatus.textContent = __( 'Copied.', 'siteferry' );
	} );
	$( 'siteferry-regenerate' ).addEventListener( 'click', async () => {
		if ( ! confirm( __( 'Sites using the current key will no longer be able to connect. Regenerate?', 'siteferry' ) ) ) {
			return;
		}
		const res = await api( { path: `${ NS }/settings/key`, method: 'POST' } );
		$( 'siteferry-own-connection' ).value = res.connection;
		settingsStatus.textContent = __( 'New key generated.', 'siteferry' );
	} );

	// On load: an unfinished operation is shown so it can be resumed or cancelled.
	api( { path: `${ NS }/jobs/current` } ).then( ( job ) => {
		if ( job && [ 'pending', 'running', 'failed' ].includes( job.status ) ) {
			render( job );
		}
	} );
} )();
