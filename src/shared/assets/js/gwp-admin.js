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
		initKeyValidation();
		initRefreshButton();
	} );

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
			// Initial check.
			updateValidity( input, pattern );

			input.addEventListener( 'input', function () {
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
