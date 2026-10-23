<?php
namespace Mediavine\Create;

/**
 * Create 3.0's explicit plan-choice gate.
 *
 * Replaces the unreleased CRE-296 automatic free-plus floor (Free_Plus_Notice)
 * with an explicit publisher decision: a site sitting in the free band (Free
 * or Free+, not trialing, not paid) has its admin UI blocked until an admin
 * chooses Free, Free+, or upgrades to a paid plan
 * (GateKeeper::set_free_plan_choice() / a Studio sync via
 * Subscription_API::handle_set_tier()). This class redirects each Create user
 * to the plan page ONCE while a choice is required; the admin UI's own
 * `PlanChoiceGate` modal (mounted from `main.tsx`) is what actually keeps
 * blocking interaction on every visit after that. Paid and trialing sites
 * are never blocked — they instead see the Create 3.0 "what's new" landing
 * page once (option gate + per-user seen meta + one redirect from a Create
 * screen). The 2.0-era Welcome_Notice this mirrored has since been removed.
 */
class Plan_Choice {

	/** @var Plan_Choice */
	private static $instance = null;

	/** @var string Admin page slug for the plan-choice / "what's new" page. */
	const PAGE_SLUG = 'cre_new_plans';

	/** @var string User meta key tracking whether this user has seen the paid/trial "what's new" page. */
	const USER_META_SEEN = 'mv_create_plan_seen';

	/**
	 * User meta key tracking whether this user has already been REDIRECTED to
	 * the plan page while a choice was required. Distinct from USER_META_SEEN
	 * (the paid/trial "what's new" flow) — the two announcements are
	 * independent and a site could conceivably fire both across its lifetime.
	 *
	 * @var string
	 */
	const USER_META_CHOICE_SEEN = 'mv_create_plan_choice_seen';

	/** @var string Option key gating the paid/trial "what's new" announcement. */
	const OPTION_SHOW = 'mv_create_show_plan';

	/** @var string Option key marking the one-time creations-based backfill for existing installs. */
	const OPTION_CHECK_DONE = 'mv_create_plan_choice_check_done';

	/**
	 * Option key: true while an admin still needs to choose a free-band plan.
	 *
	 * Set on the <3.0 upgrade hook and by the one-time existing-install
	 * backfill; cleared by mark_choice_made() once a choice is on record
	 * (locally or via a Studio sync). See is_choice_required(), which layers
	 * the live tier/trial/choice checks on top of this flag so a stale `true`
	 * left over from before an upgrade to paid can never re-block the gate.
	 *
	 * @var string
	 */
	const OPTION_CHOICE_REQUIRED = 'mv_create_plan_choice_required';

	/** OPTION_CHOICE_REQUIRED value when the ask follows a paid plan or trial ending. */
	const REASON_PLAN_ENDED = 'plan_ended';

	/**
	 * First version that ships explicit plan choice. Sites updating from
	 * before this either need to choose (free band) or see the "what's new"
	 * page (paid/trial).
	 *
	 * @var string
	 */
	const TIERS_VERSION = '3.0.0';

	/**
	 * @return Plan_Choice
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( Plugin::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'maybe_set_flag' ], 5 );
		add_action( 'admin_init', [ $this, 'maybe_flag_existing' ], 1 );
		add_action( 'admin_init', [ $this, 'maybe_redirect' ] );
		add_action( 'admin_notices', [ $this, 'banner_notice' ] );
	}

	/**
	 * Flag the gate + the "what's new" announcement on a <3.0 upgrade.
	 *
	 * Every site upgrading from before 3.0 gets both flags: OPTION_SHOW drives
	 * the paid/trial "what's new" redirect, and OPTION_CHOICE_REQUIRED drives
	 * the free-band block. Setting the latter unconditionally is harmless for
	 * paid/trial sites — is_choice_required() filters them out by tier — and
	 * necessary for free-band sites, which have no other way to reach the
	 * choice screen.
	 *
	 * @param string $last_version The previously installed plugin version.
	 * @return void
	 */
	public function maybe_set_flag( $last_version ) {
		if ( version_compare( (string) $last_version, self::TIERS_VERSION, '<' ) ) {
			update_option( self::OPTION_CHOICE_REQUIRED, true );
			update_option( self::OPTION_SHOW, true );
		}
	}

