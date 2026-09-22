/**
 * ReserveChain document verifier.
 *
 * Confirms that a document someone holds is byte-identical to the one the
 * asset registry relies on.
 *
 * The file never leaves the visitor's machine. SHA-256 is computed locally
 * with WebCrypto and only the 64-character digest is sent. That matters for
 * three reasons: assay reports and ownership documents are confidential and
 * their holder should not have to hand one to us to check it; we cannot retain
 * or leak a file we were never given; and the check works on documents
 * ReserveChain has never seen, which is exactly the case where a mismatch is
 * worth knowing about.
 *
 * WebCrypto requires a secure context, so on plain HTTP over a non-localhost
 * origin `crypto.subtle` is undefined. Rather than fail silently, the script
 * says so and falls back to the manual path, which is documented on the page.
 */

(function () {
	'use strict';

	var HEX64 = /^[a-fA-F0-9]{64}$/;

	function init(root) {
		var fileInput = root.querySelector('[data-rc-verify-file]');
		var digestInput = root.querySelector('[data-rc-verify-digest]');
		var form = root.querySelector('[data-rc-verify-form]');
		var dropzone = root.querySelector('[data-rc-verify-dropzone]');
		var output = root.querySelector('[data-rc-verify-output]');
		var endpoint = root.getAttribute('data-rc-endpoint');

		if (!form || !output || !endpoint) {
			return;
		}

		var secure = typeof crypto !== 'undefined' && crypto.subtle && window.isSecureContext;

		if (!secure && fileInput) {
			fileInput.disabled = true;

			if (dropzone) {
				dropzone.hidden = true;
			}

			report(output, 'notice', root.getAttribute('data-rc-insecure') ||
				'In-browser hashing needs a secure connection (HTTPS). Compute the digest yourself and paste it below.');
		}

		function setBusy(isBusy, message) {
			root.setAttribute('aria-busy', isBusy ? 'true' : 'false');

			if (isBusy) {
				report(output, 'busy', message);
			}
		}

		async function hashFile(file) {
			// Read in one go: certificates are small. A streaming digest would
			// need a JS SHA-256 implementation, which is a dependency and a
			// correctness risk for no practical gain at these sizes.
			var buffer = await file.arrayBuffer();
			var digest = await crypto.subtle.digest('SHA-256', buffer);

			return Array.from(new Uint8Array(digest))
				.map(function (b) { return b.toString(16).padStart(2, '0'); })
				.join('');
		}

		async function lookup(digest) {
			setBusy(true, root.getAttribute('data-rc-checking') || 'Checking the registry…');

			try {
				var response = await fetch(endpoint + '/' + digest, {
					headers: { Accept: 'application/json' },
					credentials: 'omit'
				});

				var data = await response.json();

				if (response.status === 429) {
					report(output, 'notice', data.message || 'Too many requests. Please wait a few minutes.');
					return;
				}

				render(output, digest, data);
			} catch (error) {
				report(output, 'error',
					(root.getAttribute('data-rc-network') || 'The registry could not be reached.') +
					' (' + error.message + ')');
			} finally {
				setBusy(false);
			}
		}

		async function handleFile(file) {
			if (!file) {
				return;
			}

			setBusy(true, root.getAttribute('data-rc-hashing') || 'Hashing locally — the file is not uploaded…');

			try {
				var digest = await hashFile(file);

				if (digestInput) {
					digestInput.value = digest;
				}

				await lookup(digest);
			} catch (error) {
				setBusy(false);
				report(output, 'error', 'Could not read that file. (' + error.message + ')');
			}
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var value = digestInput ? digestInput.value.trim().toLowerCase() : '';

			if (!HEX64.test(value)) {
				report(output, 'error', root.getAttribute('data-rc-invalid') ||
					'Enter a 64-character SHA-256 digest, or choose a file.');
				return;
			}

			lookup(value);
		});

		if (fileInput) {
			fileInput.addEventListener('change', function () {
				handleFile(fileInput.files && fileInput.files[0]);
			});
		}

		if (dropzone && secure) {
			['dragenter', 'dragover'].forEach(function (name) {
				dropzone.addEventListener(name, function (event) {
					event.preventDefault();
					dropzone.classList.add('is-dragover');
				});
			});

			['dragleave', 'drop'].forEach(function (name) {
				dropzone.addEventListener(name, function (event) {
					event.preventDefault();
					dropzone.classList.remove('is-dragover');
				});
			});

			dropzone.addEventListener('drop', function (event) {
				var dt = event.dataTransfer;
				handleFile(dt && dt.files && dt.files[0]);
			});
		}
	}

	function report(output, kind, message) {
		output.hidden = false;
		output.className = 'rc-verify__output rc-verify__output--' + kind;
		output.textContent = message || '';
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function render(output, digest, data) {
		var kind = data.match ? (data.status === 'verified_published' ? 'match' : 'partial') : 'nomatch';
		var heading = data.match
			? (data.status === 'verified_published' ? 'Match confirmed' : 'Digest recognised')
			: 'No match';

		var html = '<p class="rc-verify__heading">' + escapeHtml(heading) + '</p>';
		html += '<p class="rc-verify__message">' + escapeHtml(data.message || '') + '</p>';
		html += '<p class="rc-verify__digest"><span>SHA-256</span><code>' + escapeHtml(digest) + '</code></p>';

		if (data.match && data.document) {
			var d = data.document;
			var rows = [];

			if (d.title) { rows.push(['Document', d.title]); }
			if (d.document_number) { rows.push(['Number', d.document_number]); }
			if (d.issuer) { rows.push(['Issuer', d.issuer]); }
			if (d.issued_on) { rows.push(['Issued', d.issued_on]); }
			rows.push(['Registry state', d.publication_state]);
			rows.push(['Version', String(d.version)]);

			html += '<dl class="rc-verify__meta">';

			rows.forEach(function (row) {
				html += '<div><dt>' + escapeHtml(row[0]) + '</dt><dd>' + escapeHtml(row[1]) + '</dd></div>';
			});

			html += '</dl>';
		}

		output.hidden = false;
		output.className = 'rc-verify__output rc-verify__output--' + kind;
		output.innerHTML = html;
	}

	function boot() {
		document.querySelectorAll('[data-rc-verify]').forEach(init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
