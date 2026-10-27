/**
 * Siteferry: envía el formulario y avanza el job paso a paso.
 * El cursor vive en el servidor, así que reintentar un paso es seguro.
 */
( function () {
	'use strict';

	const data = window.siteferryData;
	const api = window.wp.apiFetch;
	const NS = '/siteferry/v1';
	const $ = ( id ) => document.getElementById( id );
	const form = $( 'siteferry-form' );
	const panel = $( 'siteferry-progress' );
	const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

	// El mu-plugin de compatibilidad solo actúa si llega esta cabecera.
	api.use( ( options, next ) => {
		options.headers = Object.assign( {}, options.headers, { 'X-Siteferry-Compat': data.compatKey } );
		return next( options );
	} );

	const STATUS = {
		pending: 'En cola…',
		running: 'Trabajando…',
		done: 'Terminado',
		failed: 'Falló',
		cancelled: 'Cancelado',
	};
	const INTENT = { export: 'Exportación', replace: 'Buscar y reemplazar', import: 'Importación', pull: 'Pull', push: 'Push', undo: 'Deshacer' };

	// --- Formulario -------------------------------------------------------

	const intent = () => form.querySelector( 'input[name="intent"]:checked' ).value;

	function toggleSections() {
		const current = intent();
		form.querySelectorAll( '[data-show]' ).forEach( ( el ) => {
			el.hidden = ! el.dataset.show.split( ' ' ).includes( current );
		} );
		form.querySelectorAll( '[data-hide]' ).forEach( ( el ) => {
			el.hidden = el.dataset.hide.split( ' ' ).includes( current );
		} );
		// Sin base de datos en un pull/push no hay tablas ni reemplazos que elegir.
		const noDatabase = remoteIntent() && ! form.querySelector( 'input[name="database"]' ).checked;
		tableList.closest( 'details' ).hidden = current === 'import' || noDatabase;
		showTables();
	}

	// --- Tablas: las de este sitio, o las del remoto en un pull ------------

	const tableList = $( 'siteferry-table-list' );
	const localTables = [ ...tableList.children ];
	const tablesTitle = $( 'siteferry-tables-title' );
	let remote = null; // Respuesta de "hello" del sitio remoto.

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
			li.textContent = 'Conecta con el sitio remoto para ver sus tablas.';
			tableList.replaceChildren( li );
		}
		tablesTitle.textContent = `Tablas (${ tableList.querySelectorAll( 'input' ).length })`;
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
		status.textContent = 'Conectando…';
		remote = null;
		try {
			remote = await api( { path: `${ NS }/remote/hello`, method: 'POST', data: { connection: $( 'siteferry-connection' ).value } } );
			const allowed = [ remote.allow_pull ? 'pull' : '', remote.allow_push ? 'push' : '' ].filter( Boolean ).join( ' y ' ) || 'nada (actívalo allá)';
			const yes = ( v ) => ( v ? 'sí' : 'no' );
			status.textContent = `Conectado con ${ remote.home } · prefijo ${ remote.prefix } · ${ Object.keys( remote.tables ).length } tablas · permite ${ allowed } · pull de archivos: ${ yes( remote.allow_pull_files ) } · push de código: ${ yes( remote.allow_push_code ) }.`;
		} catch ( err ) {
			status.textContent = ( err && err.message ) || 'No se pudo conectar.';
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
			// La fila prellenada con la URL actual y sin destino no es un reemplazo: se ignora.
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
			return alert( 'Indica al menos un texto a buscar.' );
		}
		if ( body.intent === 'import' ) {
			const file = $( 'siteferry-file' ).files[ 0 ];
			if ( ! file ) {
				return alert( 'Elige el archivo a importar.' );
			}
			if ( ! confirm( 'La base de datos de este sitio se reemplazará por la del archivo. ¿Continuar?' ) ) {
				return;
			}
			try {
				Object.assign( body, await upload( file ) );
			} catch ( err ) {
				return showError( err );
			}
		} else if ( body.intent === 'replace' && ! confirm( 'Se modificarán los datos de este sitio. ¿Continuar?' ) ) {
			return;
		} else if ( body.intent === 'pull' || body.intent === 'push' ) {
			if ( ! body.connection ) {
				return alert( 'Pega la información de conexión del otro sitio.' );
			}
			if ( ! body.database && ! body.files.length ) {
				return alert( 'Elige la base de datos, archivos o ambos.' );
			}
			const code = body.files.filter( ( f ) => f !== 'uploads' );
			if ( body.intent === 'pull' && body.files.length && remote && ! remote.allow_pull_files ) {
				return alert( 'El otro sitio no permite pull de archivos. Actívalo allá o quita las casillas de archivos.' );
			}
			if ( body.intent === 'push' && code.length && remote && ! remote.allow_push_code ) {
				return alert( 'El otro sitio no permite push de código (temas y plugins). Actívalo allá o quita esas casillas.' );
			}
			const other = remote ? remote.home : 'el otro sitio';
			const what = [ body.database ? 'la base de datos' : '', body.files.length ? `archivos (${ body.files.join( ', ' ) })` : '' ].filter( Boolean ).join( ' y ' );
			const question = body.intent === 'pull'
				? `Se reemplazarán ${ what } de ESTE sitio por los de ${ other }. ¿Continuar?`
				: `Se reemplazarán ${ what } de ${ other } por los de este sitio. ¿Continuar?`;
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

	// --- Subida por trozos -----------------------------------------------

	async function upload( file ) {
		showPanel( 'Subiendo archivo…' );
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
			$( 'siteferry-step' ).textContent = `${ size( offset ) } de ${ size( file.size ) }`;
		}
		return { upload: token, ext };
	}

	// --- Bucle de pasos ---------------------------------------------------

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

	// Reintenta cortes de red y "otro paso en curso"; los errores de la operación no se reintentan.
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
			notice( 'error', job.error + ' Las tablas del sitio no se modificaron; «Cancelar» limpia lo temporal.' );
		}
		job.warnings.forEach( ( w ) => notice( 'warning', w ) );
		if ( ! open ) {
			refreshUndo();
		}
		if ( job.status === 'done' && ( job.intent === 'import' || job.intent === 'undo' || ( job.intent === 'pull' && job.database ) ) ) {
			notice( 'success', 'Terminado. Si se cerró tu sesión, vuelve a iniciar sesión con un usuario del sitio de origen.' );
		}

		const files = $( 'siteferry-files' );
		files.replaceChildren();
		Object.entries( job.files ).forEach( ( [ key, file ] ) => {
			const a = document.createElement( 'a' );
			a.className = key === 'export' ? 'button button-primary' : 'button';
			a.href = restUrl( `jobs/${ job.id }/download`, { file: key, _wpnonce: data.nonce } );
			a.textContent = `${ key === 'export' ? 'Descargar' : 'Descargar backup' } (${ file.name }, ${ size( file.size ) })`;
			files.append( a, ' ' );
		} );
	}

	// Sirve con enlaces permanentes (/wp-json/…) y sin ellos (?rest_route=…).
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
		const text = ( err && err.message ) || 'Error desconocido.';
		if ( ! keepPanel ) {
			form.hidden = false;
			panel.hidden = true;
			return alert( text );
		}
		notice( 'error', text + ' Puedes continuar cuando se resuelva.' );
		$( 'siteferry-resume' ).hidden = false;
	}

	function size( bytes ) {
		return bytes > 1048576 ? `${ ( bytes / 1048576 ).toFixed( 1 ) } MB` : `${ Math.ceil( bytes / 1024 ) } KB`;
	}

	$( 'siteferry-resume' ).addEventListener( 'click', async () => {
		run( await api( { path: `${ NS }/jobs/current` } ) );
	} );
	$( 'siteferry-cancel' ).addEventListener( 'click', async () => {
		if ( ! confirm( '¿Cancelar la operación? Se borran las tablas y archivos temporales.' ) ) {
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

	// --- Deshacer ----------------------------------------------------------

	const undoCard = $( 'siteferry-undo' );
	async function refreshUndo() {
		const point = await api( { path: `${ NS }/undo` } ).catch( () => null );
		undoCard.hidden = ! point;
		if ( point ) {
			const what = [ point.db_backup ? 'base de datos' : '', point.roots.length ? `archivos (${ point.roots.join( ', ' ) })` : '' ].filter( Boolean ).join( ' y ' );
			$( 'siteferry-undo-info' ).textContent = `${ point.label } · ${ new Date( point.time * 1000 ).toLocaleString() } · se restaura: ${ what }.`;
		}
	}
	$( 'siteferry-undo-run' ).addEventListener( 'click', async () => {
		if ( ! confirm( 'Se restaurará el estado anterior a la última operación. Lo cambiado después en este sitio se pierde y no se puede deshacer a su vez. ¿Continuar?' ) ) {
			return;
		}
		try {
			run( await api( { path: `${ NS }/undo`, method: 'POST' } ) );
		} catch ( err ) {
			showError( err );
		}
	} );

	// --- Conexión de este sitio ---------------------------------------------

	const settings = $( 'siteferry-settings' );
	const settingsStatus = $( 'siteferry-settings-status' );
	settings.addEventListener( 'change', async ( e ) => {
		if ( e.target.type !== 'checkbox' ) {
			return;
		}
		try {
			await api( { path: `${ NS }/settings`, method: 'POST', data: { [ e.target.name ]: e.target.checked } } );
			settingsStatus.textContent = 'Guardado.';
		} catch ( err ) {
			e.target.checked = ! e.target.checked;
			settingsStatus.textContent = ( err && err.message ) || 'No se pudo guardar.';
		}
	} );
	$( 'siteferry-copy-connection' ).addEventListener( 'click', async () => {
		const text = $( 'siteferry-own-connection' );
		try {
			await navigator.clipboard.writeText( text.value );
		} catch ( err ) {
			text.select(); // Sin permiso de portapapeles: queda seleccionado para Ctrl+C.
		}
		settingsStatus.textContent = 'Copiado.';
	} );
	$( 'siteferry-regenerate' ).addEventListener( 'click', async () => {
		if ( ! confirm( 'Los sitios que usan la clave actual dejarán de poder conectarse. ¿Regenerar?' ) ) {
			return;
		}
		const res = await api( { path: `${ NS }/settings/key`, method: 'POST' } );
		$( 'siteferry-own-connection' ).value = res.connection;
		settingsStatus.textContent = 'Clave nueva generada.';
	} );

	// Al cargar: si hay una operación sin terminar, se muestra para continuarla o cancelarla.
	api( { path: `${ NS }/jobs/current` } ).then( ( job ) => {
		if ( job && [ 'pending', 'running', 'failed' ].includes( job.status ) ) {
			render( job );
		}
	} );
} )();