	/**
	 * Catch existing installs that reached 3.0 without firing the update hook
	 * (fresh activations of an already-current build, for instance) and that
	 * already have Create Cards — the signal that this is a real pre-3.0 site,
	 * not a brand-new install.
	 *
	 * New installs never accumulate creations before this runs, so they are
	 * never flagged and start on Free with no gate, per product decision.
	 *
	 * Runs once ever, guarded by its own option.
	 *
	 * @return void
	 */
	public function maybe_flag_existing() {
		if ( get_option( self::OPTION_CHECK_DONE ) ) {
			return;
		}

		update_option( self::OPTION_CHECK_DONE, true );

		// An explicit choice already on record; never override it.
		if ( '' !== GateKeeper::get_free_plan_choice() ) {
			return;
		}

		if ( self::site_has_creations() ) {
			update_option( self::OPTION_CHOICE_REQUIRED, true );
		}
	}

	/**
	 * Whether the site has any Create Cards at all.
	 *
	 * @return bool
	 */
	private static function site_has_creations() {
		global $wpdb;

		$table = $wpdb->prefix . 'mv_creations';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is $wpdb->prefix . a fixed literal; COUNT(*) has no user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0;
	}

	/**
	 * Whether an admin still needs to choose a free-band plan before Create's
	 * admin UI is usable.
	 *
	 * Live, not just flag-based: a site that has since chosen, upgraded to a
	 * paid plan, or started a trial is never required to choose regardless of
	 * a stale OPTION_CHOICE_REQUIRED flag.
	 *
	 * @return bool
	 */
	public static function is_choice_required() {
		if ( ! get_option( self::OPTION_CHOICE_REQUIRED ) ) {
			return false;
		}

		if ( '' !== GateKeeper::get_free_plan_choice() ) {
			return false;
		}

		if ( GateKeeper::is_trialing() ) {
			return false;
		}

		return in_array(
			GateKeeper::get_effective_tier(),
			[ GateKeeper::TIER_FREE, GateKeeper::TIER_FREE_PLUS ],
			true
		);
	}

	/**
	 * Ask for a Free / Free+ choice again because a paid plan or trial ended.
	 *
	 * The site lands on Free when a subscription ends; whether it wants Free+
	 * (ad-supported) instead is the publisher's call, so the gate comes back
	 * with its "your plan has ended" wording. Any earlier free-band choice is
	 * forgotten: it predates the paid plan, and is_choice_required() treats a
	 * recorded choice as already answered.
	 *
	 * @return void
	 */
	public static function require_choice_after_plan_ended() {
		GateKeeper::clear_free_plan_choice();
		update_option( self::OPTION_CHOICE_REQUIRED, self::REASON_PLAN_ENDED );
	}

	/**
	 * Why a choice is being asked for: 'plan_ended' after a paid plan or
	 * trial, otherwise 'update' (the 3.0 upgrade).
	 *
	 * @return string
	 */
	public static function get_choice_reason() {
		return self::REASON_PLAN_ENDED === get_option( self::OPTION_CHOICE_REQUIRED ) ? self::REASON_PLAN_ENDED : 'update';
	}

	/**
	 * Clear the plan-choice-required flag.
	 *
	 * Called by GateKeeper::set_free_plan_choice() (a local choice) and by a
	 * successful Studio sync that lands on a paid or trialing tier — either
	 * counts as the choice having been made.
	 *
	 * @return void
	 */
	public static function mark_choice_made() {
		delete_option( self::OPTION_CHOICE_REQUIRED );
	}

	/**
	 * Dev-only: put the site back where a pre-3.0 install lands right after
	 * updating — not connected to Create Studio, no plan chosen, and nobody
	 * yet redirected to the plan page.
	 *
	 * Local only: Create Studio is not told about the disconnect, so its own
	 * record of this site is left as it was.
	 *
	 * @return void
	 */
	public static function reset_to_pre_upgrade() {
		// Clears the token and all subscription/trial state, landing on Free.
		Site_Verification::disconnect_locally();
		delete_option( Site_Verification::OPTION_CONNECTION_DECLINED );

		GateKeeper::clear_free_plan_choice();

		// Same flags maybe_set_flag() sets on a <3.0 update.
		update_option( self::OPTION_CHOICE_REQUIRED, true );
		update_option( self::OPTION_SHOW, true );
		update_option( self::OPTION_CHECK_DONE, true );

		// Every user gets the one-time redirect again.
		delete_metadata( 'user', 0, self::USER_META_CHOICE_SEEN, '', true );
		delete_metadata( 'user', 0, self::USER_META_SEEN, '', true );

		GateKeeper::reset_request_cache();
	}

