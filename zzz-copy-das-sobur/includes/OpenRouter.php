<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * OpenRouter API client. All calls are server-side. The key is never sent to the browser or logged.
 */
final class OpenRouter {

	public static function base_url(): string {
		return rtrim( (string) apply_filters( 'zzzc_openrouter_base_url', 'https://openrouter.ai/api/v1' ), '/' );
	}

	private static function headers(): array {
		return array(
			'Authorization' => 'Bearer ' . Secrets::get_api_key(),
			'Content-Type'  => 'application/json',
			'HTTP-Referer'  => home_url( '/' ),
			'X-Title'       => 'InkRush',
		);
	}

	/**
	 * Sends a chat completion.
	 *
	 * @param array[] $messages Chat messages.
	 * @param string  $model    Model identifier, used exactly as given.
	 * @return array{ok:bool, content:string, finish_reason:string, generation_id:string, request_id:string, usage:array, http:int, code:string, message:string, transient:bool, block:bool, retry_after:int, raw:string, model:string}
	 */
	public static function chat( array $messages, string $model, ?int $max_tokens = null ): array {
		$body = array(
			'model'    => $model,
			'messages' => $messages,
			'usage'    => array( 'include' => true ),
		);
		if ( null !== $max_tokens ) {
			$body['max_tokens'] = $max_tokens;
		}

		$response = wp_remote_post(
			self::base_url() . '/chat/completions',
			array(
				'timeout' => (int) Settings::get( 'ai_timeout' ),
				'headers' => self::headers(),
				'body'    => wp_json_encode( $body ),
			)
		);

		$out = array(
			'ok'            => false,
			'content'       => '',
			'finish_reason' => '',
			'generation_id' => '',
			'request_id'    => '',
			'usage'         => array(),
			'http'          => 0,
			'code'          => '',
			'message'       => '',
			'transient'     => false,
			'block'         => false,
			'retry_after'   => 0,
			'raw'           => '',
			'model'         => $model,
		);

		if ( is_wp_error( $response ) ) {
			$out['code']      = 'network_error';
			$out['message']   = 'Could not reach OpenRouter: ' . $response->get_error_message();
			$out['transient'] = true;
			return $out;
		}

		$out['http']        = (int) wp_remote_retrieve_response_code( $response );
		$out['raw']         = Secrets::redact( substr( (string) wp_remote_retrieve_body( $response ), 0, 200000 ) );
		$out['retry_after'] = Http::parse_retry_after( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		$request_id         = wp_remote_retrieve_header( $response, 'x-request-id' );
		$out['request_id']  = is_array( $request_id ) ? (string) reset( $request_id ) : (string) $request_id;

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $out['http'] < 200 || $out['http'] >= 300 || ( is_array( $data ) && isset( $data['error'] ) ) ) {
			$error_code = $out['http'];
			$message    = '';
			if ( is_array( $data ) && isset( $data['error'] ) ) {
				$error_code = (int) ( $data['error']['code'] ?? $out['http'] );
				$message    = (string) ( $data['error']['message'] ?? '' );
			}
			return array_merge( $out, self::classify( $error_code ?: $out['http'], $message ) );
		}

		if ( ! is_array( $data ) ) {
			$out['code']      = 'malformed_response';
			$out['message']   = 'OpenRouter returned a response that could not be read.';
			$out['transient'] = true;
			return $out;
		}

		$out['generation_id'] = (string) ( $data['id'] ?? '' );
		$out['usage']         = is_array( $data['usage'] ?? null ) ? $data['usage'] : array();
		$out['model']         = (string) ( $data['model'] ?? $model );

		$choice = $data['choices'][0] ?? null;
		if ( is_array( $choice ) && isset( $choice['error'] ) ) {
			return array_merge( $out, self::classify( (int) ( $choice['error']['code'] ?? 502 ), (string) ( $choice['error']['message'] ?? '' ) ) );
		}

		$content = $choice['message']['content'] ?? '';
		if ( is_array( $content ) ) {
			$parts = array();
			foreach ( $content as $part ) {
				if ( isset( $part['text'] ) ) {
					$parts[] = (string) $part['text'];
				}
			}
			$content = implode( '', $parts );
		}

		$out['content']       = (string) $content;
		$out['finish_reason'] = (string) ( $choice['finish_reason'] ?? '' );

		if ( '' === trim( $out['content'] ) ) {
			$out['code']      = 'empty_response';
			$out['message']   = 'The model returned an empty response.';
			$out['transient'] = true;
			return $out;
		}

		$out['ok'] = true;
		return $out;
	}

