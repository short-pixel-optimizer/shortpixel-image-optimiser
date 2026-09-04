<?php

namespace ShortPixel\Model;

use ShortPixel\Model\Image\ImageModel as ImageModel;


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


/**
 * HTML `<img>` / `<source>` element parser used by the front-end
 * WebP / AVIF `<picture>` injection pipeline.
 *
 * Takes a raw element string, parses it through DOMDocument, extracts the
 * attributes it needs (id, alt, src, srcset, class, width, height, style,
 * sizes) and stashes everything else on $attributes so the outer `<img>`
 * can be rebuilt verbatim. Supports the common lazy-loading conventions
 * (`data-src`, `data-lazy-src`, `data-srcset`) so lazy-loaded images still
 * get their WebP / AVIF companions attached.
 *
 * The class purely inspects and rewrites markup — it does NOT touch the
 * filesystem or emit any HTTP. isParseable() gates whether the image is a
 * candidate for `<picture>` wrapping (usable src, no CSS-background usage,
 * no opt-out class); parseReplacement() emits the resulting `<picture>`
 * block.
 *
 * @package ShortPixel\Model
 */
class FrontImage
{
	/** @var string The raw HTML string passed to the constructor. */
	protected $raw;
	/** @var bool True after loadImageDom() successfully parsed the element and derived an image base directory. */
	protected $image_loaded = false;
	/** @var bool Currently unused; reserved for future extension. */
	protected $is_parsable = false;
	/** @var \ShortPixel\Model\File\DirectoryModel|null Directory containing the image, derived from `src`/`srcset`. */
	protected $imageBase;

	/** @var string|null HTML `id` attribute of the parsed element. */
	protected $id;
	/** @var string|null HTML `alt` attribute — always echoed on rebuild, even when empty, for screen-reader compatibility. */
	protected $alt;
	/** @var string|null Original `src` attribute of the parsed element. */
	protected $src;
	/** @var string|null Original `srcset` attribute; a `data-srcset` fallback is used when `srcset` is missing. */
	protected $srcset;
	/** @var string|null HTML `class` attribute. */
	protected $class;
	/** @var string|null Caption placeholder (not an HTML attribute; reserved)
	 *  Present so callers can check for an existing caption without hitting
	 *  the magic __get() fallback that always returns null.
	 */
	protected $caption;
	/** @var string|null HTML `width` attribute. */
	protected $width;
	/** @var string|null HTML `height` attribute. */
	protected $height;
	/** @var string|null HTML `style` attribute; images with a `background` declaration are excluded from `<picture>` wrapping. */
	protected $style;
	/** @var string|null HTML `sizes` attribute. */
	protected $sizes;

	/** @var array<string, string>|null All parsed attributes keyed by name; source of truth for reconstruction. */
	protected $attributes;

	/** @var array<string, string> Records which prefix (`data-lazy-`, `data-`, or `''`) was found for each of src/srcset/sizes; used by buildSource() to emit matching attribute names on the generated `<source>`. */
	protected $dataTags = array();

	/**
	 * Constructor.
	 *
	 * Immediately parses the raw HTML via loadImageDom(). Errors during
	 * DOM parse are captured through libxml_use_internal_errors() so
	 * malformed markup doesn't emit warnings.
	 *
	 * @param string $raw_html Raw HTML for the element to parse.
	 */
	public function __construct($raw_html)
	{
		$this->raw = $raw_html;
		$this->loadImageDom();
	}

	/**
	 * Magic accessor — returns the value of a declared property, or null
	 * for unknown names.
	 *
	 * @param string $attr Property name.
	 * @return mixed|null
	 */
	public function __get($attr)
	{
		if (property_exists($this, $attr) && ! is_null($attr)) {
			return $this->$attr;
		}
		return null;
	}

	/**
	 * Magic mutator — assigns to a declared property, silently drops
	 * writes to unknown names.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value to assign.
	 * @return void
	 */
	public function __set($name, $value)
	{
		if (property_exists($this, $name) ) {

			$this->$name = $value;
		}

	}

