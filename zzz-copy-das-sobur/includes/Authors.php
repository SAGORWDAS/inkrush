<?php
namespace ZZZCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Which WordPress users this plugin may credit with its posts.
 *
 * Nobody is authorised automatically, whatever their WordPress role. A plugin administrator grants,
 * pauses or revokes access on the Authors page. WordPress roles and capabilities are never changed.
 * The worker checks the credited author right before each post, so a change applies at once.
 */
final class Authors {

	public const ACTIVE  = 'active';
	public const PAUSED  = 'paused';
	public const REVOKED = 'revoked';

	/** Shown, never stored: the WordPress user no longer exists, or was never authorised. */
	public const MISSING = 'missing';
	public const NONE    = 'none';

	public const STATUSES = array( self::ACTIVE, self::PAUSED, self::REVOKED );

	/** How many users one request may change. */
	public const MAX_BATCH = 200;

	/** @var array<int,string> */
	private static $labels = array();

	public static function row( int $user_id ): ?array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return null;
		}
		$table = Db::table( 'authors' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/**
	 * @param int[] $user_ids
	 * @return array<int,array> Keyed by user id.
	 */
	public static function rows( array $user_ids ): array {
		global $wpdb;
		$user_ids = array_values( array_filter( array_map( 'absint', $user_ids ) ) );
		if ( ! $user_ids ) {
			return array();
		}
		$table = Db::table( 'authors' );
		$list  = implode( ',', $user_ids );
		$out   = array();
		// $list holds only integers.
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE user_id IN ({$list})", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$out[ (int) $row['user_id'] ] = $row;
		}
		return $out;
	}

	/**
	 * User ids with a stored status, optionally only one status.
	 *
	 * @return int[]
	 */
	public static function ids( string $status = '' ): array {
		global $wpdb;
		$table = Db::table( 'authors' );
		$ids   = '' === $status
			? $wpdb->get_col( "SELECT user_id FROM {$table} ORDER BY user_id" ) // phpcs:ignore WordPress.DB
			: $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE status = %s ORDER BY user_id", $status ) ); // phpcs:ignore WordPress.DB
		return array_map( 'intval', $ids );
	}

	/**
	 * Active authors whose WordPress user still exists.
	 *
	 * @return int[]
	 */
	public static function active_ids(): array {
		return array_values(
			array_filter(
				self::ids( self::ACTIVE ),
				static function ( $id ) {
					return (bool) get_userdata( $id );
				}
			)
		);
	}

	public static function status( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return self::NONE;
		}
		$row = self::row( $user_id );
		if ( ! get_userdata( $user_id ) ) {
			return $row ? self::MISSING : self::NONE;
		}
		return $row ? (string) $row['status'] : self::NONE;
	}

	public static function is_active( int $user_id ): bool {
		return self::ACTIVE === self::status( $user_id );
	}

	public static function status_label( string $status ): string {
		$labels = array(
			self::ACTIVE  => 'Active',
			self::PAUSED  => 'Paused',
			self::REVOKED => 'Revoked',
			self::MISSING => 'User missing',
			self::NONE    => 'Not authorised',
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * A readable name for logs and screens. Never includes the email address.
	 */
	public static function user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		if ( ! isset( self::$labels[ $user_id ] ) ) {
			$user                     = get_userdata( $user_id );
			self::$labels[ $user_id ] = $user
				? ( $user->display_name && $user->display_name !== $user->user_login ? sprintf( '%1$s (%2$s)', $user->display_name, $user->user_login ) : $user->user_login )
				: sprintf( 'User %d (deleted)', $user_id );
		}
		return self::$labels[ $user_id ];
	}

	/** The user the next post is credited to: the Post author setting. */
	public static function credited_user(): int {
		return (int) Settings::get( 'post_author' );
	}

	/**
	 * Why a post cannot be credited to this user. Empty when it can.
	 */
	public static function problem( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'No post author is chosen. Authorise a user on the Authors page, then choose them as Post author on the Publishing page.';
		}
		switch ( self::status( $user_id ) ) {
			case self::ACTIVE:
				return '';
			case self::MISSING:
				return sprintf( 'The post author, user %d, no longer exists in WordPress. Choose another authorised author on the Publishing page.', $user_id );
			case self::PAUSED:
				return sprintf( 'The post author, %s, is paused on the Authors page. Resume them, or choose another author on the Publishing page.', self::user_label( $user_id ) );
			case self::REVOKED:
				return sprintf( 'The post author, %s, no longer has access. Grant access on the Authors page, or choose another author on the Publishing page.', self::user_label( $user_id ) );
			default:
				return sprintf( 'The post author, %s, is not authorised. Grant access on the Authors page, or choose another author on the Publishing page.', self::user_label( $user_id ) );
		}
	}

	/**
	 * Why the next post cannot be published, as far as its author goes. Empty when it can.
	 * In Multiple users mode each time has its own author, checked for that time, so this only
	 * asks whether anyone can post at all.
	 */
	public static function publishing_problem(): string {
		if ( AuthorSchedules::multiple() ) {
			return AuthorSchedules::ready_ids() ? '' : 'Multiple users mode is on, but no active author has a schedule. On the Authors page, open Schedule next to an author, switch it on and add a post time, or switch back to Single user mode.';
		}
		return self::problem( self::credited_user() );
	}

	/** What happens to this user's posts when their access stops, for the log. */
	private static function stop_note( int $user_id, bool $deleted ): string {
		if ( AuthorSchedules::multiple() ) {
			return AuthorSchedules::usable( AuthorSchedules::get( $user_id ) ) ? ' Their post times are not used until they are active again. Articles already written stay ready for other authors.' : '';
		}
		if ( self::credited_user() !== $user_id ) {
			return '';
		}
		return $deleted ? ' They were the Post author, so posts are held until another author is chosen on the Publishing page.' : ' They are the Post author, so posts are held until they are active again or another author is chosen.';
	}

	/**
	 * Grants, pauses or revokes access for several users, and records who did it.
	 *
	 * @param int[] $user_ids
	 * @return array{changed:int[], errors:string[]}
	 */
	public static function change( array $user_ids, string $status ): array {
		global $wpdb;
		$out = array(
			'changed' => array(),
			'errors'  => array(),
		);
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$out['errors'][] = 'Unknown access status.';
			return $out;
		}
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( count( $user_ids ) > self::MAX_BATCH ) {
			$out['errors'][] = sprintf( 'Change at most %d users at a time.', self::MAX_BATCH );
			return $out;
		}

		$table = Db::table( 'authors' );
		$actor = (int) get_current_user_id();
		$now   = Clock::mysql();
		$rows  = self::rows( $user_ids );

		foreach ( $user_ids as $user_id ) {
			if ( ! get_userdata( $user_id ) ) {
				$out['errors'][] = sprintf( 'User %d does not exist.', $user_id );
				continue;
			}
			$row    = $rows[ $user_id ] ?? null;
			$before = $row ? (string) $row['status'] : self::NONE;
			if ( $before === $status ) {
				continue;
			}
			if ( ! $row && self::ACTIVE !== $status ) {
				$out['errors'][] = sprintf( '%s is not authorised, so there is nothing to %s.', self::user_label( $user_id ), self::PAUSED === $status ? 'pause' : 'revoke' );
				continue;
			}

			$fields = array(
				'status'     => $status,
				'changed_by' => $actor,
				'changed_at' => $now,
			);
			if ( self::ACTIVE === $status && self::PAUSED !== $before ) {
				$fields['granted_by'] = $actor;
				$fields['granted_at'] = $now;
			}
			if ( $row ) {
				$wpdb->update( $table, $fields, array( 'user_id' => $user_id ) );
			} else {
				$wpdb->insert( $table, array_merge( $fields, array( 'user_id' => $user_id ) ) );
			}
			$out['changed'][] = $user_id;

			$verbs = array(
				self::ACTIVE  => self::PAUSED === $before ? 'resumed access for' : 'granted access to',
				self::PAUSED  => 'paused access for',
				self::REVOKED => 'revoked access for',
			);
			$credited = self::ACTIVE !== $status ? self::stop_note( $user_id, false ) : '';
			Events::log(
				'access',
				self::ACTIVE === $status ? 'info' : 'warning',
				sprintf( '%1$s %2$s %3$s.%4$s', $actor ? self::user_label( $actor ) : 'The plugin', $verbs[ $status ], self::user_label( $user_id ), $credited ),
				array(
					'status'         => $status,
					'author_user_id' => $user_id,
				)
			);
		}
		return $out;
	}

	/**
	 * Records an author without logging, for the upgrade and the test harness.
	 */
	public static function insert_active( int $user_id, int $by = 0 ): void {
		global $wpdb;
		$table = Db::table( 'authors' );
		$now   = Clock::mysql();
		if ( self::row( $user_id ) ) {
			$wpdb->update( $table, array( 'status' => self::ACTIVE, 'changed_by' => $by, 'changed_at' => $now ), array( 'user_id' => $user_id ) );
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'status'     => self::ACTIVE,
				'granted_by' => $by,
				'granted_at' => $now,
				'changed_by' => $by,
				'changed_at' => $now,
			)
		);
	}

	/**
	 * A deleted WordPress user keeps their row, shown as User missing, so the history stays readable.
	 */
	public static function on_deleted_user( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! self::row( $user_id ) ) {
			return;
		}
		$held = self::stop_note( $user_id, true );
		Events::log( 'access', 'warning', sprintf( 'WordPress user %d was deleted, so they can no longer be credited with posts.%s', $user_id, $held ), array( 'author_user_id' => $user_id, 'status' => self::MISSING ) );
	}

	/**
	 * A role change is recorded but changes nothing here, because access is granted only on the Authors page.
	 */
	public static function on_role_change( $user_id, $role, $old_roles = array() ): void {
		$user_id = (int) $user_id;
		if ( ! self::row( $user_id ) ) {
			return;
		}
		Events::log(
			'access',
			'info',
			sprintf( 'The WordPress role of %1$s changed from %2$s to %3$s. Their access to this plugin did not change.', self::user_label( $user_id ), $old_roles ? implode( ', ', (array) $old_roles ) : 'none', $role ? (string) $role : 'none' ),
			array( 'author_user_id' => $user_id )
		);
	}

	/**
	 * What this user's WordPress role lets them do with posts credited to them.
	 */
	public static function edit_warning( \WP_User $user ): string {
		if ( user_can( $user, 'edit_others_posts' ) ) {
			return 'Their WordPress role can already edit every post.';
		}
		if ( user_can( $user, 'edit_published_posts' ) ) {
			return 'Their WordPress role lets them edit and delete published posts credited to them.';
		}
		if ( user_can( $user, 'edit_posts' ) ) {
			return 'Their WordPress role lets them edit drafts credited to them, but not published posts.';
		}
		return '';
	}

	/**
	 * Posts this plugin has credited to each user.
	 *
	 * @param int[] $user_ids
	 * @return array<int,int>
	 */
	public static function credited_posts( array $user_ids ): array {
		global $wpdb;
		$user_ids = array_values( array_filter( array_map( 'absint', $user_ids ) ) );
		if ( ! $user_ids ) {
			return array();
		}
		$table = Db::table( 'post_links' );
		$list  = implode( ',', $user_ids );
		$out   = array();
		// $list holds only integers.
		foreach ( (array) $wpdb->get_results( "SELECT author_user_id, COUNT(*) AS n FROM {$table} WHERE author_user_id IN ({$list}) GROUP BY author_user_id", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$out[ (int) $row['author_user_id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * @return array<string,int> Stored status => count.
	 */
	public static function counts(): array {
		global $wpdb;
		$table = Db::table( 'authors' );
		$out   = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** For tests: names are cached for one request. */
	public static function flush_labels(): void {
		self::$labels = array();
	}
}
