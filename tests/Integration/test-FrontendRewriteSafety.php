<?php
/**
 * Front-end rewriters versus markup written by untrusted users.
 *
 * PictureController (local WebP/AVIF delivery) and CDNController rewrite the
 * finished page with patterns and str_replace(). The page also holds text
 * WordPress has sanitised for people who may not write HTML freely: comments
 * from visitors, posts from Contributors and Authors. That text is harmless
 * as WordPress emits it; it stays harmless only while a rewriter never
 * deletes or moves a quote or a tag. A rewriter that removes the text between
 * two url( strings removes the closing quote of an attribute with it, and the
 * visitor's text after it becomes attributes (event handlers included).
 *
 * Every test here therefore compares what a parser sees BEFORE and AFTER the
 * rewriter: the same elements with the same attribute names, apart from the
 * <picture>/<source>/<img> elements the rewriter builds itself. Most inputs
 * go through the real WordPress pipeline first (wp_kses() for the comment or
 * post context, then the comment_text / the_content filters), so the tests
 * keep following what WordPress really lets through. The inline-style step
 * is also tested on markup no sanitiser has touched, because other plugins
 * and themes print user text with their own, looser filtering: there the
 * quotes of a url("…") value can be the delimiters of an HTML attribute.
 *
 * What is covered:
 *  - the known attack shape, for comments and for post content, in both
 *    rewriters;
 *  - markup between two url( strings is never deleted;
 *  - a few thousand generated inputs built from the pieces such attacks are
 *    made of (seeded, so a failure is reproducible), sanitised by WordPress
 *    and, for the inline-style step, not sanitised at all;
 *  - a control: a rewriter with an unbounded url() pattern IS caught by the
 *    same checks, so the checks cannot pass by accident;
 *  - the legitimate inline-style shapes that must keep being converted.
 *
 * When a new pattern or replacement is added to a front-end rewriter, add
 * its building blocks to tokens() / carriers() below.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Front\PictureController;
use ShortPixel\Controller\Front\CDNController;

/**
 * Control subject: a picture rewriter that replaces the whole matched
 * url( … ) text by a new, unquoted one, so every quote inside the match is
 * dropped. It exists only to prove that the checks of this file fail when a
 * rewriter removes a quote.
 */
class SPIO_QuoteDroppingPicture extends PictureController {

	public function __construct() {}

	protected function convertInlineStyle( $matches, $content ) {
		foreach ( $matches as $match ) {
			if ( 'jpg' !== pathinfo( $match['url'], PATHINFO_EXTENSION ) ) {
				continue;
			}
			$content = str_replace( $match['item'], 'url(' . str_replace( ' ', '%20', substr( $match['url'], 0, -4 ) ) . '.webp) ', $content );
		}

		return $content;
	}
}

/**
 * Control subject: the same, with a url() pattern that may also run across
 * quotes, tags and lines, so the match itself can hold markup.
 */
class SPIO_UnboundedUrlPatternPicture extends SPIO_QuoteDroppingPicture {

	protected function testInlineStyle( $content ) {
		preg_match_all( '/(url\(.*?\))(.*?)(?:;|\"|\')/is', $content, $matches );

		$filtered = array();
		foreach ( $matches[1] as $item ) {
			if ( 1 !== preg_match( '/url\((.*)\)/imU', $item, $url_match ) ) {
				continue;
			}
			$filtered[] = array(
				'item' => $item,
				'url'  => str_replace( array( '\'', '"' ), '', $url_match[1] ),
			);
		}

		return $this->convertInlineStyle( $filtered, $content );
	}
}

class FrontendRewriteSafetyTest extends SPIO_IntegrationTestCase {

	/** @var string[] Files created on disk, removed in tear_down. */
	private $createdFiles = array();

