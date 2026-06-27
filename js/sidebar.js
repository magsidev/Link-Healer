/**
 * Link Healer Gutenberg Sidebar Integration Panel
 */
( function( wp ) {
	'use strict';

	// Destructure required WordPress packages from the global window.wp object
	var registerPlugin   = wp.plugins.registerPlugin;
	var PluginSidebar    = wp.editPost.PluginSidebar;
	var el               = wp.element.createElement;
	var PanelBody        = wp.components.PanelBody;
	var Button           = wp.components.Button;
	var TextControl      = wp.components.TextControl;
	var Spinner          = wp.components.Spinner;
	var useState         = wp.element.useState;
	var useEffect        = wp.element.useEffect;
	var apiFetch         = wp.apiFetch;
	var select           = wp.data.select;
	var dispatch         = wp.data.dispatch;

	/**
	 * Helper function to escape special characters for regular expression search.
	 */
	function escapeRegExp( string ) {
		return string.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	/**
	 * Main Sidebar Component.
	 */
	function LinkHealerSidebar() {
		var [ links, setLinks ] = useState( [] );
		var [ loading, setLoading ] = useState( true );
		var [ swappedIds, setSwappedIds ] = useState( [] );
		var [ suggestionValues, setSuggestionValues ] = useState( {} );

		// Fetch broken links on load
		useEffect( function() {
			var postId = select( 'core/editor' ).getCurrentPostId();
			if ( ! postId ) {
				setLoading( false);
				return;
			}

			apiFetch( { path: '/link-healer/v1/post-links/' + postId } )
				.then( function( response ) {
					setLinks( response || [] );
					setLoading( false );
				} )
				.catch( function() {
					setLoading( false );
				} );
		}, [] );

		/**
		 * Handles replacing target broken URLs inside active editor blocks.
		 *
		 * @param {Object} link The link object from REST.
		 */
		function handleSwap( link ) {
			var newUrl = suggestionValues[ link.id ] !== undefined ? suggestionValues[ link.id ] : link.suggested_fix;

			if ( ! newUrl ) {
				dispatch( 'core/notices' ).createErrorNotice( 'Please enter a valid target suggestion URL.' );
				return;
			}

			// Get all active blocks in the Gutenberg canvas
			var blocks = select( 'core/block-editor' ).getBlocks();
			var totalSwapped = 0;

			/**
			 * Recursively searches and updates attributes in all blocks.
			 *
			 * @param {Array} blockList List of Gutenberg blocks.
			 */
			function scanAndReplace( blockList ) {
				blockList.forEach( function( block ) {
					var attributesChanged = false;
					var newAttributes = {};

					// Inspect each attribute in the block structure
					for ( var key in block.attributes ) {
						if ( typeof block.attributes[ key ] === 'string' ) {
							var attributeValue = block.attributes[ key ];

							// Match 1: Exact matches (commonly button URLs, link block settings)
							if ( attributeValue === link.raw_url ) {
								newAttributes[ key ] = newUrl;
								attributesChanged = true;
								totalSwapped++;
							}
							// Match 2: Inline HTML hyperlink anchor tags inside contents (commonly Paragraph or List blocks)
							else if (
								attributeValue.indexOf( 'href="' + link.raw_url + '"' ) !== -1 ||
								attributeValue.indexOf( "href='" + link.raw_url + "'" ) !== -1
							) {
								var escapedRaw = escapeRegExp( link.raw_url );
								var regexDouble = new RegExp( 'href="' + escapedRaw + '"', 'g' );
								var regexSingle = new RegExp( "href='" + escapedRaw + "'", 'g' );

								newAttributes[ key ] = attributeValue
									.replace( regexDouble, 'href="' + newUrl + '"' )
									.replace( regexSingle, "href='" + newUrl + "'" );

								attributesChanged = true;
								totalSwapped++;
							}
						}
					}

					// Dispatch the attribute change to block-editor store to trigger dynamic re-render
					if ( attributesChanged ) {
						dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, newAttributes );
					}

					// Scan inner nested blocks recursively
					if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
						scanAndReplace( block.innerBlocks );
					}
				} );
			}

			scanAndReplace( blocks );

			if ( totalSwapped > 0 ) {
				// Mark as swapped locally
				setSwappedIds( function( prev ) {
					return [].concat( prev, [ link.id ] );
				} );

				dispatch( 'core/notices' ).createSuccessNotice(
					'Successfully swapped ' + totalSwapped + ' occurrence(s) on the block editor canvas! Save the page to finalize the fix.'
				);
			} else {
				dispatch( 'core/notices' ).createWarningNotice(
					'Could not locate any active block containing the URL: "' + link.raw_url + '" on the editor canvas.'
				);
			}
		}

		// Loading indicator layout
		if ( loading ) {
			return el(
				PluginSidebar,
				{
					name: 'link-healer-sidebar',
					title: 'Link Healer',
					icon: 'admin-links',
				},
				el(
					PanelBody,
					{ title: 'Analyzing Page...', initialOpen: true },
					el(
						'div',
						{ style: { display: 'flex', justifyContent: 'center', padding: '30px 0' } },
						el( Spinner )
					)
				)
			);
		}

		return el(
			PluginSidebar,
			{
				name: 'link-healer-sidebar',
				title: 'Link Healer',
				icon: 'admin-links',
			},
			el(
				PanelBody,
				{ title: 'Broken Links Audit', initialOpen: true },
				links.length === 0
					? el(
						'div',
						{ style: { padding: '15px 0', textAlign: 'center', color: '#64748b' } },
						el( 'span', { style: { fontSize: '24px', display: 'block', marginBottom: '10px' } }, '🎉' ),
						el( 'p', { style: { margin: 0, fontWeight: '500' } }, 'No broken links found on this page.' )
					)
					: links.map( function( link ) {
						var isSwapped = swappedIds.indexOf( link.id ) !== -1;
						var currentSuggestion = suggestionValues[ link.id ] !== undefined ? suggestionValues[ link.id ] : link.suggested_fix;

						return el(
							'div',
							{
								key: link.id,
								style: {
									border: '1px solid #e2e8f0',
									borderRadius: '8px',
									padding: '12px',
									marginBottom: '12px',
									backgroundColor: '#ffffff',
								},
							},
							el(
								'div',
								{ style: { marginBottom: '8px' } },
								el( 'strong', { style: { display: 'block', fontSize: '12px', color: '#334155' } }, 'Anchor Text:' ),
								el( 'span', { style: { fontSize: '13px', color: '#1e293b' } }, link.anchor_text || '(no text)' )
							),
							el(
								'div',
								{ style: { marginBottom: '12px' } },
								el( 'strong', { style: { display: 'block', fontSize: '12px', color: '#334155' } }, 'Broken Link:' ),
								el(
									'code',
									{
										style: {
											fontSize: '11px',
											wordBreak: 'break-all',
											backgroundColor: '#f8fafc',
											padding: '2px 4px',
											borderRadius: '4px',
											display: 'inline-block',
											marginTop: '4px',
										},
									},
									link.raw_url
								)
							),
							el(
								'div',
								{ style: { marginBottom: '12px' } },
								el( TextControl, {
									label: 'Suggested Fix URL',
									value: currentSuggestion,
									disabled: isSwapped,
									onChange: function( value ) {
										setSuggestionValues( function( prev ) {
											var next = Object.assign( {}, prev );
											next[ link.id ] = value;
											return next;
										} );
									},
								} )
							),
							el(
								'div',
								{ style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
								isSwapped
									? el(
										'span',
										{
											style: {
												fontSize: '11px',
												fontWeight: 'bold',
												color: '#047857',
												backgroundColor: '#d1fae5',
												padding: '4px 8px',
												borderRadius: '12px',
												textTransform: 'uppercase',
											},
										},
										'Swapped'
									)
									: el(
										Button,
										{
											isPrimary: true,
											onClick: function() {
												handleSwap( link );
											},
										},
										'Swap Link'
									)
							)
						);
					} )
			)
		);
	}

	// Register the custom editor plugin
	registerPlugin( 'link-healer-sidebar-plugin', {
		render: LinkHealerSidebar,
	} );
} )( window.wp );
