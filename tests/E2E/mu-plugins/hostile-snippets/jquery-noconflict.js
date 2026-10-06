/**
 * Hostile snippet: "jQuery.noConflict(true) — `$` AND `jQuery` released".
 *
 * Some themes/plugins call jQuery.noConflict(true) to load their own jQuery
 * copy, which removes the `jQuery` global entirely for everything that
 * loads after them. SPIO's legacy layer (res/js/shortpixel.js) is jQuery
 * only, res/js/shortpixel-onboarding.js uses jQuery without declaring the
 * dependency, and screen-media.js touches `jQuery('.imgedit-menu')`.
 *
 * WordPress core loads jQuery before admin_print_scripts priority 0 only
 * for scripts already enqueued at that point; to reproduce the situation
 * reliably this snippet defers: it releases jQuery right after DOM ready,
 * i.e. after SPIO's scripts have been PARSED but before most of their
 * lazily-bound handlers run. Whatever SPIO code reads `jQuery` lazily
 * (event handlers, timers, getScript for the comparer) then fails.
 */
(function () {
	function release() {
		if (window.jQuery && typeof window.jQuery.noConflict === 'function') {
			window.__spioE2EjQuery = window.jQuery; // keep a handle for the test
			window.jQuery.noConflict(true);
		}
		window.__spioE2EHostile = (window.__spioE2EHostile || []).concat('jquery-noconflict');
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', release, { once: true });
	} else {
		release();
	}
})();
