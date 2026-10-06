/**
 * Hostile snippet: "a plugin stubs console.debug / console.warn".
 *
 * Some optimisation and privacy plugins neuter parts of `console`. SPIO's
 * processor calls `console.debug` and `console.warn` directly
 * (shortpixel-processor.js CheckActive, worker error handling) and
 * screen-base.js HandleError uses `console.trace`; if any is missing the
 * call itself throws inside SPIO's own error path — masking the real
 * error and aborting the handler.
 *
 * Kept realistic on purpose: the methods are REPLACED by no-ops rather
 * than removed (removing them breaks WordPress/React — react-spring — so
 * the page never gets as far as SPIO). The E2E tripwire keeps seeing real
 * errors because `console.error` is left untouched.
 */
(function () {
	var noop = function () {};
	window.console.debug = noop;
	window.console.warn = noop;
	window.console.trace = noop;
	window.console.info = noop;
	window.__spioE2EHostile = (window.__spioE2EHostile || []).concat('console-clobber');
})();
