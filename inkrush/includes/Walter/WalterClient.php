<?php
namespace ZZZCopy\Walter;

use ZZZCopy\Clock;
use ZZZCopy\Events;
use ZZZCopy\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * The connection to Walter Writes, through Walter's own MCP server.
 *
 * Walter's web app cannot be called from a web server: its API sits behind Cloudflare's bot
 * check, which refuses anything that is not a browser (checked 2026-11-16). Walter's MCP server
 * is made for other programs to call. The site owner signs in to Walter once and allows access,
 * in the same way Claude connects to it, and the words come out of the owner's Walter plan
 * (account info through this connection showed the Pro plan's own word balance).
 *
 * Standard OAuth 2.1: the site registers itself as a client (dynamic client registration), the
 * owner is sent to Walter with a PKCE challenge, and the code that comes back is exchanged for an
 * access token (one hour) and a refresh token. Both are stored encrypted, like the OpenRouter key.
 */
final class WalterClient {

	public const SERVER   = 'https://mcp-server.walterwrites.ai';
	public const RESOURCE = 'https://mcp-server.walterwrites.ai/mcp';

	private const OPTION    = 'zzzc_walter';
	private const STATE_TTL = 600;

	/** The access token is renewed when it has less than this long left. */
	private const RENEW_BEFORE = 300;

	/* ---- Stored connection ---- */

	public static function data(): array {
		$data = get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	private static function save( array $changes ): void {
		update_option( self::OPTION, array_merge( self::data(), $changes ), false );
	}

	public static function connected(): bool {
		$data = self::data();
		return ! empty( $data['refresh'] ) && empty( $data['needs_reconnect'] );
	}

	/** Whether the owner connected once and the connection has since stopped working. */
	public static function needs_reconnect(): bool {
		return ! empty( self::data()['needs_reconnect'] );
	}

	public static function callback_url(): string {
		return admin_url( 'admin-post.php?action=zzzc_walter_callback' );
	}

	/* ---- Connecting ---- */

	/**
	 * The address of Walter's sign-in page for this site.
	 *
	 * @return string|\WP_Error
	 */
	public static function start_url() {
		$client = self::client_id();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$state    = wp_generate_password( 40, false, false );
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		set_transient(
			self::state_key( $state ),
			array(
				'user_id'  => (int) get_current_user_id(),
				'verifier' => Secrets::encrypt_value( $verifier ),
				'issued'   => Clock::now(),
			),
			self::STATE_TTL
		);

		return add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => rawurlencode( $client ),
				'redirect_uri'          => rawurlencode( self::callback_url() ),
				'scope'                 => rawurlencode( 'tools:read tools:write' ),
				'state'                 => $state,
				'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'code_challenge_method' => 'S256',
				'resource'              => rawurlencode( self::RESOURCE ),
			),
			self::SERVER . '/oauth/authorize'
		);
	}

	/**
	 * This site's client identifier at Walter, registered once for the current callback address.
	 *
	 * @return string|\WP_Error
	 */
	private static function client_id() {
		$data = self::data();
		if ( ! empty( $data['client_id'] ) && ( $data['redirect_uri'] ?? '' ) === self::callback_url() ) {
			return (string) $data['client_id'];
		}
		$response = wp_remote_post(
			self::SERVER . '/oauth/register',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'client_name'                => 'InkRush on ' . wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
						'redirect_uris'              => array( self::callback_url() ),
						'grant_types'                => array( 'authorization_code', 'refresh_token' ),
						'response_types'             => array( 'code' ),
						'token_endpoint_auth_method' => 'none',
						'scope'                      => 'tools:read tools:write',
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'walter_network', 'Walter could not be reached: ' . $response->get_error_message() );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['client_id'] ) ) {
			return new \WP_Error( 'walter_register', sprintf( 'Walter did not register this site (HTTP %d).', (int) wp_remote_retrieve_response_code( $response ) ) );
		}
		self::save(
			array(
				'client_id'    => (string) $body['client_id'],
				'redirect_uri' => self::callback_url(),
			)
		);
		return (string) $body['client_id'];
	}

	/**
	 * Finishes the sign-in with the code Walter sent back.
	 *
	 * @return true|\WP_Error
	 */
	public static function finish( string $code, string $state ) {
		$state = trim( $state );
		if ( ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $state ) ) {
			return new \WP_Error( 'walter_state', 'The sign-in did not carry the one-time value this site issued.' );
		}
		$stored = get_transient( self::state_key( $state ) );
		delete_transient( self::state_key( $state ) );
		if ( ! is_array( $stored ) || (int) ( $stored['user_id'] ?? 0 ) !== (int) get_current_user_id() || Clock::now() - (int) ( $stored['issued'] ?? 0 ) > self::STATE_TTL ) {
			return new \WP_Error( 'walter_state', 'The sign-in was unknown, already used, started by another user, or more than ten minutes old.' );
		}
		$tokens = self::token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::callback_url(),
				'client_id'     => (string) ( self::data()['client_id'] ?? '' ),
				'code_verifier' => Secrets::decrypt_value( (string) ( $stored['verifier'] ?? '' ) ),
				'resource'      => self::RESOURCE,
			)
		);
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		self::store_tokens( $tokens );
		self::save(
			array(
				'connected_at'    => Clock::mysql(),
				'needs_reconnect' => 0,
				'last_error'      => '',
			)
		);
		delete_transient( 'zzzc_walter_reconnect_mailed' );
		self::account();
		return true;
	}

	public static function disconnect(): void {
		$data    = self::data();
		$refresh = Secrets::decrypt_value( (string) ( $data['refresh'] ?? '' ) );
		if ( '' !== $refresh && ! empty( $data['client_id'] ) ) {
			wp_remote_post(
				self::SERVER . '/oauth/revoke',
				array(
					'timeout' => 15,
					'body'    => array(
						'token'     => $refresh,
						'client_id' => (string) $data['client_id'],
					),
				)
			);
		}
		// The client registration is kept, so connecting again does not register the site twice.
		self::save(
			array(
				'access'          => '',
				'refresh'         => '',
				'expires_at'      => 0,
				'needs_reconnect' => 0,
				'connected_at'    => '',
				'credits'         => null,
			)
		);
	}

	private static function state_key( string $state ): string {
		return 'zzzc_walter_state_' . hash( 'sha256', $state );
	}

	/**
	 * @return array|\WP_Error The token response.
	 */
	private static function token_request( array $params ) {
		$response = wp_remote_post(
			self::SERVER . '/oauth/token',
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $params,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'walter_network', 'Walter could not be reached: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || empty( $body['access_token'] ) ) {
			$code = is_array( $body ) ? (string) ( $body['error'] ?? '' ) : '';
			return new \WP_Error( 'invalid_grant' === $code || 'invalid_client' === $code ? 'walter_reconnect' : 'walter_token', sprintf( 'Walter refused the sign-in token (HTTP %1$d%2$s).', $status, '' !== $code ? ', ' . $code : '' ) );
		}
		return $body;
	}

	private static function store_tokens( array $tokens ): void {
		Secrets::guard( (string) $tokens['access_token'] );
		$changes = array(
			'access'     => Secrets::encrypt_value( (string) $tokens['access_token'] ),
			'expires_at' => Clock::now() + max( 60, (int) ( $tokens['expires_in'] ?? 3600 ) ),
		);
		// Walter may rotate the refresh token on each renewal; the newest one is always kept.
		if ( ! empty( $tokens['refresh_token'] ) ) {
			$changes['refresh'] = Secrets::encrypt_value( (string) $tokens['refresh_token'] );
		}
		self::save( $changes );
	}

	/**
	 * A current access token, renewed first when it is about to run out.
	 *
	 * @return string|\WP_Error
	 */
	private static function access_token( bool $force_renew = false ) {
		$data = self::data();
		if ( empty( $data['refresh'] ) ) {
			return new \WP_Error( 'walter_not_connected', 'Walter is not connected.' );
		}
		if ( ! empty( $data['needs_reconnect'] ) ) {
			return new \WP_Error( 'walter_reconnect', 'The Walter connection stopped working and needs to be connected again.' );
		}
		$access = Secrets::decrypt_value( (string) ( $data['access'] ?? '' ) );
		if ( ! $force_renew && '' !== $access && (int) ( $data['expires_at'] ?? 0 ) - Clock::now() > self::RENEW_BEFORE ) {
			Secrets::guard( $access );
			return $access;
		}

		$refresh = Secrets::decrypt_value( (string) $data['refresh'] );
		if ( '' === $refresh ) {
			self::lost( 'The saved Walter sign-in can no longer be read, because the WordPress salts or ZZZCOPY_SECRET_KEY changed.' );
			return new \WP_Error( 'walter_reconnect', 'The saved Walter sign-in can no longer be read.' );
		}
		$tokens = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => (string) ( $data['client_id'] ?? '' ),
				'resource'      => self::RESOURCE,
			)
		);
		if ( is_wp_error( $tokens ) ) {
			if ( 'walter_reconnect' === $tokens->get_error_code() ) {
				self::lost( 'Walter no longer accepts this site\'s sign-in. ' . $tokens->get_error_message() );
			}
			return $tokens;
		}
		self::store_tokens( $tokens );
		return (string) $tokens['access_token'];
	}

	/**
	 * The connection stopped working. Logged each time, emailed once a day.
	 */
	private static function lost( string $why ): void {
		self::save(
			array(
				'needs_reconnect' => 1,
				'last_error'      => $why,
			)
		);
		Events::log( 'humanizer', 'error', $why . ' Posts go out with the AI\'s own wording until Walter is connected again on the AI Settings page.', array( 'error_code' => 'walter_reconnect' ) );
		if ( get_transient( 'zzzc_walter_reconnect_mailed' ) ) {
			return;
		}
		set_transient( 'zzzc_walter_reconnect_mailed', 1, DAY_IN_SECONDS );
		$to = (string) get_option( 'admin_email' );
		if ( '' !== $to ) {
			wp_mail(
				$to,
				sprintf( '[%s] Connect Walter Writes again', wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
				$why . "\n\n"
					. "Posts still go out on time, with the AI's own wording, but they are not humanized until you connect Walter again:\n"
					. admin_url( 'admin.php?page=zzzc-ai#zzzc-walter' ) . "\n\n"
					. 'Open that page, click Connect Walter, sign in and click Allow.'
			);
		}
	}

	/* ---- Calling Walter's tools ---- */

	/**
	 * Calls one of Walter's tools.
	 *
	 * @return array{ok:bool, data:array, code:string, message:string, temporary:bool, words_left:?int}
	 */
	public static function call( string $tool, array $arguments, int $timeout = 120 ): array {
		$fail = static function ( string $code, string $message, bool $temporary ): array {
			return array( 'ok' => false, 'data' => array(), 'code' => $code, 'message' => $message, 'temporary' => $temporary, 'words_left' => null );
		};

		$response = null;
		for ( $try = 0; $try < 2; $try++ ) {
			$token = self::access_token( $try > 0 );
			if ( is_wp_error( $token ) ) {
				$code = (string) $token->get_error_code();
				return $fail( $code, $token->get_error_message(), 'walter_network' === $code || 'walter_token' === $code );
			}
			$response = wp_remote_post(
				self::RESOURCE,
				array(
					'timeout' => $timeout,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json, text/event-stream',
						'User-Agent'    => 'InkRush/' . ZZZC_VERSION,
					),
					'body'    => (string) wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => wp_rand( 1, 999999 ),
							'method'  => 'tools/call',
							'params'  => array(
								'name'      => $tool,
								'arguments' => (object) $arguments,
							),
						)
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $fail( 'walter_network', 'Walter did not answer: ' . $response->get_error_message(), true );
			}
			// An access token Walter no longer accepts is renewed once.
			if ( 401 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				break;
			}
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$left   = wp_remote_retrieve_header( $response, 'x-walter-words-remaining' );
		if ( 401 === $status ) {
			self::lost( 'Walter refused this site\'s sign-in even after renewing it.' );
			return $fail( 'walter_reconnect', 'Walter refused the sign-in.', false );
		}
		if ( 429 === $status || $status >= 500 ) {
			return $fail( 'walter_http_' . $status, sprintf( 'Walter is busy or unavailable (HTTP %d).', $status ), true );
		}
		if ( 200 !== $status ) {
			return $fail( 'walter_http_' . $status, sprintf( 'Walter answered with HTTP %d.', $status ), false );
		}

		$message = self::parse_rpc( (string) wp_remote_retrieve_body( $response ) );
		if ( isset( $message['error'] ) ) {
			return $fail( 'walter_rpc', 'Walter refused the request: ' . (string) ( $message['error']['message'] ?? 'unknown error' ), false );
		}
		$result = (array) ( $message['result'] ?? array() );
		$text   = '';
		foreach ( (array) ( $result['content'] ?? array() ) as $part ) {
			if ( 'text' === ( $part['type'] ?? '' ) ) {
				$text .= (string) ( $part['text'] ?? '' );
			}
		}
		$data = isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ? $result['structuredContent'] : json_decode( $text, true );
		if ( ! empty( $result['isError'] ) ) {
			return $fail( 'walter_tool', 'Walter: ' . substr( '' !== $text ? $text : 'the tool failed.', 0, 300 ), false );
		}
		if ( ! is_array( $data ) ) {
			return $fail( 'walter_format', 'Walter\'s answer was not in the expected form.', false );
		}
		return array(
			'ok'         => true,
			'data'       => $data,
			'code'       => '',
			'message'    => '',
			'temporary'  => false,
			'words_left' => is_numeric( $left ) ? (int) $left : null,
		);
	}

	/**
	 * The JSON-RPC message in an answer, which comes either as JSON or as a server-sent event stream.
	 */
	public static function parse_rpc( string $body ): array {
		$body = trim( $body );
		if ( '' !== $body && '{' === $body[0] ) {
			$json = json_decode( $body, true );
			return is_array( $json ) ? $json : array();
		}
		$found = array();
		foreach ( preg_split( '/\r?\n\r?\n/', $body ) as $event ) {
			$data = '';
			foreach ( preg_split( '/\r?\n/', (string) $event ) as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$data .= ltrim( substr( $line, 5 ) );
				}
			}
			$json = json_decode( $data, true );
			if ( is_array( $json ) && ( isset( $json['result'] ) || isset( $json['error'] ) ) ) {
				$found = $json;
			}
		}
		return $found;
	}

	/**
	 * The plan, the words left and the most one request may hold, read fresh from Walter and saved.
	 *
	 * @return array|\WP_Error
	 */
	public static function account() {
		$result = self::call( 'walter_account_info', array(), 20 );
		if ( ! $result['ok'] ) {
			self::save( array( 'last_error' => $result['message'] ) );
			return new \WP_Error( $result['code'], $result['message'] );
		}
		$data = $result['data'];
		self::save(
			array(
				'plan'       => sanitize_text_field( (string) ( $data['plan'] ?? '' ) ),
				'credits'    => isset( $data['credits_remaining'] ) ? (int) $data['credits_remaining'] : null,
				'max_words'  => isset( $data['max_words_per_request'] ) ? (int) $data['max_words_per_request'] : null,
				'checked_at' => Clock::mysql(),
				'last_error' => '',
			)
		);
		return $data;
	}

	/** Remembers the balance Walter reported after a request, without another call. */
	public static function note_balance( ?int $words_left ): void {
		if ( null !== $words_left ) {
			self::save( array( 'credits' => $words_left, 'checked_at' => Clock::mysql() ) );
		}
	}
}
