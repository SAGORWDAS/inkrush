<?php
namespace ZZZCopy\Social;

use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\JobResult;
use ZZZCopy\Jobs;
use ZZZCopy\Plugin;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The social distribution engine.
 *
 * This is the only class the rest of the plugin talks to. It decides when an article should be
 * distributed, creates one record per platform, turns those records into jobs on the plugin's
 * existing queue, and runs those jobs through the platform adapters.
 *
 * The rules it enforces, which the rest of the code depends on:
 *
 * - Nothing is distributed until WordPress reports the post as publicly published. A draft is
 *   never distributed, and a scheduled post is distributed when it actually goes public, not
 *   when it was scheduled.
 * - One platform's result never affects another's. Each platform is its own record and its own
 *   job, so a Facebook post stays published when Instagram fails.
 * - A failure on social media never changes the article. The article is already on the website.
 * - A retry can never produce a second post, because every record carries an idempotency key
 *   that is unique in the database and is re-checked immediately before each attempt.
 * - An attempt whose outcome could not be read is never retried. It is reconciled against the
 *   platform first.
 */
final class SocialManager {

	/** How an article came to be distributed. */
	public const ORIGIN_AUTO    = 'auto';
	public const ORIGIN_MANUAL  = 'manual';
	public const ORIGIN_RETRY   = 'retry';

	/**
	 * Hooks this feature into WordPress. Called from Plugin::boot.
	 */
	public static function register(): void {
		// A post published by hand, or a scheduled post reaching its time, both arrive here.
		// The publisher's own path is handled directly in Publisher::record_success, so this
		// covers everything that happens outside the worker.
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );

