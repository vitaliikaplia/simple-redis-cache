<?php
/**
 * Optional targeted HTML cache invalidation after WordPress content updates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Page_Invalidator {
	/** @var array<int, true> */
	private static array $pending_post_ids = array();
	/** @var array<int, true> Term taxonomy IDs, which are unique across taxonomies. */
	private static array $pending_term_taxonomy_ids = array();
	/**
	 * @var array<string, true> Public URLs captured before the update, for the edge.
	 *
	 * A post that is unpublished, trashed or renamed no longer resolves to the
	 * address the CDN actually holds, and shutdown runs after the change.
	 */
	private static array $pending_cloudflare_urls = array();
	/** Whether the shared listing token (home, archives, search, 404) must change. */
	private static bool $pending_listing = false;
	/** @var array<string, true> Post types whose archive lists the changed content. */
	private static array $pending_post_types = array();
	/** @var array<string, true> Term archive addresses captured before a rename or deletion. */
	private static array $pending_cloudflare_term_urls = array();
	private static bool $shutdown_scheduled = false;
	private static bool $site_purge_scheduled = false;
	/** Whether a whole-cache purge for changed content waits for shutdown. */
	private static bool $content_purge_scheduled = false;
	/** Whether this request already cleared everything for changed content. */
	private static bool $content_purged = false;
	/** @var array<int, true> Posts a meta change has already queued; later keys add nothing. */
	private static array $meta_changed_post_ids = array();

	/**
	 * Post meta core writes while editing, publishing or housekeeping that never
	 * changes a cached page. Each can land on a published post outside a post
	 * update (or before it), so without this list opening the editor, a cron run
	 * or an admin page load would invalidate — or, in the full-purge mode, clear
	 * the whole site:
	 * - _edit_lock: written whenever the editor opens and by the heartbeat;
	 * - _edit_last: who saved last, written by the save itself;
	 * - _wp_old_slug, _wp_old_date: redirect bookkeeping core writes inside
	 *   post_updated, which already invalidates the post;
	 * - _encloseme, _pingme, _trackbackme: publish-time flags that cron deletes
	 *   in a later request once pings and enclosures are processed;
	 * - enclosure: added and removed by that cron run; only RSS/Atom feeds print
	 *   it, and feeds are never cached;
	 * - _wp_desired_post_slug: the slug a trashed post gave up;
	 * - _wp_attached_file: an attachment's file path (attachments are not pages);
	 * - _wp_suggested_privacy_policy_content: plugins' suggested policy text, which
	 *   core re-syncs onto the published privacy page on admin_init and shows
	 *   only in the editor's policy guide;
	 * - _customize_draft_post_name: Customizer stub-page bookkeeping, removed when
	 *   the changeset publishes the page, which invalidates it by itself.
	 */
	private const INTERNAL_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_encloseme',
		'_pingme',
		'_trackbackme',
		'enclosure',
		'_wp_desired_post_slug',
		'_wp_attached_file',
		'_wp_suggested_privacy_policy_content',
		'_customize_draft_post_name',
	);

	/**
	 * Prefixes of the same kind:
	 * - _wp_trash_meta_: status, time and comment status saved around trashing;
	 * - _wp_attachment_: attachment metadata, alt text, backup sizes, context;
	 * - _oembed_: the oEmbed response cache, which rendering refills lazily —
	 *   also when the block editor loads a post over REST — with the same embed.
	 */
	/** Classic-editor admin-ajax actions that save post meta of a post being edited. */
	private const META_AJAX_ACTIONS = array( 'set-post-thumbnail', 'add-meta', 'delete-meta' );

	private const INTERNAL_META_PREFIXES = array( '_wp_trash_meta_', '_wp_attachment_', '_oembed_' );

	/**
	 * Non-public post types the Site Editor saves: navigation menus, templates and
	 * template parts, global styles and synced patterns. Each renders on many
	 * pages at once, so a change clears the whole page cache.
	 */
	private const SITE_POST_TYPES = array( 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_block', 'nav_menu_item' );

	/**
	 * Site-wide settings whose change alters every rendered page, so they clear
	 * the whole HTML page cache rather than individual entries.
	 */
	private const SITE_OPTIONS = array(
		'blogname',
		'blogdescription',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'permalink_structure',
		'category_base',
		'tag_base',
		'date_format',
		'time_format',
		'start_of_week',
		'timezone_string',
		'gmt_offset',
		'WPLANG',
		'site_icon',
		'sidebars_widgets',
	);

	public static function init(): void {
		add_action( 'post_updated', array( self::class, 'post_updated' ), 10, 3 );
		add_action( 'set_object_terms', array( self::class, 'set_object_terms' ), 10, 6 );
		// wp_remove_object_terms() fires only this, never set_object_terms.
		add_action( 'deleted_term_relationships', array( self::class, 'term_relationships_deleted' ), 10, 3 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'post_meta_changed' ), 10, 3 );
		}
		// Fires for scheduled publishing too, which changes status without post_updated.
		add_action( 'transition_post_status', array( self::class, 'transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'before_delete_post' ), 10, 2 );
		// edit_terms fires before the database changes; edit_term already after.
		add_action( 'edit_terms', array( self::class, 'term_changing' ), 10, 2 );
		add_action( 'pre_delete_term', array( self::class, 'term_deleting' ), 10, 2 );
		add_action( 'edited_term', array( self::class, 'term_changed' ), 10, 3 );
		add_action( 'delete_term', array( self::class, 'term_deleted' ), 10, 5 );

		foreach ( array( 'wp_update_nav_menu', 'wp_delete_nav_menu', 'wp_update_nav_menu_item', 'customize_save_after', 'switch_theme', 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			add_action( $hook, array( self::class, 'site_changed' ) );
		}
		add_action( 'upgrader_process_complete', array( self::class, 'upgraded' ), 10, 2 );
		// "Replace current with uploaded" reports itself as an install, not an update.
		add_action( 'upgrader_overwrote_package', array( self::class, 'site_changed' ) );
		add_action( 'updated_option', array( self::class, 'option_updated' ) );
		add_action( 'acf/save_post', array( self::class, 'acf_saved' ) );
	}

	/**
	 * Queue the updated post and translation relationships visible before later
	 * save hooks have finished. The group and current terms are resolved again at
	 * shutdown so late WPML/Polylang callbacks are also covered.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post_after
	 * @param WP_Post $post_before
	 */
	public static function post_updated( int $post_id, mixed $post_after, mixed $post_before ): void {
		if ( self::is_ignored_post( $post_id ) ) {
			return;
		}

		$post_type = is_object( $post_after ) ? (string) ( $post_after->post_type ?? '' ) : '';
		if ( '' === $post_type && is_object( $post_before ) ) {
			$post_type = (string) ( $post_before->post_type ?? '' );
		}
		if ( ! self::is_frontend_post_type( $post_type ) ) {
			return;
		}

		// A draft edit changes nothing visitors can see.
		if ( self::is_public_post( $post_before ) || self::is_public_post( $post_after ) ) {
			self::content_changed();
		}

		$config = Simple_Redis_Cache_Config::get();
		if ( ! self::is_enabled( $config ) ) {
			return;
		}

		/*
		 * Capture the address the edge is actually holding, before the new status can
		 * change it. Unpublishing, trashing or renaming a post makes get_permalink()
		 * return the plain ?p=ID form or the new slug, so purging at shutdown alone
		 * would clear an address the CDN never cached and leave the live one served.
		 */
		self::remember_cloudflare_url( $post_before, $config );

		// Listing pages show published entries only, so a draft edit leaves them be.
		if ( self::is_public_post( $post_before ) || self::is_public_post( $post_after ) ) {
			self::queue_listing( $post_type, $config );
		}

		$translation_ids = self::translation_ids( $post_id, $post_type );
		foreach ( $translation_ids as $translation_id ) {
			// An orphaned multilingual relationship can point at a deleted post or one
			// whose type is no longer viewable. No payload can be bound to it, so
			// queueing it would only add a permanent field to the content-version hash.
			$translation_type = get_post_type( $translation_id );
			if ( ! is_string( $translation_type ) || ! self::is_frontend_post_type( $translation_type ) ) {
				continue;
			}

			self::$pending_post_ids[ $translation_id ] = true;
			if ( ! empty( $config['page']['invalidate_term_archives_on_post_update'] ) ) {
				self::collect_current_terms( $translation_id, $translation_type );
			}
		}

		self::schedule_flush();
	}

	/**
	 * Capture both sides of a taxonomy relationship change. This hook also
	 * handles relationship changes made directly through WordPress APIs without
	 * a surrounding wp_insert_post() call.
	 *
	 * @param int      $object_id
	 * @param mixed    $terms
	 * @param int[]    $tt_ids
	 * @param string   $taxonomy
	 * @param bool     $append
	 * @param int[]    $old_tt_ids
	 */
	public static function set_object_terms( int $object_id, mixed $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		unset( $terms, $append );
		self::terms_of_public_post_changed( $object_id, $taxonomy );

		/*
		 * No status check here: inside wp_insert_post() the terms are written after
		 * the new status, so unpublishing a post and moving it to another category
		 * in one save arrives as a draft whose old category still lists it.
		 */
		self::queue_post_terms( $object_id, array_merge( $old_tt_ids, $tt_ids ), $taxonomy, false );
	}

	/**
	 * Terms removed with wp_remove_object_terms(), which fires this instead of
	 * set_object_terms. wp_set_object_terms() removes terms the same way before
	 * its own set_object_terms, so both handlers fill the same keyed queue and the
	 * request still ends in one invalidation (or one full purge).
	 *
	 * @param int    $object_id
	 * @param int[]  $tt_ids    Term taxonomy IDs that were removed.
	 * @param string $taxonomy
	 */
	public static function term_relationships_deleted( mixed $object_id, mixed $tt_ids, mixed $taxonomy ): void {
		$object_id = (int) $object_id;
		$taxonomy  = (string) $taxonomy;
		self::terms_of_public_post_changed( $object_id, $taxonomy );

		/*
		 * Unlike set_object_terms, nothing here follows a status change: a
		 * published post leaves an archive, while a draft or a trashed post being
		 * emptied was in no public archive to begin with. Without this check,
		 * emptying the trash would invalidate and purge every term archive at the
		 * edge for nothing.
		 */
		self::queue_post_terms( $object_id, array_map( 'intval', (array) $tt_ids ), $taxonomy, true );
	}

	/**
	 * Post meta changed. Only a published post of a front-end type counts, only
	 * for keys that are not core bookkeeping, and by default only where content
	 * is edited: WP-CLI, and admin screens — plus the classic editor's
	 * set-post-thumbnail, add-meta and delete-meta admin-ajax actions — by a user
	 * who can edit the post. Front-end requests, other admin-ajax actions, REST,
	 * XML-RPC and cron — where view counters and synchronisation jobs write — do
	 * not invalidate unless the `simple_redis_cache_post_meta_invalidates` filter
	 * says so:
	 *
	 *     apply_filters( 'simple_redis_cache_post_meta_invalidates', bool $default, int $post_id, string $meta_key, string $context )
	 *
	 * $context is one of "cli", "cron", "rest", "xmlrpc", "admin", "ajax" or
	 * "frontend". The filter is not asked again for a post this request already
	 * queued. A change counts as a post update: the post and its translations,
	 * their term archives and the listing token, with their Cloudflare addresses,
	 * or the full purge when the settings ask for one — collected for shutdown
	 * together with any post update in the same request.
	 *
	 * @param int|int[] $meta_ids
	 * @param int       $object_id
	 * @param string    $meta_key
	 */
	public static function post_meta_changed( mixed $meta_ids, mixed $object_id, mixed $meta_key ): void {
		unset( $meta_ids );
		$post_id  = is_numeric( $object_id ) ? (int) $object_id : 0;
		$meta_key = is_string( $meta_key ) ? $meta_key : '';
		// delete_post_meta_by_key() reports object ID 0: which posts lost the key is unknown.
		if ( $post_id < 1 || isset( self::$meta_changed_post_ids[ $post_id ] ) || self::is_internal_meta_key( $meta_key ) ) {
			return;
		}

		$config = Simple_Redis_Cache_Config::get();
		if (
			Simple_Redis_Cache_Config::is_foreign( $config ) ||
			( ! self::is_enabled( $config ) && ! self::purges_everything_on_change( $config ) ) ||
			self::is_ignored_post( $post_id )
		) {
			return;
		}

		$post      = get_post( $post_id );
		$post_type = is_object( $post ) ? (string) ( $post->post_type ?? '' ) : '';
		if ( 'attachment' === $post_type || ! self::is_public_post( $post ) || ! self::is_frontend_post_type( $post_type ) ) {
			return;
		}

		$context = self::request_context();
		$default = self::meta_change_invalidates_by_default( $context, $post_id );
		if ( ! (bool) apply_filters( 'simple_redis_cache_post_meta_invalidates', $default, $post_id, $meta_key, $context ) ) {
			return;
		}

		self::$meta_changed_post_ids[ $post_id ] = true;
		self::content_changed();
		self::queue_post( $post_id, $post_type );
	}

	/**
	 * Catch every status change into or out of public view, including scheduled
	 * publishing: wp_publish_post() changes the status directly and never fires
	 * post_updated.
	 *
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 */
	public static function transition_post_status( mixed $new_status, mixed $old_status, mixed $post ): void {
		if ( ! is_object( $post ) || ( ! self::is_public_status( (string) $new_status ) && ! self::is_public_status( (string) $old_status ) ) ) {
			return;
		}

		if ( in_array( (string) ( $post->post_type ?? '' ), self::SITE_POST_TYPES, true ) ) {
			self::site_changed();
			return;
		}

		self::post_content_changed( (int) ( $post->ID ?? 0 ), (string) ( $post->post_type ?? '' ) );
		self::queue_post( (int) ( $post->ID ?? 0 ), (string) ( $post->post_type ?? '' ) );
	}

	/**
	 * Queue a published post that is about to be deleted outright. Its terms and
	 * public address are only known before core removes them.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function before_delete_post( int $post_id, mixed $post = null ): void {
		$post = is_object( $post ) ? $post : get_post( $post_id );
		if ( ! is_object( $post ) || ! self::is_public_post( $post ) ) {
			return;
		}
		if ( in_array( (string) ( $post->post_type ?? '' ), self::SITE_POST_TYPES, true ) ) {
			self::site_changed();
			return;
		}

		self::post_content_changed( $post_id, (string) ( $post->post_type ?? '' ) );
		$config = Simple_Redis_Cache_Config::get();
		if ( self::is_enabled( $config ) ) {
			self::remember_cloudflare_url( $post, $config );
		}
		self::queue_post( $post_id, (string) ( $post->post_type ?? '' ) );
	}

	/**
	 * Remember a term's public address before its slug or parent changes, so the
	 * edge copy under the old address is purged too.
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 */
	public static function term_changing( int $term_id, string $taxonomy ): void {
		self::remember_term_url( $term_id, $taxonomy );
	}

	/**
	 * @param int    $term_id
	 * @param string $taxonomy
	 */
	public static function term_deleting( int $term_id, string $taxonomy ): void {
		self::remember_term_url( $term_id, $taxonomy );
	}

	/**
	 * A renamed or moved term changes its own archive and every listing that
	 * prints its name.
	 *
	 * @param int    $term_id
	 * @param int    $term_taxonomy_id
	 * @param string $taxonomy
	 */
	public static function term_changed( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
		unset( $term_id );
		if ( self::is_viewable_taxonomy( $taxonomy ) ) {
			self::content_changed();
		}
		self::queue_term( $term_taxonomy_id, $taxonomy, get_term_by( 'term_taxonomy_id', $term_taxonomy_id, $taxonomy ) );
	}

	/**
	 * @param int    $term_id
	 * @param int    $term_taxonomy_id
	 * @param string $taxonomy
	 * @param mixed  $deleted_term Copy of the deleted term.
	 */
	public static function term_deleted( int $term_id, int $term_taxonomy_id, string $taxonomy, mixed $deleted_term = null, mixed $object_ids = null ): void {
		unset( $term_id, $object_ids );
		if ( self::is_viewable_taxonomy( $taxonomy ) ) {
			self::content_changed();
		}
		self::queue_term( $term_taxonomy_id, $taxonomy, $deleted_term );
	}

	/**
	 * Updates change what every page renders; installing a plugin or theme does
	 * not, until it is activated.
	 *
	 * @param mixed                $upgrader
	 * @param array<string, mixed> $hook_extra
	 */
	public static function upgraded( mixed $upgrader, mixed $hook_extra = array() ): void {
		unset( $upgrader );
		if ( is_array( $hook_extra ) && 'update' === ( $hook_extra['action'] ?? '' ) ) {
			self::site_changed();
		}
	}

	/** Menus, widgets, the Customizer, the theme or an update changed every page. */
	public static function site_changed(): void {
		$config = Simple_Redis_Cache_Config::get();
		// The whole-cache purge for content changes covers site changes as well.
		if ( self::purges_everything_on_change( $config ) ) {
			self::content_changed();
			return;
		}
		if ( empty( $config['page']['enabled'] ) || empty( $config['page']['purge_on_site_change'] ) || self::$site_purge_scheduled ) {
			return;
		}

		self::$site_purge_scheduled = true;
		add_action( 'shutdown', array( self::class, 'flush_site_change' ), PHP_INT_MAX );
	}

	/** @param string $option */
	public static function option_updated( mixed $option ): void {
		$option = (string) $option;
		if ( in_array( $option, self::SITE_OPTIONS, true ) || str_starts_with( $option, 'widget_' ) || str_starts_with( $option, 'theme_mods_' ) ) {
			self::site_changed();
		}
	}

	/**
	 * ACF options pages save under an "options"-style ID rather than a post ID,
	 * and their fields typically render in headers and footers on every page.
	 *
	 * @param mixed $post_id
	 */
	public static function acf_saved( mixed $post_id ): void {
		if ( is_string( $post_id ) && str_starts_with( $post_id, 'option' ) ) {
			self::site_changed();
		}
	}

	/** Clear the whole HTML page cache once, after the request's changes are done. */
	public static function flush_site_change(): void {
		self::$site_purge_scheduled = false;
		$result = Simple_Redis_Cache_Purger::purge( 'page', 'purge_on_content_change' );
		if ( is_wp_error( $result ) ) {
			Simple_Redis_Cache_Admin::queue_notice(
				sprintf(
					/* translators: %s: Redis or Cloudflare error. */
					__( 'The site changed, but the HTML page cache could not be cleared: %s', 'simple-redis-cache' ),
					implode( ' ', $result->get_error_messages() )
				),
				'warning'
			);
		}
	}

	/**
	 * Public content changed: when the settings ask for it, clear the whole cache
	 * (and the Cloudflare zone) once at the end of the request.
	 */
	public static function content_changed(): void {
		if ( self::$content_purge_scheduled || self::$content_purged ) {
			return;
		}

		$config = Simple_Redis_Cache_Config::get();
		if ( Simple_Redis_Cache_Config::is_foreign( $config ) || ! self::purges_everything_on_change( $config ) ) {
			return;
		}

		self::$content_purge_scheduled = true;
		add_action( 'shutdown', array( self::class, 'flush_content_change' ), PHP_INT_MAX );
	}

	/**
	 * Clear everything the settings name, once, after the request's changes are
	 * done. The targeted flush of this request has nothing left to do then.
	 */
	public static function flush_content_change(): void {
		self::$content_purge_scheduled = false;

		$config = Simple_Redis_Cache_Config::get();
		$scope  = self::content_purge_scope( $config );
		if ( 'off' === $scope ) {
			/*
			 * No page cache here: the edge is the only HTML cache, so it can be purged
			 * on its own. With the page cache on, the targeted flush purges the zone
			 * once Redis no longer serves the old page.
			 */
			if ( empty( $config['page']['enabled'] ) && Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_content_change', $config ) ) {
				self::$content_purged = true;
				self::purge_zone( $config );
			}
			return;
		}

		self::$content_purged = true;
		self::reset_pending();
		$result = Simple_Redis_Cache_Purger::purge( $scope, 'purge_on_content_change' );
		if ( is_wp_error( $result ) ) {
			Simple_Redis_Cache_Admin::queue_notice(
				sprintf(
					/* translators: %s: Redis or Cloudflare error. */
					__( 'The content changed, but the cache could not be cleared: %s', 'simple-redis-cache' ),
					implode( ' ', $result->get_error_messages() )
				),
				'warning'
			);
		}
	}

	/**
	 * What a content change clears as a whole: "off" (only what changed), "page" or
	 * "all" (the page and the object cache).
	 *
	 * @param array<string, mixed> $config
	 */
	public static function content_purge_scope( array $config ): string {
		$scope = (string) ( $config['page']['purge_on_content_change'] ?? 'off' );

		return in_array( $scope, array( 'page', 'all' ), true ) ? $scope : 'off';
	}

	/**
	 * Clear one post's cached pages now, on an explicit request — the
	 * simple-redis-cache/flush-post ability — rather than at shutdown after an
	 * edit. The post and its translations always; with $related also their term
	 * archives and the listing pages, as far as the settings tie pages to those.
	 * Cloudflare gets the same addresses when it purges on post updates, after
	 * Redis, so the edge cannot refetch the old page.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function purge_post_now( int $post_id, bool $related = false ): array|WP_Error {
		$post = $post_id > 0 && ! self::is_ignored_post( $post_id ) ? get_post( $post_id ) : null;
		if ( ! is_object( $post ) ) {
			return new WP_Error( 'src_post_not_found', __( 'No post, page or other entry has this ID.', 'simple-redis-cache' ) );
		}

		$post_type = (string) ( $post->post_type ?? '' );
		if ( 'attachment' === $post_type || ! self::is_frontend_post_type( $post_type ) ) {
			return new WP_Error( 'src_post_not_public', __( 'Entries of this type have no public page of their own, so none of it is cached.', 'simple-redis-cache' ) );
		}
		/*
		 * A draft has no cached page, and the edit_post capability that lets an author
		 * call this covers drafts: clearing listings and edge URLs for one would let a
		 * contributor bust site-wide caches at will.
		 */
		if ( ! self::is_public_post( $post ) ) {
			return new WP_Error( 'src_post_not_published', __( 'This entry is not published, so none of its pages is cached.', 'simple-redis-cache' ) );
		}

		$config = Simple_Redis_Cache_Config::get();
		if ( Simple_Redis_Cache_Config::is_foreign( $config ) ) {
			return new WP_Error( 'src_copied_site', __( 'These cache settings were copied from another site. Open any wp-admin page as an administrator so Simple Redis Cache can give this site its own cache namespace, then try again.', 'simple-redis-cache' ) );
		}

		$page_cache = ! empty( $config['page']['enabled'] );
		$edge       = Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_post_update', $config );
		if ( ! $page_cache && ! $edge ) {
			return new WP_Error( 'src_nothing_to_clear', __( 'Neither the HTML page cache nor Cloudflare purging on post updates is enabled, so no page of this post is cached here.', 'simple-redis-cache' ) );
		}
		if ( $page_cache && empty( $config['page']['invalidate_on_post_update'] ) ) {
			return new WP_Error( 'src_post_invalidation_off', __( 'Cached pages are not tied to their posts on this site ("Clear a page\'s cache when it is updated" is off on the HTML Page Cache tab), so one post cannot be cleared on its own. Clear the whole page cache instead.', 'simple-redis-cache' ) );
		}

		$post_ids = array();
		foreach ( self::translation_ids( $post_id, $post_type ) as $translation_id ) {
			$translation_type = get_post_type( $translation_id );
			if ( is_string( $translation_type ) && self::is_frontend_post_type( $translation_type ) ) {
				$post_ids[] = (int) $translation_id;
			}
		}

		$resources = array( 'post' => $post_ids );
		if ( $related ) {
			if ( ! empty( $config['page']['invalidate_term_archives_on_post_update'] ) ) {
				// Borrow the collector without disturbing work queued by this request.
				$queued                          = self::$pending_term_taxonomy_ids;
				self::$pending_term_taxonomy_ids = array();
				foreach ( $post_ids as $id ) {
					self::collect_current_terms( $id, (string) get_post_type( $id ) );
				}
				$resources['term']               = array_keys( self::$pending_term_taxonomy_ids );
				self::$pending_term_taxonomy_ids = $queued;
			}
			if ( ! empty( $config['page']['invalidate_listings_on_post_update'] ) ) {
				$resources['list'] = array( Simple_Redis_Cache_Page_Capture::LISTING_RESOURCE_ID );
			}
		}

		$result = array(
			'post_ids'      => $post_ids,
			'term_archives' => count( $resources['term'] ?? array() ),
			'listing_pages' => ! empty( $resources['list'] ),
			'page_cache'    => 'not enabled',
		);

		if ( $page_cache ) {
			$redis = new Simple_Redis_Cache_Redis( $config );
			if ( false === $redis->bump_page_content_versions( $resources ) ) {
				return new WP_Error(
					'src_post_purge_failed',
					sprintf(
						/* translators: %s: Redis connection or command error. */
						__( 'Could not clear the cached pages of this post: %s', 'simple-redis-cache' ),
						$redis->display_error() ?: __( 'Redis is unavailable.', 'simple-redis-cache' )
					)
				);
			}
			$result['page_cache'] = 'cleared';
		}

		$result['cloudflare'] = 'not purged (Cloudflare purging on post updates is off)';
		if ( $edge ) {
			$urls   = array_values( array_unique( self::cloudflare_urls( $resources, array(), array( $post_type ) ) ) );
			$purged = Simple_Redis_Cache_Cloudflare::purge_urls( $urls, $config );
			if ( is_wp_error( $purged ) ) {
				$result['cloudflare'] = 'purge failed: ' . implode( ' ', $purged->get_error_messages() );
			} else {
				$result['cloudflare']      = 'purged';
				$result['cloudflare_urls'] = $urls;
				if ( ! empty( $purged['skipped'] ) ) {
					$result['cloudflare_urls_left'] = (int) $purged['skipped'];
				}
			}
		}

		return $result;
	}

	/**
	 * Whether a content change goes to flush_content_change(): a whole-cache scope, or
	 * a Cloudflare zone purge that has no page cache to wait for.
	 *
	 * @param array<string, mixed> $config
	 */
	private static function purges_everything_on_change( array $config ): bool {
		return 'off' !== self::content_purge_scope( $config )
			|| ( empty( $config['page']['enabled'] ) && Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_content_change', $config ) );
	}

	/** A published entry of a post type visitors can see changed. */
	private static function post_content_changed( int $post_id, string $post_type ): void {
		if ( ! self::is_ignored_post( $post_id ) && self::is_frontend_post_type( $post_type ) ) {
			self::content_changed();
		}
	}

	/** Categories, tags or other public terms of a published post changed. */
	private static function terms_of_public_post_changed( int $post_id, string $taxonomy ): void {
		if ( self::is_ignored_post( $post_id ) || ! self::is_viewable_taxonomy( $taxonomy ) ) {
			return;
		}

		$post = get_post( $post_id );
		$type = is_object( $post ) ? (string) ( $post->post_type ?? '' ) : '';
		if ( self::is_public_post( $post ) && self::is_frontend_post_type( $type ) && self::taxonomy_applies_to_post_type( $taxonomy, $type ) ) {
			self::content_changed();
		}
	}

	/**
	 * Queue a post whose terms changed and the archives of those terms (with
	 * their parents; translations and current terms follow at shutdown).
	 *
	 * @param int[] $tt_ids
	 */
	private static function queue_post_terms( int $object_id, array $tt_ids, string $taxonomy, bool $published_only ): void {
		$config = Simple_Redis_Cache_Config::get();
		if (
			! self::is_enabled( $config ) ||
			empty( $config['page']['invalidate_term_archives_on_post_update'] ) ||
			self::is_ignored_post( $object_id )
		) {
			return;
		}

		$post_type = get_post_type( $object_id );
		if ( ! is_string( $post_type ) || ! self::is_frontend_post_type( $post_type ) || ! self::is_viewable_taxonomy( $taxonomy ) ) {
			return;
		}

		/*
		 * Term relationship hooks fire for any object type, and their object IDs are
		 * only unique per taxonomy. A term attached to a user or comment whose ID
		 * happens to match a post ID would otherwise invalidate that unrelated post
		 * and every term it carries. Require the post type to actually belong to
		 * this taxonomy.
		 */
		if ( ! self::taxonomy_applies_to_post_type( $taxonomy, $post_type ) ) {
			return;
		}
		if ( $published_only && ! self::is_public_post( get_post( $object_id ) ) ) {
			return;
		}

		self::$pending_post_ids[ $object_id ] = true;
		self::collect_term_taxonomy_ids( $tt_ids, $taxonomy );
		self::schedule_flush();
	}

	private static function is_internal_meta_key( string $meta_key ): bool {
		if ( '' === $meta_key || in_array( $meta_key, self::INTERNAL_META_KEYS, true ) ) {
			return true;
		}
		foreach ( self::INTERNAL_META_PREFIXES as $prefix ) {
			if ( str_starts_with( $meta_key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/** Where the current request comes from, as the post meta filter names it. */
	private static function request_context(): string {
		// Before WP-CLI: `wp cron event run`, the usual production cron runner, is both.
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return 'admin';
		}

		return 'frontend';
	}

	/**
	 * WP-CLI is someone at a terminal changing content; an admin screen is
	 * someone allowed to edit the post doing so, and so are the classic editor's
	 * own admin-ajax actions that save meta before the post is updated.
	 *
	 * Everything else stays out by default because it also runs per visit or on a
	 * timer: view counters on the front end, over admin-ajax or over REST (core
	 * REST and XML-RPC edits of a post fire post_updated anyway), and scheduled
	 * jobs that sync statistics or stock. In the 0.9.0 full-purge mode each of
	 * those would clear the whole site. The filter opts them in.
	 */
	private static function meta_change_invalidates_by_default( string $context, int $post_id ): bool {
		// The current user is pluggable: unknown before plugins have loaded.
		$can_edit = static fn (): bool => function_exists( 'wp_get_current_user' ) && current_user_can( 'edit_post', $post_id );

		return match ( $context ) {
			'cli'   => true,
			'admin' => $can_edit(),
			'ajax'  => in_array( self::ajax_action(), self::META_AJAX_ACTIONS, true ) && $can_edit(),
			default => false,
		};
	}

	/** The admin-ajax action of this request, as WordPress dispatches it. */
	private static function ajax_action(): string {
		$action = $_REQUEST['action'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared with a fixed list; the action checks its own nonce.

		return is_string( $action ) ? $action : '';
	}

	/** @param array<string, mixed> $config */
	private static function purge_zone( array $config ): void {
		$purged = Simple_Redis_Cache_Cloudflare::purge_everything( $config );
		if ( is_wp_error( $purged ) ) {
			Simple_Redis_Cache_Admin::queue_notice(
				sprintf(
					/* translators: %s: Cloudflare API error. */
					__( 'Could not clear the Cloudflare cache for the updated content: %s', 'simple-redis-cache' ),
					implode( ' ', $purged->get_error_messages() )
				),
				'warning'
			);
		}
	}

	/** Apply one atomic Redis version change after every save hook has completed. */
	public static function flush(): void {
		self::$shutdown_scheduled = false;
		if ( empty( self::$pending_post_ids ) && empty( self::$pending_term_taxonomy_ids ) && ! self::$pending_listing ) {
			return;
		}
		// The whole cache is (or is about to be) cleared for this request's changes.
		if ( self::$content_purged || ( self::$content_purge_scheduled && 'off' !== self::content_purge_scope( Simple_Redis_Cache_Config::get() ) ) ) {
			self::reset_pending();
			return;
		}

		$config = Simple_Redis_Cache_Config::get();
		if ( ! self::is_enabled( $config ) ) {
			self::reset_pending();
			return;
		}

		/* Resolve translations and current terms again after multilingual callbacks. */
		$initial_ids = array_keys( self::$pending_post_ids );
		foreach ( $initial_ids as $post_id ) {
			$post_type = get_post_type( $post_id );
			if ( ! is_string( $post_type ) || ! self::is_frontend_post_type( $post_type ) ) {
				continue;
			}

			foreach ( self::translation_ids( $post_id, $post_type ) as $translation_id ) {
				$translation_type = get_post_type( $translation_id );
				if ( ! is_string( $translation_type ) || ! self::is_frontend_post_type( $translation_type ) ) {
					continue;
				}
				self::$pending_post_ids[ $translation_id ] = true;
				if ( ! empty( $config['page']['invalidate_term_archives_on_post_update'] ) ) {
					self::collect_current_terms( $translation_id, $translation_type );
				}
			}
		}

		$resources = array();
		if ( ! empty( $config['page']['invalidate_on_post_update'] ) ) {
			$resources['post'] = array_keys( self::$pending_post_ids );
		}
		if ( ! empty( $config['page']['invalidate_term_archives_on_post_update'] ) ) {
			$resources['term'] = array_keys( self::$pending_term_taxonomy_ids );
		}
		if ( self::$pending_listing && ! empty( $config['page']['invalidate_listings_on_post_update'] ) ) {
			$resources['list'] = array( Simple_Redis_Cache_Page_Capture::LISTING_RESOURCE_ID );
		}
		/* Addresses captured before the update survive the reset below. */
		$captured_urls = array_keys( self::$pending_cloudflare_urls );
		if ( ! empty( $resources['term'] ) ) {
			$captured_terms = array_keys( self::$pending_cloudflare_term_urls );
		}
		$post_types    = array_keys( self::$pending_post_types );
		self::reset_pending();

		if ( empty( $resources['post'] ) && empty( $resources['term'] ) && empty( $resources['list'] ) ) {
			return;
		}

		$redis  = new Simple_Redis_Cache_Redis( $config );
		$bumped = $redis->bump_page_content_versions( $resources );

		/*
		 * Only clear the edge once the origin really is invalidated. Purging while
		 * Redis still serves the old generation makes staleness worse: the CDN
		 * immediately refetches that same stale page and holds it for a fresh
		 * s-maxage.
		 */
		if ( false !== $bumped ) {
			self::purge_cloudflare_urls( $resources, $captured_urls, $post_types, $config, $captured_terms ?? array() );
			return;
		}

		Simple_Redis_Cache_Admin::queue_notice(
			sprintf(
				/* translators: %s: Redis connection or command error. */
				__( 'Could not invalidate page cache related to the updated post: %s', 'simple-redis-cache' ),
				$redis->display_error() ?: __( 'Redis is unavailable.', 'simple-redis-cache' )
			),
			'warning'
		);
	}

	/**
	 * Clear the same resources at the Cloudflare edge.
	 *
	 * Cloudflare purges exact URLs, so this covers each updated post and the first
	 * page of each related term archive. Deeper pagination is not addressed —
	 * prefix purging is Enterprise-only — and neither are the generic views the
	 * Redis side deliberately leaves alone. Failure is fail-open with a warning,
	 * exactly like a failed Redis invalidation.
	 *
	 * @param array{post?:int[],term?:int[],list?:int[]} $resources
	 * @param string[]                                   $captured_urls Addresses seen before the update.
	 * @param string[]                                   $post_types    Types of the changed posts.
	 * @param array<string, mixed>                       $config
	 * @param string[]                                   $term_urls     Term addresses seen before a rename or deletion.
	 */
	private static function purge_cloudflare_urls( array $resources, array $captured_urls, array $post_types, array $config, array $term_urls = array() ): void {
		// The whole zone, when asked for, makes the exact addresses unnecessary.
		if ( Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_content_change', $config ) ) {
			self::purge_zone( $config );
			return;
		}
		if ( ! Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_post_update', $config ) ) {
			return;
		}

		$urls = self::cloudflare_urls( $resources, $captured_urls, $post_types, $term_urls );
		if ( empty( $urls ) ) {
			return;
		}

		$purged = Simple_Redis_Cache_Cloudflare::purge_urls( $urls, $config );
		if ( ! is_wp_error( $purged ) ) {
			if ( ! empty( $purged['skipped'] ) ) {
				Simple_Redis_Cache_Admin::queue_notice(
					sprintf(
						/* translators: %d: number of URLs left on the CDN. */
						__( 'This update touched more URLs than one Cloudflare purge can carry, so %d of them were left on the CDN. Use Clear Cloudflare cache to clear the whole zone.', 'simple-redis-cache' ),
						(int) $purged['skipped']
					),
					'warning'
				);
			}
			return;
		}

		Simple_Redis_Cache_Admin::queue_notice(
			sprintf(
				/* translators: %s: Cloudflare API error. */
				__( 'Could not clear the Cloudflare cache for the updated content: %s', 'simple-redis-cache' ),
				implode( ' ', $purged->get_error_messages() )
			),
			'warning'
		);
	}

	/**
	 * The edge addresses of invalidated resources.
	 *
	 * @param array{post?:int[],term?:int[],list?:int[]} $resources
	 * @param string[]                                   $captured_urls Addresses seen before the update.
	 * @param string[]                                   $post_types    Types of the changed posts.
	 * @param string[]                                   $term_urls     Term addresses seen before a rename or deletion.
	 * @return string[]
	 */
	private static function cloudflare_urls( array $resources, array $captured_urls, array $post_types, array $term_urls = array() ): array {
		/*
		 * The pre-update addresses belong to posts, so they only apply when singular
		 * invalidation is the thing that ran. With only taxonomy invalidation enabled
		 * they would spend the URL budget on a page this flush never invalidated.
		 */
		$urls = empty( $resources['post'] ) ? array() : $captured_urls;
		foreach ( (array) ( $resources['post'] ?? array() ) as $post_id ) {
			$permalink = self::public_permalink( (int) $post_id );
			if ( null !== $permalink ) {
				$urls[] = $permalink;
			}
		}

		foreach ( (array) ( $resources['term'] ?? array() ) as $term_taxonomy_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $term_taxonomy_id );
			if ( ! is_object( $term ) || is_wp_error( $term ) ) {
				continue;
			}

			$link = get_term_link( $term );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}

		if ( ! empty( $resources['list'] ) ) {
			$urls = array_merge( $urls, self::listing_urls( $post_types ) );
		}

		return array_merge( $urls, $term_urls );
	}

	/**
	 * Record the pre-update public address of a post, if it had one.
	 *
	 * @param mixed                $post
	 * @param array<string, mixed> $config
	 */
	private static function remember_cloudflare_url( mixed $post, array $config ): void {
		if ( ! is_object( $post ) || ! Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_post_update', $config ) ) {
			return;
		}

		$permalink = self::public_permalink( $post );
		if ( null !== $permalink ) {
			self::$pending_cloudflare_urls[ $permalink ] = true;
		}
	}

	/**
	 * Return a post's permalink only while its status is publicly viewable.
	 *
	 * A non-viewable status makes WordPress fall back to the plain ?p=ID form,
	 * which no visitor ever requested and no CDN ever cached. Purging it would
	 * spend an API call on nothing while the real address stayed on the edge.
	 *
	 * @param int|WP_Post $post
	 */
	private static function public_permalink( mixed $post ): ?string {
		$post = get_post( $post );
		if ( ! is_object( $post ) ) {
			return null;
		}

		$status = get_post_status_object( (string) ( $post->post_status ?? '' ) );
		if ( ! is_object( $status ) || ! is_post_status_viewable( $status ) ) {
			return null;
		}

		$permalink = get_permalink( $post );

		return is_string( $permalink ) && '' !== $permalink ? $permalink : null;
	}

	/** @param array<string, mixed> $config */
	private static function is_enabled( array $config ): bool {
		return ! empty( $config['page']['enabled'] ) && ! Simple_Redis_Cache_Config::is_foreign( $config ) && (
			! empty( $config['page']['invalidate_on_post_update'] ) ||
			! empty( $config['page']['invalidate_term_archives_on_post_update'] ) ||
			! empty( $config['page']['invalidate_listings_on_post_update'] )
		);
	}

	/**
	 * Queue a post, its translations and current terms, and the listing token.
	 * Callers have established that the change affects public output.
	 */
	private static function queue_post( int $post_id, string $post_type ): void {
		$config = Simple_Redis_Cache_Config::get();
		if ( ! self::is_enabled( $config ) || self::is_ignored_post( $post_id ) || ! self::is_frontend_post_type( $post_type ) ) {
			return;
		}

		self::queue_listing( $post_type, $config );
		foreach ( self::translation_ids( $post_id, $post_type ) as $translation_id ) {
			$translation_type = get_post_type( $translation_id );
			if ( ! is_string( $translation_type ) || ! self::is_frontend_post_type( $translation_type ) ) {
				continue;
			}

			self::$pending_post_ids[ $translation_id ] = true;
			if ( ! empty( $config['page']['invalidate_term_archives_on_post_update'] ) ) {
				self::collect_current_terms( $translation_id, $translation_type );
			}
		}

		self::schedule_flush();
	}

	/** @param array<string, mixed> $config */
	private static function queue_listing( string $post_type, array $config ): void {
		if ( empty( $config['page']['invalidate_listings_on_post_update'] ) ) {
			return;
		}

		self::$pending_listing = true;
		if ( '' !== $post_type ) {
			self::$pending_post_types[ $post_type ] = true;
		}
		self::schedule_flush();
	}

	/** Queue a changed or deleted public term, its parents and the listing token. */
	private static function queue_term( int $term_taxonomy_id, string $taxonomy, mixed $term ): void {
		$config = Simple_Redis_Cache_Config::get();
		if ( ! self::is_enabled( $config ) || ! self::is_viewable_taxonomy( $taxonomy ) ) {
			return;
		}

		self::queue_listing( '', $config );
		if ( ! empty( $config['page']['invalidate_term_archives_on_post_update'] ) && $term_taxonomy_id > 0 ) {
			self::$pending_term_taxonomy_ids[ $term_taxonomy_id ] = true;
			self::collect_term( $term, $taxonomy );
			// A deleted term has no ancestors left to look up; its parent is on the copy.
			$parent = is_object( $term ) ? (int) ( $term->parent ?? 0 ) : 0;
			if ( $parent > 0 ) {
				self::collect_term( get_term( $parent, $taxonomy ), $taxonomy );
			}
		}
		self::schedule_flush();
	}

	/** Record a public term archive address while it still resolves. */
	private static function remember_term_url( int $term_id, string $taxonomy ): void {
		$config = Simple_Redis_Cache_Config::get();
		if (
			! self::is_enabled( $config ) ||
			empty( $config['page']['invalidate_term_archives_on_post_update'] ) ||
			! Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_post_update', $config ) ||
			! self::is_viewable_taxonomy( $taxonomy )
		) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		$link = is_object( $term ) && ! is_wp_error( $term ) ? get_term_link( $term ) : '';
		if ( is_string( $link ) && '' !== $link ) {
			self::$pending_cloudflare_term_urls[ $link ] = true;
		}
	}

	/** @param mixed $post */
	private static function is_public_post( mixed $post ): bool {
		return is_object( $post ) && self::is_public_status( (string) ( $post->post_status ?? '' ) );
	}

	private static function is_public_status( string $status ): bool {
		$status_object = '' !== $status ? get_post_status_object( $status ) : null;
		return is_object( $status_object ) && is_post_status_viewable( $status_object );
	}

	/**
	 * Exact addresses of the listing pages a CDN may hold: the home page of every
	 * language WPML or Polylang knows, the posts page and the changed types'
	 * archives. Deeper pagination is out of reach of exact-URL purging.
	 *
	 * @param string[] $post_types
	 * @return string[]
	 */
	private static function listing_urls( array $post_types ): array {
		$urls = array( home_url( '/' ) );

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( 'page' === get_option( 'show_on_front' ) && $posts_page > 0 ) {
			$urls[] = (string) get_permalink( $posts_page );
		}

		foreach ( $post_types as $post_type ) {
			$archive = get_post_type_archive_link( $post_type );
			if ( is_string( $archive ) ) {
				$urls[] = $archive;
			}
		}

		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_home_url' ) ) {
			foreach ( (array) pll_languages_list() as $language ) {
				$urls[] = (string) pll_home_url( (string) $language );
			}
		}

		if ( function_exists( 'has_filter' ) && has_filter( 'wpml_active_languages' ) ) {
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $language ) {
				if ( is_array( $language ) && is_string( $language['url'] ?? null ) ) {
					$urls[] = $language['url'];
				}
			}
		}

		return array_values( array_filter( $urls, 'strlen' ) );
	}

	private static function is_ignored_post( int $post_id ): bool {
		return $post_id < 1 || (bool) wp_is_post_revision( $post_id ) || (bool) wp_is_post_autosave( $post_id );
	}

	private static function is_frontend_post_type( string $post_type ): bool {
		if ( '' === $post_type ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post_type );
		return is_object( $post_type_object ) && is_post_type_viewable( $post_type_object );
	}

	private static function is_viewable_taxonomy( string $taxonomy ): bool {
		$taxonomy_object = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : false;
		return is_object( $taxonomy_object ) && is_taxonomy_viewable( $taxonomy_object );
	}

	private static function taxonomy_applies_to_post_type( string $taxonomy, string $post_type ): bool {
		$taxonomy_object = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : false;
		if ( ! is_object( $taxonomy_object ) ) {
			return false;
		}

		return in_array( $post_type, array_map( 'strval', (array) ( $taxonomy_object->object_type ?? array() ) ), true );
	}

	private static function collect_current_terms( int $post_id, string $post_type ): void {
		if ( $post_id < 1 || ! self::is_frontend_post_type( $post_type ) ) {
			return;
		}

		$taxonomy_objects = get_object_taxonomies( $post_type, 'objects' );
		if ( ! is_array( $taxonomy_objects ) ) {
			return;
		}

		foreach ( $taxonomy_objects as $taxonomy_object ) {
			if ( ! is_object( $taxonomy_object ) || ! is_taxonomy_viewable( $taxonomy_object ) ) {
				continue;
			}
			$taxonomy = (string) ( $taxonomy_object->name ?? '' );
			if ( '' === $taxonomy ) {
				continue;
			}

			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
			if ( ! is_array( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				self::collect_term( $term, $taxonomy );
			}
		}
	}

	/** @param int[] $term_taxonomy_ids */
	private static function collect_term_taxonomy_ids( array $term_taxonomy_ids, string $taxonomy ): void {
		if ( ! self::is_viewable_taxonomy( $taxonomy ) ) {
			return;
		}

		foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
			$term_taxonomy_id = (int) $term_taxonomy_id;
			if ( $term_taxonomy_id < 1 ) {
				continue;
			}
			$term = get_term_by( 'term_taxonomy_id', $term_taxonomy_id, $taxonomy );
			self::collect_term( $term, $taxonomy );
		}
	}

	private static function collect_term( mixed $term, string $taxonomy ): void {
		if ( ! is_object( $term ) || is_wp_error( $term ) ) {
			return;
		}

		$term_id          = (int) ( $term->term_id ?? 0 );
		$term_taxonomy_id = (int) ( $term->term_taxonomy_id ?? 0 );
		if ( $term_taxonomy_id > 0 ) {
			self::$pending_term_taxonomy_ids[ $term_taxonomy_id ] = true;
		}
		if ( $term_id < 1 ) {
			return;
		}

		$ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
		foreach ( (array) $ancestors as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, $taxonomy );
			if ( is_object( $ancestor ) && ! is_wp_error( $ancestor ) ) {
				$ancestor_tt_id = (int) ( $ancestor->term_taxonomy_id ?? 0 );
				if ( $ancestor_tt_id > 0 ) {
					self::$pending_term_taxonomy_ids[ $ancestor_tt_id ] = true;
				}
			}
		}
	}

	private static function schedule_flush(): void {
		if ( self::$shutdown_scheduled ) {
			return;
		}

		self::$shutdown_scheduled = true;
		add_action( 'shutdown', array( self::class, 'flush' ), PHP_INT_MAX );
	}

	private static function reset_pending(): void {
		self::$pending_post_ids          = array();
		self::$pending_term_taxonomy_ids = array();
		self::$pending_cloudflare_urls   = array();
		self::$pending_listing           = false;
		self::$pending_post_types        = array();
		self::$pending_cloudflare_term_urls = array();
		self::$meta_changed_post_ids        = array();
	}

	/**
	 * Return the current post plus translations known to Polylang and WPML.
	 * Integrations are optional and use only their documented public APIs.
	 *
	 * @return int[]
	 */
	private static function translation_ids( int $post_id, string $post_type ): array {
		$ids = array( $post_id => true );

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );
			if ( is_array( $translations ) ) {
				foreach ( $translations as $translation_id ) {
					$translation_id = (int) $translation_id;
					if ( $translation_id > 0 ) {
						$ids[ $translation_id ] = true;
					}
				}
			}
		}

		if (
			function_exists( 'apply_filters' ) &&
			function_exists( 'has_filter' ) &&
			has_filter( 'wpml_element_trid' ) &&
			has_filter( 'wpml_get_element_translations' )
		) {
			$element_type = apply_filters( 'wpml_element_type', $post_type );
			$element_type = is_string( $element_type ) && '' !== $element_type ? $element_type : 'post_' . $post_type;
			$trid         = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
			if ( is_numeric( $trid ) && (int) $trid > 0 ) {
				$translations = apply_filters( 'wpml_get_element_translations', array(), (int) $trid, $element_type );
				if ( is_array( $translations ) ) {
					foreach ( $translations as $translation ) {
						$translation_id = is_object( $translation )
							? (int) ( $translation->element_id ?? 0 )
							: ( is_array( $translation ) ? (int) ( $translation['element_id'] ?? 0 ) : 0 );
						if ( $translation_id > 0 ) {
							$ids[ $translation_id ] = true;
						}
					}
				}
			}
		}

		return array_keys( $ids );
	}
}
