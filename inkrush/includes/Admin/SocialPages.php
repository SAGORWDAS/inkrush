<?php
namespace ZZZCopy\Admin;

use ZZZCopy\Schedule;
use ZZZCopy\Settings;
use ZZZCopy\Social\Platforms;
use ZZZCopy\Social\SocialAccounts;
use ZZZCopy\Social\SocialManager;
use ZZZCopy\Social\SocialQueue;
use ZZZCopy\Social\SocialSecrets;
use ZZZCopy\Social\SocialSettings;

defined( 'ABSPATH' ) || exit;

/**
 * The two social media screens.
 *
 * They are built from the same layout helpers as every other InkRush screen, so they look and
 * behave like the rest of the plugin rather than like something bolted on. No access token is
 * ever printed here: the account rows these screens read have already had their credential
 * columns removed, and an application secret is shown only as its last four characters.
 */
final class SocialPages {

	/* ---- InkRush, Social Media ---- */

	public static function accounts(): void {
		Pages::open( 'Social Media', 'zzzc-social' );

		self::overview_card();
		self::global_settings_card();

		echo '<h3 class="zzzc-section-title">Accounts</h3>';
		echo '<p class="zzzc-help">Connect an account, then switch its platform on. Nothing is published to a platform that is switched off, and nothing is published at all until Social media distribution is on above.</p>';

		foreach ( Platforms::ORDER as $platform ) {
			self::platform_card( $platform );
		}

		Pages::close();
	}

	/**
	 * What is on and what is connected, in one glance, plus the one address every platform needs.
	 */
	private static function overview_card(): void {
		$enabled   = SocialSettings::enabled();
		$connected = SocialAccounts::count_connected();
		$active    = SocialSettings::active_platforms();

		echo '<section class="zzzc-card"><div class="zzzc-card-head"><h2>Social media distribution</h2></div>';

		printf(
			'<p class="zzzc-state-row"><span class="zzzc-state %1$s">%2$s</span> %3$s</p>',
			esc_attr( $enabled ? 'is-running' : 'is-stopped' ),
			esc_html( $enabled ? 'On' : 'Off' ),
			esc_html(
				$enabled
					? sprintf(
						'%d account(s) connected. Publishing to: %s.',
						$connected,
						$active ? implode( ', ', array_map( array( Platforms::class, 'label' ), $active ) ) : 'no platform yet'
					)
					: 'Articles are published to the website only. Nothing is sent to any social media platform.'
			)
		);

		if ( $enabled && ! $active ) {
			echo '<div class="zzzc-alert">Distribution is on, but no platform is both switched on and connected to a usable account, so nothing will be published. Connect an account below.</div>';
		}

		// The callback address, which must be entered in each platform's developer settings.
		printf(
			'<div class="zzzc-field"><label for="zzzc-social-callback">Callback address for every platform</label><input type="text" id="zzzc-social-callback" class="zzzc-wide" value="%1$s" readonly onclick="this.select()"><p class="zzzc-help">Copy this into each platform\'s developer settings as the allowed callback, redirect or return address. It must match exactly, including https and any trailing path. Authorising will be refused by the platform if it does not.</p></div>',
			esc_attr( SocialSecrets::callback_url() )
		);

		if ( ! is_ssl() ) {
			echo '<div class="zzzc-alert">This site is not served over https. Most of these platforms refuse a callback address that is not https, so connecting an account will fail until the site has a certificate.</div>';
		}

		echo '</section>';
	}