	public function tear_down() {
		foreach ( $this->createdFiles as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->createdFiles = array();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Upload the fixture image and put a .webp companion next to it.
	 *
	 * @return string URL of the image.
	 */
	private function imageWithWebp(): string {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$file          = get_attached_file( $attachment_id );
		$webp          = pathinfo( $file, PATHINFO_DIRNAME ) . '/' . pathinfo( $file, PATHINFO_FILENAME ) . '.webp';

		copy( $file, $webp );
		$this->assertFileExists( $webp, 'Companion fixture must exist on disk' );
		$this->createdFiles[] = $webp;

		return wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Put an image and its .webp companion in the uploads folder under a
	 * name WordPress would not produce itself (e.g. with a space).
	 *
	 * @param string $name File name with the .jpg extension.
	 * @return string URL of the image, with the name as given.
	 */
	private function imageWithWebpNamed( string $name ): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['path'] );
		$webp    = $dir . pathinfo( $name, PATHINFO_FILENAME ) . '.webp';

		$this->assertTrue( copy( $this->fixturePath( 'fixture-small.jpg' ), $dir . $name ) );
		$this->assertTrue( copy( $this->fixturePath( 'fixture-small.jpg' ), $webp ) );
		$this->createdFiles[] = $dir . $name;
		$this->createdFiles[] = $webp;

		return trailingslashit( $uploads['url'] ) . $name;
	}

	/** What WordPress shows for a comment a logged-out visitor posted. */
	private function asComment( string $raw ): string {
		return apply_filters( 'comment_text', wp_kses( $raw, 'pre_comment_content' ), null, array() );
	}

	/** What WordPress shows for a post written without unfiltered_html. */
	private function asPostContent( string $raw ): string {
		return apply_filters( 'the_content', wp_kses_post( $raw ) );
	}

	/** Run HTML through the local WebP/AVIF delivery entry point. */
	private function pictureRewrite( string $html ): string {
		$controller = new PictureController();
		return $controller->convertImgToPictureAddWebp( $html );
	}

	/** Run HTML through the control subject. */
	private function unboundedRewrite( string $html ): string {
		$controller = new SPIO_UnboundedUrlPatternPicture();
		return $controller->convertImgToPictureAddWebp( $html );
	}

	/**
	 * Run HTML through the inline-style step only. Used for markup that did
	 * not pass a sanitiser, where the <img> step is not the subject.
	 *
	 * @param string                 $html       Markup to rewrite.
	 * @param PictureController|null $controller Subject; the real controller by default.
	 */
	private function inlineStyleRewrite( string $html, $controller = null ): string {
		if ( null === $controller ) {
			$controller = new PictureController();
		}
		$method = new ReflectionMethod( PictureController::class, 'testInlineStyle' );
		$method->setAccessible( true );

		return $method->invoke( $controller, $html );
	}

	/** Run HTML through the CDN output-buffer callback. */
	private function cdnRewrite( string $html ): string {
		$ref        = new ReflectionClass( CDNController::class );
		$controller = $ref->newInstanceWithoutConstructor();
		$values     = array(
			'cdn_domain'       => 'https://cdn.example.com/spio/',
			'site_url'         => home_url(),
			'site_domain'      => parse_url( home_url(), PHP_URL_HOST ),
			'regex_exclusions' => array( '/data:image\/.*/' ),
			'content_is_json'  => false,
		);

		foreach ( $values as $name => $value ) {
			$owner = $ref;
			while ( ! $owner->hasProperty( $name ) ) {
				$owner = $owner->getParentClass();
			}
			$property = $owner->getProperty( $name );
			$property->setAccessible( true );
			$property->setValue( $controller, $value );
		}

		$method = new ReflectionMethod( CDNController::class, 'processFront' );
		$method->setAccessible( true );

		return $method->invoke( $controller, $html );
	}

	/**
	 * The elements and attribute names a parser sees, in document order.
	 *
	 * <picture>, <source> and <img> are left out: the picture rewriter builds
	 * those itself, with every attribute value escaped.
	 *
	 * @param string $html HTML fragment.
	 * @return string E.g. "p[] abbr[title] code[]".
	 */
	private function structure( string $html ): string {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$result = array();
		foreach ( $dom->getElementsByTagName( '*' ) as $element ) {
			if ( in_array( $element->nodeName, array( 'html', 'body', 'picture', 'source', 'img' ), true ) ) {
				continue;
			}
			$names = array();
			foreach ( $element->attributes as $attribute ) {
				$names[] = $attribute->nodeName;
			}
			sort( $names );
			$result[] = $element->nodeName . '[' . implode( ',', $names ) . ']';
		}

		return implode( ' ', $result );
	}

	/**
	 * The comment a visitor can post: a url( left open in an allowed
	 * attribute, and a real image url( followed by would-be attributes in
	 * text that WordPress does not texturize.
	 */
	private function attackShape( string $image_url ): string {
		$relative = preg_replace( '#^https?:#', '', $image_url );

		return "<abbr title=\"url(\n\">x</abbr><code>url(" . $relative . ')" style=display:block tabindex=1 autofocus onfocus=alert(document.domain) x=</code>';
	}

	// -------------------------------------------------------------------------
	// The known attack shape
	// -------------------------------------------------------------------------

	/**
	 * Precondition for everything below: WordPress itself leaves the attack
	 * shape harmless, with the would-be attributes as plain text in <code>.
	 */
	public function test_wordpress_emits_the_attack_shape_as_harmless_text() {
		$url = $this->imageWithWebp();

		foreach ( array( $this->asComment( $this->attackShape( $url ) ), $this->asPostContent( $this->attackShape( $url ) ) ) as $html ) {
			$this->assertSame( 'p[] abbr[title] code[]', $this->structure( $html ) );
			$this->assertStringContainsString( 'onfocus=alert(document.domain) x=</code>', $html, 'The payload must reach the rewriter intact, or the tests below prove nothing' );
			$this->assertStringContainsString( "title=\"url(\n\"", $html );
		}
	}

	public function test_picture_delivery_keeps_the_markup_of_a_comment() {
		$url  = $this->imageWithWebp();
		$html = $this->asComment( $this->attackShape( $url ) );

		$output = $this->pictureRewrite( $html );

		$this->assertStringContainsString( '.webp)" style=display:block', $output, 'Sentinel: the image url() in the comment text must have been rewritten, so the rewriter ran on this markup' );
		$this->assertSame( $this->structure( $html ), $this->structure( $output ), 'The rewriter must not add, remove or change an element or attribute' );
		$this->assertStringContainsString( "<abbr title=\"url(\n\">x</abbr><code>", $output, 'The markup between the two url( strings must survive' );
		$this->assertStringContainsString( 'x=</code>', $output );
	}

	public function test_picture_delivery_keeps_the_markup_of_post_content() {
		$url  = $this->imageWithWebp();
		$html = $this->asPostContent( $this->attackShape( $url ) );

		$output = $this->pictureRewrite( $html );

		$this->assertStringContainsString( '.webp)" style=display:block', $output, 'Sentinel: the rewriter ran on this markup' );
		$this->assertSame( $this->structure( $html ), $this->structure( $output ) );
		$this->assertStringContainsString( "<abbr title=\"url(\n\">x</abbr><code>", $output );
	}

	public function test_cdn_delivery_keeps_the_markup_of_a_comment_and_of_post_content() {
		$url = $this->imageWithWebp();

		foreach ( array( $this->asComment( $this->attackShape( $url ) ), $this->asPostContent( $this->attackShape( $url ) ) ) as $html ) {
			$output = $this->cdnRewrite( $html );

			$this->assertStringContainsString( 'url(https://cdn.example.com/spio/', $output, 'Sentinel: the image url() in the text must have been rewritten to the CDN' );
			$this->assertSame( $this->structure( $html ), $this->structure( $output ) );
			$this->assertStringContainsString( "<abbr title=\"url(\n\">x</abbr><code>", $output );
		}
	}

	/**
	 * Control: the same check fails for a rewriter whose url() pattern may
	 * span markup. If this test fails, structure() stopped seeing the
	 * difference and the tests of this file no longer protect anything.
	 */
	public function test_structure_check_catches_an_unbounded_url_pattern() {
		$url  = $this->imageWithWebp();
		$html = $this->asComment( $this->attackShape( $url ) );

		$output = $this->unboundedRewrite( $html );

		$this->assertSame( 'p[] abbr[title] code[]', $this->structure( $html ) );
		$this->assertSame( 'p[] abbr[autofocus,onfocus,style,tabindex,title,x]', $this->structure( $output ) );
	}

	// -------------------------------------------------------------------------
	// Markup between two url( strings
	// -------------------------------------------------------------------------

	/**
	 * A url( without its closing parenthesis, somewhere before a complete
	 * url(), must not make the rewriter swallow what stands between them.
	 */
	public function test_markup_between_an_open_and_a_complete_url_is_not_deleted() {
		$url      = $this->imageWithWebp();
		$relative = preg_replace( '#^https?:#', '', $url );
		$path     = parse_url( $url, PHP_URL_PATH );

		$inputs = array(
			'open url( in <pre>, complete url() in an attribute' => "<pre> style=x url( style=x </pre><abbr title=\"url(" . $relative . ')">x</abbr><b>kept</b>',
			'open url( in <code> with a line break'              => "<code>url(\n" . $path . '</code><abbr title="url(' . $path . ')">x</abbr><b>kept</b>',
			'open url( in an attribute, complete url() in the next' => "<abbr title=\"see url(\n\">x</abbr><b>kept</b><abbr title=\"url(" . $relative . ')">y</abbr>',
			'open url( in plain text'                            => "see url(\nfor details <b>kept</b> <abbr title=\"url(" . $relative . ')">x</abbr>',
		);

		foreach ( $inputs as $label => $raw ) {
			$control_caught = 0;

			foreach ( array( 'comment' => $this->asComment( $raw ), 'post' => $this->asPostContent( $raw ) ) as $context => $html ) {
				$this->assertStringContainsString( '<b>kept</b>', $html, "Precondition ($label, $context)" );

				foreach ( array( 'picture' => $this->pictureRewrite( $html ), 'cdn' => $this->cdnRewrite( $html ) ) as $rewriter => $output ) {
					$this->assertNotSame( $html, $output, "Sentinel: the $rewriter rewriter must have rewritten the complete url() ($label, $context)" );
					$this->assertSame( $this->structure( $html ), $this->structure( $output ), "$rewriter: $label, $context" );
					$this->assertStringContainsString( '<b>kept</b>', $output, "$rewriter: $label, $context" );
				}

				if ( $this->structure( $html ) !== $this->structure( $this->unboundedRewrite( $html ) ) ) {
					++$control_caught;
				}
			}

			$this->assertGreaterThan( 0, $control_caught, "Control: an unbounded pattern must lose markup for this input in at least one context ($label)" );
		}
	}

	// -------------------------------------------------------------------------
	// Generated inputs
	// -------------------------------------------------------------------------

	/**
	 * Pieces the generated values are built from.
	 *
	 * @param string $url URL of an image with a .webp companion.
	 * @return string[]
	 */
	private function tokens( string $url ): array {
		$relative = preg_replace( '#^https?:#', '', $url );
		$path     = parse_url( $url, PHP_URL_PATH );

		return array(
			'url(', 'url(', 'url(', 'url(', ')', ')', '(', '"', '"', "'", "\n", "\n", ' ', ';', '>', '<', '&quot;', '\\',
			$url, $relative, $relative, $path, $path, substr( $url, 0, -4 ), '.jpg', '.jpg)',
			'url(' . $relative . ')', 'url(' . $relative . ')', 'url(' . $path . ')', "url('" . $url . "')",
			' onfocus=alert(1) ', ' style=x ', ' autofocus onfocus=alert(1) x=', 'x',
		);
	}

	/**
	 * Markup the generated values are placed in; %s is the value.
	 *
	 * @param string $url URL of an image with a .webp companion.
	 * @return string[]
	 */
	private function carriers( string $url ): array {
		return array(
			'<abbr title="%s">x</abbr>', '<abbr title="%s">x</abbr>', '<abbr title="%s">x</abbr>', "<abbr title='%s'>x</abbr>",
			'<a href="%s">x</a>', '<a title="%s" href="#">x</a>', '<q cite="%s">x</q>', '<blockquote cite="%s">x</blockquote>',
			'<code>%s</code>', '<code>%s</code>', '<code>%s</code>', '<pre>%s</pre>', '<pre>%s</pre>', '%s', '%s', '<b>%s</b>',
			'<img src="' . $url . '" alt="%s">', '<img alt="%s" src="' . $url . '" class="a">',
			'<div style="%s">x</div>', '<span style="background:%s">x</span>', '<td background="%s">x</td>',
			'<picture><source srcset="%s"><img src="' . $url . '" alt="%s"></picture>', '<p title="%s" class="%s">x</p>',
			'<!-- %s -->', '[caption id="a" width="1"]%s[/caption]',
			"<abbr title=\"%s\n\">x</abbr><code>%s</code>", "<abbr title=\"%s\n\">x</abbr><code>%s</code>", "<code>%s\n</code><abbr title=\"%s\">x</abbr>",
		);
	}

	/**
	 * Build one input: two or three carriers, each filled with one to four
	 * random tokens.
	 */
	private function generate( array $tokens, array $carriers ): string {
		$raw   = '';
		$parts = mt_rand( 2, 3 );

		for ( $p = 0; $p < $parts; $p++ ) {
			$pieces = explode( '%s', $carriers[ mt_rand( 0, count( $carriers ) - 1 ) ] );
			$raw   .= $pieces[0];
			for ( $i = 1; $i < count( $pieces ); $i++ ) {
				$length = mt_rand( 1, 4 );
				for ( $t = 0; $t < $length; $t++ ) {
					$raw .= $tokens[ mt_rand( 0, count( $tokens ) - 1 ) ];
				}
				$raw .= $pieces[ $i ];
			}
		}

		return $raw;
	}

	/**
	 * No generated input, sanitised by WordPress as a comment or as post
	 * content, may come out of a rewriter with other elements or attributes
	 * than it went in with.
	 *
	 * The same inputs go through the control subject, which must be caught
	 * at least once: that proves the generator reaches the dangerous shapes.
	 * The run is seeded; a failure message carries the round and the input.
	 */
	public function test_generated_markup_keeps_its_structure_in_both_rewriters() {
		$this->assertSame( 0, get_current_user_id(), 'The inputs must be sanitised as for a visitor' );

		$url      = $this->imageWithWebp();
		$tokens   = $this->tokens( $url );
		$carriers = $this->carriers( $url );
		$rounds   = 30000;
		$changed  = array( 'picture' => 0, 'cdn' => 0 );
		$caught   = 0;

		// WordPress' own sanitiser raises deprecation notices on PHP 8.5 for
		// some of the generated character references; they are not the
		// subject here and would bury the result.
		$reporting = error_reporting( error_reporting() & ~E_DEPRECATED );

		mt_srand( 20261118 );

		for ( $round = 0; $round < $rounds; $round++ ) {
			$raw    = $this->generate( $tokens, $carriers );
			$html   = 0 === $round % 2 ? $this->asComment( $raw ) : $this->asPostContent( $raw );
			$before = null;

			foreach ( array( 'picture' => $this->pictureRewrite( $html ), 'cdn' => $this->cdnRewrite( $html ) ) as $rewriter => $output ) {
				if ( $output === $html ) {
					continue;
				}
				++$changed[ $rewriter ];
				if ( null === $before ) {
					$before = $this->structure( $html );
				}
				$after = $this->structure( $output );
				if ( $after !== $before ) {
					$this->fail( "The $rewriter rewriter changed the markup structure in round $round.\nInput:      $raw\nWordPress:  $html\nRewritten:  $output\nBefore: $before\nAfter:  $after" );
				}
			}

			$control = $this->unboundedRewrite( $html );
			if ( $control !== $html ) {
				if ( null === $before ) {
					$before = $this->structure( $html );
				}
				if ( $this->structure( $control ) !== $before ) {
					++$caught;
				}
			}
		}

		mt_srand();
		error_reporting( $reporting );

		$this->assertGreaterThan( 1000, $changed['picture'], 'Sentinel: the picture rewriter must have rewritten a good share of the inputs' );
		$this->assertGreaterThan( 1000, $changed['cdn'], 'Sentinel: the CDN rewriter must have rewritten a good share of the inputs' );
		$this->assertGreaterThan( 20, $caught, 'Control: the generator must produce inputs on which an unbounded pattern breaks the markup' );
	}

	// -------------------------------------------------------------------------
	// Markup no sanitiser has touched
	// -------------------------------------------------------------------------

	/**
	 * The quotes of url("…") can be the delimiters of an HTML attribute: the
	 * attribute ends at the first one and the next opens after the path. The
	 * rewriter must leave both in place, or the attribute runs on to the
	 * next quote in the page and the text after it becomes attributes.
	 *
	 * The byte-exact comparison is the rule itself. The parser checks ask only
	 * whether onfocus is an attribute, not for the full attribute list: what
	 * a parser makes of the path between the two quotes differs between
	 * libxml versions (older ones skip it, newer ones tokenize it like a
	 * browser into attributes named after the path segments). Each input is
	 * parsed with the quote that follows it in a page ($tail), because a
	 * browser-like tokenizer drops a tag whose last attribute value is never
	 * closed.
	 */
	public function test_quotes_that_delimit_an_attribute_are_kept() {
		$url  = $this->imageWithWebp();
		$path = parse_url( $url, PHP_URL_PATH );
		$webp = substr( $path, 0, -4 ) . '.webp';

		$inputs = array(
			'double quotes'            => array( '<abbr title="url(" %s ")>TEXT" onfocus=alert(1) x=">y</abbr>', '">' ),
			'single quotes'            => array( "<abbr title='url(' %s ')>TEXT' onfocus=alert(1) x='>y</abbr>", "'>" ),
			'no space around the path' => array( '<abbr title="url("%s")>TEXT" onfocus=alert(1) x=">y</abbr>', '">' ),
		);

		foreach ( $inputs as $label => $input ) {
			list( $shape, $tail ) = $input;

			$html   = sprintf( $shape, $path );
			$output = $this->inlineStyleRewrite( $html );

			$this->assertStringStartsWith( 'abbr[', $this->structure( $html . $tail ), "Precondition: the parser sees the element ($label)" );
			$this->assertStringNotContainsString( 'onfocus', $this->structure( $html . $tail ), "Precondition: onfocus is text, not an attribute ($label)" );
			$this->assertSame( sprintf( $shape, $webp ), $output, "Only the URL may change ($label)" );
			$this->assertStringStartsWith( 'abbr[', $this->structure( $output . $tail ), "The parser still sees the element ($label)" );
			$this->assertStringNotContainsString( 'onfocus', $this->structure( $output . $tail ), "onfocus is still text, not an attribute ($label)" );

			$control = $this->inlineStyleRewrite( $html, new SPIO_QuoteDroppingPicture() );
			$this->assertStringContainsString( 'onfocus', $this->structure( $control . $tail ), "Control: dropping the quotes turns the text into attributes ($label)" );
		}
	}

	/**
	 * The byte-level rule behind the test above: whatever the markup, the
	 * inline-style step leaves every character that steers an HTML parser
	 * (quotes, angle brackets, equals signs, whitespace) exactly where it
	 * was, in the same order. Inputs are random runs of the pieces attacks
	 * are made of, with no sanitiser in between; a parser's view of such
	 * fragments is too erratic to compare, the character sequence is not.
	 * The control subject must be caught.
	 */
	public function test_generated_unsanitised_markup_keeps_its_quotes_and_structure() {
		$url   = $this->imageWithWebp();
		$path  = parse_url( $url, PHP_URL_PATH );
		$spaced = $this->imageWithWebpNamed( 'safety space.jpg' );

		$tokens = array(
			'url(', 'url(', 'url(', ')', ')', '(', '"', '"', '"', "'", "'", "'", "\n", ' ', ' ', ';', '>', '<', '=', '&quot;', '\\',
			$path, $path, $url, "url('$path')", "url(\"$path\")", "url($path)", "url( '$path' )", $spaced, "url('$spaced')",
			' onfocus=alert(1) ', ' style=x ', ' x=', 'x', '<b>', '</b>', '<abbr title="', "<abbr title='", '<abbr title=', '">', "'>", '</abbr>',
			'<code>', '</code>', '<div style="background:', "<div style='background:", ' class="',
		);
		$count   = count( $tokens );
		$control = new SPIO_QuoteDroppingPicture();
		$changed = 0;
		$caught  = 0;

		mt_srand( 4242 );

		for ( $round = 0; $round < 60000; $round++ ) {
			$raw    = '';
			$length = mt_rand( 3, 12 );
			for ( $t = 0; $t < $length; $t++ ) {
				$raw .= $tokens[ mt_rand( 0, $count - 1 ) ];
			}

			$marks  = preg_replace( '/[^"\'<>=\s]/', '', $raw );
			$output = $this->inlineStyleRewrite( $raw );
			if ( $output !== $raw ) {
				++$changed;
				if ( preg_replace( '/[^"\'<>=\s]/', '', $output ) !== $marks ) {
					$this->fail( "The inline-style step moved a quote, bracket, equals sign or space in round $round.\nInput:     $raw\nRewritten: $output" );
				}
			}

			$controlled = $this->inlineStyleRewrite( $raw, $control );
			if ( $controlled !== $raw && preg_replace( '/[^"\'<>=\s]/', '', $controlled ) !== $marks ) {
				++$caught;
			}
		}

		mt_srand();

		$this->assertGreaterThan( 5000, $changed, 'Sentinel: the rewriter must have rewritten a good share of the inputs' );
		$this->assertGreaterThan( 100, $caught, 'Control: a rewriter that drops the wrapping quotes must be caught on many inputs' );
	}

	// -------------------------------------------------------------------------
	// Legitimate inline styles keep working
	// -------------------------------------------------------------------------

	/**
	 * The shapes themes and page builders write must still be converted, and
	 * only the URL may change: quotes, whitespace and everything around the
	 * url() value come out as written.
	 */
	public function test_legitimate_inline_backgrounds_are_converted() {
		$url  = $this->imageWithWebp();
		$webp = substr( $url, 0, -4 ) . '.webp';

		$shapes = array(
			'single quotes'      => '<div style="background-image: url(\'%s\');">c</div>',
			'no quotes'          => '<div style="background-image: url(%s);">c</div>',
			'double quotes'      => '<div style=\'background: url("%s") no-repeat center\'>c</div>',
			'inner spaces'       => '<div style="background:url( %s ) no-repeat">c</div>',
			'quotes and spaces'  => '<div style="background:url( \'%s\' ) no-repeat">c</div>',
			'inner line breaks'  => "<div style=\"background:url(\n%s\n)\">c</div>",
			'after a gradient'   => '<div style="background-image:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),url(%s)">c</div>',
			'two in one value'   => '<div style="background-image: url(\'%1$s\'), url(\'%1$s\');">c</div>',
			'style block'        => '<style>.a{background:url(%1$s)}.b{background-image:url("%1$s") !important}</style>',
			'next to a data URI' => '<div style="background:url(data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\'></svg>)">x</div><div style="background:url(%s)">c</div>',
		);

		foreach ( $shapes as $label => $shape ) {
			$this->assertSame( sprintf( $shape, $webp ), $this->pictureRewrite( sprintf( $shape, $url ) ), $label );
		}
	}

	/**
	 * Values the rewriter cannot resolve to a local image with a companion
	 * come out byte-identical.
	 */
	public function test_inline_backgrounds_that_cannot_be_converted_are_left_untouched() {
		$url = $this->imageWithWebp();

		$shapes = array(
			'entity-quoted value'   => '<div style="background-image: url(&quot;' . $url . '&quot;);">c</div>',
			'query string'          => '<div style="background:url(' . $url . '?ver=2)">c</div>',
			'other domain'          => '<div style="background:url(https://other.example.net/a/b.jpg)">c</div>',
			'JSON-escaped in script' => '<script>var s = {"bg":"url(' . str_replace( '/', '\\/', $url ) . ')"};</script>',
			'quote inside the value' => '<div style=\'background:url("' . substr( $url, 0, -4 ) . '\'.jpg")\'>c</div>',
			'unclosed url('          => '<div title="url(' . $url . '">c</div>',
		);

		foreach ( $shapes as $label => $html ) {
			$this->assertSame( $html, $this->pictureRewrite( $html ), $label );
		}
	}
}
