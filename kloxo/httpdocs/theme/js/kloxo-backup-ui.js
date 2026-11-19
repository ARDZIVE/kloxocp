/**
 * Kloxo CP — Backup / Restore console (chunked upload, URL/FTP fetch, AJAX).
 * Copyright (C) 2026 Kloxo CP contributors — AGPLv3.
 */
(function () {
	'use strict';

	var bootEl = document.getElementById('kbu-boot');
	var root = document.getElementById('kloxo-backup-ui');
	if (!bootEl || !root) {
		return;
	}

	var cfg;
	try {
		cfg = JSON.parse(bootEl.textContent || '{}');
	} catch (e0) {
		cfg = {};
	}

	var account = String(cfg.account || '');
	var token = String(cfg.token || '');
	var api = String(cfg.api || '/theme/lbin/backup-api.php');
	var maxBytes = Number(cfg.maxBytes) || 10737418240;
	var chunkBytes = Number(cfg.chunkBytes) || 3145728;
	var ftpCfg = cfg.ftp || {};

	var selectedFile = null;
	var uploadId = null;
	var uploadAbort = false;
	var uploading = false;
	var pendingRestore = null;

	function $(id) {
		return document.getElementById(id);
	}

	function toast(text, kind) {
		try {
			if (window.KloxoToast && typeof window.KloxoToast.show === 'function') {
				window.KloxoToast.show(String(text || ''), kind || 'info');
				return;
			}
		} catch (e1) { /* ignore */ }
	}

	function busy(on, text) {
		try {
			if (!window.KloxoBusy) {
				return;
			}
			if (on) {
				window.KloxoBusy.show(text || 'Please wait…');
			} else {
				window.KloxoBusy.hide();
			}
		} catch (e2) { /* ignore */ }
	}

	function setStatus(el, text, kind) {
		if (!el) {
			return;
		}
		if (!text) {
			el.hidden = true;
			el.textContent = '';
			el.className = 'kbu-status';
			return;
		}
		el.hidden = false;
		el.textContent = text;
		el.className = 'kbu-status' + (kind ? (' is-' + kind) : '');
	}

	function humanBytes(n) {
		n = Number(n) || 0;
		if (n < 1024) {
			return n + ' B';
		}
		var u = ['KB', 'MB', 'GB', 'TB'];
		var v = n;
		var i = -1;
		do {
			v /= 1024;
			i++;
		} while (v >= 1024 && i < u.length - 1);
		return (Math.round(v * (v >= 10 ? 10 : 100)) / (v >= 10 ? 10 : 100)) + ' ' + u[i];
	}

	function apiUrl(action, extra) {
		var q = ['action=' + encodeURIComponent(action),
			'account=' + encodeURIComponent(account),
			'token=' + encodeURIComponent(token)];
		if (extra) {
			Object.keys(extra).forEach(function (k) {
				q.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(extra[k])));
			});
		}
		return api + '?' + q.join('&');
	}

	function postForm(action, fields) {
		var body = new FormData();
		body.append('action', action);
		body.append('account', account);
		body.append('token', token);
		if (fields) {
			Object.keys(fields).forEach(function (k) {
				if (fields[k] != null) {
					body.append(k, String(fields[k]));
				}
			});
		}
		return fetch(apiUrl(action), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
			body: body
		}).then(parseJson);
	}

	function parseJson(res) {
		return res.text().then(function (t) {
			var j = null;
			var raw = String(t || '');
			try {
				j = JSON.parse(raw || '{}');
			} catch (e3) {
				j = null;
			}
			if (!res.ok || !j || j.ok === false) {
				var msg = (j && j.message) ? j.message : '';
				if (!msg && raw.indexOf('<') !== -1) {
					msg = res.status === 403 || res.status === 401
						? 'Session expired — refresh the page and try again.'
						: 'Server returned a page instead of JSON (HTTP ' + res.status + '). Refresh and try again.';
				}
				if (!msg) {
					msg = 'Request failed (HTTP ' + res.status + ').';
				}
				var err = new Error(msg);
				err.payload = j;
				err.status = res.status;
				throw err;
			}
			return j;
		});
	}

	function switchTab(name) {
		root.querySelectorAll('.kbu-tab').forEach(function (btn) {
			btn.classList.toggle('is-on', btn.getAttribute('data-tab') === name);
		});
		root.querySelectorAll('.kbu-pane').forEach(function (pane) {
			var on = pane.getAttribute('data-pane') === name;
			pane.hidden = !on;
			pane.classList.toggle('is-on', on);
		});
	}

	function renderList(archives) {
		var tbody = $('kbu-tbody');
		if (!tbody) {
			return;
		}
		archives = Array.isArray(archives) ? archives : [];
		if (archives.length === 0) {
			tbody.innerHTML = '<tr><td colspan="4" class="kbu-empty">No archives yet. Create a backup or add one above.</td></tr>';
			return;
		}
		var html = '';
		archives.forEach(function (a) {
			var name = String(a.name || '');
			if (!name) {
				return;
			}
			var size = a.human ? String(a.human) : humanBytes(a.size || 0);
			var when = a.mtime ? new Date(Number(a.mtime) * 1000).toLocaleString() : '—';
			html += '<tr data-name="' + escapeAttr(name) + '">'
				+ '<td class="kbu-fname">' + escapeHtml(name) + '</td>'
				+ '<td>' + escapeHtml(size) + '</td>'
				+ '<td>' + escapeHtml(when) + '</td>'
				+ '<td>'
				+ '<button type="button" class="kbu-btn kbu-btn-sm kbu-btn-primary" data-act="restore">Restore</button> '
				+ '<button type="button" class="kbu-btn kbu-btn-sm kbu-btn-ghost" data-act="download">Download</button> '
				+ '<button type="button" class="kbu-btn kbu-btn-sm kbu-btn-ghost" data-act="delete">Delete</button>'
				+ '</td></tr>';
		});
		tbody.innerHTML = html || '<tr><td colspan="4" class="kbu-empty">No archives yet. Create a backup or add one above.</td></tr>';
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
		});
	}

	function escapeAttr(s) {
		return escapeHtml(s).replace(/\n/g, ' ');
	}

	function refreshList(quiet) {
		if (!token) {
			if (!quiet) {
				setStatus($('kbu-list-status'), 'Session token missing — refresh the page.', 'err');
			}
			return Promise.resolve();
		}
		if (!quiet) {
			setStatus($('kbu-list-status'), 'Refreshing…', 'busy');
		}
		return fetch(apiUrl('list'), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token }
		}).then(parseJson).then(function (j) {
			renderList(j.archives);
			if (j.writable === false) {
				setStatus($('kbu-list-status'),
					'Backup folder is not writable. As root: chown -R lxlabs:lxlabs '
						+ String(j.dir || '/home/kloxo/client/' + account + '/__backup'),
					'err');
			} else {
				setStatus($('kbu-list-status'), '');
			}
		}).catch(function (err) {
			if (!quiet) {
				setStatus($('kbu-list-status'), err.message || 'Could not list archives.', 'err');
				toast(err.message || 'Could not list archives.', 'error');
			}
		});
	}

	function downloadArchive(name) {
		// Use an <a download> so kloxo-busy.js does not treat navigation as a page load.
		var a = document.createElement('a');
		a.href = apiUrl('download', { file: name });
		a.setAttribute('download', name);
		a.setAttribute('data-kloxo-nobusy', '1');
		a.style.display = 'none';
		document.body.appendChild(a);
		a.click();
		setTimeout(function () {
			if (a.parentNode) {
				a.parentNode.removeChild(a);
			}
		}, 0);
	}

	function resetDropTitle() {
		var title = root.querySelector('.kbu-drop-title');
		if (title) {
			title.textContent = 'Drop a backup archive here, or click to browse';
		}
	}

	function setFile(file) {
		selectedFile = file || null;
		var btn = $('kbu-btn-upload');
		if (btn) {
			btn.disabled = !selectedFile || uploading;
		}
		var drop = $('kbu-drop');
		if (drop && selectedFile) {
			var title = drop.querySelector('.kbu-drop-title');
			if (title) {
				title.textContent = selectedFile.name + ' (' + humanBytes(selectedFile.size) + ')';
			}
		} else if (!selectedFile) {
			resetDropTitle();
		}
	}

	function setProgress(pct, detail) {
		var box = $('kbu-progress');
		var fill = $('kbu-progress-fill');
		var pctEl = $('kbu-progress-pct');
		var det = $('kbu-progress-detail');
		if (!box) {
			return;
		}
		box.hidden = false;
		pct = Math.max(0, Math.min(100, Math.round(pct)));
		if (fill) {
			fill.style.width = pct + '%';
		}
		if (pctEl) {
			pctEl.textContent = pct + '%';
		}
		if (det) {
			det.textContent = detail || '';
		}
	}

	function hideProgress() {
		var box = $('kbu-progress');
		if (box) {
			box.hidden = true;
		}
	}

	function startUpload(file) {
		if (!file || uploading) {
			return;
		}
		if (!token) {
			setStatus($('kbu-add-status'), 'Session token missing — refresh the page.', 'err');
			toast('Session token missing — refresh the page.', 'error');
			return;
		}
		if (file.size > maxBytes) {
			toast('File over 10 GB.', 'warning');
			return;
		}
		if (file.size < 1) {
			toast('Empty file.', 'warning');
			return;
		}
		setFile(file);
		uploading = true;
		var btn = $('kbu-btn-upload');
		if (btn) {
			btn.disabled = true;
		}
		uploadChunked(file).catch(function (err) {
			setStatus($('kbu-add-status'), err.message || 'Upload failed.', 'err');
			toast(err.message || 'Upload failed.', 'error');
		}).finally(function () {
			uploading = false;
			if (btn) {
				btn.disabled = !selectedFile;
			}
		});
	}

	function uploadChunked(file) {
		uploadAbort = false;
		uploadId = null;
		var cancelBtn = $('kbu-btn-cancel-upload');
		if (cancelBtn) {
			cancelBtn.hidden = false;
		}
		setStatus($('kbu-add-status'), 'Uploading… keep this tab open.', 'busy');
		setProgress(0, humanBytes(0) + ' / ' + humanBytes(file.size));

		return postForm('upload_init', { file: file.name, size: file.size }).then(function (init) {
			uploadId = init.upload_id;
			var size = file.size;
			var chunk = Number(init.chunk_size) || chunkBytes;
			var index = 0;
			var offset = 0;

			function next() {
				if (uploadAbort) {
					return postForm('upload_abort', { upload_id: uploadId }).then(function () {
						throw new Error('Upload canceled.');
					});
				}
				if (offset >= size) {
					setProgress(99, 'Assembling on server…');
					return postForm('upload_complete', { upload_id: uploadId });
				}
				var end = Math.min(offset + chunk, size);
				var blob = file.slice(offset, end);
				return blob.arrayBuffer().then(function (buf) {
					return fetch(apiUrl('upload_chunk', { upload_id: uploadId, index: index }), {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/octet-stream',
							'X-Requested-With': 'XMLHttpRequest',
							'X-CSRF-Token': token
						},
						body: buf
					}).then(parseJson);
				}).then(function () {
					offset = end;
					index++;
					var pct = (offset / size) * 100;
					setProgress(pct, humanBytes(offset) + ' / ' + humanBytes(size));
					return next();
				});
			}

			return next();
		}).then(function (done) {
			setProgress(100, 'Done');
			setStatus($('kbu-add-status'), 'Upload complete: ' + (done.name || file.name), 'ok');
			toast('File uploaded.', 'success');
			renderList(done.archives);
			setFile(null);
			var input = $('kbu-file');
			if (input) {
				input.value = '';
			}
		}).finally(function () {
			uploadId = null;
			if (cancelBtn) {
				cancelBtn.hidden = true;
			}
			setTimeout(hideProgress, 800);
		});
	}

	function runBackup() {
		var prefix = ($('kbu-prefix') && $('kbu-prefix').value) || '';
		setStatus($('kbu-backup-status'), 'Creating backup… keep this tab open.', 'busy');
		busy(true, 'Creating backup…');
		postForm('backup', { prefix: prefix }).then(function (j) {
			setStatus($('kbu-backup-status'), j.message || 'Backup completed.', 'ok');
			toast(j.message || 'Backup completed.', 'success');
			renderList(j.archives);
		}).catch(function (err) {
			setStatus($('kbu-backup-status'), err.message || 'Backup failed.', 'err');
			toast(err.message || 'Backup failed.', 'error');
		}).finally(function () {
			busy(false);
		});
	}

	function openRestoreDialog(name) {
		pendingRestore = name;
		var dlg = $('kbu-restore-dialog');
		var msg = $('kbu-restore-msg');
		if (msg) {
			msg.textContent = 'Restore “‘ + name + '” into this account? Existing data may be replaced.';
		}
		if (dlg) {
			dlg.hidden = false;
			dlg.classList.add('is-open');
		} else if (window.confirm('Restore ' + name + '?')) {
			doRestore(name);
		}
	}

	function closeRestoreDialog() {
		var dlg = $('kbu-restore-dialog');
		if (dlg) {
			dlg.hidden = true;
			dlg.classList.remove('is-open');
		}
		pendingRestore = null;
	}

	function doRestore(name) {
		closeRestoreDialog();
		setStatus($('kbu-list-status'), 'Restoring ' + name + '… keep this tab open.', 'busy');
		busy(true, 'Restoring backup…');
		postForm('restore', { file: name }).then(function (j) {
			setStatus($('kbu-list-status'), j.message || 'Restore completed.', 'ok');
			toast(j.message || 'Restore completed.', 'success');
		}).catch(function (err) {
			setStatus($('kbu-list-status'), err.message || 'Restore failed.', 'err');
			toast(err.message || 'Restore failed.', 'error');
		}).finally(function () {
			busy(false);
		});
	}

	function deleteArchive(name) {
		if (!window.confirm('Delete archive “‘ + name + '”?')) {
			return;
		}
		postForm('delete', { file: name }).then(function (j) {
			toast('Archive deleted.', 'success');
			renderList(j.archives);
		}).catch(function (err) {
			toast(err.message || 'Delete failed.', 'error');
			setStatus($('kbu-list-status'), err.message || 'Delete failed.', 'err');
		});
	}

	// Prefill FTP from saved settings.
	if ($('kbu-ftp-server') && ftpCfg.server) {
		$('kbu-ftp-server').value = String(ftpCfg.server);
	}
	if ($('kbu-ftp-user') && ftpCfg.user) {
		$('kbu-ftp-user').value = String(ftpCfg.user);
	}
	if ($('kbu-ftp-file') && ftpCfg.dir) {
		var dirHint = String(ftpCfg.dir).replace(/\/+$/, '');
		if (dirHint && !$('kbu-ftp-file').value) {
			$('kbu-ftp-file').placeholder = dirHint + '/backup.tgz';
		}
	}

	root.querySelectorAll('.kbu-tab').forEach(function (btn) {
		btn.addEventListener('click', function () {
			switchTab(btn.getAttribute('data-tab') || 'file');
		});
	});

	var drop = $('kbu-drop');
	var fileInput = $('kbu-file');
	if (drop) {
		['dragenter', 'dragover'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) {
				e.preventDefault();
				e.stopPropagation();
				drop.classList.add('is-drag');
			});
		});
		drop.addEventListener('dragleave', function (e) {
			e.preventDefault();
			drop.classList.remove('is-drag');
		});
		drop.addEventListener('drop', function (e) {
			e.preventDefault();
			e.stopPropagation();
			drop.classList.remove('is-drag');
			var files = e.dataTransfer && e.dataTransfer.files;
			if (files && files[0]) {
				if (fileInput) {
					try {
						fileInput.value = '';
					} catch (eClr) { /* ignore */ }
				}
				startUpload(files[0]);
			}
		});
		drop.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				if (fileInput) {
					fileInput.click();
				}
			}
		});
	}
	if (fileInput) {
		fileInput.addEventListener('change', function () {
			var f = fileInput.files && fileInput.files[0];
			if (!f) {
				setFile(null);
				return;
			}
			// Select enables Upload; start immediately (button remains as fallback).
			startUpload(f);
		});
	}

	var btnUpload = $('kbu-btn-upload');
	if (btnUpload) {
		btnUpload.addEventListener('click', function () {
			if (!selectedFile || uploading) {
				return;
			}
			startUpload(selectedFile);
		});
	}
	var btnCancel = $('kbu-btn-cancel-upload');
	if (btnCancel) {
		btnCancel.addEventListener('click', function () {
			uploadAbort = true;
		});
	}

	var btnUrl = $('kbu-btn-url');
	if (btnUrl) {
		btnUrl.addEventListener('click', function () {
			var url = ($('kbu-url') && $('kbu-url').value) || '';
			setStatus($('kbu-add-status'), 'Fetching from URL…', 'busy');
			busy(true, 'Fetching archive…');
			postForm('fetch_url', { url: url }).then(function (j) {
				setStatus($('kbu-add-status'), j.message || 'Fetched.', 'ok');
				toast(j.message || 'Archive fetched from URL.', 'success');
				renderList(j.archives);
			}).catch(function (err) {
				setStatus($('kbu-add-status'), err.message || 'Fetch failed.', 'err');
				toast(err.message || 'Fetch failed.', 'error');
			}).finally(function () {
				busy(false);
			});
		});
	}

	var btnFtp = $('kbu-btn-ftp');
	if (btnFtp) {
		btnFtp.addEventListener('click', function () {
			setStatus($('kbu-add-status'), 'Fetching from FTP…', 'busy');
			busy(true, 'Fetching from FTP…');
			postForm('fetch_ftp', {
				server: ($('kbu-ftp-server') && $('kbu-ftp-server').value) || '',
				user: ($('kbu-ftp-user') && $('kbu-ftp-user').value) || '',
				pass: ($('kbu-ftp-pass') && $('kbu-ftp-pass').value) || '',
				file: ($('kbu-ftp-file') && $('kbu-ftp-file').value) || ''
			}).then(function (j) {
				setStatus($('kbu-add-status'), j.message || 'Fetched.', 'ok');
				toast(j.message || 'Archive fetched from FTP.', 'success');
				renderList(j.archives);
			}).catch(function (err) {
				setStatus($('kbu-add-status'), err.message || 'FTP fetch failed.', 'err');
				toast(err.message || 'FTP fetch failed.', 'error');
			}).finally(function () {
				busy(false);
			});
		});
	}

	var btnBackup = $('kbu-btn-backup');
	if (btnBackup) {
		btnBackup.addEventListener('click', runBackup);
	}
	var btnRefresh = $('kbu-btn-refresh');
	if (btnRefresh) {
		btnRefresh.addEventListener('click', function () {
			refreshList(false);
		});
	}

	var tbody = $('kbu-tbody');
	if (tbody) {
		tbody.addEventListener('click', function (e) {
			var btn = e.target.closest('button[data-act]');
			if (!btn) {
				return;
			}
			var tr = btn.closest('tr[data-name]');
			if (!tr) {
				return;
			}
			var name = tr.getAttribute('data-name') || '';
			var act = btn.getAttribute('data-act');
			if (act === 'restore') {
				openRestoreDialog(name);
			} else if (act === 'download') {
				downloadArchive(name);
			} else if (act === 'delete') {
				deleteArchive(name);
			}
		});
	}

	var dlg = $('kbu-restore-dialog');
	var btnRestoreCancel = $('kbu-btn-restore-cancel');
	var btnRestoreConfirm = $('kbu-btn-restore-confirm');
	if (btnRestoreCancel) {
		btnRestoreCancel.addEventListener('click', function () {
			closeRestoreDialog();
		});
	}
	if (btnRestoreConfirm) {
		btnRestoreConfirm.addEventListener('click', function () {
			if (pendingRestore) {
				doRestore(pendingRestore);
			} else {
				closeRestoreDialog();
			}
		});
	}
	if (dlg) {
		dlg.addEventListener('click', function (e) {
			if (e.target === dlg) {
				closeRestoreDialog();
			}
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && dlg.classList.contains('is-open')) {
				closeRestoreDialog();
			}
		});
	}

	if (!token) {
		setStatus($('kbu-add-status'), 'Session token missing — refresh the page.', 'err');
	}

	// Seeded HTML already lists archives; refresh quietly in the background.
	if (Array.isArray(cfg.archives) && cfg.archives.length) {
		renderList(cfg.archives);
	}
	if (cfg.writable === false && cfg.dir) {
		setStatus($('kbu-list-status'),
			'Backup folder is not writable. As root: chown -R lxlabs:lxlabs ' + String(cfg.dir),
			'err');
	}
	refreshList(true);
})();