	/**
	 * The settings that apply to every platform.
	 */
	private static function global_settings_card(): void {
		echo '<section class="zzzc-card"><div class="zzzc-card-head"><h2>Distribution settings</h2></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		Actions::url_field( 'social_settings' );

		echo '<div class="zzzc-columns">';

		echo '<div>';
		echo '<h4>What happens</h4>';
		Pages::check_row( 'social_enabled', 'Enable social media distribution', 'The main switch. With this off nothing is published to any platform, and no queued social post is sent.' );
		Pages::check_row( 'social_auto_distribute', 'Distribute articles automatically', 'As soon as WordPress confirms an article is publicly published, queue it for every platform that is switched on.' );
		Pages::check_row( 'social_on_manual_publish', 'Distribute articles published by hand', 'When an InkRush draft is published by an editor rather than by the plugin, distribute it too. Off by default, so publishing a draft never posts to social media unexpectedly. A draft is never distributed either way.' );
		Pages::check_row( 'social_republish_on_edit', 'Distribute again when an article is edited', 'Off by default. An article already published to social media is not posted a second time because it was edited.' );
		Pages::check_row( 'social_history_enabled', 'Keep publishing history', 'Record every attempt and its result on the Social Publishing page.' );
		echo '</div>';

		echo '<div>';
		echo '<h4>What is posted</h4>';
		Pages::check_row( 'social_generate_captions', 'Write the post text with AI', 'Uses your existing OpenRouter key and model. With this off, each post uses the article\'s own headline.' );
		Pages::check_row( 'social_generate_hashtags', 'Add hashtags', 'Only hashtags drawn from the article\'s own subjects, within each platform\'s limit.' );
		Pages::check_row( 'social_use_featured_image', 'Use the article\'s featured image', 'The picture the Wikimedia Commons pipeline already chose. No separate image search.' );
		Pages::check_row( 'social_include_url', 'Include the article address', 'Where the platform makes a link usable. On Instagram a link in a caption is not clickable, so a call to action is used instead.' );
		Pages::check_row( 'social_platform_specific', 'Write each platform its own text', 'Each platform gets text in its own tone and length rather than one post copied six times.' );
		echo '</div>';

		echo '</div>';

		echo '<div class="zzzc-columns">';

		echo '<div>';
		echo '<h4>Approval</h4>';
		Pages::select_row(
			'social_approval_mode',
			'Before publishing',
			array(
				SocialSettings::APPROVAL_AUTOMATIC => 'Publish automatically',
				SocialSettings::APPROVAL_MANUAL    => 'Wait for my approval',
			),
			'With approval on, every post waits on the Social Publishing page where you can read it, edit it and release it.'
		);
		echo '</div>';

		echo '<div>';
		echo '<h4>Timing</h4>';
		Pages::select_row(
			'social_when',
			'When to publish',
			array(
				SocialSettings::WHEN_IMMEDIATE => 'As soon as the article is published',
				SocialSettings::WHEN_DELAY     => 'After a delay',
				SocialSettings::WHEN_TIME      => 'At a time I choose for each article',
			),
			sprintf( 'Times use the plugin\'s own timezone, %s, the same one the posting schedule uses.', Schedule::zone_label() )
		);
		Pages::number_row( 'social_delay_minutes', 'Delay in minutes', 'Used only with "After a delay". 0 to 1440.' );
		echo '</div>';

		echo '<div>';
		echo '<h4>Retries and history</h4>';
		Pages::number_row( 'social_max_attempts', 'Attempts per post', 'How many times one platform is tried before the post is left as failed. 1 to 10.' );
		Pages::number_row( 'social_retention_days', 'Keep failed records for (days)', 'Published records are kept indefinitely. 7 to 3650.' );
		echo '</div>';

		echo '</div>';

		printf(
			'<div class="zzzc-field"><label for="social_cta">Call to action where a link cannot be clicked</label><input type="text" id="social_cta" name="social_cta" class="zzzc-wide" value="%1$s" maxlength="120"><p class="zzzc-help">Used on Instagram, where a web address in a caption is not clickable. A platform can be given its own wording in its card below.</p></div>',
			esc_attr( (string) Settings::get( 'social_cta' ) )
		);
		Pages::field_error( 'social_cta' );

		Pages::save_bar();
		echo '</form></section>';
	}

