/**
 * Siteferry: screens, the operation forms and the step loop.
 * The cursor lives on the server, so retrying a step is safe. The loop lives in this page, so
 * moving between Siteferry screens keeps it running; leaving the page pauses it.
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
	const host = ( url ) => String( url || '' ).replace( /^https?:\/\//, '' ).replace( /\/$/, '' );
	const here = host( data.home );
	// Numbers and times in the admin's language, not the browser's.
	const locale = document.documentElement.lang || undefined;
	const num = ( n ) => Number( n || 0 ).toLocaleString( locale );
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
	const FOLDERS = {
		uploads: __( 'Media', 'siteferry' ),
		themes: __( 'Themes', 'siteferry' ),
		plugins: __( 'Plugins', 'siteferry' ),
		'mu-plugins': __( 'Must-use plugins', 'siteferry' ),
	};
	const UNFINISHED = [ 'pending', 'running', 'review', 'paused' ];

	// --- Dialogs: the page's own, in place of the browser's alert() and confirm() ---

	const dialog = $( 'siteferry-dialog' );
	// Resolves true when the person confirms. tone: "danger" for what is hard to take back, "info" for a message.
	function ask( { title, text, ok, cancel, tone } ) {
		const okButton = $( 'siteferry-dialog-ok' );
		const cancelButton = $( 'siteferry-dialog-cancel' );
		$( 'siteferry-dialog-title' ).textContent = title;
		$( 'siteferry-dialog-text' ).textContent = text;
		okButton.textContent = ok || __( 'OK', 'siteferry' );
		okButton.classList.toggle( 'sf-danger', tone === 'danger' );
		okButton.classList.toggle( 'button-primary', tone !== 'danger' );
		cancelButton.textContent = cancel || __( 'Cancel', 'siteferry' );
		cancelButton.hidden = tone === 'info';
		dialog.dataset.tone = tone || '';
		dialog.returnValue = '';
		dialog.showModal();
		// When the action is risky, the safe choice has the focus.
		( tone === 'danger' ? cancelButton : okButton ).focus();
		return new Promise( ( resolve ) => dialog.addEventListener( 'close', () => resolve( dialog.returnValue === 'ok' ), { once: true } ) );
	}
	const tell = ( title, text ) => ask( { title, text, tone: 'info' } );
	const BEFORE = __( 'Before you start', 'siteferry' );
	const backupNote = ( body ) => ( body.backup ? ` ${ __( 'A backup of the current one is kept for 7 days.', 'siteferry' ) }` : '' );

	// --- Screens ------------------------------------------------------------

	const tabs = [ ...document.querySelectorAll( '.sf-tab' ) ];
	const screens = { home: $( 'siteferry-home' ), connection: $( 'siteferry-settings' ) };
	const stepper = $( 'siteferry-stepper' );
	let tab = 'home';

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
		panel.hidden = true;
		const operation = ! screens[ name ];
		form.hidden = ! operation;
		Object.entries( screens ).forEach( ( [ key, el ] ) => ( el.hidden = key !== name ) );
		if ( operation ) {
			form.querySelector( 'input[name="intent"]' ).value = name;
			form.setAttribute( 'aria-labelledby', `siteferry-tab-${ name }` );
			toggleSections();
		} else {
			stepper.hidden = true;
		}
		showFloat();
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
	document.querySelectorAll( '[data-go]' ).forEach( ( el ) => el.addEventListener( 'click', () => {
		if ( panel.contains( el ) ) {
			seen = true; // Leaving a finished operation: the floating button goes away.
		}
		selectTab( el.dataset.go );
	} ) );

	// Steps of a pull or push, shown above the form and the progress.
	function setStepper( current, hasReview ) {
		const order = [ 'connect', 'choose', 'review', 'transfer' ];
		stepper.hidden = false;
		[ ...stepper.children ].forEach( ( li ) => {
			const at = order.indexOf( li.dataset.step );
			li.hidden = li.dataset.step === 'review' && ! hasReview;
			li.className = current === 'all' || at < order.indexOf( current ) ? 'is-done' : ( li.dataset.step === current ? 'is-current' : '' );
			if ( li.className === 'is-current' ) {
				li.setAttribute( 'aria-current', 'step' );
			} else {
				li.removeAttribute( 'aria-current' );
			}
		} );
	}

	// --- Form ---------------------------------------------------------------

	const intent = () => form.querySelector( 'input[name="intent"]' ).value;
	const remoteIntent = () => intent() === 'pull' || intent() === 'push';
	const checked = ( name ) => form.querySelector( `input[name="${ name }"]` ).checked;
	const wantsReview = () => form.querySelector( 'input[name="review"]:checked' ).value === '1';
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
		$( 'siteferry-tables' ).hidden = current === 'import' || noDatabase;
		$( 'siteferry-choose-tables' ).hidden = noDatabase;
		form.querySelector( '[data-files-only]' ).hidden = ! selectedFiles().length;
		form.querySelector( '[data-no-review]' ).hidden = wantsReview();
		$( 'siteferry-users-note' ).hidden = ! ( current === 'import' || ( current === 'pull' && checked( 'database' ) ) );
		if ( current === 'replace' ) {
			$( 'siteferry-replacements' ).open = true;
		}
		if ( remoteIntent() ) {
			form.dataset.wizard = remote ? 'choose' : 'connect';
			setStepper( remote ? 'choose' : 'connect', selectedFiles().length > 0 && wantsReview() );
			showRoute();
		} else {
			delete form.dataset.wizard;
			stepper.hidden = true;
		}
		showTables();
		summarize();
	}

	function showRoute() {
		if ( ! remote ) {
			return;
		}
		/* translators: 1: Siteferry version, 2: number of tables, 3: table prefix */
		const meta = sprintf( __( 'Siteferry %1$s · %2$s tables · prefix %3$s', 'siteferry' ), remote.version, num( Object.keys( remote.tables ).length ), remote.prefix );
		const pull = intent() === 'pull';
		$( 'siteferry-route-from' ).textContent = pull ? host( remote.home ) : here;
		$( 'siteferry-route-from-meta' ).textContent = pull ? meta : __( 'This site', 'siteferry' );
		$( 'siteferry-route-to' ).textContent = pull ? here : host( remote.home );
		$( 'siteferry-route-to-meta' ).textContent = pull ? __( 'What you choose here is replaced on this site', 'siteferry' ) : __( 'What you choose here is replaced on that site', 'siteferry' );
		const count = pull ? Object.keys( remote.tables ).length : localTables.length;
		/* translators: 1: number of tables, 2: table prefix */
		$( 'siteferry-db-hint' ).textContent = sprintf( __( '%1$s tables from %2$s. The site address is replaced automatically.', 'siteferry' ), num( count ), pull ? remote.prefix : data.prefix || '' );
	}

	function summarize() {
		const other = remote ? host( remote.home ) : __( 'the other site', 'siteferry' );
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
		const compare = remoteIntent() && files.length && wantsReview();
		const buttons = {
			pull: compare ? __( 'Compare files', 'siteferry' ) : __( 'Start pull', 'siteferry' ),
			push: compare ? __( 'Compare files', 'siteferry' ) : __( 'Start push', 'siteferry' ),
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
			status.hidden = true;
			toggleSections();
			$( 'siteferry-route-from' ).closest( '.sf-route' ).scrollIntoView( { block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' } );
		} catch ( err ) {
			status.dataset.state = 'error';
			status.textContent = ( err && err.message ) || __( 'Could not connect.', 'siteferry' );
		}
	} );
	$( 'siteferry-change-site' ).addEventListener( 'click', () => {
		remote = null;
		toggleSections();
		$( 'siteferry-connection' ).focus();
	} );
	$( 'siteferry-connection' ).addEventListener( 'input', () => {
		$( 'siteferry-remote-info' ).hidden = true;
	} );
	$( 'siteferry-choose-tables' ).addEventListener( 'click', () => {
		$( 'siteferry-replacements' ).open = true;
		$( 'siteferry-tables' ).open = true;
		$( 'siteferry-tables' ).scrollIntoView( { block: 'start', behavior: reduceMotion ? 'auto' : 'smooth' } );
	} );
	form.addEventListener( 'change', ( e ) => {
		if ( [ 'database', 'files', 'export_files', 'review' ].includes( e.target.name ) ) {
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
		const review = wantsReview();
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
			// With a review these are chosen there, after the comparison.
			delete_files: ! review && checked( 'delete_files' ),
			only_new: ! review && checked( 'only_new' ),
			review,
		};
	}

	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		const body = payload();

		if ( body.intent === 'replace' && ! body.pairs.length ) {
			return tell( BEFORE, __( 'Enter at least one text to find.', 'siteferry' ) );
		}
		if ( body.intent === 'import' ) {
			const file = fileInput.files[ 0 ];
			if ( ! file ) {
				return tell( BEFORE, __( 'Choose the file to import.', 'siteferry' ) );
			}
			if ( ! await ask( {
				title: __( 'Replace this site’s database?', 'siteferry' ),
				text: __( 'This site’s database will be replaced with the one in the file.', 'siteferry' ) + backupNote( body ),
				ok: __( 'Import', 'siteferry' ),
				tone: 'danger',
			} ) ) {
				return;
			}
			try {
				Object.assign( body, await upload( file ) );
			} catch ( err ) {
				return showError( err );
			}
		} else if ( body.intent === 'replace' && ! await ask( {
			title: __( 'Change this site’s data?', 'siteferry' ),
			text: __( 'The texts you entered are replaced in this site’s database.', 'siteferry' ) + backupNote( body ),
			ok: __( 'Run search and replace', 'siteferry' ),
			tone: 'danger',
		} ) ) {
			return;
		} else if ( body.intent === 'pull' || body.intent === 'push' ) {
			if ( ! body.connection ) {
				return tell( BEFORE, __( 'Paste the other site’s connection info.', 'siteferry' ) );
			}
			if ( ! body.database && ! body.files.length ) {
				return tell( BEFORE, __( 'Choose the database, files, or both.', 'siteferry' ) );
			}
			const code = body.files.filter( ( f ) => f !== 'uploads' );
			if ( body.intent === 'pull' && body.files.length && remote && ! remote.allow_pull_files ) {
				return tell( BEFORE, __( 'The other site does not allow file pulls. Enable it there or clear the file checkboxes.', 'siteferry' ) );
			}
			if ( body.intent === 'push' && code.length && remote && ! remote.allow_push_code ) {
				return tell( BEFORE, __( 'The other site does not allow code pushes (themes and plugins). Enable it there or clear those checkboxes.', 'siteferry' ) );
			}
			const later = body.files.length && body.review ? ` ${ __( 'Nothing is transferred until you review the file comparison.', 'siteferry' ) }` : '';
			if ( ! await ask( {
				title: body.intent === 'pull' ? __( 'Start the pull?', 'siteferry' ) : __( 'Start the push?', 'siteferry' ),
				text: $( 'siteferry-summary' ).textContent + later,
				ok: $( 'siteferry-run' ).textContent,
			} ) ) {
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
		showProgress();
		$( 'siteferry-status' ).textContent = __( 'Uploading file…', 'siteferry' );
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
	let seen = false; // The person left the finished operation's screen.

	async function run( job ) {
		running = true;
		seen = false;
		showProgress();
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

	// The percentage counts up instead of jumping; the bar eases in CSS.
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

	// Transfer speed, measured in this page from the bytes the server reports.
	let speed = null;
	function measure( job ) {
		const folders = Object.values( job.folders || {} );
		const sent = folders.reduce( ( a, f ) => a + f.sent_bytes, 0 );
		const total = folders.reduce( ( a, f ) => a + f.bytes, 0 );
		const now = Date.now();
		if ( ! job.transfer || ! speed || speed.id !== job.id || sent < speed.bytes ) {
			speed = { id: job.id, at: now, bytes: sent };
			return null;
		}
		const seconds = ( now - speed.at ) / 1000;
		if ( seconds < 5 || sent === speed.bytes ) {
			return null;
		}
		const rate = ( sent - speed.bytes ) / seconds;
		return { rate, minutes: Math.max( 1, Math.round( ( total - sent ) / rate / 60 ) ) };
	}

	function tile( dl, label, value, className ) {
		const div = document.createElement( 'div' );
		div.className = className || '';
		const dt = document.createElement( 'dt' );
		dt.textContent = label;
		const dd = document.createElement( 'dd' );
		dd.textContent = value;
		div.append( dt, dd );
		dl.append( div );
	}

	function render( job ) {
		if ( ! job ) {
			return;
		}
		lastJob = job;
		const open = job.status === 'pending' || job.status === 'running';
		const remoteHost = host( job.remote );
		panel.dataset.state = job.status;
		showFloat();

		// Header: where it goes, how far it is.
		$( 'siteferry-run-label' ).textContent = `${ INTENT[ job.intent ] } · ${ STATUS[ job.status ] }`;
		$( 'siteferry-status' ).textContent = job.intent === 'pull' ? `${ remoteHost } → ${ here }` : ( job.intent === 'push' ? `${ here } → ${ remoteHost }` : INTENT[ job.intent ] );
		setPercent( job.percent );
		const current = ( job.phases || [] ).find( ( p ) => p.state === 'current' );
		const tiles = $( 'siteferry-tiles' );
		tiles.replaceChildren();
		tile( tiles, __( 'Now', 'siteferry' ), open && current ? PHASES[ current.key ] || current.key : STATUS[ job.status ] );
		const t = open && job.transfer;
		if ( t ) {
			/* translators: 1: files transferred, 2: files to transfer */
			tile( tiles, FOLDERS[ t.root ] || t.root, sprintf( __( '%1$s of %2$s files', 'siteferry' ), num( t.files ), num( t.total ) ) );
			/* translators: 1: size transferred, 2: size to transfer */
			tile( tiles, __( 'Data', 'siteferry' ), sprintf( __( '%1$s of %2$s', 'siteferry' ), size( t.bytes ), size( t.total_bytes ) ) );
			const pace = measure( job );
			/* translators: 1: speed in MB/s, 2: minutes left */
			tile( tiles, __( 'Speed', 'siteferry' ), pace ? sprintf( __( '%1$s MB/s · ~%2$s min left', 'siteferry' ), ( pace.rate / 1048576 ).toFixed( 1 ), num( pace.minutes ) ) : __( 'Measuring…', 'siteferry' ) );
		}
		$( 'siteferry-step' ).textContent = `${ STATUS[ job.status ] }${ t ? ` · ${ FOLDERS[ t.root ] || t.root }: ${ num( t.files ) } / ${ num( t.total ) }` : '' }`;

		const hasReview = ( job.phases || [] ).some( ( p ) => p.key === 'review' );
		if ( panel.hidden ) {
			// Another screen is showing: its own steps (or none) stay.
		} else if ( job.intent === 'pull' || job.intent === 'push' ) {
			setStepper( job.status === 'done' ? 'all' : ( job.status === 'review' ? 'review' : 'transfer' ), hasReview );
		} else {
			stepper.hidden = true;
		}

		$( 'siteferry-log' ).textContent = job.log.join( '\n' );
		renderPhases( job );
		renderReview( job );
		renderDone( job );
		$( 'siteferry-resume' ).hidden = ! ( job.status === 'paused' || ( open && ! running ) );
		$( 'siteferry-cancel' ).hidden = ! ( UNFINISHED.includes( job.status ) || job.status === 'failed' );
		$( 'siteferry-new' ).hidden = UNFINISHED.includes( job.status ) || job.status === 'done';

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

	function renderPhases( job ) {
		const ol = $( 'siteferry-phases' );
		const folders = Object.entries( job.folders || {} ).filter( ( [ , f ] ) => f.send > 0 );
		const items = ( job.phases || [] ).map( ( phase ) => {
			const li = document.createElement( 'li' );
			li.className = `sf-phase is-${ phase.state }`;
			if ( phase.state === 'current' ) {
				li.setAttribute( 'aria-current', 'step' );
			}
			const dot = document.createElement( 'span' );
			dot.className = 'sf-phase-dot';
			dot.setAttribute( 'aria-hidden', 'true' );
			const name = document.createElement( 'span' );
			name.className = 'sf-phase-name';
			name.textContent = PHASES[ phase.key ] || phase.key;
			li.append( dot, name );
			if ( phase.key === 'database' && job.tables ) {
				const meta = document.createElement( 'span' );
				meta.className = 'sf-phase-meta';
				/* translators: %s: number of tables */
				meta.textContent = sprintf( __( '%s tables', 'siteferry' ), num( job.tables ) );
				li.append( meta );
			}
			// While files travel, one bar per folder.
			if ( phase.key === 'files' && phase.state === 'current' && folders.length ) {
				const grid = document.createElement( 'span' );
				grid.className = 'sf-folders';
				folders.forEach( ( [ root, f ] ) => {
					const label = document.createElement( 'span' );
					label.textContent = FOLDERS[ root ] || root;
					const track = document.createElement( 'span' );
					track.className = 'sf-folder-bar';
					const fill = document.createElement( 'span' );
					fill.style.transform = `scaleX(${ f.sent / f.send })`;
					track.append( fill );
					const count = document.createElement( 'span' );
					count.className = 'sf-phase-meta';
					count.textContent = `${ num( f.sent ) } / ${ num( f.send ) }`;
					grid.append( label, track, count );
				} );
				li.append( grid );
			}
			return li;
		} );
		// Rebuild only when a phase changes state, so its transition plays once; otherwise refresh the folder bars.
		const signature = ( job.phases || [] ).map( ( p ) => p.key + p.state ).join();
		if ( ol.dataset.signature !== signature ) {
			ol.dataset.signature = signature;
			ol.replaceChildren( ...items );
			return;
		}
		const fresh = items.find( ( li ) => li.querySelector( '.sf-folders' ) );
		const old = ol.querySelector( '.sf-folders' );
		if ( fresh && old ) {
			old.replaceWith( fresh.querySelector( '.sf-folders' ) );
		} else if ( fresh ) {
			ol.replaceChildren( ...items );
		}
	}

	function renderReview( job ) {
		const box = $( 'siteferry-review' );
		box.hidden = ! job.review;
		if ( ! job.review ) {
			return;
		}
		const stats = Object.entries( job.review.stats );
		const sum = ( key ) => stats.reduce( ( a, [ , s ] ) => a + s[ key ], 0 );
		const cell = ( tag, count, bytes ) => {
			const td = document.createElement( tag );
			td.className = 'sf-num';
			const strong = document.createElement( 'strong' );
			strong.textContent = num( count );
			td.append( strong );
			if ( bytes ) {
				const meta = document.createElement( 'span' );
				meta.textContent = size( bytes );
				td.append( meta );
			}
			return td;
		};
		const rows = stats.map( ( [ root, s ] ) => {
			const tr = document.createElement( 'tr' );
			const name = document.createElement( 'th' );
			name.scope = 'row';
			name.textContent = FOLDERS[ root ] || root;
			tr.append( name, cell( 'td', s.new, s.new_bytes ), cell( 'td', s.changed, s.changed_bytes ), cell( 'td', s.extra, s.extra_bytes ) );
			return tr;
		} );
		$( 'siteferry-review-rows' ).replaceChildren( ...rows );
		const total = document.createElement( 'tr' );
		const label = document.createElement( 'th' );
		label.scope = 'row';
		label.textContent = __( 'Total', 'siteferry' );
		total.append( label, cell( 'td', sum( 'new' ), sum( 'new_bytes' ) ), cell( 'td', sum( 'changed' ), sum( 'changed_bytes' ) ), cell( 'td', sum( 'extra' ), sum( 'extra_bytes' ) ) );
		$( 'siteferry-review-total' ).replaceChildren( total );

		const other = host( job.remote );
		/* translators: 1: source site, 2: destination site */
		$( 'siteferry-review-text' ).textContent = sprintf( __( '%1$s compared with %2$s. Nothing has moved yet.', 'siteferry' ), job.intent === 'pull' ? other : here, job.intent === 'pull' ? here : other );
		/* translators: 1: number of files, 2: size */
		const filesText = ( count, bytes ) => sprintf( __( '%1$s files · %2$s', 'siteferry' ), num( count ), size( bytes ) );
		$( 'siteferry-mode-all' ).textContent = `${ filesText( sum( 'new' ) + sum( 'changed' ), sum( 'new_bytes' ) + sum( 'changed_bytes' ) ) }. ${ __( 'Changed files are overwritten.', 'siteferry' ) }`;
		$( 'siteferry-mode-new' ).textContent = `${ filesText( sum( 'new' ), sum( 'new_bytes' ) ) }. ${ __( 'What the destination already has is left alone.', 'siteferry' ) }`;
		/* translators: %s: number of files */
		$( 'siteferry-delete-title' ).textContent = sprintf( __( 'Also delete the %s files that only the destination has', 'siteferry' ), num( sum( 'extra' ) ) );
		if ( box.dataset.job !== job.id ) {
			// Set the choice once per job, so a re-render does not undo what the person picked.
			box.dataset.job = job.id;
			box.querySelector( `input[name="review_mode"][value="${ job.review.only_new ? 'new' : 'all' }"]` ).checked = true;
			$( 'siteferry-review-delete' ).checked = job.review.delete_files;
		}
		const go = () => {
			const onlyNew = box.querySelector( 'input[name="review_mode"]:checked' ).value === 'new';
			/* translators: %s: number of files */
			$( 'siteferry-review-go' ).textContent = sprintf( __( 'Transfer %s files', 'siteferry' ), num( sum( 'new' ) + ( onlyNew ? 0 : sum( 'changed' ) ) ) );
		};
		box.onchange = go;
		go();
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

	function renderDone( job ) {
		const done = job.status === 'done';
		$( 'siteferry-done' ).hidden = ! done;
		$( 'siteferry-phases' ).hidden = done;
		$( 'siteferry-changes' ).hidden = ! done;
		$( 'siteferry-main-title' ).textContent = ! done ? __( 'Phases', 'siteferry' ) : ( job.intent === 'export' ? __( 'What it includes', 'siteferry' ) : __( 'What changed', 'siteferry' ) );
		panel.querySelectorAll( '[data-when="open"]' ).forEach( ( el ) => ( el.hidden = done ) );
		$( 'siteferry-undo-card' ).hidden = ! ( done && [ 'pull', 'import', 'replace' ].includes( job.intent ) );
		$( 'siteferry-run-body' ).hidden = job.status === 'review';
		if ( ! done ) {
			return;
		}
		const other = host( job.remote );
		const titles = {
			/* translators: 1: this site, 2: the other site */
			pull: sprintf( __( '%1$s is now a copy of %2$s', 'siteferry' ), here, other ),
			/* translators: %s: the other site */
			push: sprintf( __( '%s now has this site’s content', 'siteferry' ), other ),
			export: __( 'The export is ready', 'siteferry' ),
			import: __( 'The import is finished', 'siteferry' ),
			replace: __( 'Search and replace is finished', 'siteferry' ),
			undo: __( 'The site is back as it was', 'siteferry' ),
		};
		$( 'siteferry-done-title' ).textContent = titles[ job.intent ];
		const minutes = Math.max( 1, Math.round( ( job.updated - job.created ) / 60 ) );
		const at = new Date( job.updated * 1000 ).toLocaleTimeString( locale, { hour: '2-digit', minute: '2-digit' } );
		/* translators: 1: time of day, 2: minutes */
		$( 'siteferry-done-text' ).textContent = sprintf( __( 'Finished at %1$s, in %2$s min.', 'siteferry' ), at, num( minutes ) );

		const folders = Object.entries( job.folders || {} );
		const sent = folders.reduce( ( a, [ , f ] ) => a + f.sent, 0 );
		const bytes = folders.reduce( ( a, [ , f ] ) => a + f.sent_bytes, 0 );
		const moved = job.deletes ? folders.reduce( ( a, [ , f ] ) => a + f.delete, 0 ) : 0;
		const stats = $( 'siteferry-done-stats' );
		stats.replaceChildren();
		const stat = ( value, label, warn ) => tile( stats, label, value, warn ? 'is-warning' : '' );
		if ( job.tables && job.database !== false ) {
			stat( num( job.tables ), __( 'tables', 'siteferry' ) );
		}
		if ( folders.length ) {
			stat( num( sent ), __( 'files transferred', 'siteferry' ) );
			stat( size( bytes ), __( 'transferred', 'siteferry' ) );
		}
		if ( moved ) {
			stat( num( moved ), __( 'files moved to the backup', 'siteferry' ), true );
		}

		// A new database brings its users: this session is gone.
		const loggedOut = job.intent === 'import' || job.intent === 'undo' || ( job.intent === 'pull' && job.database );
		$( 'siteferry-done-login' ).hidden = ! loggedOut;
		$( 'siteferry-login' ).hidden = ! loggedOut;

		const changes = $( 'siteferry-changes' );
		const line = ( name, text ) => {
			const li = document.createElement( 'li' );
			const a = document.createElement( 'span' );
			a.textContent = name;
			const b = document.createElement( 'span' );
			b.className = 'sf-help';
			b.textContent = text;
			li.append( a, b );
			return li;
		};
		const items = [];
		if ( job.tables && job.database !== false ) {
			/* translators: %s: number of tables */
			items.push( line( __( 'Database', 'siteferry' ), sprintf( __( '%s tables', 'siteferry' ), num( job.tables ) ) ) );
		}
		folders.forEach( ( [ root, f ] ) => {
			/* translators: %s: number of files */
			const parts = [ sprintf( __( '%s files transferred', 'siteferry' ), num( f.sent ) ) ];
			if ( job.deletes && f.delete ) {
				/* translators: %s: number of files */
				parts.push( sprintf( __( '%s moved to the backup', 'siteferry' ), num( f.delete ) ) );
			}
			items.push( line( FOLDERS[ root ] || root, parts.join( ' · ' ) ) );
		} );
		changes.replaceChildren( ...items );
		$( 'siteferry-run-body' ).hidden = ! items.length && ! Object.keys( job.files ).length && $( 'siteferry-undo-card' ).hidden;
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

	// The operation's screen; the tab bar stays usable and the loop keeps running behind other screens.
	function showProgress() {
		form.hidden = true;
		Object.values( screens ).forEach( ( el ) => ( el.hidden = true ) );
		panel.hidden = false;
		tabs.forEach( ( t ) => {
			t.setAttribute( 'aria-selected', 'false' );
			t.tabIndex = -1;
		} );
		( lastJob && tabs.find( ( t ) => t.dataset.tab === lastJob.intent ) || tabs[ 0 ] ).tabIndex = 0;
		showFloat();
	}

	// A floating button back to the operation, on any other screen while it is not over or not yet seen.
	function showFloat() {
		const button = $( 'siteferry-float' );
		const job = lastJob;
		const active = job && ( running || UNFINISHED.includes( job.status ) || ( ! seen && job.status !== 'cancelled' ) );
		button.hidden = ! ( active && panel.hidden );
		if ( ! button.hidden ) {
			button.dataset.state = job.status;
			$( 'siteferry-float-text' ).textContent = job.status === 'running' || job.status === 'pending' ? `${ INTENT[ job.intent ] } · ${ job.percent }%` : `${ INTENT[ job.intent ] } · ${ STATUS[ job.status ] }`;
		}
	}
	$( 'siteferry-float' ).addEventListener( 'click', () => {
		showProgress();
		render( lastJob );
		panel.querySelector( 'h2' ).scrollIntoView( { block: 'nearest' } );
	} );

	function showError( err, keepPanel ) {
		const text = ( err && err.message ) || __( 'Unknown error.', 'siteferry' );
		if ( ! keepPanel ) {
			if ( ! lastJob || ! UNFINISHED.includes( lastJob.status ) ) {
				selectTab( tab );
			}
			return tell( __( 'Something went wrong', 'siteferry' ), text );
		}
		// WordPress refuses the nonce once the session is gone, as happens when a pull or import replaces this site's users.
		const sessionGone = err && err.code === 'rest_cookie_invalid_nonce';
		notice( 'error', sessionGone ? __( 'Your session on this site ended, probably because its users were just replaced. Reload the page, log in (with a user from the source site if the database was replaced) and press “Resume”.', 'siteferry' ) : text + ' ' + __( 'You can resume once it is fixed.', 'siteferry' ) );
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
		if ( ! await ask( {
			title: __( 'Cancel the operation?', 'siteferry' ),
			text: __( 'Temporary tables and files will be deleted.', 'siteferry' ),
			ok: __( 'Cancel the operation', 'siteferry' ),
			cancel: __( 'Keep going', 'siteferry' ),
			tone: 'danger',
		} ) ) {
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
		seen = true;
		selectTab( 'home' );
	} );

	// --- Undo ---------------------------------------------------------------

	async function refreshUndo() {
		const point = await api( { path: `${ NS }/undo` } ).catch( () => null );
		$( 'siteferry-undo-run' ).disabled = ! point;
		$( 'siteferry-undo-again' ).disabled = ! point;
		if ( point ) {
			const what = list( [ point.db_backup ? __( 'database', 'siteferry' ) : '', point.roots.length ? `${ __( 'files', 'siteferry' ) } (${ point.roots.join( ', ' ) })` : '' ].filter( Boolean ) );
			/* translators: 1: operation, 2: date, 3: what gets restored */
			$( 'siteferry-undo-info' ).textContent = sprintf( __( '%1$s · %2$s · restores: %3$s.', 'siteferry' ), point.label, new Date( point.time * 1000 ).toLocaleString( locale ), what );
		} else {
			$( 'siteferry-undo-info' ).textContent = __( 'There is nothing to undo.', 'siteferry' );
		}
	}
	const undo = async () => {
		if ( ! await ask( {
			title: __( 'Undo the last operation?', 'siteferry' ),
			text: __( 'The site will be restored to its state before the last operation. Anything changed on this site since then is lost, and this cannot itself be undone.', 'siteferry' ),
			ok: __( 'Undo', 'siteferry' ),
			tone: 'danger',
		} ) ) {
			return;
		}
		try {
			run( await api( { path: `${ NS }/undo`, method: 'POST' } ) );
		} catch ( err ) {
			showError( err );
		}
	};
	$( 'siteferry-undo-run' ).addEventListener( 'click', undo );
	$( 'siteferry-undo-again' ).addEventListener( 'click', undo );

	// --- This site's connection ---------------------------------------------

	const settings = $( 'siteferry-settings' );
	const settingsStatus = $( 'siteferry-settings-status' );
	const secret = $( 'siteferry-own-connection' );
	const ACCEPTS = {
		allow_pull: __( 'pull', 'siteferry' ),
		allow_pull_files: __( 'file pull', 'siteferry' ),
		allow_push: __( 'push', 'siteferry' ),
		allow_push_code: __( 'code push', 'siteferry' ),
	};
	function showAccepts() {
		const on = Object.keys( ACCEPTS ).filter( ( name ) => settings.querySelector( `input[name="${ name }"]` ).checked );
		$( 'siteferry-accepts' ).dataset.on = on.length ? '1' : '0';
		/* translators: %s: list of allowed operations */
		$( 'siteferry-accepts' ).textContent = on.length ? sprintf( __( 'This site accepts: %s', 'siteferry' ), on.map( ( name ) => ACCEPTS[ name ] ).join( ', ' ) ) : __( 'This site accepts no connections', 'siteferry' );
		document.querySelectorAll( '#siteferry-perm-chips [data-perm]' ).forEach( ( li ) => ( li.hidden = ! on.includes( li.dataset.perm ) ) );
	}
	// The key stays masked until asked for.
	function showSecret() {
		const [ url, key = '' ] = secret.dataset.connection.split( /\s+/ );
		const visible = $( 'siteferry-show-key' ).getAttribute( 'aria-pressed' ) === 'true';
		$( 'siteferry-own-url' ).textContent = url;
		$( 'siteferry-own-key' ).textContent = visible ? key : '•'.repeat( Math.min( key.length, 32 ) ).replace( /(.{4})/g, '$1 ' ).trim();
		$( 'siteferry-own-key' ).classList.toggle( 'is-masked', ! visible );
		$( 'siteferry-show-key' ).textContent = visible ? __( 'Hide key', 'siteferry' ) : __( 'Show key', 'siteferry' );
	}
	$( 'siteferry-show-key' ).addEventListener( 'click', ( e ) => {
		e.currentTarget.setAttribute( 'aria-pressed', e.currentTarget.getAttribute( 'aria-pressed' ) === 'true' ? 'false' : 'true' );
		showSecret();
	} );
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
		try {
			await navigator.clipboard.writeText( secret.dataset.connection );
			settingsStatus.textContent = __( 'Copied.', 'siteferry' );
		} catch ( err ) {
			// No clipboard permission: show the key and select it for Ctrl+C.
			$( 'siteferry-show-key' ).setAttribute( 'aria-pressed', 'true' );
			showSecret();
			window.getSelection().selectAllChildren( secret );
		}
	} );
	$( 'siteferry-regenerate' ).addEventListener( 'click', async () => {
		if ( ! await ask( {
			title: __( 'Regenerate the key?', 'siteferry' ),
			text: __( 'Sites using the current key will no longer be able to connect.', 'siteferry' ),
			ok: __( 'Regenerate key', 'siteferry' ),
			tone: 'danger',
		} ) ) {
			return;
		}
		const res = await api( { path: `${ NS }/settings/key`, method: 'POST' } );
		secret.dataset.connection = res.connection;
		showSecret();
		settingsStatus.textContent = __( 'New key generated.', 'siteferry' );
	} );
	showSecret();

	selectTab( 'home' );

	// On load: an unfinished operation is shown so it can be resumed, reviewed or cancelled.
	api( { path: `${ NS }/jobs/current` } ).then( ( job ) => {
		if ( job && [ ...UNFINISHED, 'failed' ].includes( job.status ) ) {
			showProgress();
			render( job );
		}
	} );
} )();
