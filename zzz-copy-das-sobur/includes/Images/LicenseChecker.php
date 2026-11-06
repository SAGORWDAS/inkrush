<?php
namespace ZZZCopy\Images;

use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Checks each file's own license data from Commons. Accepts only CC0, public domain, CC BY and,
 * unless it is turned off on the Images page, CC BY-SA.
 * Anything missing, unclear or conflicting is rejected. Nothing is assumed or invented.
 */
final class LicenseChecker {

	private static function field( array $meta, string $name ): string {
		$value = $meta[ $name ]['value'] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * @param array $meta The file's extmetadata.
	 * @return array{ok:bool, family:string, license:string, version:string, url:string, creator:string, creator_url:string, reason:string}
	 */
	public static function check( array $meta ): array {
		$out = array(
			'ok'          => false,
			'family'      => '',
			'license'     => '',
			'version'     => '',
			'url'         => '',
			'creator'     => '',
			'creator_url' => '',
			'reason'      => '',
		);

		$short       = self::field( $meta, 'LicenseShortName' );
		$code        = strtolower( self::field( $meta, 'License' ) );
		$url         = self::field( $meta, 'LicenseUrl' );
		$copyrighted = strtolower( self::field( $meta, 'Copyrighted' ) );
		$attribution = strtolower( self::field( $meta, 'AttributionRequired' ) );

		$creator               = self::creator( self::field( $meta, 'Artist' ) );
		$out['creator']        = $creator['name'];
		$out['creator_url']    = $creator['url'];
		$out['license']        = $short;

		if ( '' === $short ) {
			$out['reason'] = 'No license is stated.';
			return $out;
		}
		if ( 'true' === strtolower( self::field( $meta, 'NonFree' ) ) ) {
			$out['reason'] = 'The file is marked as not free.';
			return $out;
		}
		if ( '' !== self::field( $meta, 'Restrictions' ) ) {
			$out['reason'] = 'The file has extra restrictions: ' . self::field( $meta, 'Restrictions' ) . '.';
			return $out;
		}

		$lower = strtolower( $short );

		// Rejected families come first, so a name like "CC BY-NC 4.0" can never match CC BY.
		$refused = Settings::get( 'image_allow_share_alike' ) ? 'nc|nd' : 'sa|nc|nd';
		if ( preg_match( '/\b(' . $refused . ')\b/', $lower ) || preg_match( '/-(' . $refused . ')\b/', $code ) ) {
			$out['reason'] = 'This license is not on the approved list: ' . $short . '.';
			return $out;
		}

		if ( preg_match( '/^(cc0|cc-zero|cc zero|creative commons zero)/', $lower ) ) {
			if ( '' !== $code && 0 !== strpos( $code, 'cc0' ) && 0 !== strpos( $code, 'cc-zero' ) ) {
				$out['reason'] = 'The license fields disagree: ' . $short . ' and ' . $code . '.';
				return $out;
			}
			$out['ok']      = true;
			$out['family']  = 'cc0';
			$out['license'] = 'CC0';
			$out['version'] = '1.0';
			$out['url']     = self::valid_license_url( $url ) ?: 'https://creativecommons.org/publicdomain/zero/1.0/';
			return $out;
		}

		if ( preg_match( '/^public domain\b/', $lower ) ) {
			if ( 'true' === $copyrighted ) {
				$out['reason'] = 'The file says public domain but is also marked as copyrighted.';
				return $out;
			}
			if ( '' !== $code && 0 !== strpos( $code, 'pd' ) && 'public domain' !== $code ) {
				$out['reason'] = 'The license fields disagree: ' . $short . ' and ' . $code . '.';
				return $out;
			}
			if ( '' === self::field( $meta, 'UsageTerms' ) && '' === $code ) {
				$out['reason'] = 'The public domain status cannot be confirmed from the file data.';
				return $out;
			}
			$out['ok']      = true;
			$out['family']  = 'pd';
			$out['license'] = 'Public domain';
			$out['url']     = self::valid_license_url( $url );
			return $out;
		}

		if ( preg_match( '/^cc[ -]by([ -]sa)?[ -](\d+(?:\.\d+)?)(?:[ -]?[a-z]{2,3})?$/', $lower, $match ) ) {
			$family = '' !== $match[1] ? 'by-sa' : 'by';
			$name   = '' !== $match[1] ? 'CC BY-SA' : 'CC BY';
			if ( '' !== $code && 0 !== strpos( $code, 'cc-' . $family . '-' . $match[2] ) ) {
				$out['reason'] = 'The license fields disagree: ' . $short . ' and ' . $code . '.';
				return $out;
			}
			$valid_url = self::valid_license_url( $url );
			if ( '' === $valid_url || false === strpos( $valid_url, '/licenses/' . $family . '/' ) ) {
				$out['reason'] = 'The license address is missing or does not match ' . $name . '.';
				return $out;
			}
			if ( 'false' === $attribution ) {
				$out['reason'] = 'The attribution data conflicts with ' . $name . '.';
				return $out;
			}
			if ( '' === $creator['name'] || preg_match( '/^(unknown|anonymous|unknown author|author unknown)\b/i', $creator['name'] ) ) {
				$out['reason'] = $name . ' needs a named creator, and none is stated.';
				return $out;
			}
			$out['ok']      = true;
			$out['family']  = $family;
			$out['license'] = $name . ' ' . $match[2];
			$out['version'] = $match[2];
			$out['url']     = $valid_url;
			return $out;
		}

		$out['reason'] = 'This license is not on the approved list: ' . $short . '.';
		return $out;
	}

	private static function valid_license_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
			return '';
		}
		$url  = (string) preg_replace( '#^//#', 'https://', $url );
		$url  = (string) preg_replace( '#^http://#i', 'https://', $url );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return in_array( $host, array( 'creativecommons.org', 'www.creativecommons.org' ), true ) ? $url : '';
	}

	/**
	 * Reads the creator's name and link from the Artist field, which holds HTML.
	 *
	 * @return array{name:string, url:string}
	 */
	public static function creator( string $artist_html ): array {
		$url = '';
		if ( preg_match( '/<a\b[^>]*href=["\']([^"\']+)["\']/i', $artist_html, $match ) ) {
			$href = html_entity_decode( $match[1], ENT_QUOTES );
			$href = 0 === strpos( $href, '//' ) ? 'https:' . $href : $href;
			if ( preg_match( '#^https?://#i', $href ) ) {
				$url = esc_url_raw( $href );
			}
		}
		$name = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $artist_html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return array(
			'name' => mb_substr( $name, 0, 180 ),
			'url'  => $url,
		);
	}
}
