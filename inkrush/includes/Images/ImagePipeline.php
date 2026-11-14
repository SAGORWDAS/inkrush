<?php
namespace ZZZCopy\Images;

use ZZZCopy\Categories;
use ZZZCopy\Clock;
use ZZZCopy\Db;
use ZZZCopy\Events;
use ZZZCopy\Jobs;
use ZZZCopy\Lifecycle;
use ZZZCopy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and imports the featured image that every post must have. No usable image means no post.
 *
 * Each article has one image_runs row, which holds progress so a retry or crash recovery
 * continues the same work. This never regenerates an article or its tags, and makes no AI calls.
 */
final class ImagePipeline {

	public const USE   = 'use';
	public const NONE  = 'none';
	public const RETRY = 'retry';
	public const HOLD  = 'hold';

	private const TRY_FILES = 5;

	/** A file this good ends the searching early. Most stories about one person are settled by the first search. */
	private const STRONG_MATCH = 75;

	/** Points added, for ordering only, to a file whose labels name more than one of the article's subjects. */
	private const FULLER_PICTURE = 4;

	public static function get_run( int $article_job_id ): ?array {
		global $wpdb;
		$table = Db::table( 'image_runs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE article_job_id = %d", $article_job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	private static function save_run( int $article_job_id, array $fields ): void {
		global $wpdb;
		$table = Db::table( 'image_runs' );
		$now   = Clock::mysql();
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (article_job_id, created_at, updated_at) VALUES (%d, %s, %s)", $article_job_id, $now, $now ) ); // phpcs:ignore WordPress.DB
		$fields['updated_at'] = $now;
		$wpdb->update( $table, $fields, array( 'article_job_id' => $article_job_id ) );
	}

	private static function log( string $severity, string $message, array $job, array $extra = array() ): void {
		Events::log(
			'image',
			$severity,
			$message,
			array_merge(
				array(
					'article_job_id' => (int) $job['id'],
					'category_id'    => (int) $job['category_id'],
					'source_item_id' => (int) $job['source_item_id'],
				),
				$extra
			)
		);
	}

	/**
	 * No usable image was found. Every post needs one, so the article must not be published.
	 */
	public static function give_up( array $job, string $reason ): array {
		self::save_run(
			(int) $job['id'],
			array(
				'status' => 'none',
				'reason' => $reason,
			)
		);
		self::log( 'warning', 'No usable image: ' . $reason, $job, array( 'status' => 'none', 'error_code' => 'no_image' ) );
		return array(
			'action' => self::HOLD,
			'reason' => $reason,
		);
	}

	/**
	 * Called once an article is confirmed eligible at its slot, before its post is created.
	 *
	 * @return array{action:string, reason?:string, delay?:int, history_id?:int, attachment_id?:int}
	 */
	public static function prepare( array $job, array $item, array $content ): array {
		$article_job_id = (int) $job['id'];
		$run            = self::get_run( $article_job_id );

		// Already done on an earlier attempt, so reuse it. A retry never picks a second image.
		if ( $run && in_array( $run['status'], array( 'ready', 'assigned' ), true ) && (int) $run['attachment_id'] && get_post( (int) $run['attachment_id'] ) ) {
			return array(
				'action'        => self::USE,
				'history_id'    => (int) $run['history_id'],
				'attachment_id' => (int) $run['attachment_id'],
			);
		}
		$held = ImageHistory::recover_for_article( $article_job_id );
		if ( $held ) {
			self::save_run(
				$article_job_id,
				array(
					'status'        => 'ready',
					'history_id'    => (int) $held['id'],
					'attachment_id' => (int) $held['attachment_id'],
				)
			);
			return array(
				'action'        => self::USE,
				'history_id'    => (int) $held['id'],
				'attachment_id' => (int) $held['attachment_id'],
			);
		}
		if ( $run && 'none' === $run['status'] ) {
			return array(
				'action' => self::HOLD,
				'reason' => (string) $run['reason'],
			);
		}

		$attempts = (int) ( $run['attempts'] ?? 0 ) + 1;
		if ( $attempts > (int) Settings::get( 'image_max_attempts' ) ) {
			return self::give_up( $job, 'The image step used all its attempts. Last problem: ' . (string) ( $run['reason'] ?? 'unknown' ) );
		}
		self::save_run(
			$article_job_id,
			array(
				'status'   => 'searching',
				'attempts' => $attempts,
			)
		);

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$tags     = json_decode( (string) $content['tags_json'], true ) ?: array();
		$category = Categories::get( (int) $job['category_id'] );
		$name     = $category ? (string) $category['name'] : '';
		$analysis = ArticleAnalyzer::analyze( (string) $content['title'], (string) $content['body'], $tags, (string) $item['headline'], $name, (string) ( $item['publisher'] ?? '' ) );
		$queries  = array_values( $analysis['queries'] );

		$strong = max( self::STRONG_MATCH, (int) Settings::get( 'image_min_score' ) );
		$enough = static function ( array $items ) use ( $analysis, $name, $strong ): bool {
			foreach ( self::review( $items, $analysis, $name )['eligible'] as $candidate ) {
				if ( $candidate['score'] >= $strong ) {
					return true;
				}
			}
			return false;
		};

		$items        = array();
		$searched     = array();
		$searches     = (int) ( $run['searches'] ?? 0 );
		$checked      = (int) ( $run['candidates_checked'] ?? 0 );
		$search_temp  = false;
		$search_error = '';
		$search_wait  = 0;
		$temporary    = '';
		$retry        = 0;

		// Searching stops at a strong match. If none of the files found so far can be used, the other searches run too.
		while ( true ) {
			$first = ! $searched;
			$found = self::search_all( array_slice( $queries, count( $searched ) ), $enough );
			foreach ( $found['items'] as $found_item ) {
				$items[ $found_item['page_id'] ] = $found_item;
			}
			$searched = array_merge( $searched, $found['searched'] );
			if ( $found['temporary'] ) {
				$search_temp  = true;
				$search_error = $found['error'];
				$search_wait  = max( $search_wait, (int) $found['retry_after'] );
			}

			// Nothing reached Wikimedia this time, because it asked for a wait, so this does not use up an attempt.
			if ( $first && $found['not_sent'] && 0 === $found['requests'] ) {
				$attempts--;
			}

			$searches += $found['requests'];
			self::save_run(
				$article_job_id,
				array(
					'attempts' => $attempts,
					'queries'  => wp_json_encode( $queries ),
					'searches' => $searches,
				)
			);

			if ( ! $items && $search_temp ) {
				return self::retry_later( $job, $attempts, $search_error, $search_wait );
			}

			$reviewed = self::review( array_values( $items ), $analysis, $name );
			$checked += count( $found['items'] );
			self::save_run(
				$article_job_id,
				array(
					'candidates'         => wp_json_encode( self::summaries( $reviewed['all'] ) ),
					'candidates_checked' => $checked,
				)
			);
			self::log(
				'info',
				sprintf( 'Searched Wikimedia Commons: %1$d files checked, %2$d passed. Searches: %3$s%4$s', count( $reviewed['all'] ), count( $reviewed['eligible'] ), implode( ' | ', $found['searched'] ), $found['stopped_early'] ? sprintf( ' (stopped after %1$d of %2$d, because a file scored %3$d or more)', count( $searched ), count( $queries ), $strong ) : '' ),
				$job,
				array(
					'status'  => 'searched',
					'attempt' => $attempts,
				)
			);

			if ( ! $reviewed['eligible'] ) {
				// Some searches or downloads did not finish, so this is not yet proof that no file suits. Earlier results are cached.
				if ( '' !== $temporary ) {
					return self::retry_later( $job, $attempts, $temporary, $retry );
				}
				if ( $search_temp ) {
					return self::retry_later( $job, $attempts, $search_error, $search_wait );
				}
				return self::give_up( $job, self::no_match_reason( $reviewed['all'], $found['error'] ) );
			}

			$chosen = self::try_files( $reviewed['eligible'], $job, $content, $temporary, $retry );
			if ( $chosen ) {
				return $chosen;
			}
			if ( ! $found['stopped_early'] || count( $searched ) >= count( $queries ) ) {
				break;
			}
			self::log( 'info', 'None of the files found so far could be used, so the remaining searches run now.', $job );
			$enough = null;
		}

		if ( '' !== $temporary ) {
			return self::retry_later( $job, $attempts, $temporary, $retry );
		}
		if ( $search_temp ) {
			return self::retry_later( $job, $attempts, $search_error, $search_wait );
		}
		return self::give_up( $job, 'Every suitable file was already used, or failed the file checks.' );
	}

	/**
	 * Tries the best files in turn, and imports the first that passes every file check.
	 *
	 * @return array|null The image to use, or null when none of these files could be used.
	 */
	private static function try_files( array $eligible, array $job, array $content, string &$temporary, int &$retry ): ?array {
		$article_job_id = (int) $job['id'];
		$tries          = 0;

		foreach ( $eligible as $candidate ) {
			if ( ++$tries > self::TRY_FILES ) {
				break;
			}

			$history_id = ImageHistory::reserve( $candidate, $candidate['license_info'], $article_job_id, wp_strip_all_tags( (string) $content['title'] ) );
			if ( ! $history_id ) {
				self::log( 'info', 'Another article already holds this image: ' . $candidate['title'], $job );
				continue;
			}

			$file = MediaImporter::download( $candidate );
			if ( ! $file['ok'] ) {
				if ( $file['temporary'] ) {
					ImageHistory::release_temporary( $history_id, $file['reason'] );
					$temporary = $file['reason'];
					$retry     = max( $retry, (int) $file['retry_after'] );
				} else {
					ImageHistory::reject( $history_id, $file['reason'] );
				}
				self::log( 'warning', sprintf( 'Could not use %1$s: %2$s', $candidate['title'], $file['reason'] ), $job, array( 'error_code' => 'image_download' ) );
				continue;
			}

			$same = ImageHistory::find_same_file( $history_id, $file['sha256'], $file['phash'] );
			ImageHistory::update(
				$history_id,
				array(
					'sha256'        => $file['sha256'],
					'phash'         => $file['phash'],
					'downloaded_at' => Clock::mysql(),
				)
			);
			if ( $same ) {
				wp_delete_file( $file['path'] );
				ImageHistory::reject( $history_id, 'The same picture was already used: ' . (string) $same['image_title'] );
				self::log( 'warning', sprintf( '%1$s is the same picture as %2$s, which was already used.', $candidate['title'], (string) $same['image_title'] ), $job, array( 'error_code' => 'image_duplicate' ) );
				continue;
			}

			$record     = (array) ImageHistory::get( $history_id );
			$attachment = MediaImporter::sideload( $file['path'], $file['ext'], $record );
			if ( is_wp_error( $attachment ) ) {
				ImageHistory::release_temporary( $history_id, $attachment->get_error_message() );
				$temporary = 'The Media Library refused the image: ' . $attachment->get_error_message();
				self::log( 'warning', $temporary, $job, array( 'error_code' => 'image_library' ) );
				continue;
			}

			ImageHistory::update(
				$history_id,
				array(
					'attachment_id' => (int) $attachment,
					'status'        => ImageHistory::IMPORTED,
				)
			);
			self::save_run(
				$article_job_id,
				array(
					'status'        => 'ready',
					'history_id'    => $history_id,
					'attachment_id' => (int) $attachment,
					'reason'        => '',
				)
			);
			self::log( 'info', sprintf( 'Selected %1$s (score %2$d, %3$s, by %4$s). Commons page: %5$s', $candidate['title'], $candidate['score'], $candidate['license_info']['license'], $candidate['license_info']['creator'] ?: 'creator not stated', $candidate['page_url'] ), $job, array( 'status' => 'imported' ) );

			return array(
				'action'        => self::USE,
				'history_id'    => $history_id,
				'attachment_id' => (int) $attachment,
			);
		}
		return null;
	}

	private static function retry_later( array $job, int $attempts, string $reason, int $retry_after ): array {
		if ( $attempts >= (int) Settings::get( 'image_max_attempts' ) ) {
			return self::give_up( $job, 'A temporary problem did not clear: ' . $reason );
		}
		$delay = max( $retry_after, Jobs::backoff( $attempts, 120, 900 ) );
		self::save_run(
			(int) $job['id'],
			array(
				'status' => 'waiting',
				'reason' => $reason,
			)
		);
		self::log( 'warning', sprintf( 'Temporary image problem, trying again in about %1$d seconds: %2$s', $delay, $reason ), $job, array( 'error_code' => 'image_temporary', 'attempt' => $attempts ) );
		return array(
			'action' => self::RETRY,
			'delay'  => $delay,
			'reason' => $reason,
		);
	}

	/**
	 * Runs each search and merges the results.
	 *
	 * @return array{items:array, requests:int, temporary:bool, retry_after:int, error:string, not_sent:bool}
	 */
	private static function search_all( array $queries, ?callable $enough = null ): array {
		$out = array(
			'items'         => array(),
			'searched'      => array(),
			'stopped_early' => false,
			'requests'    => 0,
			'temporary'   => false,
			'retry_after' => 0,
			'error'       => '',
			'not_sent'    => false,
		);
		foreach ( $queries as $query ) {
			$result            = Commons::search( (string) $query );
			$out['searched'][] = (string) $query;
			if ( empty( $result['cached'] ) && empty( $result['not_sent'] ) ) {
				$out['requests']++;
			}
			if ( ! $result['ok'] ) {
				$out['error']       = $result['error'];
				$out['temporary']   = $out['temporary'] || $result['temporary'];
				$out['retry_after'] = max( $out['retry_after'], (int) $result['retry_after'] );
				$out['not_sent']    = $out['not_sent'] || ! empty( $result['not_sent'] );
				// A rate limit applies to every search, so stop at once.
				if ( $result['retry_after'] > 0 ) {
					break;
				}
				continue;
			}
			foreach ( $result['items'] as $item ) {
				$out['items'][ $item['page_id'] ] = $item;
			}
			// Never stop on the first search alone. The first query is built from the single
			// subject the analyzer thought most likely, and when that subject is wrong the first
			// query is exactly the one that finds a confident, irrelevant picture. Searching at
			// least twice costs one more request and gives a wrong first guess something to lose
			// to. Wikimedia Commons is free, and the comparison is on files already in hand.
			$searched_enough = count( $out['searched'] ) >= 2 || count( $queries ) < 2;
			if ( $enough && $searched_enough && $enough( array_values( $out['items'] ) ) ) {
				$out['stopped_early'] = true;
				break;
			}
		}
		$out['items'] = array_values( $out['items'] );
		return $out;
	}

	/**
	 * Applies every rule to every file. High scores never override a failure.
	 *
	 * @return array{all:array, eligible:array}
	 */
	public static function review( array $items, array $analysis, string $category_name ): array {
		$all      = array();
		$eligible = array();
		$min      = (int) Settings::get( 'image_min_score' );

		foreach ( $items as $candidate ) {
			$license                   = LicenseChecker::check( (array) $candidate['extmetadata'] );
			$scored                    = CandidateScorer::score( $candidate, $analysis );
			$candidate['license_info'] = $license;
			$candidate['score']        = $scored['total'];
			$candidate['parts']        = $scored['parts'];
			$candidate['subjects']     = CandidateScorer::subjects_matched( $candidate, $analysis );
			$candidate['rejected']     = '';

			if ( ! $license['ok'] ) {
				$candidate['rejected'] = $license['reason'];
			} else {
				$block = ImageHistory::find_block( $candidate );
				if ( $block ) {
					$candidate['rejected'] = 'Already used or reserved: this image is permanently recorded.';
				} else {
					$failure = CandidateScorer::mandatory_failure( $candidate, $analysis, $category_name );
					if ( '' !== $failure ) {
						$candidate['rejected'] = $failure;
					} elseif ( $scored['total'] < $min ) {
						$candidate['rejected'] = sprintf( 'Relevance score %1$d is below the minimum of %2$d.', $scored['total'], $min );
					}
				}
			}

			$all[] = $candidate;
			if ( '' === $candidate['rejected'] ) {
				$eligible[] = $candidate;
			}
		}

		// Between two pictures that score alike, the one naming more of the article's subjects is
		// the better illustration: a photograph of both people in the headline beats a photograph of
		// one of them. The bonus is small on purpose, so it decides near-ties and never overrides a
		// picture that is plainly the better match. The score itself is reported unchanged.
		$rank     = static function ( array $candidate ): int {
			return (int) $candidate['score'] + ( (int) ( $candidate['subjects'] ?? 0 ) > 1 ? self::FULLER_PICTURE : 0 );
		};
		$by_score = static function ( $a, $b ) use ( $rank ) {
			return $rank( $b ) <=> $rank( $a );
		};
		usort( $eligible, $by_score );
		usort( $all, $by_score );

		return array(
			'all'      => $all,
			'eligible' => $eligible,
		);
	}

	private static function no_match_reason( array $all, string $error ): string {
		if ( ! $all ) {
			return '' !== $error ? 'The search failed: ' . $error : 'The searches found no files.';
		}
		$counts = array();
		foreach ( $all as $candidate ) {
			$key            = preg_replace( '/\d+/', 'N', (string) $candidate['rejected'] );
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
		arsort( $counts );
		$reason = (string) array_key_first( $counts );
		// Grouped with the numbers left out, so say the real ones: the minimum and how close a file came.
		if ( 0 === strpos( $reason, 'Relevance score' ) ) {
			$best = 0;
			foreach ( $all as $candidate ) {
				if ( 0 === strpos( (string) $candidate['rejected'], 'Relevance score' ) ) {
					$best = max( $best, (int) $candidate['score'] );
				}
			}
			$reason = sprintf( 'The relevance score is below the minimum of %1$d. The best score was %2$d.', (int) Settings::get( 'image_min_score' ), $best );
		}
		return sprintf( '%1$d files checked, none passed. Most common reason: %2$s', count( $all ), $reason );
	}

	/**
	 * Keeps what the admin screens need, without storing whole metadata records.
	 */
	public static function summaries( array $all ): array {
		$out = array();
		foreach ( array_slice( $all, 0, 40 ) as $candidate ) {
			$out[] = array(
				'title'    => (string) $candidate['title'],
				'page_url' => (string) $candidate['page_url'],
				'thumb'    => Commons::is_preview_url( (string) $candidate['thumb_url'] ) ? (string) $candidate['thumb_url'] : '',
				'size'     => $candidate['width'] . ' x ' . $candidate['height'],
				'license'  => (string) $candidate['license_info']['license'],
				'creator'  => (string) $candidate['license_info']['creator'],
				'score'    => (int) $candidate['score'],
				'rejected' => (string) $candidate['rejected'],
			);
		}
		return $out;
	}

	/**
	 * Sets the featured image and saves the credit, then confirms both.
	 *
	 * @return true|\WP_Error
	 */
	public static function assign( int $post_id, array $job, int $history_id, int $attachment_id ) {
		$record = ImageHistory::get( $history_id );
		if ( ! $record ) {
			return new \WP_Error( 'image_record_missing', 'The image record is missing.' );
		}

		ImageHistory::update( $history_id, array( 'wp_post_id' => $post_id ) );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $post_id,
			)
		);
		set_post_thumbnail( $post_id, $attachment_id );

		$credit = Attribution::from_record( $record );
		update_post_meta( $post_id, Attribution::META, $credit );

		if ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id ) {
			delete_post_meta( $post_id, Attribution::META );
			return new \WP_Error( 'image_not_assigned', 'WordPress did not save the featured image.' );
		}
		if ( ! Attribution::is_complete( (array) get_post_meta( $post_id, Attribution::META, true ) ) ) {
			delete_post_thumbnail( $post_id );
			delete_post_meta( $post_id, Attribution::META );
			return new \WP_Error( 'image_credit_incomplete', 'The image credit could not be saved, so the image was not used.' );
		}