	/**
	 * One platform's card: its status, its account, what it needs, and its own settings.
	 */
	private static function platform_card( string $platform ): void {
		$details  = Platforms::get( $platform );
		$accounts = SocialAccounts::all( $platform );
		$chosen   = SocialAccounts::chosen( $platform );
		$adapter  = SocialManager::adapter( $platform );
		$on       = SocialSettings::platform_enabled( $platform );

		echo '<section class="zzzc-card zzzc-social-card">';

		echo '<div class="zzzc-card-head"><h2>' . SocialIcons::label( $platform, 22 ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Fixed inline markup, platform name escaped in label().
		printf(
			'<span class="zzzc-state %1$s">%2$s</span></div>',
			esc_attr( self::state_class( $platform, $accounts, $on ) ),
			esc_html( self::state_text( $platform, $accounts, $on ) )
		);

		printf(
			'<p class="zzzc-help">%1$s. %2$s</p>',
			esc_html( (string) $details['api'] ),
			esc_html( self::cost_note( $platform ) )
		);

		// Connected accounts.
		if ( $accounts ) {
			echo '<table class="zzzc-table"><thead><tr><th>Account</th><th>Type</th><th>Status</th><th>Last checked</th><th>Last published</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $accounts as $account ) {
				self::account_row( $platform, $account, $chosen );
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="zzzc-empty">No ' . esc_html( strtolower( (string) $details['account_noun'] ) ) . ' is connected yet.</p>';
		}

		// How to connect.
		if ( $adapter && ! $adapter->uses_oauth() ) {
			self::form_connect_card( $platform );
		} else {
			self::oauth_connect_card( $platform, (bool) $accounts );
		}

		// This platform's own settings.
		self::platform_settings( $platform, $accounts );

		// What the platform requires, kept out of the way until it is wanted.
		self::requirements( $platform, $details );

		echo '</section>';
	}

	private static function account_row( string $platform, array $account, ?array $chosen ): void {
		$is_chosen = $chosen && (int) $chosen['id'] === (int) $account['id'];
		$expires   = (string) ( $account['token_expires_at'] ?? '' );
		$warning   = '';

		if ( '' !== $expires ) {
			$days = (int) floor( ( strtotime( $expires . ' UTC' ) - time() ) / DAY_IN_SECONDS );
			if ( $days < 0 ) {
				$warning = ' The credential has expired.';
			} elseif ( $days <= 7 ) {
				$warning = sprintf( ' The credential expires in %d day(s).', $days );
			}
		}

		echo '<tr>';
		printf(
			'<td>%1$s%2$s</td>',
			esc_html( SocialAccounts::label( $account ) ),
			$is_chosen ? ' <span class="zzzc-tag">publishing</span>' : ''
		);
		printf( '<td>%s</td>', esc_html( (string) $account['account_type'] ) );
		printf(
			'<td><span class="zzzc-sev is-%1$s">%2$s</span>%3$s</td>',
			esc_attr( SocialAccounts::CONNECTED === $account['status'] ? 'info' : 'error' ),
			esc_html( SocialAccounts::status_label( (string) $account['status'] ) ),
			esc_html( ( '' !== (string) $account['status_reason'] ? ' ' . $account['status_reason'] : '' ) . $warning )
		);
		printf(
			'<td class="zzzc-nowrap">%s</td>',
			esc_html( '' !== (string) $account['last_check_at'] ? Schedule::format_local( (string) $account['last_check_at'] ) : 'Never' )
		);
		printf(
			'<td class="zzzc-nowrap">%s</td>',
			esc_html( '' !== (string) $account['last_published_at'] ? Schedule::format_local( (string) $account['last_published_at'] ) : 'Never' )
		);

		echo '<td class="zzzc-buttons">';
		printf(
			'<button type="button" class="zzzc-btn zzzc-test-social" data-account="%1$d" data-platform="%2$s">Test connection</button><span class="zzzc-result" data-for="%1$d"></span>',
			(int) $account['id'],
			esc_attr( $platform )
		);
		Pages::action_button(
			'social_disconnect',
			'Disconnect',
			array( 'account_id' => (int) $account['id'] ),
			'',
			sprintf(
				'Disconnect %s? Its saved credentials are deleted and anything not yet published is cancelled. Posts already published on the platform are not touched and are not deleted.',
				SocialAccounts::label( $account )
			)
		);
		echo '</td></tr>';
	}

	/**
	 * The application credentials and the Connect button, for a platform that authorises.
	 */
	private static function oauth_connect_card( string $platform, bool $has_accounts ): void {
		$has_app   = SocialSecrets::has_app( $platform );
		$readable  = SocialSecrets::app_is_readable( $platform );
		$masked    = SocialSecrets::masked_secret( $platform );

		echo '<details class="zzzc-social-connect"' . ( $has_app ? '' : ' open' ) . '>';
		echo '<summary>' . esc_html( $has_app ? 'Application settings' : 'Set up the application' ) . '</summary>';

		if ( ! $readable ) {
			echo '<div class="zzzc-alert">The saved application secret cannot be decrypted, which happens when the WordPress salts or ZZZCOPY_SECRET_KEY changed after it was saved. Enter it again below.</div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		Actions::url_field( 'social_app' );
		printf( '<input type="hidden" name="platform" value="%s">', esc_attr( $platform ) );

		printf(
			'<div class="zzzc-field"><label for="client_id_%1$s">Application or client identifier</label><input type="text" id="client_id_%1$s" name="client_id" class="zzzc-wide" value="%2$s" autocomplete="off"></div>',
			esc_attr( $platform ),
			esc_attr( SocialSecrets::client_id( $platform ) )
		);
		Pages::field_error( $platform . '_client_id' );

		printf(
			'<div class="zzzc-field"><label for="client_secret_%1$s">Application or client secret</label><input type="password" id="client_secret_%1$s" name="client_secret" class="zzzc-wide" value="" autocomplete="off" placeholder="%2$s"><p class="zzzc-help">%3$s It is encrypted before it is saved and is never shown again or sent to the browser.</p></div>',
			esc_attr( $platform ),
			esc_attr( '' !== $masked ? $masked . ' (leave empty to keep it)' : '' ),
			esc_html( '' !== $masked ? 'A secret is saved.' : 'Not saved yet.' )
		);
		Pages::field_error( $platform . '_client_secret' );

		Pages::save_bar( 'Save application settings' );
		echo '</form>';

		if ( $has_app && $readable ) {
			echo '<p class="zzzc-buttons">';
			Pages::action_button( 'social_connect', $has_accounts ? 'Connect another account' : 'Connect account', array( 'platform' => $platform ), 'is-primary' );
			if ( $has_accounts ) {
				Pages::action_button( 'social_connect', 'Reconnect', array( 'platform' => $platform ) );
			}
			echo '</p><p class="zzzc-help">Connecting opens ' . esc_html( Platforms::label( $platform ) ) . ' so you can authorise this site. You are never asked for a social media password here. Reconnecting replaces the saved credentials of an account already connected and keeps its history.</p>';
		}

		echo '</details>';
	}

	/**
	 * The handle and app password form, for Bluesky.
	 */
	private static function form_connect_card( string $platform ): void {
		echo '<details class="zzzc-social-connect" open><summary>Connect an account</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		Actions::url_field( 'social_connect_form' );
		printf( '<input type="hidden" name="platform" value="%s">', esc_attr( $platform ) );

		echo '<div class="zzzc-field"><label for="bluesky_handle">Handle</label><input type="text" id="bluesky_handle" name="handle" class="zzzc-wide" value="" placeholder="example.bsky.social" autocomplete="off"><p class="zzzc-help">Your full handle, including the domain.</p></div>';
		Pages::field_error( 'bluesky_handle' );

		echo '<div class="zzzc-field"><label for="bluesky_app_password">App password</label><input type="password" id="bluesky_app_password" name="app_password" class="zzzc-wide" value="" placeholder="xxxx-xxxx-xxxx-xxxx" autocomplete="off"><p class="zzzc-help">Create one in Bluesky under Settings, Privacy and security, App passwords. Do not enter your account password: it is not needed and will not be accepted. The app password is encrypted before it is saved and can be revoked in Bluesky at any time without changing your account password.</p></div>';
		Pages::field_error( 'bluesky_app_password' );

		Pages::save_bar( 'Connect account' );
		echo '</form></details>';
	}

	/**
	 * One platform's own settings.
	 */
	private static function platform_settings( string $platform, array $accounts ): void {
		$prefix = 'platforms[' . $platform . ']';

		echo '<details class="zzzc-social-options"><summary>Settings for ' . esc_html( Platforms::label( $platform ) ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		Actions::url_field( 'social_settings' );

		printf(
			'<div class="zzzc-field zzzc-check"><label><input type="checkbox" name="%1$s[enabled]" value="1"%2$s> Publish to %3$s</label><p class="zzzc-help">%4$s</p></div>',
			esc_attr( $prefix ),
			checked( SocialSettings::platform_enabled( $platform ), true, false ),
			esc_html( Platforms::label( $platform ) ),
			esc_html( $accounts ? 'Switched off, this platform is skipped and nothing is queued for it.' : 'An account must be connected before this can be switched on.' )
		);
		Pages::field_error( $platform . '_enabled' );

		if ( count( $accounts ) > 0 ) {
			printf( '<div class="zzzc-field"><label for="account_%1$s">Publish through</label><select id="account_%1$s" name="%2$s[account_id]">', esc_attr( $platform ), esc_attr( $prefix ) );
			printf( '<option value="0"%s>%s</option>', selected( 0, (int) SocialSettings::platform( $platform, 'account_id' ), false ), count( $accounts ) > 1 ? esc_html( 'Choose an account' ) : esc_html( 'The only connected account' ) );
			foreach ( $accounts as $account ) {
				printf(
					'<option value="%1$d"%2$s>%3$s</option>',
					(int) $account['id'],
					selected( (int) $account['id'], (int) SocialSettings::platform( $platform, 'account_id' ), false ),
					esc_html( SocialAccounts::label( $account ) )
				);
			}
			echo '</select>';
			if ( count( $accounts ) > 1 ) {
				echo '<p class="zzzc-help">With more than one account connected, one must be chosen. Nothing is published until it is, rather than guessing between them.</p>';
			}
			echo '</div>';
			Pages::field_error( $platform . '_account_id' );
		}

		$hashtag_limit = (int) Platforms::value( $platform, 'hashtag_limit', 0 );
		if ( $hashtag_limit > 0 ) {
			printf(
				'<div class="zzzc-field"><label for="hashtags_%1$s">Hashtags to always include</label><input type="text" id="hashtags_%1$s" name="%2$s[hashtags]" class="zzzc-wide" value="%3$s"><p class="zzzc-help">Used when AI hashtags are off, or when none could be drawn from the article. %4$s allows %5$d.</p></div>',
				esc_attr( $platform ),
				esc_attr( $prefix ),
				esc_attr( (string) SocialSettings::platform( $platform, 'hashtags' ) ),
				esc_html( Platforms::label( $platform ) ),
				$hashtag_limit
			);
		}

		if ( Platforms::LINK_NONE === (string) Platforms::value( $platform, 'link_mode' ) ) {
			printf(
				'<div class="zzzc-field"><label for="cta_%1$s">Call to action for %4$s</label><input type="text" id="cta_%1$s" name="%2$s[cta]" class="zzzc-wide" value="%3$s" maxlength="120"><p class="zzzc-help">Leave empty to use the wording in the settings above.</p></div>',
				esc_attr( $platform ),
				esc_attr( $prefix ),
				esc_attr( (string) SocialSettings::platform( $platform, 'cta' ) ),
				esc_html( Platforms::label( $platform ) )
			);
		}

		if ( Platforms::version_is_editable( $platform ) ) {
			printf(
				'<div class="zzzc-field"><label for="version_%1$s">API version</label><input type="text" id="version_%1$s" name="%2$s[api_version]" value="%3$s" placeholder="%4$s"><p class="zzzc-help">%5$s retires API versions on a timetable, so this can be changed without waiting for a plugin update. Leave it empty to use %4$s, the version this release was built against. Currently sending %6$s.</p></div>',
				esc_attr( $platform ),
				esc_attr( $prefix ),
				esc_attr( (string) SocialSettings::platform( $platform, 'api_version' ) ),
				esc_attr( (string) Platforms::value( $platform, 'api_version' ) ),
				esc_html( Platforms::label( $platform ) ),
				esc_html( Platforms::version( $platform ) )
			);
			Pages::field_error( $platform . '_api_version' );
		}

		Pages::save_bar( 'Save ' . Platforms::label( $platform ) . ' settings' );
		echo '</form></details>';
	}

	/**
	 * What the platform requires before any of this works. Written out in full, because an
	 * approval requirement or a monthly charge is not a detail to discover through a failure.
	 */
	private static function requirements( string $platform, array $details ): void {
		$requires = (array) $details['requires'];
		$rows     = array(
			'app'        => 'Developer application',
			'products'   => 'Products to add',
			'scopes'     => 'Permissions requested',
			'account'    => 'Account eligibility',
			'approval'   => 'Approval needed',
			'cost'       => 'Cost',
			'rate_limit' => 'Rate limits',
			'limits'     => 'Publishing limitations',
		);

		echo '<details class="zzzc-social-requires"><summary>What ' . esc_html( Platforms::label( $platform ) ) . ' requires</summary><dl class="zzzc-dl">';
		foreach ( $rows as $key => $label ) {
			if ( empty( $requires[ $key ] ) ) {
				continue;
			}
			printf( '<dt>%1$s</dt><dd>%2$s</dd>', esc_html( $label ), esc_html( (string) $requires[ $key ] ) );
		}

		printf(
			'<dt>Post length</dt><dd>%1$d characters%2$s</dd>',
			(int) $details['text_limit'],
			Platforms::LINK_TEXT === (string) $details['link_mode'] && (int) Platforms::value( $platform, 'link_weight', 0 ) > 0
				? esc_html( sprintf( ', of which a link always counts as %d however long it is', (int) Platforms::value( $platform, 'link_weight', 0 ) ) )
				: ''
		);
		printf(
			'<dt>Pictures</dt><dd>%1$s. Up to %2$s, as %3$s.%4$s</dd>',
			esc_html( 'required' === (string) $details['image'] ? 'Required on every post' : 'Optional' ),
			esc_html( size_format( (int) $details['image_max_bytes'] ) ),
			esc_html( str_replace( 'image/', '', implode( ' or ', (array) $details['image_mime'] ) ) ),
			Platforms::needs_public_image_url( $platform )
				? esc_html( ' The platform fetches the picture from this site, so the site must be reachable from the internet.' )
				: esc_html( ' The picture is uploaded directly, so the site does not need to be publicly reachable.' )
		);
		printf( '<dt>What is sent to %1$s</dt><dd>%2$s</dd>', esc_html( Platforms::label( $platform ) ), esc_html( (string) $details['sends'] ) );

		echo '</dl></details>';
	}

	private static function state_class( string $platform, array $accounts, bool $on ): string {
		if ( ! $accounts ) {
			return 'is-stopped';
		}
		foreach ( $accounts as $account ) {
			if ( SocialAccounts::CONNECTED !== $account['status'] ) {
				return 'is-error';
			}
		}
		return $on ? 'is-running' : 'is-paused';
	}

	private static function state_text( string $platform, array $accounts, bool $on ): string {
		if ( ! $accounts ) {
			return 'Not connected';
		}
		foreach ( $accounts as $account ) {
			if ( SocialAccounts::CONNECTED !== $account['status'] ) {
				return 'Needs attention';
			}
		}
		return $on ? 'Publishing' : 'Connected, switched off';
	}

	private static function cost_note( string $platform ): string {
		$requires = (array) Platforms::value( $platform, 'requires', array() );
		$cost     = (string) ( $requires['cost'] ?? '' );
		return 0 === strpos( $cost, 'PAID' ) ? 'Paid access required.' : 'No charge for publishing.';
	}

	/* ---- InkRush, Social Publishing ---- */

	public static function publishing(): void {
		Pages::open( 'Social Publishing', 'zzzc-social-posts' );

		if ( ! SocialSettings::enabled() ) {
			echo '<div class="zzzc-alert">Social media distribution is switched off, so nothing new is being queued. Anything already queued is shown below and is not sent while it is off. Turn it on from the <a href="' . esc_url( admin_url( 'admin.php?page=zzzc-social' ) ) . '">Social Media</a> page.</div>';
		}

		self::summary_card();
		self::approval_queue();
		self::history();

		Pages::close();
	}

	private static function summary_card(): void {
		$summary = SocialManager::summary();

		$tiles = array(
			'Total posts'        => $summary['total'],
			'Published'          => $summary['published'],
			'Scheduled'          => $summary['scheduled'],
			'Waiting approval'   => $summary['awaiting'],
			'In progress'        => $summary['pending'],
			'Failed'             => $summary['failed'],
			'Connected accounts' => $summary['accounts'],
		);

		echo '<section class="zzzc-card"><div class="zzzc-card-head"><h2>Activity</h2></div><div class="zzzc-kpis">';
		foreach ( $tiles as $label => $value ) {
			printf(
				'<div class="zzzc-kpi"><span class="zzzc-kpi-value">%1$s</span><span class="zzzc-kpi-label">%2$s</span></div>',
				esc_html( number_format_i18n( (int) $value ) ),
				esc_html( $label )
			);
		}
		echo '</div>';

		if ( $summary['failed'] > 0 ) {
			echo '<p class="zzzc-help">A failure on one platform never affects another. Each platform is published independently, so the others for the same article may well have succeeded.</p>';
		}

		echo '</section>';
	}

	/**
	 * Posts waiting for a person, with the text to read, change or release.
	 */
	private static function approval_queue(): void {
		$waiting = SocialQueue::awaiting_approval( 50 );
		if ( ! $waiting ) {
			return;
		}

		echo '<section class="zzzc-card"><div class="zzzc-card-head"><h2>Waiting for approval</h2></div>';
		echo '<p class="zzzc-help">Read each post, change it if you want to, then approve it. Approving one platform does not affect the others for the same article.</p>';

		foreach ( $waiting as $row ) {
			self::caption_editor( $row, true );
		}

		echo '</section>';
	}

	/**
	 * The text of one post, with the buttons that act on it.
	 */
	private static function caption_editor( array $row, bool $open = false ): void {
		$platform = (string) $row['platform'];
		$limit    = (int) Platforms::value( $platform, 'hard_text_limit', 280 );
		$title    = (string) get_the_title( (int) $row['wp_post_id'] );

		printf(
			'<details class="zzzc-social-caption"%1$s><summary>%2$s %3$s</summary>',
			$open ? ' open' : '',
			SocialIcons::label( $platform, 16 ), // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in label().
			esc_html( $title )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		Actions::url_field( 'social_caption' );
		printf( '<input type="hidden" name="social_post_id" value="%d">', (int) $row['id'] );

		printf(
			'<div class="zzzc-field"><label for="caption_%1$d">Post text</label><textarea id="caption_%1$d" name="caption" rows="5" class="zzzc-wide" maxlength="%2$d">%3$s</textarea><p class="zzzc-help">%4$d of %2$d characters used. Written by %5$s. The article address and the hashtags are added on top of this where the platform allows them.</p></div>',
			(int) $row['id'],
			$limit,
			esc_textarea( (string) $row['caption'] ),
			(int) mb_strlen( (string) $row['caption'] ),
			esc_html( self::source_label( (string) $row['caption_source'] ) )
		);

		if ( (int) Platforms::value( $platform, 'hashtag_limit', 0 ) > 0 ) {
			printf(
				'<div class="zzzc-field"><label for="hashtags_post_%1$d">Hashtags</label><input type="text" id="hashtags_post_%1$d" name="hashtags" class="zzzc-wide" value="%2$s"><p class="zzzc-help">%3$s allows %4$d.</p></div>',
				(int) $row['id'],
				esc_attr( (string) $row['hashtags'] ),
				esc_html( Platforms::label( $platform ) ),
				(int) Platforms::value( $platform, 'hashtag_limit', 0 )
			);
		}

		echo '<p class="zzzc-buttons"><button type="submit" class="zzzc-btn">Save text</button>';
		echo '</p></form>';

		echo '<p class="zzzc-buttons">';
		Pages::action_button( 'social_regenerate', 'Write it again', array( 'social_post_id' => (int) $row['id'] ) );
		if ( in_array( $row['status'], array( SocialQueue::AWAITING, SocialQueue::PENDING ), true ) ) {
			Pages::action_button( 'social_approve', 'Approve and publish', array( 'social_post_id' => (int) $row['id'] ), 'is-primary' );
		}
		Pages::action_button(
			'social_cancel',
			'Cancel',
			array( 'social_post_id' => (int) $row['id'] ),
			'',
			sprintf( 'Cancel the %s post for this article? It will not be published.', Platforms::label( $platform ) )
		);
		echo '</p><p class="zzzc-help">"Write it again" rewrites only this platform\'s text. The other platforms for this article are left exactly as they are.</p>';

		echo '</details>';
	}

	private static function source_label( string $source ): string {
		$map = array(
			'ai'       => 'AI',
			'manual'   => 'you',
			'fallback' => 'the article\'s own headline',
		);
		return $map[ $source ] ?? 'AI';
	}

	/**
	 * Every attempt and its result, with filters.
	 */
	private static function history(): void {
		$filters  = self::history_filters();
		$per_page = 25;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$total    = SocialQueue::count( $filters['query'] );
		$rows     = SocialQueue::query( $filters['query'], $per_page, ( $paged - 1 ) * $per_page );

		echo '<section class="zzzc-card"><div class="zzzc-card-head"><h2>Publishing history</h2></div>';

		// Filters.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="zzzc-filters">';
		echo '<input type="hidden" name="page" value="zzzc-social-posts">';

		echo '<div class="zzzc-field"><label for="filter_platform">Platform</label><select id="filter_platform" name="platform"><option value="">All platforms</option>';
		foreach ( Platforms::ORDER as $platform ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $platform ),
				selected( $filters['raw']['platform'], $platform, false ),
				esc_html( Platforms::label( $platform ) )
			);
		}
		echo '</select></div>';

		echo '<div class="zzzc-field"><label for="filter_status">Status</label><select id="filter_status" name="status"><option value="">All results</option>';
		foreach ( array( SocialQueue::PUBLISHED, SocialQueue::SCHEDULED, SocialQueue::AWAITING, SocialQueue::PENDING, SocialQueue::PUBLISHING, SocialQueue::RECONCILING, SocialQueue::FAILED, SocialQueue::CANCELLED, SocialQueue::SKIPPED ) as $status ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $status ),
				selected( $filters['raw']['status'], $status, false ),
				esc_html( SocialQueue::status_label( $status ) )
			);
		}
		echo '</select></div>';

		printf(
			'<div class="zzzc-field"><label for="filter_from">From</label><input type="date" id="filter_from" name="from" value="%s"></div>',
			esc_attr( $filters['raw']['from'] )
		);
		printf(
			'<div class="zzzc-field"><label for="filter_to">To</label><input type="date" id="filter_to" name="to" value="%s"></div>',
			esc_attr( $filters['raw']['to'] )
		);

		echo '<div class="zzzc-field"><label>&nbsp;</label><button type="submit" class="zzzc-btn">Filter</button></div>';
		echo '</form>';

		if ( ! $rows ) {
			echo '<p class="zzzc-empty">No social posts match. Once an article is published and a platform is switched on, its posts appear here.</p></section>';
			return;
		}

		echo '<div class="zzzc-table-wrap"><table class="zzzc-table"><thead><tr><th>Article</th><th>Platform</th><th>Account</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			self::history_row( $row );
		}
		echo '</tbody></table></div>';

