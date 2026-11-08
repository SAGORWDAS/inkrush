/* InkRush admin behavior. No libraries. */
( function () {
	'use strict';

	var config = window.zzzcAdmin || {};

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', 'zzzc_' + action );
		body.append( 'nonce', config.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function setText( node, text ) {
		if ( node ) {
			node.textContent = text;
		}
	}

	function element( tag, text ) {
		var node = document.createElement( tag );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	/* Confirm before stopping or deleting. */
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var message = form.getAttribute && form.getAttribute( 'data-confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	/* Show when a settings form has unsaved changes. */
	document.querySelectorAll( 'form[data-track-changes]' ).forEach( function ( form ) {
		var dirty = false;
		var marker = form.querySelector( '.zzzc-unsaved' );
		function markDirty() {
			dirty = true;
			if ( marker ) {
				marker.hidden = false;
			}
		}
		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	} );

	/* Test API connection. */
	var connection = document.getElementById( 'zzzc-test-connection' );
	if ( connection ) {
		connection.addEventListener( 'click', function () {
			var result = document.getElementById( 'zzzc-connection-result' );
			connection.disabled = true;
			setText( result, 'Testing...' );
			post( 'test_connection' ).then( function ( reply ) {
				setText( result, reply.data && reply.data.message ? reply.data.message : 'The test could not finish.' );
			} ).catch( function () {
				setText( result, 'The test could not finish. Check your connection and try again.' );
			} ).finally( function () {
				connection.disabled = false;
			} );
		} );
	}

	/* Test the Wikimedia Commons connection. */
	var commons = document.getElementById( 'zzzc-test-commons' );
	if ( commons ) {
		commons.addEventListener( 'click', function () {
			var result = document.getElementById( 'zzzc-commons-result' );
			commons.disabled = true;
			setText( result, 'Testing...' );
			post( 'test_commons' ).then( function ( reply ) {
				setText( result, reply.data && reply.data.message ? reply.data.message : 'The test could not finish.' );
			} ).catch( function () {
				setText( result, 'The test could not finish. Check your connection and try again.' );
			} ).finally( function () {
				commons.disabled = false;
			} );
		} );
	}

	/* Preview which image files would pass, without saving anything. */
	var imageSearch = document.getElementById( 'zzzc-image-search' );
	if ( imageSearch ) {
		imageSearch.addEventListener( 'click', function () {
			var box = document.getElementById( 'zzzc-image-results' );
			imageSearch.disabled = true;
			setText( box, 'Searching. This takes a few seconds, because Wikimedia Commons allows only slow requests...' );
			post( 'test_image_search', {
				text: document.getElementById( 'zzzc-image-text' ).value,
				category_id: document.getElementById( 'zzzc-image-category' ).value
			} ).then( function ( reply ) {
				box.textContent = '';
				if ( ! reply.success ) {
					setText( box, reply.data && reply.data.message ? reply.data.message : 'The search failed.' );
					return;
				}
				var data = reply.data;
				box.appendChild( element( 'p', 'Searches used: ' + data.queries.join( ' | ' ) ) );
				box.appendChild( element( 'p', data.checked + ' files checked, ' + data.passed + ' passed.' + ( data.error ? ' Search problem: ' + data.error : '' ) ) );
				if ( ! data.candidates.length ) {
					return;
				}
				var wrap = element( 'div' );
				wrap.className = 'zzzc-table-wrap';
				var table = element( 'table' );
				table.className = 'zzzc-table';
				var head = element( 'tr' );
				[ 'Picture', 'File', 'Size', 'License', 'Creator', 'Score', 'Result' ].forEach( function ( label ) {
					head.appendChild( element( 'th', label ) );
				} );
				var thead = element( 'thead' );
				thead.appendChild( head );
				table.appendChild( thead );
				var tbody = element( 'tbody' );
				data.candidates.forEach( function ( candidate ) {
					var row = element( 'tr' );
					var picture = element( 'td' );
					if ( candidate.thumb ) {
						var image = element( 'img' );
						image.src = candidate.thumb;
						image.alt = '';
						image.className = 'zzzc-thumb';
						image.loading = 'lazy';
						picture.appendChild( image );
					}
					row.appendChild( picture );
					var file = element( 'td' );
					var link = element( 'a', candidate.title );
					link.href = candidate.page_url;
					link.target = '_blank';
					link.rel = 'noopener noreferrer';
					file.appendChild( link );
					row.appendChild( file );
					row.appendChild( element( 'td', candidate.size ) );
					row.appendChild( element( 'td', candidate.license || 'Not accepted' ) );
					row.appendChild( element( 'td', candidate.creator ) );
					row.appendChild( element( 'td', String( candidate.score ) ) );
					row.appendChild( element( 'td', candidate.rejected || 'Passed' ) );
					tbody.appendChild( row );
				} );
				table.appendChild( tbody );
				wrap.appendChild( table );
				box.appendChild( wrap );
			} ).catch( function () {
				setText( box, 'The search could not finish.' );
			} ).finally( function () {
				imageSearch.disabled = false;
			} );
		} );
	}

	/* Test search on a category. */
	document.querySelectorAll( '[data-test-search]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var id = button.getAttribute( 'data-test-search' );
			var row = document.getElementById( 'zzzc-search-' + id );
			var cell = row.querySelector( 'td' );
			row.hidden = false;
			button.disabled = true;
			setText( cell, 'Searching...' );
			post( 'test_search', { id: id } ).then( function ( reply ) {
				cell.textContent = '';
				if ( ! reply.success ) {
					setText( cell, reply.data && reply.data.message ? reply.data.message : 'The search failed.' );
					return;
				}
				reply.data.results.forEach( function ( result ) {
					cell.appendChild( element( 'strong', result.context ) );
					var summary = result.status === 'ok'
						? result.total + ' stories returned, ' + result.passed + ' pass the filters. Filter applied: ' + result.filter
						: 'Failed: ' + ( result.message || result.status );
					cell.appendChild( element( 'p', summary ) );
					if ( result.stories.length ) {
						var list = element( 'ul' );
						list.className = 'zzzc-list';
						result.stories.forEach( function ( story ) {
							list.appendChild( element( 'li', story.headline + ' (' + story.publisher + ', ' + story.published + '): ' + story.result ) );
						} );
						cell.appendChild( list );
					}
				} );
			} ).catch( function () {
				setText( cell, 'The search could not finish.' );
			} ).finally( function () {
				button.disabled = false;
			} );
		} );
	} );

	/* Test generation. */
	var generate = document.getElementById( 'zzzc-test-generation' );
	var testDraft = document.getElementById( 'zzzc-test-draft' );
	if ( generate ) {
		generate.addEventListener( 'click', function () {
			var result = document.getElementById( 'zzzc-generation-result' );
			generate.disabled = true;
			testDraft.hidden = true;
			setText( result, 'Generating. This can take a few minutes...' );
			post( 'test_generation', {
				headline: document.getElementById( 't-headline' ).value,
				publisher: document.getElementById( 't-publisher' ).value,
				url: document.getElementById( 't-url' ).value,
				source_text: document.getElementById( 't-text' ).value
			} ).then( function ( reply ) {
				result.textContent = '';
				if ( ! reply.success ) {
					setText( result, reply.data && reply.data.message ? reply.data.message : 'The test failed.' );
					return;
				}
				var data = reply.data;
				result.appendChild( element( 'p', data.message ) );
				var cost = data.usage.cost === null || data.usage.cost === undefined ? 'Unavailable' : '$' + data.usage.cost;
				result.appendChild( element( 'p', 'Model: ' + data.usage.model + '. Input tokens: ' + data.usage.input + '. Output tokens: ' + data.usage.output + '. Reported cost: ' + cost + '.' ) );
				[ [ 'Errors', data.errors ], [ 'Warnings', data.warnings ] ].forEach( function ( group ) {
					if ( group[ 1 ].length ) {
						result.appendChild( element( 'strong', group[ 0 ] ) );
						var list = element( 'ul' );
						list.className = 'zzzc-list';
						group[ 1 ].forEach( function ( text ) {
							list.appendChild( element( 'li', text ) );
						} );
						result.appendChild( list );
					}
				} );
				result.appendChild( element( 'h3', data.title ) );
				var preview = element( 'div' );
				preview.className = 'zzzc-preview';
				/* The server has passed this through wp_kses_post. */
				preview.innerHTML = data.body;
				result.appendChild( preview );
				result.appendChild( element( 'p', 'Tags (' + data.tags.length + '): ' + data.tags.join( ', ' ) ) );
				testDraft.hidden = false;
			} ).catch( function () {
				setText( result, 'The test could not finish. The server may have timed out. Check Usage and Logs.' );
			} ).finally( function () {
				generate.disabled = false;
			} );
		} );

		testDraft.addEventListener( 'click', function () {
			var result = document.getElementById( 'zzzc-generation-result' );
			testDraft.disabled = true;
			post( 'save_test_draft' ).then( function ( reply ) {
				var line = element( 'p', reply.data && reply.data.message ? reply.data.message : 'The draft could not be created.' );
				if ( reply.success && reply.data.url ) {
					var link = element( 'a', ' Open the draft' );
					link.href = reply.data.url;
					line.appendChild( link );
				}
				result.insertBefore( line, result.firstChild );
			} ).finally( function () {
				testDraft.disabled = false;
			} );
		} );
	}

	/* Refresh dashboard figures in the background. Processing never depends on this. */
	var live = document.querySelector( '#zzzc-live[data-live]' );
	if ( live ) {
		window.setInterval( function () {
			if ( document.hidden ) {
				return;
			}
			post( 'live_status' ).then( function ( reply ) {
				if ( reply.success ) {
					// When the state changes, reload so the label, buttons and messages all match it.
					var badge = document.querySelector( '.zzzc-state' );
					if ( badge && ! badge.classList.contains( reply.data.state_class ) ) {
						window.location.reload();
						return;
					}
					live.innerHTML = reply.data.html;
				}
			} ).catch( function () {} );
		}, 30000 );
	}

	/* Authors page: select every user on the page, and confirm before revoking access. */
	document.querySelectorAll( '[data-check-all]' ).forEach( function ( box ) {
		box.addEventListener( 'change', function () {
			box.closest( 'form' ).querySelectorAll( 'input[name="user_ids[]"]' ).forEach( function ( item ) {
				item.checked = box.checked;
			} );
		} );
	} );
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var message = form.getAttribute && form.getAttribute( 'data-confirm-revoke' );
		if ( ! message ) {
			return;
		}
		var submitter = event.submitter;
		var bulk = form.querySelector( '[name="bulk_action"]' );
		var action = submitter && 'single' === submitter.name ? String( submitter.value ).split( ':' )[ 0 ] : ( bulk ? bulk.value : '' );
		if ( 'revoke' === action && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	/* Social Media page: test one connected account against its platform. */
	document.querySelectorAll( '.zzzc-test-social' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var id = button.getAttribute( 'data-account' );
			var result = document.querySelector( '.zzzc-result[data-for="' + id + '"]' );
			button.disabled = true;
			setText( result, 'Testing the connection with the platform...' );
			if ( result ) {
				result.className = 'zzzc-result';
			}
			post( 'test_social', { account_id: id } ).then( function ( reply ) {
				button.disabled = false;
				setText( result, reply.data && reply.data.message ? reply.data.message : 'The test did not finish.' );
				if ( result ) {
					result.className = 'zzzc-result ' + ( reply.success ? 'is-ok' : 'is-bad' );
				}
			} ).catch( function () {
				button.disabled = false;
				setText( result, 'The test could not be run. Check that this site can reach the internet.' );
				if ( result ) {
					result.className = 'zzzc-result is-bad';
				}
			} );
		} );
	} );

	/* Social Publishing page: show how much of a platform's limit the text has used. */
	document.querySelectorAll( '.zzzc-social-caption textarea[maxlength]' ).forEach( function ( area ) {
		var help = area.parentNode.querySelector( '.zzzc-help' );
		if ( ! help ) {
			return;
		}
		var original = help.textContent;
		area.addEventListener( 'input', function () {
			help.textContent = original.replace( /^\d+ of/, String( area.value.length ) + ' of' );
		} );
	} );
}() );