		// Deleting an article must never remove anything already published on a platform.
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_deleted' ), 10, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_post_deleted' ), 10, 1 );
	}

	/**
	 * The adapter for one platform.
	 */
	public static function adapter( string $platform ): ?Adapters\Base {
		$map = array(
			Platforms::FACEBOOK  => Adapters\Facebook::class,
			Platforms::INSTAGRAM => Adapters\Instagram::class,
			Platforms::X         => Adapters\X::class,
			Platforms::LINKEDIN  => Adapters\LinkedIn::class,
			Platforms::THREADS   => Adapters\Threads::class,
			Platforms::BLUESKY   => Adapters\Bluesky::class,
		);
		if ( ! isset( $map[ $platform ] ) ) {
			return null;
		}
		$class = $map[ $platform ];
		return new $class();
	}

	/* ---- Deciding when to distribute ---- */

	/**
	 * Called by the publisher the moment WordPress confirms a post is publicly published.
	 *
	 * @param array  $job     The article job row.
	 * @param int    $post_id The WordPress post.
	 */
	public static function on_article_published( array $job, int $post_id ): void {
		if ( ! SocialSettings::auto_distribute() ) {
			return;
		}
		self::enqueue_for_post(
			$post_id,
			array(
				'origin'         => self::ORIGIN_AUTO,
				'article_job_id' => (int) ( $job['id'] ?? 0 ),
			)
		);
	}

	/**
	 * A post changing status outside the worker.
	 *
	 * This covers the two cases the owner asked about: an InkRush draft that an editor publishes
	 * by hand, and a scheduled post that WordPress moves to public at its time. Both are only
	 * distributed when the setting for them is on, so publishing a draft by hand never posts to
	 * social media unexpectedly.
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! SocialSettings::enabled() ) {
			return;
		}
		// Only articles this plugin created. Other posts on the site are not its business.
		if ( ! self::is_plugin_post( (int) $post->ID ) ) {
			return;
		}

		// The worker's own publishing is handled in Publisher::record_success, which has the
		// article record to hand. Anything already recorded here has been dealt with.
		if ( SocialQueue::for_post( (int) $post->ID ) ) {
			return;
		}

		$scheduled = 'future' === $old_status;

		if ( ! $scheduled && 1 !== (int) Settings::get( 'social_on_manual_publish' ) ) {
			SocialLogger::info(
				'',
				sprintf( 'Article %d was published by hand and was not distributed to social media, because "Distribute articles published by hand" is switched off. It can be distributed from the Articles page.', (int) $post->ID ),
				array( 'wp_post_id' => (int) $post->ID )
			);
			return;
		}

		if ( ! SocialSettings::auto_distribute() ) {
			return;
		}

		self::enqueue_for_post(
			(int) $post->ID,
			array(
				'origin' => $scheduled ? self::ORIGIN_AUTO : self::ORIGIN_MANUAL,
			)
		);
	}

	/**
	 * Creates the records for one published post and starts the work.
	 *
	 * @param int   $post_id The WordPress post, which must already be publicly published.
	 * @param array $options origin, article_job_id, platforms, scheduled_for, approved.
	 * @return array{ok:bool, message:string, created:int, platforms:string[], skipped:array<string,string>}
	 */
	public static function enqueue_for_post( int $post_id, array $options = array() ): array {
		$out = array(
			'ok'        => false,
			'message'   => '',
			'created'   => 0,
			'platforms' => array(),
			'skipped'   => array(),
		);

		if ( ! SocialSettings::enabled() ) {
			$out['message'] = 'Social media distribution is switched off.';
			return $out;
		}

		// The one rule that is never relaxed: only a post the public can actually see.
		if ( 'publish' !== get_post_status( $post_id ) ) {
			$out['message'] = 'This article is not publicly published, so it was not distributed. A draft is never distributed to social media.';
			return $out;
		}

		$origin   = (string) ( $options['origin'] ?? self::ORIGIN_AUTO );
		$wanted   = isset( $options['platforms'] ) ? (array) $options['platforms'] : SocialSettings::active_platforms();
		$excluded = (array) ( $options['exclude'] ?? array() );

		$article_job_id = (int) ( $options['article_job_id'] ?? self::article_job_for_post( $post_id ) );
		$scheduled_for  = self::scheduled_for( $options );

		// In Manual approval mode a record waits for a person, unless the caller is that person
		// approving it as they create it.
		$approved = array_key_exists( 'approved', $options )
			? (bool) $options['approved']
			: ! SocialSettings::needs_approval();

		foreach ( Platforms::ORDER as $platform ) {
			if ( ! in_array( $platform, $wanted, true ) || in_array( $platform, $excluded, true ) ) {
				continue;
			}
			if ( ! SocialSettings::platform_enabled( $platform ) ) {
				$out['skipped'][ $platform ] = 'This platform is switched off.';
				continue;
			}

			$account = SocialAccounts::chosen( $platform );
			if ( ! $account ) {
				$out['skipped'][ $platform ] = 'No account is connected, or more than one is connected and none is chosen in the settings.';
				continue;
			}
			if ( SocialAccounts::CONNECTED !== $account['status'] ) {
				$out['skipped'][ $platform ] = sprintf( 'The account %1$s is not usable: %2$s.', SocialAccounts::label( $account ), SocialAccounts::status_label( $account['status'] ) );
				continue;
			}

			$added = SocialQueue::add(
				array(
					'wp_post_id'     => $post_id,
					'article_job_id' => $article_job_id,
					'platform'       => $platform,
					'account_id'     => (int) $account['id'],
					'account_label'  => SocialAccounts::label( $account ),
					'origin'         => $origin,
					'status'         => SocialQueue::PENDING,
					'approved'       => $approved,
					'scheduled_for'  => $scheduled_for,
					'link_url'       => (string) get_permalink( $post_id ),
					'max_attempts'   => SocialSettings::max_attempts(),
				)
			);

			if ( ! $added['created'] ) {
				// There is already a record for this article on this account. That is the
				// duplicate protection working, not an error.
				$out['skipped'][ $platform ] = 'This article already has a social post record for this account, so a second one was not created.';
				continue;
			}

			$out['created']++;
			$out['platforms'][] = $platform;
		}

		if ( ! $out['created'] ) {
			$out['message'] = $out['skipped']
				? 'No social post was created. ' . implode( ' ', $out['skipped'] )
				: 'No platform is switched on with a connected account, so nothing was created.';
			SocialLogger::info( '', sprintf( 'Article %1$d was not distributed. %2$s', $post_id, $out['message'] ), array( 'wp_post_id' => $post_id, 'article_job_id' => $article_job_id ) );
			return $out;
		}

		// One caption job covers every platform for this article, because they share the article
		// and the instruction, and one request costs less than six.
		Jobs::enqueue(
			Jobs::SOCIAL_GENERATE,
			array(
				'wp_post_id'     => $post_id,
				'article_job_id' => $article_job_id,
			),
			'social_generate:' . $post_id,
			0,
			3
		);

		// The worker must be scheduled, which it may not be if automation is stopped.
		Plugin::sync_schedule();

		$out['ok']      = true;
		$out['message'] = sprintf(
			'Queued %1$d social %2$s for this article: %3$s.',
			$out['created'],
			1 === $out['created'] ? 'post' : 'posts',
			implode( ', ', array_map( array( Platforms::class, 'label' ), $out['platforms'] ) )
		);

		SocialLogger::info(
			'',
			sprintf( 'Article %1$d is publicly published, so %2$s', $post_id, lcfirst( $out['message'] ) ),
			array(
				'wp_post_id'     => $post_id,
				'article_job_id' => $article_job_id,
				'context'        => array(
					'platforms' => $out['platforms'],
					'origin'    => $origin,
					'scheduled' => $scheduled_for,
				),
			)
		);

		return $out;
	}

	/**
	 * When a newly created record should be published, from the timing settings or the caller.
	 */
	private static function scheduled_for( array $options ): ?string {
		if ( ! empty( $options['scheduled_for'] ) ) {
			return (string) $options['scheduled_for'];
		}
		$delay = SocialSettings::delay_seconds();
		return $delay > 0 ? Clock::mysql( Clock::now() + $delay ) : null;
	}

	/* ---- The worker's social phase ---- */

	/**
	 * Called once per worker run, whether or not news automation is active.
	 *
	 * It recovers anything a stopped worker left behind, then turns the records that are due
	 * into jobs. Enqueueing uses a unique key, so a record that already has a job waiting does
	 * not get a second one.
	 *
	 * @return int How many records were turned into jobs.
	 */
	public static function tick(): int {
		if ( ! SocialSettings::enabled() ) {
			return 0;
		}

		SocialQueue::recover_stale();

		$queued = 0;

		foreach ( SocialQueue::due( 25 ) as $row ) {
			$id = (int) $row['id'];

			if ( SocialQueue::RECONCILING === $row['status'] ) {
				Jobs::enqueue( Jobs::SOCIAL_RECONCILE, array( 'social_post_id' => $id ), 'social_reconcile:' . $id, 0, 3 );
				$queued++;
				continue;
			}

			// A record with no caption yet needs one written first.
			if ( '' === trim( (string) $row['caption'] ) ) {
				Jobs::enqueue(
					Jobs::SOCIAL_GENERATE,
					array(
						'wp_post_id'     => (int) $row['wp_post_id'],
						'article_job_id' => (int) $row['article_job_id'],
					),
					'social_generate:' . (int) $row['wp_post_id'],
					0,
					3
				);
				$queued++;
				continue;
			}

			// Waiting for a person. Nothing to do until they approve it.
			if ( ! $row['approved'] || SocialQueue::AWAITING === $row['status'] ) {
				continue;
			}

			Jobs::enqueue( Jobs::SOCIAL_PUBLISH, array( 'social_post_id' => $id ), 'social_publish:' . $id, 0, (int) $row['max_attempts'] );
			$queued++;
		}

		self::queue_token_refreshes();

		return $queued;
	}

	/**
	 * Asks for a credential to be renewed before it expires, rather than after a post has
	 * already failed because of it.
	 */
	private static function queue_token_refreshes(): void {
		// Once an hour is plenty for something measured in days.
		if ( get_transient( 'zzzc_social_token_check' ) ) {
			return;
		}
		set_transient( 'zzzc_social_token_check', 1, HOUR_IN_SECONDS );

		foreach ( SocialAccounts::expiring( 7 ) as $account ) {
			Jobs::enqueue(
				Jobs::SOCIAL_TOKEN,
				array( 'account_id' => (int) $account['id'] ),
				'social_refresh_token:' . (int) $account['id'],
				0,
				3
			);
		}
	}

	/* ---- Job handlers ---- */

	/**
	 * Writes the captions for one article's platforms.
	 */
	public static function handle_generate( array $job ): JobResult {
		$post_id = (int) ( $job['payload']['wp_post_id'] ?? 0 );
		if ( ! $post_id ) {
			return JobResult::fail( 'no_post', 'The caption job has no article to write about.' );
		}

		$rows = SocialQueue::for_post( $post_id );
		if ( ! $rows ) {
			return JobResult::done();
		}

		// Only the records that still need a caption and are still going to happen.
		$needed = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'], SocialQueue::CLOSED, true ) ) {
				continue;
			}
			if ( '' !== trim( (string) $row['caption'] ) ) {
				continue;
			}
			$needed[ (string) $row['platform'] ] = $row;
		}

		if ( ! $needed ) {
			self::release_for_publishing( $post_id );
			return JobResult::done();
		}

		$article = self::article_payload( $post_id );
		if ( '' === trim( (string) $article['title'] ) ) {
			return JobResult::fail( 'no_article', sprintf( 'Article %d could not be read, so no caption was written.', $post_id ) );
		}

		$result = SocialContentGenerator::generate( $article, array_keys( $needed ) );

		if ( ! $result['ok'] ) {
			if ( $result['transient'] ) {
				$delay = $result['retry_after'] > 0 ? (int) $result['retry_after'] : Jobs::backoff( (int) $job['attempts'], 120, 1800 );
				return JobResult::retry( $delay, (string) $result['code'], (string) $result['message'] );
			}
			// The AI is not going to work for this article. The posts still go out, using the
			// article's own title, rather than being abandoned.
			SocialLogger::warning(
				'',
				sprintf( 'Captions could not be written for article %1$d, so the article\'s own title is used instead. %2$s', $post_id, (string) $result['message'] ),
				array(
					'wp_post_id' => $post_id,
					'error_code' => (string) $result['code'],
				)
			);
			$result['posts'] = array();
			foreach ( array_keys( $needed ) as $platform ) {
				$result['posts'][ $platform ] = SocialContentGenerator::fallback( $article, $platform );
			}
		}

		$written = array();
		foreach ( $needed as $platform => $row ) {
			$post = $result['posts'][ $platform ] ?? null;
			if ( ! is_array( $post ) || '' === trim( (string) $post['text'] ) ) {
				continue;
			}
			SocialQueue::set_caption( (int) $row['id'], (string) $post['text'], (string) $post['hashtags'], (string) $post['source'] );
			$written[] = $platform;
		}

		if ( $written ) {
			SocialLogger::info(
				'',
				sprintf(
					'Wrote social post text for article %1$d: %2$s.',
					$post_id,
					implode( ', ', array_map( array( Platforms::class, 'label' ), $written ) )
				),
				array(
					'wp_post_id' => $post_id,
					'context'    => array(
						'platforms' => $written,
						'source'    => $result['posts'][ $written[0] ]['source'] ?? '',
					),
				)
			);
		}

		self::release_for_publishing( $post_id );

		return JobResult::done();
	}

	/**
	 * Moves records with a caption on to the next step: waiting for approval, or publishing.
	 */
	private static function release_for_publishing( int $post_id ): void {
		foreach ( SocialQueue::for_post( $post_id ) as $row ) {
			if ( in_array( $row['status'], SocialQueue::CLOSED, true ) ) {
				continue;
			}
			if ( '' === trim( (string) $row['caption'] ) ) {
				continue;
			}
			$id = (int) $row['id'];

			if ( ! $row['approved'] ) {
				if ( SocialQueue::AWAITING !== $row['status'] ) {
					SocialQueue::set_status( $id, SocialQueue::AWAITING );
					SocialLogger::info(
						(string) $row['platform'],
						'The post text is ready and is waiting for approval on the Social Publishing page.',
						SocialLogger::context( $row, array( 'status' => SocialQueue::AWAITING ) )
					);
				}
				continue;
			}

			if ( SocialQueue::PENDING === $row['status'] ) {
				SocialQueue::set_status( $id, SocialQueue::SCHEDULED );
			}
			Jobs::enqueue( Jobs::SOCIAL_PUBLISH, array( 'social_post_id' => $id ), 'social_publish:' . $id, 0, (int) $row['max_attempts'] );
		}
	}

	/**
	 * Publishes one record on one platform.
	 */
	public static function handle_publish( array $job ): JobResult {
		$id  = (int) ( $job['payload']['social_post_id'] ?? 0 );
		$row = $id ? SocialQueue::get( $id ) : null;

		if ( ! $row ) {
			return JobResult::done();
		}

		// Already done, cancelled, skipped or failed for good. Nothing to do, and above all
		// nothing to send: this is the check that stops a retry duplicating a post.
		if ( in_array( $row['status'], SocialQueue::CLOSED, true ) ) {
			return JobResult::done();
		}
		if ( SocialQueue::already_published( $id ) ) {
			return JobResult::done();
		}
		if ( SocialQueue::RECONCILING === $row['status'] ) {
			Jobs::enqueue( Jobs::SOCIAL_RECONCILE, array( 'social_post_id' => $id ), 'social_reconcile:' . $id, 0, 3 );
			return JobResult::done();
		}
		if ( ! $row['approved'] ) {
			return JobResult::done();
		}

		$platform = (string) $row['platform'];

		if ( ! SocialSettings::enabled() || ! SocialSettings::platform_enabled( $platform ) ) {
			return JobResult::defer( 900, 'This platform is switched off, so the post is waiting.' );
		}

		// Its time has not come yet.
		if ( ! empty( $row['scheduled_for'] ) ) {
			$due = (int) strtotime( (string) $row['scheduled_for'] . ' UTC' );
			if ( $due > Clock::now() + 5 ) {
				return JobResult::defer( max( 30, min( 3600, $due - Clock::now() ) ), 'Waiting for the time this post is scheduled for.' );
			}
		}

		$adapter = self::adapter( $platform );
		if ( ! $adapter ) {
			SocialQueue::mark_failed( $id, 'no_adapter', 'This platform is not supported by this version of the plugin.' );
			return JobResult::done();
		}

		$account = SocialAccounts::get( (int) $row['account_id'] );
		if ( ! $account ) {
			SocialQueue::mark_failed( $id, 'account_missing', 'The account this post was going to be published through is no longer connected.' );
			SocialLogger::error( $platform, 'The account this post was going to use is no longer connected, so it was not published.', SocialLogger::context( $row, array( 'error_code' => 'account_missing' ) ) );
			return JobResult::done();
		}
		if ( SocialAccounts::CONNECTED !== $account['status'] ) {
			return self::handle_result(
				$row,
				$account,
				\ZZZCopy\Social\SocialResult::failed(
					'auth_expired',
					sprintf( 'The account %1$s cannot be used: %2$s. %3$s Reconnect it on the Social Media page.', SocialAccounts::label( $account ), SocialAccounts::status_label( $account['status'] ), (string) $account['status_reason'] )
				),
				$job
			);
		}

		// The featured image, prepared for this platform's own requirements.
		$media = SocialMedia::resolve( (int) $row['wp_post_id'], $platform );
		if ( ! $media['ok'] && Platforms::needs_image( $platform ) ) {
			SocialQueue::skip( $id, 'image_required', sprintf( '%1$s requires a picture on every post, and this article has none that %1$s accepts. %2$s', Platforms::label( $platform ), (string) $media['reason'] ) );
			SocialLogger::warning(
				$platform,
				sprintf( 'Skipped, because %1$s requires a picture on every post. %2$s', Platforms::label( $platform ), (string) $media['reason'] ),
				SocialLogger::context( $row, array( 'error_code' => 'image_required', 'status' => SocialQueue::SKIPPED ) )
			);
			return JobResult::done();
		}
		if ( ! $media['ok'] && '' !== (string) $media['reason'] ) {
			SocialLogger::info(
				$platform,
				sprintf( 'Publishing without a picture. %s', (string) $media['reason'] ),
				SocialLogger::context( $row )
			);
		}
		SocialQueue::set_media( $id, $media );

		// Claim it. This fails when another worker already holds it, or when it has been
		// published since this job was created, so only one attempt can ever be in flight.
		$lease = (int) Settings::get( 'ai_timeout' ) + 120;
		if ( ! SocialQueue::begin_attempt( $id, (string) $job['lease_owner'], $lease ) ) {
			return JobResult::done();
		}

		$fresh   = SocialQueue::get( $id ) ?: $row;
		$payload = self::publish_payload( $fresh, $account, $media );

		try {
			$result = $adapter->publish( $fresh, $payload );
		} catch ( \Throwable $e ) {
			// An unexpected error after the request may have gone out. Treated as unknown, never
			// as something to send again.
			$result = \ZZZCopy\Social\SocialResult::unknown(
				'exception',
				'An unexpected error happened while publishing, so it is not known whether the post was created: ' . $e->getMessage()
			);
		}

		return self::handle_result( $fresh, $account, $result, $job );
	}

	/**
	 * What to send: the caption, the hashtags, the article's address and its picture.
	 */
	private static function publish_payload( array $row, array $account, array $media ): array {
		$post_id = (int) $row['wp_post_id'];
		$url     = (string) ( $row['link_url'] ?: get_permalink( $post_id ) );

		return array(
			'text'             => (string) $row['caption'],
			'hashtags'         => (string) $row['hashtags'],
			'url'              => $url,
			'media'            => $media,
			'account'          => $account,
			'link_title'       => (string) get_the_title( $post_id ),
			'link_description' => self::excerpt( $post_id ),
		);
	}

	/**
	 * Records what an adapter reported, and says what should happen to the job.
	 */
	private static function handle_result( array $row, array $account, \ZZZCopy\Social\SocialResult $result, array $job ): JobResult {
		$id       = (int) $row['id'];
		$platform = (string) $row['platform'];
		$context  = SocialLogger::context( $row );

		switch ( $result->outcome ) {
			case \ZZZCopy\Social\SocialResult::PUBLISHED:
				SocialQueue::mark_published( $id, $result->external_id, $result->external_url );
				SocialLogger::info(
					$platform,
					sprintf(
						'Published to %1$s as %2$s.%3$s',
						SocialAccounts::label( $account ),
						$result->external_id,
						'' !== $result->external_url ? ' ' . $result->external_url : ''
					),
					array_merge( $context, array( 'status' => SocialQueue::PUBLISHED ) )
				);
				return JobResult::done();

			case \ZZZCopy\Social\SocialResult::DEFER:
				SocialQueue::mark_deferred( $id, $result->delay, $result->code, $result->message );
				SocialLogger::info( $platform, $result->message, array_merge( $context, array( 'error_code' => $result->code ) ) );
				return JobResult::defer( $result->delay, $result->message );

			case \ZZZCopy\Social\SocialResult::UNKNOWN:
				SocialQueue::mark_reconciling( $id, $result->code, $result->message, $result->remote_ref );
				SocialLogger::warning(
					$platform,
					$result->message,
					array_merge( $context, array( 'error_code' => $result->code, 'status' => SocialQueue::RECONCILING ) )
				);
				Jobs::enqueue( Jobs::SOCIAL_RECONCILE, array( 'social_post_id' => $id ), 'social_reconcile:' . $id, 120, 3 );
				// The job itself is finished. The reconcile job takes it from here, so this is
				// not retried and cannot send a second post.
				return JobResult::done();

			case \ZZZCopy\Social\SocialResult::RETRY:
				$delay = $result->delay > 0 ? $result->delay : Jobs::backoff( (int) $row['attempts'], 90, 3600 );
				if ( SocialQueue::mark_retry( $id, $delay, $result->code, $result->message ) ) {
					SocialLogger::warning(
						$platform,
						sprintf( 'Will try again in about %1$d minutes. %2$s', max( 1, (int) round( $delay / 60 ) ), $result->message ),
						array_merge( $context, array( 'error_code' => $result->code ) )
					);
					Jobs::enqueue( Jobs::SOCIAL_PUBLISH, array( 'social_post_id' => $id ), 'social_publish:' . $id, $delay, (int) $row['max_attempts'] );
					return JobResult::done();
				}
				// Attempts used up.
				SocialQueue::mark_failed( $id, $result->code, $result->message );
				SocialLogger::error(
					$platform,
					sprintf( 'Gave up after %1$d attempts. %2$s', (int) $row['attempts'], $result->message ),
					array_merge( $context, array( 'error_code' => $result->code, 'status' => SocialQueue::FAILED ) )
				);
				return JobResult::done();

			case \ZZZCopy\Social\SocialResult::FAILED:
			default:
				SocialQueue::mark_failed( $id, $result->code, $result->message );
				SocialLogger::error(
					$platform,
					$result->message,
					array_merge( $context, array( 'error_code' => $result->code, 'status' => SocialQueue::FAILED ) )
				);
				// A credential problem is about the account, not this one post, so it is recorded
				// against the account as well. Every other post on it would fail the same way.
				if ( 'auth_expired' === $result->code ) {
					SocialAccounts::mark_status( (int) $account['id'], SocialAccounts::EXPIRED, $result->message );
				}
				return JobResult::done();
		}
	}

	/**
	 * Asks a platform what became of an attempt whose outcome was never seen.
	 */
	public static function handle_reconcile( array $job ): JobResult {
		$id  = (int) ( $job['payload']['social_post_id'] ?? 0 );
		$row = $id ? SocialQueue::get( $id ) : null;

		if ( ! $row || in_array( $row['status'], SocialQueue::CLOSED, true ) ) {
			return JobResult::done();
		}

		$platform = (string) $row['platform'];
		$adapter  = self::adapter( $platform );
		$account  = SocialAccounts::get( (int) $row['account_id'] );

		if ( ! $adapter || ! $account ) {
			SocialQueue::mark_failed( $id, 'cannot_reconcile', 'Whether this post was published could not be checked, because the account is no longer connected. It was not sent again.' );
			return JobResult::done();
		}

		$result  = $adapter->reconcile( $row, $account );
		$context = SocialLogger::context( $row );

		if ( $result->is_published() ) {
			// It did reach the platform. Recording it as published is what prevents the duplicate.
			SocialQueue::mark_published( $id, $result->external_id, $result->external_url );
			SocialLogger::info(
				$platform,
				sprintf( 'The earlier attempt did reach %1$s after all, as %2$s, so it is recorded as published and was not sent again.', Platforms::label( $platform ), $result->external_id ),
				array_merge( $context, array( 'status' => SocialQueue::PUBLISHED ) )
			);
			return JobResult::done();
		}

		if ( \ZZZCopy\Social\SocialResult::RETRY === $result->outcome ) {
			// The platform does not have it, so sending it again cannot duplicate anything.
			if ( SocialQueue::mark_retry( $id, 60, $result->code, $result->message ) ) {
				SocialLogger::info( $platform, $result->message, array_merge( $context, array( 'error_code' => $result->code ) ) );
				Jobs::enqueue( Jobs::SOCIAL_PUBLISH, array( 'social_post_id' => $id ), 'social_publish:' . $id, 60, (int) $row['max_attempts'] );
				return JobResult::done();
			}
			SocialQueue::mark_failed( $id, $result->code, $result->message . ' No attempts are left.' );
			return JobResult::done();
		}

		// Still not known. It stays as it is for a person to decide, and is not sent again.
		// Leaving it here is deliberate: a duplicate post is worse than one that waits.
		SocialQueue::mark_reconciling( $id, $result->code, $result->message );
		SocialLogger::warning(
			$platform,
			$result->message,
			array_merge( $context, array( 'error_code' => $result->code, 'status' => SocialQueue::RECONCILING ) )
		);
		return JobResult::done();
	}

	/**
	 * Renews one account's credential.
	 */
	public static function handle_refresh_token( array $job ): JobResult {
		$account_id = (int) ( $job['payload']['account_id'] ?? 0 );
		$account    = $account_id ? SocialAccounts::get( $account_id ) : null;

		if ( ! $account ) {
			return JobResult::done();
		}

		$adapter = self::adapter( (string) $account['platform'] );
		if ( ! $adapter ) {
			return JobResult::done();
		}

		$result = $adapter->refresh( $account );

		if ( ! empty( $result['changed'] ) ) {
			SocialLogger::info(
				(string) $account['platform'],
				sprintf( 'The credential for %1$s was renewed. %2$s', SocialAccounts::label( $account ), (string) $result['message'] ),
				array( 'account_id' => $account_id )
			);
			return JobResult::done();
		}

		if ( empty( $result['ok'] ) ) {
			// It could not be renewed. The account is marked so the administrator sees it on the
			// Social Media page before a post fails because of it.
			SocialAccounts::mark_status( $account_id, SocialAccounts::EXPIRED, (string) $result['message'] );
			SocialLogger::warning(
				(string) $account['platform'],
				sprintf( 'The credential for %1$s could not be renewed, so the account needs reconnecting. %2$s', SocialAccounts::label( $account ), (string) $result['message'] ),
				array( 'account_id' => $account_id, 'error_code' => 'token_refresh_failed' )
			);
		}

		return JobResult::done();
	}

	/**
	 * A social job that has run out of attempts.
	 *
	 * Nothing here touches the article. The article is published on the website, and a platform
	 * being unavailable says nothing about it.
	 */
	public static function on_job_exhausted( string $type, array $payload, string $code, string $message ): void {
		$id  = (int) ( $payload['social_post_id'] ?? 0 );
		$row = $id ? SocialQueue::get( $id ) : null;

		if ( $row && ! in_array( $row['status'], SocialQueue::CLOSED, true ) ) {
			// A publish job that ran out of attempts without a clear answer is reconciled, not
			// called a failure, because the last attempt may have arrived.
			if ( Jobs::SOCIAL_PUBLISH === $type && SocialQueue::PUBLISHING === $row['status'] ) {
				SocialQueue::mark_reconciling( $id, $code, $message );
			} else {
				SocialQueue::mark_failed( $id, $code, $message );
			}
		}

		SocialLogger::error(
			$row ? (string) $row['platform'] : '',
			sprintf( 'The %1$s job stopped for good. %2$s', str_replace( '_', ' ', $type ), $message ),
			$row
				? SocialLogger::context( $row, array( 'error_code' => $code ) )
				: array( 'error_code' => $code )
		);
	}

	/* ---- Things the administrator does ---- */

	/**
	 * Approves one record and starts it.
	 */
	public static function approve( int $id, ?string $scheduled_for = null ): bool {
		if ( ! SocialQueue::approve( $id, $scheduled_for ) ) {
			return false;
		}
		$row = SocialQueue::get( $id );
		if ( ! $row ) {
			return false;
		}
		Jobs::enqueue( Jobs::SOCIAL_PUBLISH, array( 'social_post_id' => $id ), 'social_publish:' . $id, 0, (int) $row['max_attempts'] );
		Plugin::sync_schedule();
		SocialLogger::info( (string) $row['platform'], 'Approved for publishing by an administrator.', SocialLogger::context( $row ) );
		return true;
	}

	/**
	 * Tries a failed record again.
	 *
	 * A record that was published is never retried, whatever the administrator presses, because
	 * that is how a duplicate would be created.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function retry( int $id ): array {
		$row = SocialQueue::get( $id );
		if ( ! $row ) {
			return array(
				'ok'      => false,
				'message' => 'This social post no longer exists.',
			);
		}
		if ( SocialQueue::PUBLISHED === $row['status'] ) {
			return array(
				'ok'      => false,
				'message' => 'This post was published, so it cannot be sent again. Sending it again would post the same article twice.',
			);
		}
		if ( SocialQueue::RECONCILING === $row['status'] ) {
			Jobs::enqueue( Jobs::SOCIAL_RECONCILE, array( 'social_post_id' => $id ), 'social_reconcile:' . $id, 0, 3 );
			Plugin::sync_schedule();
			return array(
				'ok'      => true,
				'message' => 'This post is being checked against the platform first, because an earlier attempt may have reached it. It will be sent again only if the platform does not have it.',
			);
		}

		global $wpdb;
		$wpdb->update(
			Db::table( 'social_posts' ),
			array(
				'status'        => SocialQueue::SCHEDULED,
				'attempts'      => 0,
				'retry_at'      => null,
				'claimed_by'    => '',
				'claim_expires' => null,
				'error_code'    => '',
				'error_message' => null,
				'approved'      => 1,
				'updated_at'    => Clock::mysql(),
			),
			array( 'id' => $id )
		);

		Jobs::enqueue( Jobs::SOCIAL_PUBLISH, array( 'social_post_id' => $id ), 'social_publish:' . $id, 0, (int) $row['max_attempts'] );
		Plugin::sync_schedule();

		SocialLogger::info( (string) $row['platform'], 'An administrator asked for this post to be tried again.', SocialLogger::context( $row ) );

		return array(
			'ok'      => true,
			'message' => sprintf( 'The %s post will be tried again shortly.', Platforms::label( (string) $row['platform'] ) ),
		);
	}

	/**
	 * Replaces one platform's caption by hand, leaving the other platforms alone.
	 */
	public static function edit_caption( int $id, string $caption, string $hashtags ): array {
		$row = SocialQueue::get( $id );
		if ( ! $row ) {
			return array(
				'ok'      => false,
				'message' => 'This social post no longer exists.',
			);
		}
		if ( SocialQueue::PUBLISHED === $row['status'] ) {
			return array(
				'ok'      => false,
				'message' => 'This post has already been published, so its text cannot be changed. Editing it here would not change what is on the platform.',
			);
		}

		$platform = (string) $row['platform'];
		$limit    = (int) Platforms::value( $platform, 'hard_text_limit', 280 );
		$caption  = trim( wp_strip_all_tags( $caption ) );

		if ( '' === $caption ) {
			return array(
				'ok'      => false,
				'message' => 'The post text cannot be empty.',
			);
		}
		if ( mb_strlen( $caption ) > $limit ) {
			return array(
				'ok'      => false,
				'message' => sprintf( '%1$s allows %2$d characters and this text is %3$d.', Platforms::label( $platform ), $limit, mb_strlen( $caption ) ),
			);
		}

		SocialQueue::set_caption( $id, $caption, SocialSettings::clean_hashtags( $hashtags, $platform ), 'manual' );
		SocialLogger::info( $platform, 'An administrator edited the post text by hand.', SocialLogger::context( $row ) );

		return array(
			'ok'      => true,
			'message' => sprintf( 'The %s post text was saved.', Platforms::label( $platform ) ),
		);
	}

	/**
	 * Writes one platform's caption again, leaving the others exactly as they are.
	 */
	public static function regenerate_caption( int $id ): array {
		$row = SocialQueue::get( $id );
		if ( ! $row ) {
			return array(
				'ok'      => false,
				'message' => 'This social post no longer exists.',
			);
		}
		if ( SocialQueue::PUBLISHED === $row['status'] ) {
			return array(
				'ok'      => false,
				'message' => 'This post has already been published, so writing new text for it would change nothing.',
			);
		}

		$platform = (string) $row['platform'];
		$article  = self::article_payload( (int) $row['wp_post_id'] );
		$result   = SocialContentGenerator::generate( $article, array( $platform ) );

		if ( ! $result['ok'] || empty( $result['posts'][ $platform ] ) ) {
			return array(
				'ok'      => false,
				'message' => '' !== (string) $result['message'] ? (string) $result['message'] : 'New text could not be written.',
			);
		}

		$post = $result['posts'][ $platform ];
		SocialQueue::set_caption( $id, (string) $post['text'], (string) $post['hashtags'], (string) $post['source'] );
		SocialLogger::info( $platform, 'An administrator asked for the post text to be written again.', SocialLogger::context( $row ) );

		return array(
			'ok'      => true,
			'message' => sprintf( 'New %s post text was written.', Platforms::label( $platform ) ),
			'caption' => (string) $post['text'],
			'hashtags' => (string) $post['hashtags'],
		);
	}

	public static function cancel( int $id ): array {
		$row = SocialQueue::get( $id );
		if ( ! $row ) {
			return array(
				'ok'      => false,
				'message' => 'This social post no longer exists.',
			);
		}
		if ( SocialQueue::PUBLISHED === $row['status'] ) {
			return array(
				'ok'      => false,
				'message' => 'This post has already been published. Cancelling it here would not remove it from the platform: delete it on the platform itself.',
			);
		}
		if ( ! SocialQueue::cancel( $id ) ) {
			return array(
				'ok'      => false,
				'message' => 'This post could not be cancelled.',
			);
		}

		SocialLogger::info( (string) $row['platform'], 'Cancelled by an administrator before it was published.', SocialLogger::context( $row, array( 'status' => SocialQueue::CANCELLED ) ) );

		return array(
			'ok'      => true,
			'message' => sprintf( 'The %s post was cancelled.', Platforms::label( (string) $row['platform'] ) ),
		);
	}

	/**
	 * A WordPress article being deleted.
	 *
	 * Nothing is removed from any platform. A post already published stays published, which is
	 * what the owner asked for, and anything not yet published is cancelled because there is no
	 * longer an article for a reader to be sent to.
	 */
	public static function on_post_deleted( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
			return;
		}
		$rows = SocialQueue::for_post( $post_id );
		if ( ! $rows ) {
			return;
		}

		$cancelled = SocialQueue::on_post_deleted( $post_id );
		$published = 0;
		foreach ( $rows as $row ) {
			if ( SocialQueue::PUBLISHED === $row['status'] ) {
				$published++;
			}
		}

		SocialLogger::info(
			'',
			sprintf(
				'Article %1$d was deleted or trashed. %2$d social post(s) not yet published were cancelled. %3$d already published on social media were left exactly as they are, and nothing was removed from any platform.',
				$post_id,
				$cancelled,
				$published
			),
			array( 'wp_post_id' => $post_id )
		);
	}

	/* ---- Reading the article ---- */

	/**
	 * The article as the caption writer needs it.
	 */
	public static function article_payload( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'title' => '',
				'body'  => '',
				'url'   => '',
			);
		}

		$categories = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
		$tags       = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

		$attachment_id = (int) get_post_thumbnail_id( $post_id );

		return array(
			'article_job_id' => self::article_job_for_post( $post_id ),
			'title'          => (string) get_the_title( $post_id ),
			'body'           => (string) $post->post_content,
			'url'            => (string) get_permalink( $post_id ),
			'category'       => is_array( $categories ) && $categories ? (string) $categories[0] : '',
			'tags'           => is_array( $tags ) ? $tags : array(),
			'keywords'       => is_array( $tags ) ? array_slice( $tags, 0, 10 ) : array(),
			'image_alt'      => $attachment_id ? SocialMedia::alt_text( $attachment_id, $post_id ) : '',
		);
	}

	/**
	 * A short plain-text description of the article, for a link preview.
	 */
	private static function excerpt( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$text = '' !== trim( (string) $post->post_excerpt )
			? (string) $post->post_excerpt
			: (string) $post->post_content;
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return SocialContentGenerator::clip( $text, 300 );
	}

	/**
	 * The article job that produced a WordPress post, from the existing post_links table.
	 */
	public static function article_job_for_post( int $post_id ): int {
		global $wpdb;
		$table = Db::table( 'post_links' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT article_job_id FROM {$table} WHERE wp_post_id = %d LIMIT 1", $post_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Whether a post was created by this plugin, which is the only kind it distributes.
	 */
	public static function is_plugin_post( int $post_id ): bool {
		global $wpdb;
		$table = Db::table( 'post_links' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE wp_post_id = %d LIMIT 1", $post_id ) ); // phpcs:ignore WordPress.DB
	}

	/* ---- Figures for the dashboard ---- */

	/**
	 * The numbers shown at the top of the Social Publishing page.
	 */
	public static function summary(): array {
		$counts = SocialQueue::counts();
		return array(
			'total'     => (int) $counts['total'],
			'published' => (int) $counts[ SocialQueue::PUBLISHED ],
			'failed'    => (int) $counts[ SocialQueue::FAILED ],
			'pending'   => (int) $counts[ SocialQueue::PENDING ] + (int) $counts[ SocialQueue::PUBLISHING ] + (int) $counts[ SocialQueue::RECONCILING ],
			'awaiting'  => (int) $counts[ SocialQueue::AWAITING ],
			'scheduled' => (int) $counts[ SocialQueue::SCHEDULED ],
			'cancelled' => (int) $counts[ SocialQueue::CANCELLED ],
			'skipped'   => (int) $counts[ SocialQueue::SKIPPED ],
			'accounts'  => SocialAccounts::count_connected(),
		);
	}

	/**
	 * Removes old closed records, called from the plugin's daily maintenance.
	 */
	public static function daily_maintenance(): void {
		$days = (int) Settings::get( 'social_retention_days' );
		$gone = SocialQueue::prune( $days );
		if ( $gone > 0 ) {
			SocialLogger::info( '', sprintf( 'Removed %1$d social post records older than %2$d days that were failed, cancelled or skipped. Published records were kept.', $gone, $days ) );
		}
	}
}