		Pages::pagination( 'zzzc-social-posts', $total, $per_page, $paged, array_filter( $filters['raw'], 'strlen' ) );
		echo '<p class="zzzc-help">Times are in ' . esc_html( Schedule::zone_label() ) . '.</p></section>';
	}

	private static function history_row( array $row ): void {
		$post_id  = (int) $row['wp_post_id'];
		$platform = (string) $row['platform'];
		$title    = (string) get_the_title( $post_id );
		$title    = '' !== $title ? $title : sprintf( 'Article %d (deleted)', $post_id );

		echo '<tr>';

		printf( '<td>%s</td>', esc_html( $title ) );
		printf( '<td class="zzzc-nowrap">%s</td>', SocialIcons::label( $platform, 16 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in label().
		printf( '<td>%s</td>', esc_html( (string) $row['account_label'] ) );

		$severity = SocialQueue::PUBLISHED === $row['status'] ? 'info' : ( in_array( $row['status'], array( SocialQueue::FAILED, SocialQueue::RECONCILING ), true ) ? 'error' : 'warning' );
		printf(
			'<td><span class="zzzc-sev is-%1$s">%2$s</span></td>',
			esc_attr( $severity ),
			esc_html( SocialQueue::status_label( (string) $row['status'] ) )
		);

		$when = (string) ( $row['published_at'] ?: ( $row['scheduled_for'] ?: $row['created_at'] ) );
		printf( '<td class="zzzc-nowrap">%s</td>', esc_html( '' !== $when ? Schedule::format_local( $when ) : '' ) );

		echo '<td class="zzzc-buttons">';

		if ( $post_id && get_post_status( $post_id ) ) {
			printf( '<a class="zzzc-btn" href="%s">View article</a>', esc_url( (string) get_permalink( $post_id ) ) );
		}
		if ( '' !== (string) $row['external_post_url'] ) {
			printf( '<a class="zzzc-btn" href="%s" target="_blank" rel="noopener noreferrer">View post</a>', esc_url( (string) $row['external_post_url'] ) );
		}

		if ( in_array( $row['status'], array( SocialQueue::FAILED, SocialQueue::RECONCILING, SocialQueue::SKIPPED ), true ) ) {
			Pages::action_button( 'social_retry', 'Retry', array( 'social_post_id' => (int) $row['id'] ) );
		}
		if ( ! in_array( $row['status'], SocialQueue::CLOSED, true ) ) {
			Pages::action_button(
				'social_cancel',
				'Cancel',
				array( 'social_post_id' => (int) $row['id'] ),
				'',
				'Cancel this post before it is published?'
			);
		}

		echo '</td></tr>';

		// The error and the text, on their own row so the table stays readable.
		if ( '' !== (string) $row['error_message'] || SocialQueue::PUBLISHED !== $row['status'] ) {
			echo '<tr class="zzzc-social-detail"><td colspan="6">';
			if ( '' !== (string) $row['error_message'] ) {
				printf(
					'<details><summary>Error details</summary><p class="zzzc-pre">%1$s</p><p class="zzzc-help">Code: %2$s. Attempt %3$d of %4$d.</p></details>',
					esc_html( (string) $row['error_message'] ),
					esc_html( (string) $row['error_code'] ),
					(int) $row['attempts'],
					(int) $row['max_attempts']
				);
			}
			if ( ! in_array( $row['status'], array( SocialQueue::PUBLISHED, SocialQueue::CANCELLED ), true ) && '' !== trim( (string) $row['caption'] ) ) {
				self::caption_editor( $row );
			}
			echo '</td></tr>';
		}
	}

	/**
	 * The history filters, both as submitted and as the query needs them.
	 *
	 * @return array{raw:array<string,string>, query:array}
	 */
	private static function history_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- Read-only filters on an admin screen.
		$raw = array(
			'platform' => Pages::request_string( 'platform' ),
			'status'   => Pages::request_string( 'status' ),
			'from'     => Pages::request_string( 'from' ),
			'to'       => Pages::request_string( 'to' ),
		);
		// phpcs:enable

		$query = array(
			'platform' => Platforms::exists( $raw['platform'] ) ? $raw['platform'] : '',
			'status'   => $raw['status'],
		);

		// The dates are entered in the plugin's own timezone and stored in UTC, converted exactly
		// as the Logs page converts its date filters.
		$zone = Schedule::timezone();
		foreach ( array(
			'from' => '00:00:00',
			'to'   => '23:59:59',
		) as $key => $time ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw[ $key ] ) ) {
				$query[ $key ] = gmdate( 'Y-m-d H:i:s', ( new \DateTimeImmutable( $raw[ $key ] . ' ' . $time, $zone ) )->getTimestamp() );
			}
		}

		return array(
			'raw'   => $raw,
			'query' => $query,
		);
	}

	/* ---- The social controls on the Articles page ---- */

	/**
	 * One article's social posts, and the buttons to distribute it, shown inside the existing
	 * article detail without changing anything already there.
	 */
	public static function article_card( int $article_job_id, int $post_id ): void {
		if ( ! SocialSettings::enabled() || ! $post_id ) {
			return;
		}

		$rows      = SocialQueue::for_post( $post_id );
		$published = 'publish' === get_post_status( $post_id );

		echo '<section class="zzzc-card"><div class="zzzc-card-head"><h2>Social media</h2></div>';

		if ( ! $published ) {
			echo '<p class="zzzc-help">This article is not publicly published, so it cannot be distributed. A draft is never sent to social media. Publish it first and the buttons appear here.</p></section>';
			return;
		}

		if ( $rows ) {
			echo '<table class="zzzc-table"><thead><tr><th>Platform</th><th>Account</th><th>Status</th><th>Post</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr>';
				printf( '<td class="zzzc-nowrap">%s</td>', SocialIcons::label( (string) $row['platform'], 16 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in label().
				printf( '<td>%s</td>', esc_html( (string) $row['account_label'] ) );
				printf( '<td>%s</td>', esc_html( SocialQueue::status_label( (string) $row['status'] ) ) );
				printf(
					'<td>%s</td>',
					'' !== (string) $row['external_post_url']
						? '<a href="' . esc_url( (string) $row['external_post_url'] ) . '" target="_blank" rel="noopener noreferrer">View</a>'
						: '&mdash;'
				);
				echo '</tr>';
			}
			echo '</tbody></table>';
			printf(
				'<p class="zzzc-help">Manage these on the <a href="%s">Social Publishing</a> page.</p>',
				esc_url( admin_url( 'admin.php?page=zzzc-social-posts&wp_post_id=' . $post_id ) )
			);
		}

		$available = SocialSettings::active_platforms();
		$existing  = array_column( $rows, 'platform' );
		$missing   = array_values( array_diff( $available, $existing ) );

		if ( ! $available ) {
			echo '<p class="zzzc-help">No platform is switched on with a connected account, so there is nothing to distribute to yet.</p></section>';
			return;
		}

		if ( ! $missing ) {
			echo '<p class="zzzc-help">Every platform that is switched on already has a post for this article. A second one is not created, so the same article cannot be posted twice to the same account.</p></section>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		Actions::url_field( 'social_distribute' );
		printf( '<input type="hidden" name="wp_post_id" value="%d">', $post_id );

		echo '<p class="zzzc-help">Choose where to publish this article. Leave a platform unticked to exclude it.</p><div class="zzzc-check-grid">';
		foreach ( $missing as $platform ) {
			printf(
				'<label class="zzzc-check"><input type="checkbox" name="platforms[]" value="%1$s" checked> %2$s</label>',
				esc_attr( $platform ),
				SocialIcons::label( $platform, 16 ) // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in label().
			);
		}
		echo '</div>';

		echo '<div class="zzzc-field zzzc-check"><label><input type="checkbox" name="review" value="1"' . checked( SocialSettings::needs_approval(), true, false ) . '> Write the text and let me read it before publishing</label></div>';

		echo '<p class="zzzc-buttons"><button type="submit" class="zzzc-btn is-primary">Publish to selected platforms</button></p>';
		echo '</form></section>';
	}
}