	/**
	 * Maps an API error to a clear message and the right recovery action.
	 *
	 * @return array{code:string, message:string, transient:bool, block:bool}
	 */
	public static function classify( int $status, string $provider_message ): array {
		$detail = '' !== $provider_message ? ' Provider said: ' . Secrets::redact( substr( $provider_message, 0, 300 ) ) : '';
		$lower  = strtolower( $provider_message );

		if ( 401 === $status ) {
			return array(
				'code'      => 'invalid_key',
				'message'   => 'OpenRouter rejected the API key. Enter a valid key on the AI Settings page.' . $detail,
				'transient' => false,
				'block'     => true,
			);
		}
		if ( 402 === $status ) {
			return array(
				'code'      => 'insufficient_credits',
				'message'   => 'Your OpenRouter account has too few credits. Add credits, then press Resume on the Dashboard.' . $detail,
				'transient' => false,
				'block'     => true,
			);
		}
		if ( 404 === $status || ( 400 === $status && ( false !== strpos( $lower, 'not a valid model' ) || false !== strpos( $lower, 'model' ) && false !== strpos( $lower, 'not' ) && false !== strpos( $lower, 'exist' ) ) ) ) {
			return array(
				'code'      => 'model_unavailable',
				'message'   => 'The configured model is not available on OpenRouter. It was not replaced with another model. Choose or confirm a model on the AI Settings page.' . $detail,
				'transient' => false,
				'block'     => true,
			);
		}
		if ( 403 === $status ) {
			return array(
				'code'      => 'permission_denied',
				'message'   => 'OpenRouter refused this request. The key may lack permission, or the content may have been flagged.' . $detail,
				'transient' => false,
				'block'     => false,
			);
		}
		if ( 429 === $status ) {
			return array(
				'code'      => 'rate_limited',
				'message'   => 'OpenRouter rate limit reached. The job will try again later.' . $detail,
				'transient' => true,
				'block'     => false,
			);
		}
		if ( 408 === $status || $status >= 500 ) {
			return array(
				'code'      => 'provider_error',
				'message'   => sprintf( 'OpenRouter or the model provider had a temporary problem (HTTP %d).', $status ) . $detail,
				'transient' => true,
				'block'     => false,
			);
		}
		return array(
			'code'      => 'request_rejected',
			'message'   => sprintf( 'OpenRouter rejected the request (HTTP %d).', $status ) . $detail,
			'transient' => false,
			'block'     => false,
		);
	}

	/**
	 * Checks the API key and that the exact model exists. Never substitutes a model.
	 *
	 * @return array{ok:bool, message:string, code:string, label:string}
	 */
	public static function verify_setup( ?string $model = null ): array {
		$model = $model ?? (string) Settings::get( 'model_id' );
		$out   = array(
			'ok'      => false,
			'message' => '',
			'code'    => '',
			'label'   => '',
		);

		$args = array(
			'timeout' => 20,
			'headers' => self::headers(),
		);

		$key_response = wp_remote_get( self::base_url() . '/key', $args );
		if ( is_wp_error( $key_response ) ) {
			return self::verify_network_fallback( $model, $key_response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $key_response );
		if ( 200 !== $status ) {
			if ( $status >= 500 ) {
				return self::verify_network_fallback( $model, sprintf( 'HTTP %d', $status ) );
			}
			$class          = self::classify( $status, '' );
			$out['code']    = $class['code'];
			$out['message'] = $class['message'];
			return $out;
		}

		$key_data     = json_decode( (string) wp_remote_retrieve_body( $key_response ), true );
		$out['label'] = (string) ( $key_data['data']['label'] ?? '' );

		$models_response = wp_remote_get( self::base_url() . '/models', $args );
		if ( is_wp_error( $models_response ) || 200 !== (int) wp_remote_retrieve_response_code( $models_response ) ) {
			return self::verify_network_fallback( $model, 'The model list could not be read.' );
		}

		$models = json_decode( (string) wp_remote_retrieve_body( $models_response ), true );
		$found  = false;
		foreach ( (array) ( $models['data'] ?? array() ) as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $model ) {
				$found = true;
				break;
			}
		}

		update_option(
			'zzzc_model_check',
			array(
				'model' => $model,
				'ok'    => $found,
				'at'    => Clock::now(),
			),
			false
		);

		if ( ! $found ) {
			$out['code']    = 'model_unavailable';
			$out['message'] = sprintf( 'Model "%s" is not available on OpenRouter. It was not replaced with another model. Choose or confirm a model on the AI Settings page.', $model );
			return $out;
		}

		$out['ok']      = true;
		$out['message'] = sprintf( 'Connected. The API key works and model "%s" is available.', $model );
		return $out;
	}

	/**
	 * A temporary outage should not stop a Start if this exact model was confirmed in the last 7 days.
	 */
	private static function verify_network_fallback( string $model, string $detail ): array {
		$check = get_option( 'zzzc_model_check' );
		if ( is_array( $check ) && ! empty( $check['ok'] ) && $check['model'] === $model && Clock::now() - (int) $check['at'] < WEEK_IN_SECONDS ) {
			return array(
				'ok'      => true,
				'message' => 'OpenRouter could not be reached just now, but this model was confirmed recently. Detail: ' . $detail,
				'code'    => 'network_error',
				'label'   => '',
			);
		}
		return array(
			'ok'      => false,
			'message' => 'OpenRouter could not be reached, so the API key and model could not be confirmed. Detail: ' . $detail,
			'code'    => 'network_error',
			'label'   => '',
		);
	}

	/**
	 * Reads the reported statistics for a past generation.
	 *
	 * @return array|null Generation data, or null when not available yet.
	 */
	public static function generation( string $generation_id ): ?array {
		$response = wp_remote_get(
			self::base_url() . '/generation?id=' . rawurlencode( $generation_id ),
			array(
				'timeout' => 20,
				'headers' => self::headers(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data['data'] ?? null ) ? $data['data'] : null;
	}
}
