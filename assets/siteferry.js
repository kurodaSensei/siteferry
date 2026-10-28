/**
 * Siteferry: tabs, the operation form and the step loop.
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
	const reduceMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
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
		review: __( 'Waiting for your review', 'siteferry' ),
		paused: __( 'Paused', 'siteferry' ),
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
	const PHASES = {
		checks: __( 'Preflight checks', 'siteferry' ),
		backup: __( 'Back up the destination', 'siteferry' ),
		database: __( 'Copy the database', 'siteferry' ),
		compare: __( 'Compare files', 'siteferry' ),
		review: __( 'Review the comparison', 'siteferry' ),
		files: __( 'Transfer files', 'siteferry' ),
		swap: __( 'Swap tables and folders', 'siteferry' ),
		finish: __( 'Clean up', 'siteferry' ),
	};
	const UNFINISHED = [ 'pending', 'running', 'review', 'paused' ];

	// --- Tabs ---------------------------------------------------------------

	const tabs = [ ...document.querySelectorAll( '.sf-tab' ) ];
	const sidePanels = { undo: $( 'siteferry-undo' ), connection: $( 'siteferry-settings' ) };
	let tab = 'pull';

	function selectTab( name, focus ) {
		tab = name;
		tabs.forEach( ( t ) => {
			const on = t.dataset.tab === name;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
			if ( on && focus ) {
				t.focus();
			}
		} );
		if ( ! panel.hidden ) {
			return; // A job is on screen: the tabs come back with "New operation".
		}
		const operation = ! sidePanels[ name ];
		form.hidden = ! operation;
		Object.entries( sidePanels ).forEach( ( [ key, el ] ) => ( el.hidden = key !== name ) );
		if ( operation ) {
			form.querySelector( 'input[name="intent"]' ).value = name;
			form.setAttribute( 'aria-labelledby', `siteferry-tab-${ name }` );
			toggleSections();
		}
	}

	tabs.forEach( ( t, i ) => {
		t.addEventListener( 'click', () => selectTab( t.dataset.tab ) );
		t.addEventListener( 'keydown', ( e ) => {
			const moves = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 };
			if ( ! ( e.key in moves ) ) {
				return;
			}
			e.preventDefault();
			const next = tabs[ ( moves[ e.key ] + tabs.length ) % tabs.length ];
			selectTab( next.dataset.tab, true );
		} );
	} );

	function lockTabs( locked ) {
		tabs.forEach( ( t ) => ( t.disabled = locked ) );
	}

	// --- Form ---------------------------------------------------------------

	const intent = () => form.querySelector( 'input[name="intent"]' ).value;
	const remoteIntent = () => intent() === 'pull' || intent() === 'push';
	const checked = ( name ) => form.querySelector( `input[name="${ name }"]` ).checked;
	const selectedFiles = () => [ ...form.querySelectorAll( intent() === 'export' ? 'input[name="export_files"]:checked' : 'input[name="files"]:checked' ) ].map( ( b ) => b.value );

	function toggleSections() {
		const current = intent();
		form.querySelectorAll( '[data-show]' ).forEach( ( el ) => {
			el.hidden = ! el.dataset.show.split( ' ' ).includes( current );
		} );
		form.querySelectorAll( '[data-hide]' ).forEach( ( el ) => {
			el.hidden = el.dataset.hide.split( ' ' ).includes( current );
		} );
		// A pull/push without the database has no tables to choose.
		const noDatabase = remoteIntent() && ! checked( 'database' );
		tableList.closest( 'details' ).hidden = current === 'import' || noDatabase;
		form.querySelector( '[data-files-only]' ).hidden = ! selectedFiles().length;
		showTables();
		summarize();
	}

	function summarize() {
		const other = remote ? remote.home : __( 'the other site', 'siteferry' );
		const files = selectedFiles();
		/* translators: %s: list of folders */
		const filesText = files.length ? sprintf( __( 'files (%s)', 'siteferry' ), files.join( ', ' ) ) : '';
		const what = list( [ checked( 'database' ) ? __( 'the database', 'siteferry' ) : '', filesText ].filter( Boolean ) ) || __( 'nothing yet', 'siteferry' );
		const texts = {
			/* translators: 1: what gets replaced, 2: other site URL */
			pull: sprintf( __( 'Replaces %1$s on this site with the ones from %2$s.', 'siteferry' ), what, other ),
			/* translators: 1: what gets replaced, 2: other site URL */
			push: sprintf( __( 'Replaces %1$s on %2$s with the ones from this site.', 'siteferry' ), what, other ),
			export: selectedFiles().length
				? __( 'Downloads a .sfy package with the database and the files you chose.', 'siteferry' )
				: __( 'Downloads the database as a .sql.gz file.', 'siteferry' ),
			import: __( 'Replaces this site’s database with the one in the file.', 'siteferry' ),
			replace: __( 'Changes text in this site’s database, including inside serialized data and JSON.', 'siteferry' ),
		};
		const buttons = {
			pull: __( 'Start pull', 'siteferry' ),
			push: __( 'Start push', 'siteferry' ),
			export: __( 'Export', 'siteferry' ),
			import: __( 'Import', 'siteferry' ),
			replace: __( 'Run search and replace', 'siteferry' ),
		};
		$( 'siteferry-summary' ).textContent = texts[ intent() ];
		$( 'siteferry-run' ).textContent = buttons[ intent() ];
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
		status.hidden = false;
		status.dataset.state = 'busy';
		status.textContent = __( 'Connecting…', 'siteferry' );
		remote = null;
		try {
			remote = await api( { path: `${ NS }/remote/hello`, method: 'POST', data: { connection: $( 'siteferry-connection' ).value } } );
			const allowed = list( [ remote.allow_pull ? 'pull' : '', remote.allow_push ? 'push' : '' ].filter( Boolean ) ) || __( 'nothing (enable it there)', 'siteferry' );
			const yes = ( v ) => ( v ? __( 'yes', 'siteferry' ) : __( 'no', 'siteferry' ) );
			status.dataset.state = 'ok';
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
			status.dataset.state = 'error';
			status.textContent = ( err && err.message ) || __( 'Could not connect.', 'siteferry' );
		}
		showTables();
		summarize();
	} );
	$( 'siteferry-connection' ).addEventListener( 'input', () => {
		remote = null;
		$( 'siteferry-remote-info' ).hidden = true;
		showTables();
		summarize();
	} );
	form.addEventListener( 'change', ( e ) => {
		if ( [ 'database', 'files', 'export_files' ].includes( e.target.name ) ) {
			toggleSections();
		}
	} );

	// Import: a file can be dropped on the box as well as chosen.
	const fileInput = $( 'siteferry-file' );
	const drop = fileInput.closest( '.sf-drop' );
	const showFileName = () => {
		const file = fileInput.files[ 0 ];
		if ( file ) {
			$( 'siteferry-file-name' ).textContent = `${ file.name } · ${ size( file.size ) }`;
		}
	};
	fileInput.addEventListener( 'change', showFileName );
	[ 'dragenter', 'dragover' ].forEach( ( type ) => drop.addEventListener( type, ( e ) => {
		e.preventDefault();
		drop.classList.add( 'is-over' );
	} ) );
	[ 'dragleave', 'drop' ].forEach( ( type ) => drop.addEventListener( type, () => drop.classList.remove( 'is-over' ) ) );
	drop.addEventListener( 'drop', ( e ) => {
		e.preventDefault();
		if ( e.dataTransfer.files.length ) {
			fileInput.files = e.dataTransfer.files;
			showFileName();
		}
	} );

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
	$( 'siteferry-table-filter' ).addEventListener( 'input', ( e ) => {
		const needle = e.target.value.trim().toLowerCase();
		[ ...tableList.children ].forEach( ( li ) => ( li.hidden = needle !== '' && ! li.textContent.toLowerCase().includes( needle ) ) );
	} );
	$( 'siteferry-toggle-tables' ).addEventListener( 'click', () => {
		// Only the tables the filter shows.
		const boxes = [ ...form.querySelectorAll( 'input[name="tables"]' ) ].filter( ( b ) => ! b.closest( 'li' ).hidden );
		const all = boxes.every( ( b ) => b.checked );
		boxes.forEach( ( b ) => ( b.checked = ! all ) );
	} );

	function payload() {
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
			files: selectedFiles(),
			delete_files: checked( 'delete_files' ),
			only_new: checked( 'only_new' ),
			review: checked( 'review' ),
		};
	}

	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		const body = payload();

		if ( body.intent === 'replace' && ! body.pairs.length ) {
			return alert( __( 'Enter at least one text to find.', 'siteferry' ) );
		}
		if ( body.intent === 'import' ) {
			const file = fileInput.files[ 0 ];
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
			if ( ! confirm( `${ $( 'siteferry-summary' ).textContent } ${ __( 'Continue?', 'siteferry' ) }` ) ) {
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
		// wp_localize_script hands numbers over as strings: with "+" the offset would be concatenated.
		const chunk = Number( data.maxChunk );
		let token = '';
		let ext = '';
		for ( let offset = 0; offset < file.size; ) {
			const fd = new FormData();
			fd.append( 'chunk', file.slice( offset, offset + chunk ), file.name );
			fd.append( 'name', file.name );
			fd.append( 'offset', String( offset ) );
			fd.append( 'upload', token );
			const res = await retry( () => api( { path: `${ NS }/uploads`, method: 'POST', body: fd } ) );
			token = res.upload;
			ext = res.ext;
			offset = res.size;
			setPercent( Math.floor( ( offset * 100 ) / file.size ) );
			/* translators: 1: bytes uploaded, 2: file size */
			$( 'siteferry-step' ).textContent = sprintf( __( '%1$s of %2$s', 'siteferry' ), size( offset ), size( file.size ) );
		}
		return { upload: token, ext };
	}

	// --- Step loop ----------------------------------------------------------

	let running = false;
	let lastJob = null;

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

	// The percentage counts up instead of jumping; the bar's width eases in CSS.
	let shownPercent = 0;
	let counter = 0;
	function setPercent( value ) {
		const bar = $( 'siteferry-bar' );
		bar.firstElementChild.style.transform = `translateX(${ value - 100 }%)`;
		bar.setAttribute( 'aria-valuenow', String( value ) );
		const label = $( 'siteferry-percent' );
		cancelAnimationFrame( counter );
		if ( reduceMotion || value <= shownPercent ) {
			shownPercent = value;
			label.textContent = `${ value }%`;
			return;
		}
		const from = shownPercent;
		const start = performance.now();
		const tick = ( now ) => {
			const t = Math.min( 1, ( now - start ) / 600 );
			shownPercent = Math.round( from + ( value - from ) * ( 1 - Math.pow( 1 - t, 3 ) ) );
			label.textContent = `${ shownPercent }%`;
			if ( t < 1 ) {
				counter = requestAnimationFrame( tick );
			}
		};
		counter = requestAnimationFrame( tick );
	}

	function render( job ) {
		if ( ! job ) {
			return;
		}
		lastJob = job;
		const where = job.remote ? ` · ${ job.remote.replace( /^https?:\/\//, '' ) }` : '';
		showPanel( `${ INTENT[ job.intent ] }${ where }` );
		panel.dataset.state = job.status;
		const open = job.status === 'pending' || job.status === 'running';
		setPercent( job.percent );
		$( 'siteferry-step' ).textContent = `${ STATUS[ job.status ] }${ open && job.step ? ` · ${ job.step }` : '' }`;
		$( 'siteferry-log' ).textContent = job.log.join( '\n' );
		renderPhases( job.phases || [] );
		renderReview( job );
		$( 'siteferry-resume' ).hidden = ! ( job.status === 'paused' || ( open && ! running ) );
		$( 'siteferry-cancel' ).hidden = ! ( UNFINISHED.includes( job.status ) || job.status === 'failed' );
		$( 'siteferry-new' ).hidden = UNFINISHED.includes( job.status );

		const msg = $( 'siteferry-message' );
		msg.replaceChildren();
		if ( job.status === 'failed' ) {
			notice( 'error', job.error + ' ' + __( 'The site’s tables were not modified; “Cancel” cleans up the temporary data.', 'siteferry' ) );
		}
		if ( job.status === 'paused' ) {
			notice( 'warning', job.error + ' ' + __( 'Your progress is kept: resume when the other site answers again.', 'siteferry' ) );
		}
		job.warnings.forEach( ( w ) => notice( 'warning', w ) );
		if ( ! UNFINISHED.includes( job.status ) ) {
			refreshUndo();
		}
		if ( job.status === 'done' && ( job.intent === 'import' || job.intent === 'undo' || ( job.intent === 'pull' && job.database ) ) ) {
			notice( 'success', __( 'Done. If you were logged out, log in again with a user from the source site.', 'siteferry' ) );
		}

		const files = $( 'siteferry-files' );
		files.replaceChildren();
		Object.entries( job.files ).forEach( ( [ key, file ] ) => {
			const a = document.createElement( 'a' );
			a.className = `sf-download${ key === 'export' ? ' is-primary' : '' }`;
			a.href = restUrl( `jobs/${ job.id }/download`, { file: key, _wpnonce: data.nonce } );
			const icon = document.createElement( 'span' );
			icon.className = 'dashicons dashicons-download';
			icon.setAttribute( 'aria-hidden', 'true' );
			const title = document.createElement( 'strong' );
			title.textContent = key === 'export' ? __( 'Download the export', 'siteferry' ) : __( 'Download the backup', 'siteferry' );
			const meta = document.createElement( 'span' );
			meta.textContent = `${ file.name } · ${ size( file.size ) }`;
			const text = document.createElement( 'span' );
			text.className = 'sf-download-text';
			text.append( title, meta );
			a.append( icon, text );
			files.append( a );
		} );
	}

	function renderPhases( phases ) {
		const ol = $( 'siteferry-phases' );
		const items = phases.map( ( phase ) => {
			const li = document.createElement( 'li' );
			li.className = `sf-phase is-${ phase.state }`;
			if ( phase.state === 'current' ) {
				li.setAttribute( 'aria-current', 'step' );
			}
			const dot = document.createElement( 'span' );
			dot.className = 'sf-phase-dot';
			dot.setAttribute( 'aria-hidden', 'true' );
			li.append( dot, PHASES[ phase.key ] || phase.key );
			return li;
		} );
		// Only rebuild when something changed, so the transitions play once per change.
		const signature = phases.map( ( p ) => p.key + p.state ).join();
		if ( ol.dataset.signature !== signature ) {
			ol.dataset.signature = signature;
			ol.replaceChildren( ...items );
		}
	}

	function renderReview( job ) {
		const box = $( 'siteferry-review' );
		box.hidden = ! job.review;
		if ( ! job.review ) {
			return;
		}
		/* translators: 1: number of files, 2: size */
		const cell = ( kind, count, bytes ) => {
			const td = document.createElement( 'td' );
			td.className = `sf-num is-${ kind }`;
			const strong = document.createElement( 'strong' );
			strong.textContent = count.toLocaleString();
			const meta = document.createElement( 'span' );
			meta.textContent = size( bytes );
			td.append( strong, meta );
			return td;
		};
		const rows = Object.entries( job.review.stats ).map( ( [ root, s ] ) => {
			const tr = document.createElement( 'tr' );
			const name = document.createElement( 'th' );
			name.scope = 'row';
			name.textContent = root;
			tr.append( name, cell( 'new', s.new, s.new_bytes ), cell( 'changed', s.changed, s.changed_bytes ), cell( 'extra', s.extra, s.extra_bytes ) );
			return tr;
		} );
		$( 'siteferry-review-rows' ).replaceChildren( ...rows );
		box.querySelector( `input[name="review_mode"][value="${ job.review.only_new ? 'new' : 'all' }"]` ).checked = true;
		$( 'siteferry-review-delete' ).checked = job.review.delete_files;
	}

	$( 'siteferry-review-go' ).addEventListener( 'click', async () => {
		try {
			const job = await api( {
				path: `${ NS }/jobs/${ lastJob.id }/review`,
				method: 'POST',
				data: {
					only_new: $( 'siteferry-review' ).querySelector( 'input[name="review_mode"]:checked' ).value === 'new',
					delete_files: $( 'siteferry-review-delete' ).checked,
				},
			} );
			run( job );
		} catch ( err ) {
			showError( err, true );
		}
	} );

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
		Object.values( sidePanels ).forEach( ( el ) => ( el.hidden = true ) );
		panel.hidden = false;
		lockTabs( true );
		$( 'siteferry-status' ).textContent = title;
	}

	function hidePanel() {
		panel.hidden = true;
		lockTabs( false );
		selectTab( tab );
	}

	function showError( err, keepPanel ) {
		const text = ( err && err.message ) || __( 'Unknown error.', 'siteferry' );
		if ( ! keepPanel ) {
			hidePanel();
			return alert( text );
		}
		notice( 'error', text + ' ' + __( 'You can resume once it is fixed.', 'siteferry' ) );
		$( 'siteferry-resume' ).hidden = false;
	}

	function size( bytes ) {
		return bytes > 1048576 ? `${ ( bytes / 1048576 ).toFixed( 1 ) } MB` : `${ Math.ceil( bytes / 1024 ) } KB`;
	}

	$( 'siteferry-resume' ).addEventListener( 'click', async () => {
		try {
			let job = await api( { path: `${ NS }/jobs/current` } );
			if ( job && job.status === 'paused' ) {
				job = await retry( () => api( { path: `${ NS }/jobs/${ job.id }/step`, method: 'POST' } ) );
			}
			run( job );
		} catch ( err ) {
			showError( err, true );
		}
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
	$( 'siteferry-new' ).addEventListener( 'click', hidePanel );

	// --- Undo ---------------------------------------------------------------

	async function refreshUndo() {
		const point = await api( { path: `${ NS }/undo` } ).catch( () => null );
		$( 'siteferry-undo-badge' ).hidden = ! point;
		$( 'siteferry-undo-run' ).disabled = ! point;
		if ( point ) {
			const what = list( [ point.db_backup ? __( 'database', 'siteferry' ) : '', point.roots.length ? `${ __( 'files', 'siteferry' ) } (${ point.roots.join( ', ' ) })` : '' ].filter( Boolean ) );
			/* translators: 1: operation, 2: date, 3: what gets restored */
			$( 'siteferry-undo-info' ).textContent = sprintf( __( '%1$s · %2$s · restores: %3$s.', 'siteferry' ), point.label, new Date( point.time * 1000 ).toLocaleString(), what );
		} else {
			$( 'siteferry-undo-info' ).textContent = __( 'There is nothing to undo.', 'siteferry' );
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
	const ACCEPTS = {
		allow_pull: __( 'pull', 'siteferry' ),
		allow_pull_files: __( 'file pull', 'siteferry' ),
		allow_push: __( 'push', 'siteferry' ),
		allow_push_code: __( 'code push', 'siteferry' ),
	};
	function showAccepts() {
		const on = Object.keys( ACCEPTS ).filter( ( name ) => settings.querySelector( `input[name="${ name }"]` ).checked ).map( ( name ) => ACCEPTS[ name ] );
		$( 'siteferry-accepts' ).dataset.on = on.length ? '1' : '0';
		/* translators: %s: list of allowed operations */
		$( 'siteferry-accepts' ).textContent = on.length ? sprintf( __( 'This site accepts: %s', 'siteferry' ), on.join( ', ' ) ) : __( 'This site accepts no connections', 'siteferry' );
	}
	settings.addEventListener( 'change', async ( e ) => {
		if ( e.target.type !== 'checkbox' ) {
			return;
		}
		try {
			await api( { path: `${ NS }/settings`, method: 'POST', data: { [ e.target.name ]: e.target.checked } } );
			settingsStatus.textContent = __( 'Saved.', 'siteferry' );
			showAccepts();
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

	selectTab( 'pull' );

	// On load: an unfinished operation is shown so it can be resumed, reviewed or cancelled.
	api( { path: `${ NS }/jobs/current` } ).then( ( job ) => {
		if ( job && [ ...UNFINISHED, 'failed' ].includes( job.status ) ) {
			render( job );
		}
	} );
} )();
