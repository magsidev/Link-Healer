/**
 * Link Healer Admin JavaScript Actions
 */
(function($) {
	'use strict';

	$(document).ready(function() {
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
					// We refresh using the status logic or reload to get clean state
					// But we want to avoid reloads entirely:
					// We can trigger an admin-ajax reload query
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
				} else {
					showToast(response.data.message, 'error');
				}
			}).fail(function() {
				button.prop('disabled', false).find('.link-healer-spinner').remove();
				showToast('Discovery execution failed.', 'error');
			});
		});

		// Trigger Crawl Manually (Asynchronous)
		$(document).on('click', '#link-healer-trigger-crawl', function(e) {
			e.preventDefault();
			const button = $(this);
			button.prop('disabled', true).append(' <span class="link-healer-spinner"></span>');

			$.post(ajaxurl, {
				action: 'link_healer_trigger_crawl',
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
				showToast('Crawl execution failed.', 'error');
			});
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
