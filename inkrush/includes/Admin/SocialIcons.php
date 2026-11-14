<?php
namespace ZZZCopy\Admin;

use ZZZCopy\Social\Platforms;

defined( 'ABSPATH' ) || exit;

/**
 * The platform marks shown on the social media screens.
 *
 * Each one is a single-colour vector drawn inline in the page. Three reasons for that:
 *
 * The admin screens are monochrome, which is the owner's rule, so the marks take the colour of
 * the text around them with currentColor rather than bringing six brand palettes onto the page.
 *
 * Nothing is loaded from anywhere else. The plugin uses no content delivery network, no remote
 * fonts and no outside assets, so each mark is drawn here rather than fetched from a platform.
 *
 * They are shown next to each platform's name purely to identify it, which is what makes a
 * settings screen readable at a glance. They are not used as a badge of endorsement.
 */
final class SocialIcons {

	/**
	 * One platform's mark as inline markup, ready to print.
	 *
	 * @param string $platform One of the six.
	 * @param int    $size     Width and height in pixels.
	 */
	public static function mark( string $platform, int $size = 20 ): string {
		$paths = self::paths();
		if ( ! isset( $paths[ $platform ] ) ) {
			return '';
		}

		return sprintf(
			'<svg class="zzzc-social-mark" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" role="img">%2$s</svg>',
			max( 12, min( 64, $size ) ),
			$paths[ $platform ]
		);
	}

	/**
	 * A platform's mark followed by its name, which is how every heading on these screens reads.
	 */
	public static function label( string $platform, int $size = 20 ): string {
		return '<span class="zzzc-social-name">' . self::mark( $platform, $size ) . '<span>' . esc_html( Platforms::label( $platform ) ) . '</span></span>';
	}

