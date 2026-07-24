( function () {
	'use strict';

	const config = window.SimpleRedisCacheWarm;
	const form = document.getElementById( 'src-cache-warm-form' );
	if ( ! config || ! form ) {
		return;
	}

	const startButton = document.getElementById( 'src-warm-start' );
	const stopButton = document.getElementById( 'src-warm-stop' );
	const selectAllButton = document.getElementById( 'src-warm-select-all' );
	const selectNoneButton = document.getElementById( 'src-warm-select-none' );
	const progressWrap = document.getElementById( 'src-warm-progress-wrap' );
	const progress = document.getElementById( 'src-warm-progress' );
	const progressText = document.getElementById( 'src-warm-progress-text' );
	const countsNode = document.getElementById( 'src-warm-counts' );
	const resultNode = document.getElementById( 'src-warm-result' );
	const errorsNode = document.getElementById( 'src-warm-errors' );
	const strings = config.strings;
	const maxVisibleErrors = 100;
	const requestTimeout = Math.max( 1000, Number( config.requestTimeout ) || 30000 );
	let controller = null;
	let running = false;
	let visibleIssueCount = 0;
	let hiddenIssueCount = 0;
	let hiddenIssueNode = null;

	function format( template, values ) {
		let output = String( template );
		values.forEach( ( value, index ) => {
			output = output.replace( new RegExp( `%${ index + 1 }\\$[ds]`, 'g' ), String( value ) );
			output = output.replace( /%[ds]/, String( value ) );
		} );
		return output;
	}

	function setNotice( message, type ) {
		const paragraph = document.createElement( 'p' );
		paragraph.textContent = message;
		resultNode.className = `notice notice-${ type } inline`;
		resultNode.replaceChildren( paragraph );
	}

	function clearNotice() {
		resultNode.className = '';
		resultNode.replaceChildren();
	}

	function addIssue( message ) {
		if ( visibleIssueCount >= maxVisibleErrors ) {
			hiddenIssueCount++;
			if ( ! hiddenIssueNode ) {
				hiddenIssueNode = document.createElement( 'li' );
				hiddenIssueNode.className = 'src-warm-hidden-issues';
				errorsNode.appendChild( hiddenIssueNode );
			}
			hiddenIssueNode.textContent = format(
				strings.moreIssues || '%d more issues not shown.',
				[ hiddenIssueCount ]
			);
			errorsNode.hidden = false;
			return;
		}

		const item = document.createElement( 'li' );
		item.textContent = message;
		errorsNode.appendChild( item );
		visibleIssueCount++;
		errorsNode.hidden = false;
	}

	function resetOutput() {
		clearNotice();
		errorsNode.replaceChildren();
		errorsNode.hidden = true;
		visibleIssueCount = 0;
		hiddenIssueCount = 0;
		hiddenIssueNode = null;
		progress.value = 0;
		progress.max = 1;
		progressText.textContent = '';
		countsNode.textContent = '';
	}

	function setRunning( value ) {
		running = value;
		startButton.disabled = value;
		selectAllButton.disabled = value;
		selectNoneButton.disabled = value;
		stopButton.hidden = ! value;
	}

	function updateCounts( counts ) {
		countsNode.textContent = [
			`${ strings.alreadyCached }: ${ counts.cached }`,
			`${ strings.warmed }: ${ counts.warmed }`,
			`${ strings.bypassed }: ${ counts.bypass }`,
			`${ strings.failed }: ${ counts.failed }`,
		].join( ' · ' );
	}

	async function ajax( data, signal ) {
		const response = await fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
			signal,
		} );
		const payload = await response.json().catch( () => null );
		if ( ! response.ok || ! payload || ! payload.success ) {
			const message = payload && payload.data && payload.data.message
				? payload.data.message
				: strings.unknownError;
			throw new Error( message );
		}

		return payload.data;
	}

	async function prepare( signal ) {
		const data = new FormData( form );
		data.append( 'action', config.prepareAction );
		data.append( 'nonce', config.nonce );
		return ajax( data, signal );
	}

	async function requestStatus( url, method, signal ) {
		const requestController = new AbortController();
		let timedOut = false;
		const abortRequest = () => requestController.abort();
		const timeoutId = window.setTimeout( () => {
			timedOut = true;
			requestController.abort();
		}, requestTimeout );

		if ( signal.aborted ) {
			abortRequest();
		} else {
			signal.addEventListener( 'abort', abortRequest, { once: true } );
		}

		try {
			const response = await fetch( url, {
				method,
				credentials: 'omit',
				cache: 'reload',
				redirect: 'follow',
				referrerPolicy: 'no-referrer',
				headers: {
					Accept: 'text/html,application/xhtml+xml',
					'X-Simple-Redis-Cache-Warm': '1',
				},
				signal: requestController.signal,
			} );

			if ( 'GET' === method ) {
				await response.arrayBuffer();
			}

			if ( response.redirected ) {
				return { status: '', httpStatus: response.status, error: strings.requestFailed };
			}
			if ( response.status < 200 || response.status >= 300 ) {
				return { status: '', httpStatus: response.status, error: `${ strings.requestFailed } HTTP ${ response.status }` };
			}

			const status = String( response.headers.get( 'X-Simple-Redis-Cache' ) || '' ).toUpperCase();
			if ( ! [ 'HIT', 'MISS', 'BYPASS' ].includes( status ) ) {
				return { status: '', httpStatus: response.status, error: strings.missingStatus };
			}

			return { status, httpStatus: response.status, error: '' };
		} catch ( error ) {
			if ( signal.aborted ) {
				throw error;
			}
			if ( timedOut ) {
				throw new Error( strings.requestTimedOut || strings.requestFailed );
			}
			throw error;
		} finally {
			window.clearTimeout( timeoutId );
			signal.removeEventListener( 'abort', abortRequest );
		}
	}

	function resultFromStatus( response ) {
		if ( response.error ) {
			return { result: 'failed', message: response.error };
		}
		if ( 'BYPASS' === response.status ) {
			return { result: 'bypass', message: strings.bypassMessage };
		}
		return null;
	}

	async function warmDirect( item, signal ) {
		const probe = await requestStatus( item.url, 'HEAD', signal );
		const probeFailure = resultFromStatus( probe );
		if ( probeFailure ) {
			return probeFailure;
		}
		if ( 'HIT' === probe.status ) {
			return { result: 'cached', message: '' };
		}

		for ( let attempt = 0; attempt < 2; attempt++ ) {
			const render = await requestStatus( item.url, 'GET', signal );
			const renderFailure = resultFromStatus( render );
			if ( renderFailure ) {
				return renderFailure;
			}
			if ( 'HIT' === render.status ) {
				return { result: 'cached', message: '' };
			}

			const verify = await requestStatus( item.url, 'HEAD', signal );
			const verifyFailure = resultFromStatus( verify );
			if ( verifyFailure ) {
				return verifyFailure;
			}
			if ( 'HIT' === verify.status ) {
				return { result: 'warmed', message: '' };
			}

			if ( 0 === attempt ) {
				await new Promise( ( resolve ) => window.setTimeout( resolve, Number( config.retryDelay ) || 250 ) );
			}
		}

		return { result: 'failed', message: strings.notStored };
	}

	async function warmThroughProxy( item, signal ) {
		const data = new FormData();
		data.append( 'action', config.proxyAction );
		data.append( 'nonce', config.nonce );
		data.append( 'url', item.url );
		data.append( 'signature', item.signature );
		return ajax( data, signal );
	}

	async function warmItem( item, signal ) {
		let url;
		try {
			url = new URL( item.url, window.location.href );
		} catch ( error ) {
			return { result: 'failed', message: strings.requestFailed };
		}

		if ( url.origin !== window.location.origin ) {
			return warmThroughProxy( item, signal );
		}

		try {
			return await warmDirect( item, signal );
		} catch ( error ) {
			if ( error && 'AbortError' === error.name ) {
				throw error;
			}
			return warmThroughProxy( item, signal );
		}
	}

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		if ( running ) {
			return;
		}

		const selected = form.querySelectorAll( 'input[type="checkbox"]:checked:not(:disabled)' );
		if ( 0 === selected.length ) {
			setNotice( strings.noSources, 'warning' );
			return;
		}

		controller = new AbortController();
		resetOutput();
		setRunning( true );
		progressWrap.hidden = false;
		progressText.textContent = strings.preparing;

		try {
			const prepared = await prepare( controller.signal );
			const items = Array.isArray( prepared.items ) ? prepared.items : [];
			const warnings = Array.isArray( prepared.warnings ) ? prepared.warnings : [];
			warnings.forEach( addIssue );

			if ( 0 === items.length ) {
				setNotice( strings.noUrls, 'warning' );
				return;
			}

			progress.max = items.length;
			const counts = { cached: 0, warmed: 0, bypass: 0, failed: 0 };
			updateCounts( counts );

			for ( let index = 0; index < items.length; index++ ) {
				if ( controller.signal.aborted ) {
					throw new DOMException( 'Aborted', 'AbortError' );
				}

				const item = items[ index ];
				progressText.textContent = format( strings.warming, [ item.url ] );
				let outcome;
				try {
					outcome = await warmItem( item, controller.signal );
				} catch ( error ) {
					if ( error && 'AbortError' === error.name ) {
						throw error;
					}
					outcome = { result: 'failed', message: error && error.message ? error.message : strings.unknownError };
				}

				if ( Object.prototype.hasOwnProperty.call( counts, outcome.result ) ) {
					counts[ outcome.result ]++;
				} else {
					counts.failed++;
				}
				if ( outcome.message ) {
					addIssue( `${ item.url } — ${ outcome.message }` );
				}

				progress.value = index + 1;
				progressText.textContent = format( strings.progress, [ index + 1, items.length ] );
				updateCounts( counts );
			}

			if ( 0 === counts.bypass && 0 === counts.failed && 0 === warnings.length ) {
				setNotice( strings.success, 'success' );
			} else {
				setNotice( strings.partial, 'warning' );
			}
		} catch ( error ) {
			if ( error && 'AbortError' === error.name ) {
				setNotice( strings.stopped, 'warning' );
			} else {
				setNotice( error && error.message ? error.message : strings.prepareFailed, 'error' );
			}
		} finally {
			setRunning( false );
			controller = null;
		}
	} );

	stopButton.addEventListener( 'click', () => {
		if ( controller ) {
			controller.abort();
		}
	} );

	selectAllButton.addEventListener( 'click', () => {
		form.querySelectorAll( 'input[type="checkbox"]:not(:disabled)' ).forEach( ( checkbox ) => {
			checkbox.checked = true;
		} );
	} );

	selectNoneButton.addEventListener( 'click', () => {
		form.querySelectorAll( 'input[type="checkbox"]:not(:disabled)' ).forEach( ( checkbox ) => {
			checkbox.checked = false;
		} );
	} );
}() );
