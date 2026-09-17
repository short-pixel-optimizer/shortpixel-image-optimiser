/**
 * Hostile snippet: "another plugin extends built-in prototypes".
 *
 * A common class of admin-page conflict: a third-party script adds an
 * ENUMERABLE method to Array.prototype (old MooTools/Prototype.js habits,
 * sloppy polyfills). Code that walks arrays with `for…in` then sees the
 * extra key and misbehaves (SPIO's processor iterates response arrays with
 * `for (var i in …)` in a few places; the settings exclusions editor
 * builds arrays it then serialises).
 *
 * Kept realistic on purpose: ONLY Array.prototype, ONLY a function value.
 * (Polluting Object.prototype, or a non-function property, takes down
 * WordPress core/React itself — "Getter must be a function" storms — which
 * tells us nothing about SPIO.)
 */
(function () {
	Object.defineProperty(Array.prototype, 'spioE2EHostile', {
		value: function () { return 'polluted'; },
		enumerable: true, // the harmful part — shows up in for…in
		configurable: true,
		writable: true,
	});
	window.__spioE2EHostile = (window.__spioE2EHostile || []).concat('prototype-pollution');
})();
