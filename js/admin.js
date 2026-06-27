/**
 * Link Healer Admin JavaScript Actions
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// State tracking variables for cascading crawl loop
		let isCrawling = false;
		let crawlFailureCount = 0;
		let totalPendingAtStart = 0;

		// Unified Toast Notification Helper
		function showToast(message, type) {
			const toast = $('#link-healer-toast');
			toast.removeClass('success error show').addClass(type);
			toast.find('.toast-message').text(message);
			toast.addClass('show');

			// Hide after 4 seconds
			setTimeout(function() {
				toast.removeClass('show');
			}, 4000);
		}

		// Helper to dynamically update Metrics and Table content without page reloads
		function updateDashboardUI(data) {
			if (data && data.kpis) {
				$('#link-healer-count-scanned').text(data.kpis.scanned);
				$('#link-healer-count-broken-int').text(data.kpis.broken_int);
				$('#link-healer-count-broken-ext').text(data.kpis.broken_ext);
				$('#link-healer-count-healed').text(data.kpis.healed);
			}
			if (data && data.table_html) {
				$('#link-healer-table-container').html(data.table_html);
			}
		}

		// Tab Navigation Controller
		$('.link-healer-tab-trigger').on('click', function(e) {
			e.preventDefault();
			const targetTab = $(this).data('tab');

			// Toggle active tab trigger class
			$('.link-healer-tab-trigger').removeClass('active');
			$(this).addClass('active');

			// Toggle active tab content pane
			$('.link-healer-tab-content').removeClass('active');
			$('#tab-' + targetTab).addClass('active');

			// Persist active tab in URL hash
			window.location.hash = targetTab;
		});

		// Activate initial tab from URL hash if present
		const hash = window.location.hash.substring(1);
		if (hash && $('.link-healer-tab-trigger[data-tab="' + hash + '"]').length) {
			$('.link-healer-tab-trigger[data-tab="' + hash + '"]').trigger('click');
		}

		// Checkbox Toggle: Select All Rows (Using delegation to support dynamic reload)
		$(document).on('change', '#link-healer-select-all', function() {
			const checked = $(this).prop('checked');
			$('.link-row-cb').prop('checked', checked);
		});

		// Individual Link Healing (AJAX with delegation)
		$(document).on('click', '.link-healer-heal-btn', function(e) {
			e.preventDefault();
			const button = $(this);
			const linkId = button.data('link-id');
			const row = button.closest('tr');
			const newUrl = row.find('.link-healer-suggestion-input').val();

			if (!newUrl) {
				showToast(linkHealerAdmin.i18n.empty_suggestion, 'error');
				return;
			}

			// Show loading status inside button
			button.prop('disabled', true).html('<span class="link-healer-spinner"></span>');

			$.post(ajaxurl, {
				action: 'link_healer_heal_link',
				link_id: linkId,
				new_url: newUrl,
				nonce: linkHealerAdmin.nonce
			}, function(response) {
				if (response.success) {
					showToast(response.data.message, 'success');
					// Seamless dynamic update of counters and table records
					updateDashboardUI(response.data);
				} else {
					button.prop('disabled', false).text(linkHealerAdmin.i18n.heal);
					showToast(response.data.message || 'Error occurred.', 'error');
				}
			}).fail(function() {
				button.prop('disabled', false).text(linkHealerAdmin.i18n.heal);
				showToast('Server network error. Please try again.', 'error');
			});
		});

		// Bulk Action Healing Handler (Using delegation)
		$(document).on('click', '#link-healer-bulk-apply', function(e) {
			e.preventDefault();
			const action = $('#link-healer-bulk-action').val();
			if (action !== 'heal') {
				return;
			}

			const checkedBoxes = $('.link-row-cb:checked');
			if (checkedBoxes.length === 0) {
				showToast(linkHealerAdmin.i18n.no_selection, 'error');
				return;
			}

			const ids = [];
			checkedBoxes.each(function() {
				ids.push($(this).val());
			});

			// Disable buttons during process
			$('#link-healer-bulk-apply').prop('disabled', true);
			showToast('Starting bulk healing of ' + ids.length + ' links...', 'success');

			// Sequentially process the queue
			processBulkQueue(ids, 0, 0);
		});

		// Queue Runner: Processes items sequentially to avoid server/HTTP timeout
		function processBulkQueue(ids, processedCount, successCount) {
			if (ids.length === 0) {
				showToast('Bulk healing completed. ' + successCount + ' links resolved.', 'success');
				$('#link-healer-bulk-apply').prop('disabled', false);

				// Perform one final ajax call to sync the fully updated table state
				$.post(ajaxurl, {
					action: 'link_healer_get_status',
					nonce: linkHealerAdmin.nonce
				}, function() {
					location.reload(); // Simple fallback if bulk needs full page pagination recalculation
				});
				return;
			}

			const currentId = ids.shift();
			const row = $('#link-row-' + currentId);
			const newUrl = row.find('.link-healer-suggestion-input').val();
			const button = row.find('.link-healer-heal-btn');

			row.css('opacity', '0.5');

			$.post(ajaxurl, {
				action: 'link_healer_heal_link',
				link_id: currentId,
				new_url: newUrl,
				nonce: linkHealerAdmin.nonce
			}, function(response) {
				row.css('opacity', '1');
				if (response.success) {
					row.find('.link-healer-badge')
						.removeClass('status-broken status-unchecked')
						.addClass('status-fixed')
						.text('fixed');
					row.find('.link-healer-suggestion-input').prop('disabled', true);
					button.remove();

					// Sync KPIs incrementally or completely
					if (response.data && response.data.kpis) {
						$('#link-healer-count-scanned').text(response.data.kpis.scanned);
						$('#link-healer-count-broken-int').text(response.data.kpis.broken_int);
						$('#link-healer-count-broken-ext').text(response.data.kpis.broken_ext);
						$('#link-healer-count-healed').text(response.data.kpis.healed);
					}

					processBulkQueue(ids, processedCount + 1, successCount + 1);
				} else {
					row.css('background-color', '#fee2e2');
					processBulkQueue(ids, processedCount + 1, successCount);
				}
			}).fail(function() {
				row.css('opacity', '1');
				row.css('background-color', '#fee2e2');
				processBulkQueue(ids, processedCount + 1, successCount);
			});
		}

		// Trigger Discovery Manually (Asynchronous)
		$(document).on('click', '#link-healer-trigger-discovery', function(e) {
			e.preventDefault();
			const button = $(this);
			button.prop('disabled', true).append(' <span class="link-healer-spinner"></span>');

			$.post(ajaxurl, {
				action: 'link_healer_trigger_discovery',
				nonce: linkHealerAdmin.nonce
			}, function(response) {
				button.prop('disabled', false).find('.link-healer-spinner').remove();
				if (response.success) {
					showToast(response.data.message, 'success');
					updateDashboardUI(response.data);

					// Automatically initiate recursive crawl loop if source URLs exist
					if (response.data.remaining_pending > 0) {
						setTimeout(startCrawlLoop, 800);
					}
				} else {
					showToast(response.data.message, 'error');
				}
			}).fail(function() {
				button.prop('disabled', false).find('.link-healer-spinner').remove();
				showToast('Discovery execution failed.', 'error');
			});
		});

		// Recursive AJAX Cascading Crawl Loop
		function executeCrawlLoop() {
			if (!isCrawling) {
				return;
			}

			$.post(ajaxurl, {
				action: 'link_healer_trigger_crawl',
				nonce: linkHealerAdmin.nonce
			}, function(response) {
				if (!isCrawling) {
					return;
				}

				if (response.success) {
					const data = response.data;

					// Initialize starting pending count on first loop iteration
					if (totalPendingAtStart === 0) {
						totalPendingAtStart = data.remaining_pending + data.processed_in_batch;
					}

					// Safeguard: repeated 0 item processes triggers backup break
					if (data.processed_in_batch === 0 && data.remaining_pending > 0) {
						crawlFailureCount++;
						if (crawlFailureCount >= 3) {
							showToast('Crawl loop paused to avoid server execution lock.', 'error');
							stopCrawlLoop();
							updateDashboardUI(data);
							return;
						}
					} else {
						crawlFailureCount = 0; // Reset safety tracking
					}

					// Update DOM widgets
					updateDashboardUI(data);

					if (data.remaining_pending > 0) {
						// Calculate Phase-based progress to guarantee forward progression
						let pct = 0;
						let progressLabel = 'Crawling queue...';

						const pendingSources = parseInt(data.pending_sources, 10) || 0;
						const completedSources = parseInt(data.completed_sources, 10) || 0;
						const totalSources = parseInt(data.total_sources, 10) || 0;

						const uncheckedLinks = parseInt(data.unchecked_links, 10) || 0;
						const totalLinks = parseInt(data.total_links, 10) || 0;

						if (pendingSources > 0) {
							// Phase 1: Source Crawling (0% to 50%)
							const sourcePct = totalSources > 0 ? (completedSources / totalSources) : 0;
							pct = Math.round(sourcePct * 50);
							progressLabel = 'Crawling pages: ' + completedSources + ' / ' + totalSources;
						} else {
							// Phase 2: Link Verifying (50% to 100%)
							const checkedLinks = totalLinks - uncheckedLinks;
							const linkPct = totalLinks > 0 ? (checkedLinks / totalLinks) : 0;
							pct = Math.round(50 + (linkPct * 50));
							progressLabel = 'Verifying links: ' + checkedLinks + ' / ' + totalLinks;
						}

						pct = Math.max(0, Math.min(100, pct));

						// Update Progress bar details
						$('#link-healer-progress-label').text(progressLabel);
						$('#link-healer-progress-percentage').text(pct + '%');
						$('#link-healer-progress-bar').css('width', pct + '%');
						$('#link-healer-progress-details').text('Remaining sources: ' + pendingSources + ' | Unchecked links: ' + uncheckedLinks);

						// Trigger next recursive iteration
						setTimeout(executeCrawlLoop, 200);
					} else {
						// Final completion
						$('#link-healer-progress-label').text('Scan Complete!');
						$('#link-healer-progress-percentage').text('100%');
						$('#link-healer-progress-bar').css('width', '100%');
						$('#link-healer-progress-details').text('All links and pages are fully verified.');
						showToast('Auditing completed! 100% of links checked.', 'success');
						stopCrawlLoop();
					}
				} else {
					crawlFailureCount++;
					if (crawlFailureCount >= 3) {
						showToast(response.data.message || 'Crawl failed due to repeated server exceptions.', 'error');
						stopCrawlLoop();
					} else {
						setTimeout(executeCrawlLoop, 1000);
					}
				}
			}).fail(function() {
				if (!isCrawling) {
					return;
				}
				crawlFailureCount++;
				if (crawlFailureCount >= 3) {
					showToast('Crawl aborted due to persistent network connection timeouts.', 'error');
					stopCrawlLoop();
				} else {
					setTimeout(executeCrawlLoop, 2000);
				}
			});
		}

		function startCrawlLoop() {
			isCrawling = true;
			crawlFailureCount = 0;
			totalPendingAtStart = 0;

			// Show container progress bar
			$('#link-healer-progress-container').show();
			$('#link-healer-progress-label').text('Starting scanner...');
			$('#link-healer-progress-percentage').text('0%');
			$('#link-healer-progress-bar').css('width', '0%');
			
			// Toggle buttons
			$('#link-healer-trigger-crawl').hide();
			$('#link-healer-trigger-discovery').prop('disabled', true);
			$('#link-healer-reset-data').prop('disabled', true);
			$('#link-healer-cancel-crawl').show();

			executeCrawlLoop();
		}

		function stopCrawlLoop() {
			isCrawling = false;

			// Reset buttons
			$('#link-healer-cancel-crawl').hide();
			$('#link-healer-trigger-crawl').show().prop('disabled', false);
			$('#link-healer-trigger-discovery').prop('disabled', false);
			$('#link-healer-reset-data').prop('disabled', false);

			// Clean progress fade out
			setTimeout(function() {
				if (!isCrawling) {
					$('#link-healer-progress-container').fadeOut();
				}
			}, 4000);
		}

		// Trigger Crawl Click Controller
		$(document).on('click', '#link-healer-trigger-crawl', function(e) {
			e.preventDefault();
			startCrawlLoop();
		});

		// Cancel Crawl Click Controller
		$(document).on('click', '#link-healer-cancel-crawl', function(e) {
			e.preventDefault();
			showToast('Auditing paused by user.', 'info');
			stopCrawlLoop();
		});

		// Trigger Data Reset Manually (Asynchronous)
		$(document).on('click', '#link-healer-reset-data', function(e) {
			e.preventDefault();
			if (!confirm(linkHealerAdmin.i18n.confirm_reset)) {
				return;
			}

			const button = $(this);
			button.prop('disabled', true).append(' <span class="link-healer-spinner"></span>');

			$.post(ajaxurl, {
				action: 'link_healer_reset_data',
				nonce: linkHealerAdmin.nonce
			}, function(response) {
				button.prop('disabled', false).find('.link-healer-spinner').remove();
				if (response.success) {
					showToast(response.data.message, 'success');
					updateDashboardUI(response.data);
				} else {
					showToast(response.data.message, 'error');
				}
			}).fail(function() {
				button.prop('disabled', false).find('.link-healer-spinner').remove();
				showToast('Data reset failed.', 'error');
			});
		});
	});
})(jQuery);
