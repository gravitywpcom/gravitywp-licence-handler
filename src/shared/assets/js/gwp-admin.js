/**
 * GravityWP Admin JavaScript
 *
 * Handles tab switching, license key validation feedback, and other
 * interactive elements on the GravityWP Settings and Hub pages.
 *
 * @package gravitywp-license-handler
 * @since   2.1.0
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initTabs();
		initTabLinks();
		initKeyValidation();
		initRefreshButton();
		initHubActions();
	} );

	/**
	 * Initialize cross-tab navigation links.
	 *
	 * Buttons with data-gwp-tab-link="<tab-key>" inside any tab panel
	 * will switch to that tab when clicked.
	 */
	function initTabLinks() {
		var links = document.querySelectorAll( '[data-gwp-tab-link]' );
		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var targetKey = link.getAttribute( 'data-gwp-tab-link' );
				var tab = document.querySelector(
					'.gwp-tab[data-gwp-tab="' + targetKey + '"]'
				);
				if ( tab ) {
					tab.click();
				}
			} );
		} );
	}

	/**
	 * Initialize the tabbed interface.
	 *
	 * Looks for .gwp-tab elements with data-gwp-tab attributes and
	 * toggles corresponding .gwp-tab-panel elements with matching data-gwp-panel.
	 * Remembers the active tab in sessionStorage.
	 */
	function initTabs() {
		var tabs = document.querySelectorAll( '.gwp-tab' );
		var panels = document.querySelectorAll( '.gwp-tab-panel' );

		if ( ! tabs.length ) {
			return;
		}

		// Restore active tab from session storage or URL hash.
		var activeTab = sessionStorage.getItem( 'gwpActiveTab' );
		if ( window.location.hash ) {
			activeTab = window.location.hash.substring( 1 );
		}

		if ( activeTab ) {
			var targetTab = document.querySelector(
				'.gwp-tab[data-gwp-tab="' + activeTab + '"]'
			);
			if ( targetTab ) {
				setActiveTab( targetTab, tabs, panels );
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setActiveTab( tab, tabs, panels );

				var tabKey = tab.getAttribute( 'data-gwp-tab' );
				if ( tabKey ) {
					sessionStorage.setItem( 'gwpActiveTab', tabKey );
					// Update URL hash without scrolling.
					if ( history.replaceState ) {
						history.replaceState( null, '', '#' + tabKey );
					}
				}
			} );
		} );
	}

	/**
	 * Set a tab as active and show its panel.
	 *
	 * @param {Element} tab    The tab element to activate.
	 * @param {NodeList} tabs   All tab elements.
	 * @param {NodeList} panels All panel elements.
	 */
	function setActiveTab( tab, tabs, panels ) {
		var tabKey = tab.getAttribute( 'data-gwp-tab' );
		if ( ! tabKey ) {
			return;
		}

		tabs.forEach( function ( t ) {
			t.classList.remove( 'is-active' );
		} );
		panels.forEach( function ( p ) {
			p.classList.remove( 'is-active' );
		} );

		tab.classList.add( 'is-active' );
		var panel = document.querySelector(
			'.gwp-tab-panel[data-gwp-panel="' + tabKey + '"]'
		);
		if ( panel ) {
			panel.classList.add( 'is-active' );
		}
	}

	/**
	 * Initialize license key validation visual feedback.
	 *
	 * Adds is-valid/is-invalid classes based on basic format checks
	 * (UUID format). This is purely a visual hint — actual validation
	 * happens server-side.
	 */
	function initKeyValidation() {
		var inputs = document.querySelectorAll( '.gwp-input[data-gwp-validate="license-key"]' );
		if ( ! inputs.length ) {
			return;
		}

		// Basic UUID pattern (loose — accepts any string 20+ chars with dashes/alphanum).
		var pattern = /^[a-f0-9-]{20,}$/i;

		inputs.forEach( function ( input ) {
			// Initial check — honors any server-stamped force-state.
			updateValidity( input, pattern );

			// Remember the value the server marked as invalid. As long as
			// the user is still looking at THAT exact value, we stay locked
			// to invalid. The moment they type anything different, they're
			// trying to fix it, so we release the lock and let the shape
			// check take over.
			var serverInvalidValue = 'invalid' === input.dataset.gwpForceState ? input.value : null;

			input.addEventListener( 'input', function () {
				if ( null !== serverInvalidValue && input.value !== serverInvalidValue ) {
					delete input.dataset.gwpForceState;
					serverInvalidValue = null;
				}
				updateValidity( input, pattern );
			} );

			input.addEventListener( 'blur', function () {
				updateValidity( input, pattern );
			} );
		} );
	}

	/**
	 * Update the validity class on an input.
	 *
	 * @param {HTMLInputElement} input   The input element.
	 * @param {RegExp}           pattern The validation pattern.
	 */
	function updateValidity( input, pattern ) {
		// Server-side override: when PHP knows this row is invalid (wrong-
		// plugin key, expired, etc.) it stamps data-gwp-force-state="invalid"
		// on the input. The shape-only UUID check below would otherwise
		// re-apply .is-valid on every keystroke, flashing the input green
		// over a server-validated bad value. Lock it to invalid instead.
		if ( 'invalid' === input.dataset.gwpForceState ) {
			input.classList.remove( 'is-valid' );
			input.classList.add( 'is-invalid' );
			return;
		}

		var value = input.value.trim();
		input.classList.remove( 'is-valid', 'is-invalid' );

		if ( ! value ) {
			return; // Empty — no state.
		}

		if ( pattern.test( value ) ) {
			input.classList.add( 'is-valid' );
		} else {
			input.classList.add( 'is-invalid' );
		}
	}

	/**
	 * Initialize Hub action buttons (Install / Activate / Deactivate).
	 *
	 * Uses event delegation so dynamically replaced footers keep working
	 * after an AJAX swap.
	 */
	function initHubActions() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.gwp-hub-action' );
			if ( ! btn || btn.disabled ) {
				return;
			}
			e.preventDefault();
			runHubAction( btn );
		} );
	}

	/**
	 * Execute one Hub action (Install / Activate / Deactivate) via admin-ajax.
	 *
	 * @param {HTMLButtonElement} btn The clicked action button.
	 */
	function runHubAction( btn ) {
		if ( ! window.gwpHub || ! window.gwpHub.ajaxUrl ) {
			return;
		}

		var action = btn.dataset.action; // install | activate | deactivate | delete
		var labels = ( window.gwpHub && gwpHub.i18n ) || {};

		// Destructive action — confirm before sending.
		if ( action === 'delete' ) {
			var confirmMsg = labels.confirmDelete || 'Delete this plugin? This cannot be undone.';
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}
		}

		var busyText;
		if ( action === 'install' ) {
			busyText = labels.installing;
		} else if ( action === 'update' ) {
			busyText = labels.updating;
		} else if ( action === 'activate' ) {
			busyText = labels.activating;
		} else if ( action === 'delete' ) {
			busyText = labels.deleting;
		} else {
			busyText = labels.deactivating;
		}

		var footer = btn.closest( '.gwp-plugin-card__footer' );
		var status = footer ? footer.querySelector( '.gwp-hub-action-status' ) : null;

		btn.disabled = true;
		btn.classList.add( 'is-loading' );
		if ( status ) {
			status.textContent = busyText || '';
			status.className   = 'gwp-hub-action-status is-busy';
		}

		var body = new URLSearchParams();
		body.append( 'action', 'gwp_hub_' + action );
		body.append( 'nonce', btn.dataset.nonce || gwpHub.nonce || '' );
		body.append( 'slug', btn.dataset.slug || '' );
		if ( btn.dataset.pluginFile ) {
			body.append( 'plugin_file', btn.dataset.pluginFile );
		}
		if ( btn.dataset.package ) {
			body.append( 'package', btn.dataset.package );
		}

		fetch( gwpHub.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' },
			body: body
		} )
			.then( function ( res ) {
				return res.json().catch( function () {
					throw new Error( labels.genericError || 'Error' );
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var msg = ( payload && payload.data && payload.data.message ) || labels.genericError || 'Error';
					throw new Error( msg );
				}
				if ( footer && payload.data && payload.data.footer_html ) {
					footer.innerHTML = payload.data.footer_html;
				}
			} )
			.catch( function ( err ) {
				btn.disabled = false;
				btn.classList.remove( 'is-loading' );
				if ( status ) {
					status.textContent = err && err.message ? err.message : ( labels.genericError || 'Error' );
					status.className   = 'gwp-hub-action-status is-error';
				}
			} );
	}

	/**
	 * Initialize the refresh button (prevents double-clicks).
	 */
	function initRefreshButton() {
		var refreshButtons = document.querySelectorAll( '.gwp-refresh-link, [data-gwp-refresh]' );
		if ( ! refreshButtons.length ) {
			return;
		}

		refreshButtons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				// Add loading state.
				btn.classList.add( 'is-loading' );
				btn.style.pointerEvents = 'none';
				btn.style.opacity = '0.6';

				// Reset after page reload would normally happen anyway,
				// but in case of anchor navigation:
				setTimeout( function () {
					btn.style.pointerEvents = '';
					btn.style.opacity = '';
					btn.classList.remove( 'is-loading' );
				}, 5000 );
			} );
		} );
	}
} )();