	/**
	 * Parse the raw HTML through DOMDocument and hydrate the declared
	 * attribute properties.
	 *
	 * Flow:
	 *   1. Convert HTML entities via mb_encode_numericentity so non-ASCII
	 *      URLs and attribute values survive DOMDocument's default coder.
	 *   2. Loads the fragment silently (libxml errors muted).
	 *   3. Picks the first `<img>` element; falls back to the first
	 *      `<source>` for cases where the fragment came from a `<picture>`
	 *      block. Bails out on truly malformed inputs.
	 *   4. Iterates the element's attributes, dropping empty values,
	 *      assigning to the declared property when one exists, and always
	 *      storing on `$attributes` for later reconstruction.
	 *   5. If `srcset` is empty but `data-srcset` is present, promotes
	 *      `data-srcset` to be the working srcset value (common with
	 *      lazy-loading plugins that swap the two).
	 *   6. Calls setupSource() to derive the image base directory.
	 *
	 * @return false|void False on DOM parse failure or when no image
	 *                    element is present; otherwise void with
	 *                    side-effects on the properties.
	 */
	protected function loadImageDom()
	{
		if (function_exists("mb_convert_encoding")) {
			$this->raw = mb_encode_numericentity($this->raw, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors(true); // disable error emit from libxml

		$result = $dom->loadHTML($this->raw, LIBXML_NOWARNING);

		// HTML failed loading
		if (false === $result) {
			return false;
		}

		// Elements we support.  @todo for futuro 
		$parseable_elements = ['img', 'source'];

		$image = $dom->getElementsByTagName('img')->item(0);
		// $attributes = array();

		/* This can happen with mismatches, or extremely malformed HTML.
        In customer case, a javascript that did  for (i<imgDefer) --- </script> */
		if (! is_object($image)) {
			$source = $dom->getElementsByTagName('source')->item(0);
			if (null == $source) {
				$this->is_parsable = false;
				return false; // done. 			 
			}

			$image = $source;
		}

		foreach ($image->attributes as $attr) {
			// Preserve attributes even when they have an empty/nodeValue so
			// boolean attributes (data-no-lazy, nopin, ...) survive a
			// parse+rebuild cycle. Store the raw nodeValue (may be empty
			// string) and mirror onto declared properties where present.

			$value = $attr->nodeValue;

			if (property_exists($this, $attr->nodeName)) {
				$this->{$attr->nodeName} = $value;
			}

			// Preserve insertion order from the DOM so rebuilds keep the
			// original attribute ordering as closely as possible.
			$this->attributes[$attr->nodeName] = $value;
		}

		// Seen in wild, skipping over data-srcset because 
		if (is_null($this->srcset) && isset($this->attributes['data-srcset']))
		{
			 $this->srcset = $this->attributes['data-srcset'];
		}

		//$src = $this->src; 
		//$src = preg_replace('/[^a-zA-Z],\//', '', $src)	;
		//$this->src = filter_var(stripslashes($this->src), FILTER_SANITIZE_URL);
		

		// Parse the directory path and other sources
		$result = $this->setupSource();


		if (true === $result)
			$this->image_loaded = true;
	}

	/**
	 * Whether the element declares a CSS `background` in its inline style.
	 *
	 * Images used as CSS backgrounds are excluded from `<picture>` wrapping
	 * because swapping their source would break the layout.
	 *
	 * @return bool
	 */
	public function hasBackground()
	{
		if (! is_null($this->style) && strpos($this->style, 'background') !== false) {
			return true;
		}
		return false;
	}

	/**
	 * Whether the element carries a class that opts it out of `<picture>`
	 * wrapping.
	 *
	 * Default opt-out classes are `sp-no-webp` and `rev-sildebg`; the list is
	 * filterable via `shortpixel/front/preventclasses`. `sp-no-webp` is used
	 * internally to mark already-wrapped images so a second pass through
	 * the front controller doesn't recursively wrap them.
	 *
	 * @return bool
	 */
	public function hasPreventClasses()
	{
		// no class, no prevent.
		if (is_null($this->class)) {
			return false;
		}

		$preventArray = apply_filters('shortpixel/front/preventclasses', array('sp-no-webp', 'rev-sildebg'));

		foreach ($preventArray as $classname) {
			if (false !== strpos($this->class, $classname)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the element carries any usable image source (`src` or `srcset`).
	 *
	 * @return bool
	 */
	public function hasSource()
	{
		if (is_null($this->src) && is_null($this->srcset)) {
			return false;
		}
		return true;
	}

	/**
	 * Whether this element is a candidate for `<picture>` wrapping.
	 *
	 * All of the following must hold:
	 *   - hasPreventClasses() is false
	 *   - hasBackground() is false
	 *   - hasSource() is true
	 *   - the DOM parsed successfully (`$image_loaded`)
	 *
	 * @return bool
	 */
	public function isParseable()
	{
		if (
			false === $this->hasPreventClasses() &&
			false === $this->hasBackground()  &&
			true === $this->hasSource() &&
			true === $this->image_loaded
		) {
			return true;
		}

		return false;
	}

	/**
	 * Return the list of image URLs the caller should look up WebP / AVIF
	 * companions for.
	 *
	 * For srcset-based images each entry is a `URL <descriptor>` fragment
	 * (comma-split from the srcset); for a plain src, a single-element
	 * array. Also updates `dataTags['sizes']` as a side effect so
	 * buildSource() can echo the matching attribute name on rebuild.
	 *
	 * @return string[]
	 */
	public function getImageData()
	{
		if (! is_null($this->srcset)) {
			$data = $this->getLazyData('srcset');
			$data = explode(',', $data); // srcset is multiple images, split.

		} else {
			$data = $this->getLazyData('src');
			$data = array($data);  // single item, wrap in array
		}

		$this->getLazyData('sizes'); // sets the sizes.

		return $data;
	}


	/**
	 * Return the absolute directory path of the image, or null when no
	 * source could be resolved during loadImageDom().
	 *
	 * @return string|null
	 */
	public function getImageBase()
	{
		if (! is_null($this->imageBase))
			return $this->imageBase->getPath();

		return null;
	}

	/**
	 * Build the `<picture>` block that wraps the original `<img>` with
	 * optional AVIF and WebP source alternatives.
	 *
	 * The generated block has the shape:
	 *   `<picture>[<source ...avif>][<source ...webp>]<img ...></picture>`
	 * The original `<img>` is echoed through buildImage() with the class
	 * `sp-no-webp` appended so a subsequent parse pass short-circuits via
	 * hasPreventClasses().
	 *
	 * @param array{avif?: string[], webp?: string[]} $args Companion URL lists keyed by format.
	 * @return string The complete `<picture>` block.
	 */
	public function parseReplacement($args)
	{
		if (is_null($this->class)) {
			$this->class = '';
		}

		$this->class .= ' sp-no-webp';

		$output = "<picture>";

		if (isset($args['avif']) && count($args['avif']) > 0) {
			$output .= $this->buildSource($args['avif'], 'avif');
		}

		if (isset($args['webp']) && count($args['webp']) > 0) {
			$output .= $this->buildSource($args['webp'], 'webp');
		}

		$output .= $this->buildImage();

		$output .= "</picture>";
		return $output;
	}


	/**
	 * Derive the image base directory from `src` (or the first entry of
	 * `srcset` when no `src` is available) and store it on `$imageBase`.
	 *
	 * Refuses to derive a base for URLs whose extension is not in
	 * ImageModel::PROCESSABLE_EXTENSIONS — SVG, HEIC, video sources etc.
	 * are all skipped so downstream code can safely assume the image is
	 * something the pipeline knows how to handle.
	 *
	 * @return bool True on success, false when no usable source was found
	 *              or the extension was filtered out.
	 */
	protected function setupSource()
	{
		$src = null;

		if (! is_null($this->src)) {
			$src = $this->src;
		} elseif (! is_null($this->srcset)) {
			$parts = preg_split('/\s+/', trim($this->srcset));

			$image_url = $parts[0];
			$src = $image_url;
		}

		if (is_null($src)) {
			return false;
		}

		// Filter out extension that are not for us.
		if (false === $this->checkExtensionConvertable($src)) {
			return false;
		}

		$fs = \wpSPIO()->filesystem();
		$fileObj = $fs->getFile($src);
		$fileDir = $fileObj->getFileDir();
		$this->imageBase = $fileObj->getFileDir();

		return true;
		// If (! is_hnull $srcset)
		// Get first item from srcset ( remove the size ? , then feed it to FS, get directory from it.
	}

	/**
	 * Whether an image URL's extension is one the pipeline should touch.
	 *
	 * Compares the substring after the last `.` against
	 * ImageModel::PROCESSABLE_EXTENSIONS.
	 *
	 * @param string $source Image URL to inspect.
	 * @return bool
	 */
	private function checkExtensionConvertable($source)
	{
		$extension = substr($source, strrpos($source, '.') + 1);
		if (in_array($extension, ImageModel::PROCESSABLE_EXTENSIONS)) {
			return true;
		}
		return false;
	}

	/**
	 * Emit a single `<source>` element for the given companion format.
	 *
	 * Chooses `srcset` prefix when the original image had a srcset,
	 * otherwise the `src` prefix — so the emitted element uses the same
	 * lazy-loading convention (`data-lazy-srcset`, `data-srcset`, or plain
	 * `srcset`) as the original.
	 *
	 * @param string[] $sources    Companion URLs to attach as `srcset`.
	 * @param string   $fileFormat 'webp' or 'avif'.
	 * @return string The `<source ...>` markup.
	 */
	protected function buildSource($sources, $fileFormat)
	{

		$prefix = (isset($this->dataTags['srcset'])) ? $this->dataTags['srcset'] : $this->dataTags['src'];
		$srcset = implode(',', $sources);

		$sizeOutput = '';
		if (! is_null($this->sizes)) {
			$sizeOutput = $this->dataTags['sizes'] . 'sizes="' . $this->sizes . '"';
		}

		$output = '<source ' . $prefix . 'srcset="' . $srcset . '" ' . $sizeOutput . ' type="image/' . $fileFormat . '">';

		return $output;
	}

	/**
	 * Rebuild the original `<img>` element, preserving the standard
	 * attributes plus any extra ones the caller had on the source markup.
	 *
	 * Rules:
	 *   - `id`, `height`, `width`, `srcset`, `sizes`, `class` are only
	 *     emitted when non-null.
	 *   - `alt` is ALWAYS emitted (even empty) for screen-reader compatibility.
	 *   - Any other attribute (e.g. `data-*`, custom tags) that wasn't
	 *     already handled by getImageAttributes()'s deny-list is passed
	 *     through untouched.
	 *
	 * Values are run through `esc_attr` for output safety.
	 *
	 * @return string The `<img ...>` markup.
	 */
	public function buildImage()
	{

		// Rebuild by iterating the original attributes in insertion order
		// so we preserve ordering and any custom/data-* attributes. If an
		// attribute has been updated on the object (e.g. alt/src/srcset)
		// prefer the object value.
		$output = '<img';
		$seen = array();

		foreach ($this->attributes as $name => $origValue) {
			$seen[$name] = true;
			// Determine the effective value: prefer declared property when
			// available, otherwise fall back to the original attribute value.
			if (property_exists($this, $name) && ! is_null($this->{$name})) {
				$value = $this->{$name};
			} else {
				$value = $origValue;
			}

			// For `src` ensure it's escaped so entities like &amp; are preserved.
			if ($name === 'src') {
				$value = \esc_attr($value);
				$output .= ' src="' . $value . '"';
				continue;
			}

			// Boolean / value-less attributes should be emitted without an ="".
			// Fix - Don't do this for the ALT tag since it ends up invalid.
			if ( ($value === '' || is_null($value)) && $name !== 'alt' ) {
				$output .= ' ' . $name;
				continue;
			}

			$output .= ' ' . $name . '="' . \esc_attr($value) . '"';
		}

		// Ensure alt is always present (even if it wasn't part of the original)
		if (! isset($seen['alt'])) {
			$output .= ' alt="' . \esc_attr($this->alt) . '"';
		}

		// Any leftover attributes that were not present in the original map
		// (unlikely) are appended now. This keeps behavior stable.
		$leftAttrs = $this->getImageAttributes();
		foreach ($leftAttrs as $name => $value) {
			if (isset($seen[$name])) {
				continue;
			}
			if ($value === '' || is_null($value)) {
				$output .= ' ' . $name;
			} else {
				$output .= ' ' . $name . '="' . \esc_attr($value) . '"';
			}
		}

		$output .= '>';

		return $output;
	}

	/**
	 * Return the "leftover" attributes from `$attributes` — everything the
	 * caller had on the original element that isn't part of the standard
	 * set already handled by buildImage().
	 *
	 * The deny-list covers `src`, `data-src`, `data-lazy-src`, `srcset`,
	 * `sizes`, plus the standard-attribute set (id, alt, height, width,
	 * srcset, sizes, class) — these are all emitted explicitly by
	 * buildImage() so echoing them again would produce duplicates.
	 *
	 * @return array<string, string>
	 */
	protected function getImageAttributes()
	{

		$dontuse = array(
			'src',
			'data-src',
			'data-lazy-src',
			'srcset',
			'sizes'

		);
		$dontuse = array_merge($dontuse, array('id', 'alt', 'height', 'width', 'srcset', 'sizes', 'class'));

		$attributes = $this->attributes;

		$leftAttrs = array();
		foreach ($attributes as $name => $value) {
			if (! in_array($name, $dontuse)) {
				$leftAttrs[$name] = $value;
			}
		}

		return $leftAttrs;
	}

	/**
	 * Look up the effective value for a lazy-load-aware attribute
	 * (`src`, `srcset`, `sizes`) and record which prefix (`data-lazy-`,
	 * `data-`, or `''`) was matched.
	 *
	 * Priority order — first non-empty wins:
	 *   1. `data-lazy-<type>` — used by several popular lazy-loading plugins
	 *   2. `data-<type>` — the older WordPress-native lazyload convention
	 *   3. `<type>` — plain HTML attribute
	 *
	 * Populates `$dataTags[$type]` with the matched prefix so buildSource()
	 * can emit `<source>` with matching attribute names, keeping the
	 * lazy-loading plugin's swap logic intact.
	 *
	 * @param string $type Attribute base name — 'src', 'srcset' or 'sizes'.
	 * @return string|false The matched value, or false when none of the
	 *                      three variants were present.
	 */
	protected function getLazyData($type)
	{
		$attributes = $this->attributes;
		$value = $prefix = false;

		if (isset($attributes['data-lazy-' . $type]) && strlen($attributes['data-lazy-' . $type]) > 0) {
			$value = $attributes['data-lazy-' . $type];
			$prefix = 'data-lazy-';
		} elseif (isset($attributes['data-' . $type]) && strlen($attributes['data-' . $type]) > 0) {
			$value = $attributes['data-' . $type];
			$prefix = 'data-';
		} elseif (isset($attributes[$type]) && strlen($attributes[$type]) > 0) {
			$value = $attributes[$type];
			$prefix = '';
		}

		$this->dataTags[$type] = $prefix;

		return $value;
	}
} // class FrontImage
