/**
 * Hostile snippet: "window.URL overwritten by a third-party script".
 *
 * Reproduces what the EMC – Embed Calendly Scheduling plugin's bundled
 * widget.js does on every admin page (bug #62 root cause, customer report
 * 2026-09-08): it replaces the global URL constructor with a minified
 * wrapper that only carries createObjectURL/revokeObjectURL statics —
 * URL.parse() (and any other static) is gone.
 *
 * Injected inline at admin_print_scripts priority 0 by the E2E support
 * mu-plugin when a test enables it via POST /spio-e2e/v1/hostile.
 * Any static SPIO relies on must therefore be feature-detected or wrapped.
 */
(function () {
	var Original = window.URL;
	function At(url, base) {
		return new Original(url, base);
	}
	At.createObjectURL = Original.createObjectURL.bind(Original);
	At.revokeObjectURL = Original.revokeObjectURL.bind(Original);
	// NOTE: deliberately no At.parse / At.canParse.
	window.URL = At;
	window.__spioE2EHostile = (window.__spioE2EHostile || []).concat('window-url-overwrite');
})();
