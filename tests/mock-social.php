<?php
/**
 * Local stand-in for the six social media platforms, reached over real HTTP.
 *
 * Included by mock-server.php. Requests arrive at /social/{platform}/{the platform's own path},
 * which is where the zzzc_social_api_url filter in run.php points the adapters.
 *
 * Every request is recorded in mock-state/social.jsonl so a test can check exactly what was
 * sent: which address, which method, which fields, and which credential. Behaviour is set from
 * mock-state/state.json under social_mode, either one value or a list used one at a time:
 *
 *   ok           Publish successfully and return an identifier.
 *   no_id        Accept the post but return no identifier, so the plugin must not call it a success.
 *   401          Refuse the credential.
 *   429          Rate limit, with a Retry-After header.
 *   500          A temporary platform problem.
 *   hang         Close the connection without answering, so the plugin sees a timeout.
 *   duplicate    Refuse as a duplicate, which is what X does.
 *   container_error  Fail while preparing a post, as Instagram does when it cannot fetch a picture.
 *   exists       Report the post in the account's recent posts, for reconciliation.
 */

$social_log = $dir . '/social.jsonl';

/**
 * Records one request so a test can assert on it.
 */
function zzzc_social_record( string $platform, string $path, string $method, array $fields, string $auth ): void {
	global $social_log;
	file_put_contents(
		$social_log,
		json_encode(
			array(
				'platform' => $platform,
				'path'     => $path,
				'method'   => $method,
				'fields'   => $fields,
				// Only whether a credential was sent, and its first characters. The whole value
				// is never written to a file, even in the tests.
				'auth'     => '' !== $auth ? substr( $auth, 0, 14 ) . '...' : '',
			)
		) . "\n",
		FILE_APPEND | LOCK_EX
	);
}

/**
 * The reply for a mode that is not a success, or null to carry on and succeed.
 */
function zzzc_social_failure( string $mode, string $platform ) {
	switch ( $mode ) {
		case 'hang':
			// Answer nothing at all, so the caller times out. This is the case the plugin must
			// never treat as a failure, because the post may have been created.
			header( 'Content-Type: application/json' );
			sleep( 30 );
			exit;

		case '401':
			if ( in_array( $platform, array( 'facebook', 'instagram', 'threads' ), true ) ) {
				zzzc_json( 400, array( 'error' => array( 'code' => 190, 'message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException' ) ) );
			}
			zzzc_json( 401, array( 'error' => 'Unauthorized', 'message' => 'The credential is not valid' ) );
			break;

		case '429':
			if ( in_array( $platform, array( 'facebook', 'instagram', 'threads' ), true ) ) {
				zzzc_json( 400, array( 'error' => array( 'code' => 4, 'message' => 'Application request limit reached' ) ), array( 'Retry-After' => '120' ) );
			}
			zzzc_json( 429, array( 'error' => 'Too Many Requests' ), array( 'Retry-After' => '120', 'x-rate-limit-remaining' => '0', 'x-rate-limit-reset' => (string) ( time() + 120 ) ) );
			break;

		case '500':
			zzzc_json( 503, array( 'error' => 'Service Unavailable' ) );
			break;

		case 'duplicate':
			zzzc_json( 403, array( 'detail' => 'You are not allowed to create a Tweet with duplicate content.', 'title' => 'Forbidden' ) );
			break;

		case 'permission':
			if ( in_array( $platform, array( 'facebook', 'instagram', 'threads' ), true ) ) {
				zzzc_json( 403, array( 'error' => array( 'code' => 200, 'message' => 'Permissions error: the app has not been approved for pages_manage_posts' ) ) );
			}
			zzzc_json( 403, array( 'error' => 'Forbidden', 'message' => 'Your client app is not configured with the appropriate scopes' ) );
			break;
	}
	return null;
}

