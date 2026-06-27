jQuery(document).ready(function($) {
	'use strict';

	// DOM Elements
	const scanBtn = document.getElementById('link-healer-trigger-discovery');
	const crawlBtn = document.getElementById('link-healer-trigger-crawl');
	const resetBtn = document.getElementById('link-healer-reset-data');
	const consoleContainer = document.querySelector('.link-healer-console');

	// Tab switcher logic
	$('.link-healer-tab-trigger').on('click', function(e) {
		e.preventDefault();
		var tabId = $(this).data('tab');
		$('.link-healer-tab-trigger').removeClass('active');
		$(this).addClass('active');
		$('.link-healer-tab-content').removeClass('active');
		$('#tab-' + tabId).addClass('active');
	});

	// Single click heal link logic (lh_heal_link)
	$(document).on('click', '.heal-single-btn', function(e) {
		e.preventDefault();
		const button = $(this);
		const linkId = button.data('id');
		const row = button.closest('tr');
		const newUrl = row.find('.suggested-fix-input').val();

		if (!newUrl) {
			alert(linkHealerAdmin.i18n.empty_suggestion || 'Please enter a valid suggestion URL.');
			return;
		}

		button.prop('disabled', true).text('Healing...');

		$.post(ajaxurl, {
			action: 'lh_heal_link',
			link_id: linkId,
			new_url: newUrl,
			nonce: linkHealerAdmin.nonce
		}, function(response) {
			if (response.success) {
				showToast(response.data.message || 'Healed successfully.');
				updateKPICards(response.data.metrics);
				reloadAuditTable();
			} else {
				button.prop('disabled', false).text('Heal Link');
				alert(response.data.message || 'Failed to heal link.');
			}
		}).fail(function() {
			button.prop('disabled', false).text('Heal Link');
			alert('Connection error.');
		});
	});

	// Bulk actions logic
	$('#link-healer-bulk-apply').on('click', function(e) {
		e.preventDefault();
		var action = $('#link-healer-bulk-action').val();
		if (action !== 'heal') {
			return;
		}

		var checkedItems = $('input[name="bulk_items[]"]:checked');
		if (checkedItems.length === 0) {
			alert(linkHealerAdmin.i18n.no_selection || 'Please check one or more rows.');
			return;
		}

		if (!confirm('Are you sure you want to heal all selected links using their smart suggestions?')) {
			return;
		}

		var count = checkedItems.length;
		var processed = 0;

		checkedItems.each(function() {
			var row = $(this).closest('tr');
			var linkId = $(this).val();
			var newUrl = row.find('.suggested-fix-input').val();
			var btn = row.find('.heal-single-btn');

			if (newUrl) {
				btn.prop('disabled', true).text('Healing...');
				$.post(ajaxurl, {
					action: 'lh_heal_link',
					link_id: linkId,
					new_url: newUrl,
					nonce: linkHealerAdmin.nonce
				}, function(response) {
					processed++;
					if (response.success && processed === count) {
						showToast('Bulk healing completed.');
						updateKPICards(response.data.metrics);
						reloadAuditTable();
					}
				});
			} else {
				processed++;
			}
		});
	});

	// Select all checkboxes toggle
	$('#link-healer-select-all').on('change', function() {
		$('input[name="bulk_items[]"]').prop('checked', this.checked);
	});

	// Toast Notification helper
	function showToast(message) {
		var toast = $('#link-healer-toast');
		toast.find('.toast-message').text(message);
		toast.addClass('show');
		setTimeout(function() {
			toast.removeClass('show');
		}, 4000);
	}

	// REDUNDANCY PATCH: Hide the confusing second button if it exists in the markup
	if (crawlBtn) {
		crawlBtn.style.display = 'none'; 
	}

	// Rename the main button to reflect a true professional unified experience
	if (scanBtn) {
		scanBtn.innerHTML = '<i class="fa-solid fa-play"></i> Start Website Audit';
		scanBtn.style.background = 'linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%)';
		scanBtn.style.color = '#ffffff';
		scanBtn.style.border = 'none';
	}

	// Create and inject a professional, real-time progress bar container if it doesn't exist
	let progressContainer = document.getElementById('lh-progress-container');
	if (!progressContainer) {
		progressContainer = document.createElement('div');
		progressContainer.id = 'lh-progress-container';
		progressContainer.style.display = 'none';
		progressContainer.style.marginTop = '20px';
		progressContainer.style.padding = '20px';
		progressContainer.style.background = '#1e293b';
		progressContainer.style.borderRadius = '8px';
		progressContainer.style.border = '1px solid #334155';

		progressContainer.innerHTML = `
			<div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: #f8fafc; font-weight: 600; font-size: 14px;">
				<span id="lh-progress-status">Initializing Audit...</span>
				<span id="lh-progress-percentage">0%</span>
			</div>
			<div style="width: 100%; background: #475569; height: 12px; border-radius: 6px; overflow: hidden; position: relative;">
				<div id="lh-progress-bar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #00f5a0 0%, #00b4d8 100%); transition: width 0.3s ease;"></div>
			</div>
			<div style="display: flex; justify-content: space-between; margin-top: 10px;">
				<span id="lh-progress-counts" style="color: #94a3b8; font-size: 12px;">Pre-scan calculation...</span>
				<button id="lh-cancel-crawl" style="background: #ef4444; color: white; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600;">Cancel Scan</button>
			</div>
		`;

		if (consoleContainer) {
			consoleContainer.appendChild(progressContainer);
		}
	}

	let isCrawlCancelled = false;
	let consecutiveFailures = 0;

	// Helper: Update Dashboard Counter Cards dynamically without reloading
	function updateKPICards(data) {
		if (!data) return;
		const totalScanned = document.querySelector('#scanned-count') || document.querySelector('.kpi-card:nth-child(1) .kpi-value');
		const brokenInternal = document.querySelector('#internal-count') || document.querySelector('.kpi-card:nth-child(2) .kpi-value');
		const brokenExternal = document.querySelector('#external-count') || document.querySelector('.kpi-card:nth-child(3) .kpi-value');
		const totalHealed = document.querySelector('#healed-count') || document.querySelector('.kpi-card:nth-child(4) .kpi-value');

		if (totalScanned && data.total_scanned !== undefined) totalScanned.textContent = data.total_scanned;
		if (brokenInternal && data.broken_internal !== undefined) brokenInternal.textContent = data.broken_internal;
		if (brokenExternal && data.broken_external !== undefined) brokenExternal.textContent = data.broken_external;
		if (totalHealed && data.total_healed !== undefined) totalHealed.textContent = data.total_healed;
	}

	// Helper: Dynamic Dashboard Table reloader (AJAX)
	function reloadAuditTable() {
		jQuery.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'lh_refresh_audit_table', nonce: linkHealerAdmin.nonce },
			success: function (response) {
				if (response.success && response.data.html) {
					const tableBody = document.querySelector('.wp-list-table tbody') || document.querySelector('#lh-audit-table-body');
					if (tableBody) {
						tableBody.innerHTML = response.data.html;
					}
				}
			}
		});
	}

	// 1. UNIFIED SINGLE-CLICK START SCANNER
	if (scanBtn) {
		scanBtn.addEventListener('click', function (e) {
			e.preventDefault();

			// UI Initialization
			scanBtn.disabled = true;
			scanBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Running Audit...';

			isCrawlCancelled = false;
			consecutiveFailures = 0;

			progressContainer.style.display = 'block';
			document.getElementById('lh-progress-status').textContent = 'Step 1 of 2: Discovering Pages and Links...';
			document.getElementById('lh-progress-bar').style.width = '10%';
			document.getElementById('lh-progress-percentage').textContent = '10%';

			// Trigger Step A: Database Discovery Loop
			jQuery.ajax({
				url: ajaxurl,
				type: 'POST',
				data: { action: 'lh_discover_urls', nonce: linkHealerAdmin.nonce },
				success: function (response) {
					if (response.success) {
						// Immediately trigger Step B: Initialize Background Queue statistics
						document.getElementById('lh-progress-status').textContent = 'Step 2 of 2: Organizing URL audit queue...';
						document.getElementById('lh-progress-bar').style.width = '20%';
						document.getElementById('lh-progress-percentage').textContent = '20%';

						jQuery.ajax({
							url: ajaxurl,
							type: 'POST',
							data: { action: 'lh_get_crawl_stats', nonce: linkHealerAdmin.nonce },
							success: function(statsResponse) {
								if (statsResponse.success) {
									const totalPending = statsResponse.data.total_pending;
									if (totalPending === 0) {
										document.getElementById('lh-progress-status').textContent = 'Audit complete! All links are healthy.';
										document.getElementById('lh-progress-bar').style.width = '100%';
										document.getElementById('lh-progress-percentage').textContent = '100%';
										resetButtons();
										return;
									}
									// Cascade directly into Step C: Auto-run the batch link auditor loop
									executeNextCrawlBatch(totalPending, totalPending);
								} else {
									alert('Failed to initialize audit statistics.');
									resetButtons();
								}
							},
							error: function() {
								alert('Connection error calculating audit queue.');
								resetButtons();
							}
						});
					} else {
						alert('Discovery phase failed: ' + (response.data.message || 'Unknown error'));
						resetButtons();
					}
				},
				error: function () {
					alert('Server error occurred during URL discovery.');
					resetButtons();
				}
			});
		});
	}

	function executeNextCrawlBatch(totalAtStart, currentRemaining) {
		if (isCrawlCancelled) {
			document.getElementById('lh-progress-status').textContent = 'Audit Cancelled by User.';
			resetButtons();
			return;
		}

		document.getElementById('lh-progress-status').textContent = `Auditing active page links (${totalAtStart - currentRemaining} of ${totalAtStart} checked)...`;

		jQuery.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'lh_crawl_batch', nonce: linkHealerAdmin.nonce },
			success: function (response) {
				if (response.success) {
					consecutiveFailures = 0;
					const remaining = response.data.remaining_pending;
					const processed = response.data.processed_in_batch;

					// Calculate math progress using standard: Progress = (1 - Remaining / Total) * 100%
					// We pad this offset to scale from 20% (since discovery steps took 20% of the UI cycle)
					const baseOffset = 20;
					const remainingProgressRatio = (totalAtStart - remaining) / totalAtStart;
					const progressPercent = Math.min(100, Math.round(baseOffset + (remainingProgressRatio * (100 - baseOffset))));

					document.getElementById('lh-progress-bar').style.width = progressPercent + '%';
					document.getElementById('lh-progress-percentage').textContent = progressPercent + '%';
					document.getElementById('lh-progress-counts').textContent = `Checked in batch: ${processed} | Remaining: ${remaining}`;

					// Synchronize KPI cards and lists dynamically without refreshing
					updateKPICards(response.data.metrics);
					reloadAuditTable();

					// Chaining Rule: If remaining links exist, loop automatically
					if (remaining > 0 && processed > 0) {
						executeNextCrawlBatch(totalAtStart, remaining);
					} else {
						// Loop Termination
						document.getElementById('lh-progress-status').textContent = 'Audit complete! Your site link health profile has been verified.';
						document.getElementById('lh-progress-bar').style.width = '100%';
						document.getElementById('lh-progress-percentage').textContent = '100%';
						resetButtons();
					}
				} else {
					handleBatchFailure(totalAtStart, currentRemaining);
				}
			},
			error: function () {
				handleBatchFailure(totalAtStart, currentRemaining);
			}
		});
	}

	function handleBatchFailure(totalAtStart, currentRemaining) {
		consecutiveFailures++;
		if (consecutiveFailures >= 3) {
			document.getElementById('lh-progress-status').textContent = 'Scan paused due to multiple server dropouts.';
			alert('Scan execution paused. The server failed to respond 3 times consecutively.');
			resetButtons();
		} else {
			document.getElementById('lh-progress-status').textContent = `Retry connection attempt ${consecutiveFailures}/3...`;
			setTimeout(function() {
				executeNextCrawlBatch(totalAtStart, currentRemaining);
			}, 2000);
		}
	}

	function resetButtons() {
		if (scanBtn) {
			scanBtn.disabled = false;
			scanBtn.innerHTML = '<i class="fa-solid fa-play"></i> Start Website Audit';
		}
	}

	// Cancel Button Listener
	const cancelBtn = document.getElementById('lh-cancel-crawl');
	if (cancelBtn) {
		cancelBtn.addEventListener('click', function(e) {
			e.preventDefault();
			isCrawlCancelled = true;
			cancelBtn.textContent = 'Stopping...';
		});
	}

	// 2. RESET DATABASE (AJAX ONLY)
	if (resetBtn) {
		resetBtn.addEventListener('click', function (e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to clear all scanned links and reset the tables?')) {
				return;
			}

			resetBtn.disabled = true;
			resetBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Resetting...';

			jQuery.ajax({
				url: ajaxurl,
				type: 'POST',
				data: { action: 'lh_reset_database', nonce: linkHealerAdmin.nonce },
				success: function (response) {
					resetBtn.disabled = false;
					resetBtn.innerHTML = '<i class="fa-solid fa-trash"></i> Reset Database';

					if (response.success) {
						alert('Database reset successful.');
						updateKPICards({ total_scanned: 0, broken_internal: 0, broken_external: 0, total_healed: 0 });
						reloadAuditTable();
						progressContainer.style.display = 'none';
					}
				},
				error: function() {
					resetBtn.disabled = false;
					resetBtn.innerHTML = '<i class="fa-solid fa-trash"></i> Reset Database';
					alert('Error resetting database.');
				}
			});
		});
	}
});