	/**
	 * Whether the current user has already seen the paid/trial "what's new" page.
	 *
	 * @return bool
	 */
	private function user_has_seen() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return true;
		}

		return (bool) get_user_meta( $user_id, self::USER_META_SEEN, true );
	}

	/**
	 * Whether the current user has already been redirected to the plan page
	 * while a choice was required.
	 *
	 * Once true, `maybe_redirect()` stops forcing this user back to the plan
	 * page on every navigation — the admin UI's own `PlanChoiceGate` modal
	 * (mounted from `main.tsx`) takes over blocking interaction from there,
	 * so a stale tab or a direct link to a Create screen doesn't loop the
	 * user back to the plan page forever.
	 *
	 * @return bool
	 */
	private static function user_has_seen_choice_redirect() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return true;
		}

		return (bool) get_user_meta( $user_id, self::USER_META_CHOICE_SEEN, true );
	}

	/**
	 * Mark the current user as having been redirected to the plan page for
	 * the choice-required gate.
	 *
	 * @return void
	 */
	private static function mark_user_seen_choice_redirect() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::USER_META_CHOICE_SEEN, '1' );
		}
	}

	/**
	 * Whether the current user can access Create at all (any Create screen),
	 * as opposed to being able to CHOOSE a plan (manage_options only).
	 *
	 * Mirrors the capability check Admin_Init uses to decide whether a user
	 * sees the Create admin UI.
	 *
	 * @return bool
	 */
	private static function user_can_access_create() {
		return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
	}

	/**
	 * Whether the current request is one the redirect must leave alone: an
	 * AJAX call, a REST request, or an admin-post.php action. None of these
	 * render the admin UI a redirect would otherwise protect, and a redirect
	 * (or the accompanying `exit`) would break them outright.
	 *
	 * @return bool
	 */
	private static function is_non_interactive_request() {
		if ( wp_doing_ajax() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( isset( $_SERVER['SCRIPT_NAME'] ) && 'admin-post.php' === basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Redirect to the plan-choice / "what's new" page.
	 *
	 * Two independent reasons to redirect:
	 *  - The plan-choice gate is live and required: every Create user (not
	 *    just admins — non-admins land on the same page and see "an
	 *    administrator needs to choose") is redirected to the plan page
	 *    ONCE (per-user meta, USER_META_CHOICE_SEEN), same shape as the
	 *    paid/trial redirect below. After that, the admin UI's own
	 *    `PlanChoiceGate` modal keeps blocking interaction client-side on
	 *    every later visit, so this doesn't loop a stale tab forever.
	 *  - Otherwise, a paid/trial site that hasn't seen the 3.0 "what's new"
	 *    announcement gets the same redirect-once behaviour.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( self::is_non_interactive_request() ) {
			return;
		}

		$current_url  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$on_plan_page = false !== strpos( $current_url, 'page=' . self::PAGE_SLUG );

		if ( self::is_choice_required() ) {
			if ( $on_plan_page ) {
				self::mark_user_seen_choice_redirect();
				// Seeing the plan page is seeing the 3.0 announcement too, so the
				// "what's new" redirect below never bounces a user who then
				// chooses a plan back to this page.
				$user_id = get_current_user_id();
				if ( $user_id ) {
					update_user_meta( $user_id, self::USER_META_SEEN, '1' );
				}
				return;
			}

			if ( ! self::user_can_access_create() ) {
				return;
			}

			if ( ! Admin_Notice_Helper::is_create_page( $current_url ) ) {
				return;
			}

			if ( self::user_has_seen_choice_redirect() ) {
				return;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_option( self::OPTION_SHOW ) ) {
			return;
		}

		if ( $this->user_has_seen() ) {
			return;
		}

		// Landing on the page itself counts as seeing it.
		if ( $on_plan_page ) {
			$user_id = get_current_user_id();
			if ( $user_id ) {
				update_user_meta( $user_id, self::USER_META_SEEN, '1' );
			}
			return;
		}

		$is_create_page = (
			false !== strpos( $current_url, 'post_type=mv_create' ) ||
			false !== strpos( $current_url, 'page=mv_settings' )
		);

		if ( ! $is_create_page ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Nudge admins toward the plan-choice page from non-Create admin screens
	 * while a choice is still required.
	 *
	 * Deliberately NOT dismissible: unlike the old Free+ announcement banner
	 * this replaces, premium features are genuinely paused until the admin
	 * acts, so there is no "maybe later" affordance to offer.
	 *
	 * @return void
	 */
	public function banner_notice() {
		if ( ! self::is_choice_required() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( Admin_Notice_Helper::is_create_page() ) {
			return;
		}

		$page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="notice notice-warning mv-create-plan-choice-notice">
			<p>
				<strong><?php esc_html_e( 'Create 3.0 needs you to choose a plan.', 'mediavine-create' ); ?></strong>
				<?php esc_html_e( 'Premium features are paused until you do.', 'mediavine-create' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $page_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Choose your plan', 'mediavine-create' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