if ( 0 === strpos( $uri, '/social/' ) ) {
	header( 'X-Mock: social' );

	$rest     = substr( $uri, strlen( '/social/' ) );
	$platform = strtok( $rest, '/' );
	$path     = '/' . (string) substr( $rest, strlen( (string) $platform ) + 1 );
	$path     = (string) strtok( $path, '?' );
	$auth     = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	$raw      = (string) file_get_contents( 'php://input' );
	$json     = json_decode( $raw, true );
	$fields   = is_array( $json ) ? $json : array();

	if ( ! $fields && '' !== $raw && false === strpos( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ), 'image/' ) ) {
		parse_str( $raw, $parsed );
		if ( is_array( $parsed ) ) {
			$fields = $parsed;
		}
	}
	// A picture's bytes are recorded only as their size, never written out.
	if ( false !== strpos( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ), 'image/' ) ) {
		$fields = array( 'image_bytes' => strlen( $raw ), 'mime' => $_SERVER['CONTENT_TYPE'] );
	}
	if ( false !== strpos( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ), 'multipart/form-data' ) ) {
		$fields = array( 'multipart_bytes' => strlen( $raw ) );
	}

	zzzc_social_record( (string) $platform, $path, $method, $fields, (string) $auth );

	$mode   = zzzc_next_mode( 'social_mode', 'ok' );
	$exists = 'exists' === ( $state['social_reconcile'] ?? '' );

	/* ---- Token endpoints. These are never made to fail by social_mode, so a test can set up
	        an account and then make only the publishing step fail. ---- */

	if ( false !== strpos( $path, 'oauth' ) || '/access_token' === $path || '/refresh_access_token' === $path || '/2/oauth2/token' === $path || '/oauth/v2/accessToken' === $path ) {
		if ( 'token_401' === ( $state['social_token_mode'] ?? '' ) ) {
			zzzc_json( 400, array( 'error' => 'invalid_grant', 'error_description' => 'The authorisation code is not valid' ) );
		}
		zzzc_json(
			200,
			array(
				'access_token'  => 'mock-access-' . $platform . '-' . substr( md5( (string) microtime( true ) ), 0, 10 ),
				'refresh_token' => 'mock-refresh-' . $platform,
				'expires_in'    => 5184000,
				'token_type'    => 'bearer',
				'scope'         => 'openid profile w_member_social r_organization_social w_organization_social',
			)
		);
	}

	/* ---- Facebook and Instagram, on the Graph API ---- */

	if ( 'facebook' === $platform || 'instagram' === $platform ) {

		if ( '/me/accounts' === $path ) {
			$page = array(
				'id'           => '111222333',
				'name'         => 'Mock News Page',
				'username'     => 'mocknews',
				'access_token' => 'EAAmockpagetoken1234567890abcdef',
				'tasks'        => array( 'CREATE_CONTENT', 'MANAGE', 'MODERATE' ),
			);
			if ( 'instagram' === $platform ) {
				$page['instagram_business_account'] = array(
					'id'              => '17841400000000000',
					'username'        => 'mocknews',
					'name'            => 'Mock News',
					'followers_count' => 4210,
				);
			}
			if ( 'no_pages' === ( $state['social_accounts_mode'] ?? '' ) ) {
				zzzc_json( 200, array( 'data' => array() ) );
			}
			if ( 'no_create' === ( $state['social_accounts_mode'] ?? '' ) ) {
				$page['tasks'] = array( 'ANALYZE' );
			}
			zzzc_json( 200, array( 'data' => array( $page ) ) );
		}

		// Publishing allowance.
		if ( false !== strpos( $path, 'content_publishing_limit' ) ) {
			zzzc_json( 200, array( 'data' => array( array( 'quota_usage' => (int) ( $state['social_quota_used'] ?? 0 ) ) ) ) );
		}

		// A Facebook photo or feed post.
		if ( false !== strpos( $path, '/photos' ) || false !== strpos( $path, '/feed' ) ) {
			zzzc_social_failure( $mode, (string) $platform );
			if ( 'no_id' === $mode ) {
				zzzc_json( 200, array( 'created' => true ) );
			}
			zzzc_json( 200, array( 'id' => '111222333_999888777', 'post_id' => '111222333_999888777' ) );
		}

		// An Instagram container, then its publication.
		if ( false !== strpos( $path, '/media_publish' ) ) {
			zzzc_social_failure( $mode, (string) $platform );
			if ( 'no_id' === $mode ) {
				zzzc_json( 200, array( 'ok' => true ) );
			}
			zzzc_json( 200, array( 'id' => '18000000000000000' ) );
		}
		if ( false !== strpos( $path, '/media' ) ) {
			if ( 'container_error' === $mode ) {
				zzzc_json( 200, array( 'id' => 'container-broken' ) );
			}
			zzzc_social_failure( $mode, (string) $platform );
			zzzc_json( 200, array( 'id' => 'container-' . substr( md5( $raw ), 0, 8 ) ) );
		}

		// Recent posts, for reconciliation.
		if ( false !== strpos( $path, '/posts' ) ) {
			$data = $exists ? array( array( 'id' => '111222333_999888777', 'message' => (string) ( $state['social_last_text'] ?? '' ), 'created_time' => gmdate( 'c' ) ) ) : array();
			zzzc_json( 200, array( 'data' => $data ) );
		}

		// A container's progress, or any other single object read.
		if ( 0 === strpos( $path, '/container-' ) ) {
			if ( 'container-broken' === trim( $path, '/' ) ) {
				zzzc_json( 200, array( 'status_code' => 'ERROR', 'status' => 'The media could not be fetched from the given address' ) );
			}
			zzzc_json( 200, array( 'status_code' => 'FINISHED' ) );
		}

		if ( '/17841400000000000' === $path ) {
			zzzc_json( 200, array( 'id' => '17841400000000000', 'username' => 'mocknews', 'followers_count' => 4210, 'media_count' => 88 ) );
		}
		if ( '/111222333' === $path ) {
			zzzc_json( 200, array( 'id' => '111222333', 'name' => 'Mock News Page', 'fan_count' => 1234 ) );
		}
	}

	/* ---- Threads ---- */

	if ( 'threads' === $platform ) {
		if ( '/v1.0/me' === $path ) {
			zzzc_json( 200, array( 'id' => '7700000000000', 'username' => 'mocknews', 'name' => 'Mock News' ) );
		}
		if ( false !== strpos( $path, 'threads_publishing_limit' ) ) {
			zzzc_json( 200, array( 'data' => array( array( 'quota_usage' => (int) ( $state['social_quota_used'] ?? 0 ) ) ) ) );
		}
		if ( false !== strpos( $path, '/threads_publish' ) ) {
			zzzc_social_failure( $mode, 'threads' );
			if ( 'no_id' === $mode ) {
				zzzc_json( 200, array( 'ok' => true ) );
			}
			zzzc_json( 200, array( 'id' => '18800000000000001' ) );
		}
		if ( false !== strpos( $path, '/threads' ) && '/v1.0/7700000000000/threads' === $path && 'GET' === $method ) {
			$data = $exists ? array( array( 'id' => '18800000000000001', 'text' => (string) ( $state['social_last_text'] ?? '' ), 'permalink' => 'https://www.threads.net/@mocknews/post/abc', 'timestamp' => gmdate( 'c' ) ) ) : array();
			zzzc_json( 200, array( 'data' => $data ) );
		}
		if ( false !== strpos( $path, '/threads' ) ) {
			zzzc_social_failure( $mode, 'threads' );
			zzzc_json( 200, array( 'id' => 'container-' . substr( md5( $raw ), 0, 8 ) ) );
		}
		if ( 0 === strpos( $path, '/v1.0/container-' ) ) {
			zzzc_json( 200, array( 'status_code' => 'FINISHED' ) );
		}
		if ( 0 === strpos( $path, '/v1.0/18800000000000001' ) ) {
			zzzc_json( 200, array( 'permalink' => 'https://www.threads.net/@mocknews/post/abc' ) );
		}
	}

	/* ---- X ---- */

	if ( 'x' === $platform ) {
		if ( '/2/users/me' === $path ) {
			zzzc_json( 200, array( 'data' => array( 'id' => '1500000000', 'username' => 'mocknews', 'name' => 'Mock News' ) ) );
		}
		if ( '/2/media/upload' === $path ) {
			if ( 'image_fail' === $mode ) {
				zzzc_json( 400, array( 'errors' => array( array( 'message' => 'The media could not be processed' ) ) ) );
			}
			zzzc_json( 200, array( 'data' => array( 'id' => '1700000000000000001' ) ) );
		}
		if ( '/2/media/metadata' === $path ) {
			zzzc_json( 200, array( 'data' => array( 'id' => '1700000000000000001' ) ) );
		}
		if ( '/2/tweets' === $path ) {
			zzzc_social_failure( $mode, 'x' );
			if ( 'no_id' === $mode ) {
				zzzc_json( 200, array( 'data' => array( 'text' => 'accepted' ) ) );
			}
			zzzc_json( 201, array( 'data' => array( 'id' => '1800000000000000002', 'text' => (string) ( $fields['text'] ?? '' ) ) ) );
		}
		if ( 0 === strpos( $path, '/2/users/' ) && false !== strpos( $path, '/tweets' ) ) {
			$data = $exists ? array( array( 'id' => '1800000000000000002', 'text' => (string) ( $state['social_last_text'] ?? '' ), 'created_at' => gmdate( 'c' ) ) ) : array();
			zzzc_json( 200, array( 'data' => $data ) );
		}
	}

	/* ---- LinkedIn ---- */

	if ( 'linkedin' === $platform ) {
		if ( '/v2/userinfo' === $path ) {
			zzzc_json( 200, array( 'sub' => 'mockmember123', 'name' => 'Mock Editor', 'email' => 'editor@example.test' ) );
		}
		if ( '/rest/organizationAcls' === $path ) {
			if ( 'no_orgs' === ( $state['social_accounts_mode'] ?? '' ) ) {
				zzzc_json( 200, array( 'elements' => array() ) );
			}
			zzzc_json( 200, array( 'elements' => array( array( 'organization' => 'urn:li:organization:5566778', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED' ) ) ) );
		}
		if ( 0 === strpos( $path, '/rest/organizations/' ) ) {
			zzzc_json( 200, array( 'localizedName' => 'Mock News Ltd', 'vanityName' => 'mock-news' ) );
		}
		if ( '/rest/images' === $path ) {
			zzzc_json(
				200,
				array(
					'value' => array(
						'uploadUrl' => 'https://api.linkedin.com/social/linkedin/mediaUpload/put',
						'image'     => 'urn:li:image:C4100AQmock',
					),
				)
			);
		}
		if ( '/mediaUpload/put' === $path ) {
			zzzc_json( 201, array( 'ok' => true ) );
		}
		if ( '/rest/posts' === $path ) {
			zzzc_social_failure( $mode, 'linkedin' );
			if ( 'no_id' === $mode ) {
				zzzc_json( 201, array( 'ok' => true ) );
			}
			zzzc_json( 201, array( 'ok' => true ), array( 'x-restli-id' => 'urn:li:share:7100000000000000003' ) );
		}
	}

	/* ---- Bluesky, on the AT Protocol ---- */

	if ( 'bluesky' === $platform ) {
		if ( '/xrpc/com.atproto.server.createSession' === $path ) {
			if ( 'bad_password' === ( $state['social_token_mode'] ?? '' ) ) {
				zzzc_json( 401, array( 'error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password' ) );
			}
			zzzc_json(
				200,
				array(
					'accessJwt'  => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJkaWQ6cGxjOm1vY2sifQ.mocksignature1234',
					'refreshJwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJyZWZyZXNoIjp0cnVlfQ.mocksignature5678',
					'did'        => 'did:plc:mock1234567890',
					'handle'     => 'mocknews.bsky.social',
				)
			);
		}
		if ( '/xrpc/app.bsky.actor.getProfile' === $path ) {
			zzzc_json( 200, array( 'did' => 'did:plc:mock1234567890', 'handle' => 'mocknews.bsky.social', 'postsCount' => 142 ) );
		}
		if ( '/xrpc/com.atproto.repo.uploadBlob' === $path ) {
			if ( 'image_fail' === $mode ) {
				zzzc_json( 400, array( 'error' => 'BlobTooLarge', 'message' => 'The blob is larger than the limit' ) );
			}
			zzzc_json(
				200,
				array(
					'blob' => array(
						'$type'    => 'blob',
						'ref'      => array( '$link' => 'bafkreimock1234567890' ),
						'mimeType' => $_SERVER['CONTENT_TYPE'] ?? 'image/jpeg',
						'size'     => strlen( $raw ),
					),
				)
			);
		}
		if ( '/xrpc/com.atproto.repo.createRecord' === $path ) {
			zzzc_social_failure( $mode, 'bluesky' );
			if ( 'no_id' === $mode ) {
				zzzc_json( 200, array( 'ok' => true ) );
			}
			zzzc_json(
				200,
				array(
					'uri' => 'at://did:plc:mock1234567890/app.bsky.feed.post/3kmockrkey01',
					'cid' => 'bafyreimock',
				)
			);
		}
		if ( '/xrpc/com.atproto.repo.listRecords' === $path ) {
			$records = $exists
				? array(
					array(
						'uri'   => 'at://did:plc:mock1234567890/app.bsky.feed.post/3kmockrkey01',
						'value' => array( 'text' => (string) ( $state['social_last_text'] ?? '' ), 'createdAt' => gmdate( 'c' ) ),
					),
				)
				: array();
			zzzc_json( 200, array( 'records' => $records ) );
		}
	}

	zzzc_json( 404, array( 'error' => 'Unknown mock social path: ' . $platform . ' ' . $path ) );
}