	/**
	 * The drawings. Simplified to stay legible at the size they are shown.
	 *
	 * @return array<string, string>
	 */
	private static function paths(): array {
		return array(

			Platforms::FACEBOOK => '<path d="M22 12.06C22 6.49 17.52 2 12 2S2 6.49 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.23.2 2.23.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.45 2.91h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94z"/>',

			Platforms::INSTAGRAM => '<path d="M12 2c2.72 0 3.06.01 4.12.06 1.07.05 1.79.22 2.43.46.66.26 1.22.6 1.77 1.16.56.55.9 1.11 1.16 1.77.25.64.41 1.36.46 2.43.05 1.06.06 1.4.06 4.12s-.01 3.06-.06 4.12c-.05 1.07-.21 1.79-.46 2.43a4.9 4.9 0 0 1-1.16 1.77c-.55.56-1.11.9-1.77 1.16-.64.25-1.36.41-2.43.46-1.06.05-1.4.06-4.12.06s-3.06-.01-4.12-.06c-1.07-.05-1.79-.21-2.43-.46a4.9 4.9 0 0 1-1.77-1.16 4.9 4.9 0 0 1-1.16-1.77c-.25-.64-.41-1.36-.46-2.43C2.01 15.06 2 14.72 2 12s.01-3.06.06-4.12c.05-1.07.21-1.79.46-2.43.26-.66.6-1.22 1.16-1.77.55-.56 1.11-.9 1.77-1.16.64-.24 1.36-.41 2.43-.46C8.94 2.01 9.28 2 12 2zm0 1.8c-2.67 0-2.99.01-4.04.06-.98.05-1.51.2-1.86.34-.47.18-.8.4-1.15.75-.35.35-.57.68-.75 1.15-.14.35-.29.88-.34 1.86-.05 1.05-.06 1.37-.06 4.04s.01 2.99.06 4.04c.05.98.2 1.51.34 1.86.18.47.4.8.75 1.15.35.35.68.57 1.15.75.35.14.88.29 1.86.34 1.05.05 1.37.06 4.04.06s2.99-.01 4.04-.06c.98-.05 1.51-.2 1.86-.34.47-.18.8-.4 1.15-.75.35-.35.57-.68.75-1.15.14-.35.29-.88.34-1.86.05-1.05.06-1.37.06-4.04s-.01-2.99-.06-4.04c-.05-.98-.2-1.51-.34-1.86a3.1 3.1 0 0 0-.75-1.15 3.1 3.1 0 0 0-1.15-.75c-.35-.14-.88-.29-1.86-.34-1.05-.05-1.37-.06-4.04-.06zm0 3.07a5.13 5.13 0 1 1 0 10.26 5.13 5.13 0 0 1 0-10.26zm0 1.8a3.33 3.33 0 1 0 0 6.66 3.33 3.33 0 0 0 0-6.66zm5.34-3.2a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4z"/>',

			Platforms::X => '<path d="M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.65l-5.21-6.82-5.96 6.82H1.68l7.74-8.84L1.25 2.25h6.82l4.71 6.23 5.46-6.23zm-1.16 17.52h1.83L6.99 4.13H5.03l12.05 15.64z"/>',

			Platforms::LINKEDIN => '<path d="M20.45 2H3.55C2.69 2 2 2.69 2 3.54v16.92C2 21.31 2.69 22 3.55 22h16.9c.86 0 1.55-.69 1.55-1.54V3.54C22 2.69 21.31 2 20.45 2zM8.12 18.9H5.19V9.47h2.93v9.43zM6.65 8.17a1.7 1.7 0 1 1 0-3.4 1.7 1.7 0 0 1 0 3.4zm12.25 10.73h-2.92v-4.59c0-1.1-.02-2.5-1.53-2.5-1.53 0-1.76 1.2-1.76 2.42v4.67H9.78V9.47h2.8v1.29h.04c.39-.74 1.34-1.52 2.76-1.52 2.96 0 3.52 1.95 3.52 4.48v5.18z"/>',

			Platforms::THREADS => '<path d="M16.52 11.15c-.1-.05-.21-.1-.32-.14-.19-3.44-2.07-5.41-5.22-5.43h-.04c-1.89 0-3.46.8-4.42 2.27l1.73 1.19c.72-1.09 1.84-1.32 2.69-1.32h.03c1.06.01 1.86.32 2.38.92.38.44.63 1.05.76 1.82a13.7 13.7 0 0 0-2.4-.12c-2.72.16-4.47 1.74-4.35 3.95.06 1.12.62 2.08 1.57 2.71.81.53 1.85.79 2.93.73 1.43-.08 2.53-.62 3.3-1.62.58-.76.95-1.74 1.11-2.98.67.4 1.17.93 1.44 1.57.47 1.08.5 2.86-.95 4.31-1.27 1.27-2.79 1.82-5.09 1.84-2.55-.02-4.48-.84-5.73-2.43C4.28 16.9 3.68 14.76 3.66 12c.02-2.76.62-4.9 1.78-6.37 1.25-1.59 3.18-2.41 5.73-2.43 2.57.02 4.53.84 5.83 2.44.64.79 1.12 1.78 1.44 2.93l2.03-.54c-.39-1.42-1-2.65-1.84-3.68C17 1.3 14.49.21 11.18.19h-.01C7.86.21 5.38 1.31 3.79 3.45 2.38 5.35 1.65 7.99 1.63 12v.01c.02 4.01.75 6.65 2.16 8.55 1.59 2.14 4.08 3.24 7.38 3.26h.01c2.94-.02 5.01-.79 6.71-2.49 2.23-2.22 2.16-5.01 1.43-6.72-.53-1.23-1.54-2.23-2.8-2.96zm-5.42 4.63c-1.2.07-2.44-.47-2.5-1.63-.05-.86.61-1.82 2.57-1.93.22-.01.44-.02.66-.02.71 0 1.38.07 1.98.2-.22 2.8-1.54 3.32-2.71 3.38z"/>',

			Platforms::BLUESKY => '<path d="M6.34 4.4c2.31 1.74 4.79 5.26 5.7 7.15.91-1.89 3.39-5.41 5.7-7.15 1.67-1.25 4.37-2.22 4.37.86 0 .62-.35 5.17-.56 5.91-.72 2.57-3.34 3.22-5.67 2.83 4.07.69 5.11 2.99 2.87 5.28-4.24 4.36-6.1-1.09-6.57-2.49-.09-.26-.13-.38-.14-.28-.01-.1-.05.02-.14.28-.47 1.4-2.33 6.85-6.57 2.49-2.24-2.29-1.2-4.59 2.87-5.28-2.33.39-4.95-.26-5.67-2.83C2.32 10.43 1.97 5.88 1.97 5.26c0-3.08 2.7-2.11 4.37-.86z"/>',
		);
	}
}