		if ( ! Attribution::save_to_post( $post_id ) ) {
			self::log( 'warning', 'The credit shows on the page, but its copy in the post text could not be saved. It would not stay if the plugin were deactivated.', $job, array( 'wp_post_id' => $post_id ) );
		}

		ImageHistory::update(
			$history_id,
			array(
				'status'      => ImageHistory::ASSIGNED,
				'assigned_at' => Clock::mysql(),
			)
		);
		self::save_run(
			(int) $job['id'],
			array(
				'status' => 'assigned',
				'reason' => '',
			)
		);
		self::log( 'info', sprintf( 'Featured image %1$d assigned to post %2$d, and its credit was saved.', $attachment_id, $post_id ), $job, array( 'wp_post_id' => $post_id, 'status' => 'assigned' ) );
		return true;
	}

	/**
	 * Records a failed assignment. The image stays reserved, because it was already chosen.
	 */
	public static function record_failure( array $job, int $post_id, string $reason ): void {
		self::save_run(
			(int) $job['id'],
			array(
				'status' => 'failed',
				'reason' => $reason,
			)
		);
		self::log( 'error', 'Featured image failed: ' . $reason, $job, array( 'wp_post_id' => $post_id, 'error_code' => 'image_assign' ) );
	}

	/**
	 * Gives back an image found for an article that will not be published, so another post can use it.
	 */
	public static function release_unused( int $article_job_id ): void {
		global $wpdb;
		$table = Db::table( 'image_history' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, attachment_id FROM {$table} WHERE article_job_id = %d AND status IN (%s, %s)", $article_job_id, ImageHistory::RESERVED, ImageHistory::IMPORTED ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			if ( (int) $row['attachment_id'] ) {
				wp_delete_attachment( (int) $row['attachment_id'], true );
			}
			ImageHistory::update( (int) $row['id'], array( 'attachment_id' => 0 ) );
			ImageHistory::release_temporary( (int) $row['id'], 'The article it was found for was not published.' );
		}
	}

	/**
	 * Gives an article a fresh set of image attempts, after an administrator asks for a retry,
	 * or once automatically for a held article.
	 */
	/**
	 * Forgets an image an administrator released, so the article searches again.
	 *
	 * reset_run deliberately leaves a run that is ready or assigned alone, because an article
	 * that already has its picture should not keep searching. That is right everywhere except
	 * here: when an administrator releases the reservation, the whole point is that this article
	 * must not use this image. Without this the article publishes with the image anyway, because
	 * prepare() reuses a ready run before it searches.
	 *
	 * The downloaded attachment is left in the Media Library rather than deleted, so nothing is
	 * destroyed if the image turns out to be in use somewhere else.
	 *
	 * @param int $article_job_id The article that was holding it.
	 * @param int $history_id     The reservation that was released.
	 * @return bool True when the article will now search again.
	 */
	public static function forget_released_image( int $article_job_id, int $history_id ): bool {
		$run = self::get_run( $article_job_id );
		if ( ! $run || (int) $run['history_id'] !== $history_id ) {
			return false;
		}
		// An image already on a published post stays there. Releasing a reservation must not
		// take the picture off something readers can already see.
		if ( 'assigned' === (string) $run['status'] ) {
			return false;
		}

		self::save_run(
			$article_job_id,
			array(
				'status'        => 'pending',
				'attempts'      => 0,
				'history_id'    => 0,
				'attachment_id' => 0,
				'reason'        => 'The image was released by an administrator, so another one is needed.',
			)
		);
		return true;
	}

	public static function reset_run( int $article_job_id, bool $automatic = false ): void {
		$run = self::get_run( $article_job_id );
		if ( $run && ! in_array( $run['status'], array( 'ready', 'assigned' ), true ) ) {
			$fields = array(
				'status'   => 'pending',
				'attempts' => 0,
				'reason'   => '',
			);
			if ( $automatic ) {
				$fields['auto_retried'] = 1;
			}
			self::save_run( $article_job_id, $fields );
		}
	}

	/**
	 * Why a post's featured image cannot be shown, or an empty string when it can.
	 * The file is checked on the server, because a request to the site's own address fails on many hosts.
	 */
	public static function image_problem( int $post_id ): string {
		$attachment_id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $attachment_id ) {
			return 'The post has no featured image.';
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return 'The featured image is missing from the Media Library, or is not a picture.';
		}
		$file = (string) get_attached_file( $attachment_id );
		if ( '' === $file || ! is_readable( $file ) || ! filesize( $file ) ) {
			return 'The featured image file is missing from the server.';
		}
		$size = wp_getimagesize( $file );
		if ( ! $size || (int) $size[0] < 1 || (int) $size[1] < 1 ) {
			return 'The featured image file cannot be opened as a picture.';
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['width'] ) || ! wp_get_attachment_image_url( $attachment_id, 'full' ) ) {
			return 'WordPress has not finished preparing the featured image.';
		}
		return '';
	}

	/**
	 * Articles held because no usable image was found.
	 */
	public static function held_count(): int {
		global $wpdb;
		$jobs = Db::table( 'article_jobs' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs} WHERE status = %s AND error_code = %s", Lifecycle::PUBLISH_FAILED, 'image_required' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Published posts from this plugin with no featured image, such as posts from before images were required.
	 */
	public static function posts_without_image( int $limit = 20 ): array {
		global $wpdb;
		$links = Db::table( 'post_links' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.article_job_id, l.wp_post_id FROM {$links} l JOIN {$wpdb->posts} p ON p.ID = l.wp_post_id LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id' WHERE p.post_status = 'publish' AND ( m.meta_value IS NULL OR m.meta_value IN ('', '0') ) ORDER BY l.id DESC LIMIT %d", // phpcs:ignore WordPress.DB
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Administrator action: tries again for an article already created without an image.
	 * Every license and history check still applies, and the article is not regenerated.
	 *
	 * @return true|string
	 */
	public static function retry_for_article( int $article_job_id ) {
		$job = Lifecycle::job( $article_job_id );
		if ( ! $job ) {
			return 'This article no longer exists.';
		}
		$item    = Lifecycle::item( (int) $job['source_item_id'] );
		$content = Lifecycle::content( $article_job_id );
		if ( ! $item || ! $content ) {
			return 'The article record is incomplete.';
		}

		// A draft kept back for its image is found too, not only posts that went public.
		$post_id = \ZZZCopy\Publisher::find_existing_post( $job );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return 'This article has no WordPress post yet. It gets an image when it reaches its slot.';
		}
		if ( has_post_thumbnail( $post_id ) ) {
			return 'This post already has a featured image.';
		}

		self::reset_run( $article_job_id );
		$result = self::prepare( $job, $item, $content );
		if ( self::USE !== $result['action'] ) {
			return (string) ( $result['reason'] ?? 'No image could be used.' );
		}

		$assigned = self::assign( $post_id, $job, (int) $result['history_id'], (int) $result['attachment_id'] );
		if ( is_wp_error( $assigned ) ) {
			self::record_failure( $job, $post_id, $assigned->get_error_message() );
			return $assigned->get_error_message();
		}
		return true;
	}

	/**
	 * Totals for the Dashboard and Usage screens. A local date limits them to that day.
	 */
	public static function stats( string $from = '', string $to = '' ): array {
		global $wpdb;
		$runs    = Db::table( 'image_runs' );
		$history = Db::table( 'image_history' );

		$range = '' !== $from ? $wpdb->prepare( ' AND updated_at BETWEEN %s AND %s', $from, $to ) : '';
		$row   = $wpdb->get_row( "SELECT COALESCE(SUM(searches),0) AS searches, COALESCE(SUM(candidates_checked),0) AS checked, COALESCE(SUM(status = 'none'),0) AS no_image, COALESCE(SUM(status = 'failed'),0) AS failed FROM {$runs} WHERE 1=1{$range}", ARRAY_A ); // phpcs:ignore WordPress.DB

		$download_range = '' !== $from ? $wpdb->prepare( ' AND downloaded_at BETWEEN %s AND %s', $from, $to ) : '';
		$assign_range   = '' !== $from ? $wpdb->prepare( ' AND assigned_at BETWEEN %s AND %s', $from, $to ) : '';
		$assigned_state = $wpdb->prepare( 'status = %s', ImageHistory::ASSIGNED );

		return array(
			'searches'   => (int) $row['searches'],
			'checked'    => (int) $row['checked'],
			'no_image'   => (int) $row['no_image'],
			'failed'     => (int) $row['failed'],
			'downloaded' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$history} WHERE downloaded_at IS NOT NULL{$download_range}" ), // phpcs:ignore WordPress.DB
			'assigned'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$history} WHERE {$assigned_state}{$assign_range}" ), // phpcs:ignore WordPress.DB
		);
	}
}
