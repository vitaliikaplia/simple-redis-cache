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
	private static bool $shutdown_scheduled = false;

	public static function init(): void {
		add_action( 'post_updated', array( self::class, 'post_updated' ), 10, 3 );
		add_action( 'set_object_terms', array( self::class, 'set_object_terms' ), 10, 6 );
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
		$config = Simple_Redis_Cache_Config::get();
		if ( ! self::is_enabled( $config ) || self::is_ignored_post( $post_id ) ) {
			return;
		}

		$post_type = is_object( $post_after ) ? (string) ( $post_after->post_type ?? '' ) : '';
		if ( '' === $post_type && is_object( $post_before ) ) {
			$post_type = (string) ( $post_before->post_type ?? '' );
		}
		if ( ! self::is_frontend_post_type( $post_type ) ) {
			return;
		}

		/*
		 * Capture the address the edge is actually holding, before the new status can
		 * change it. Unpublishing, trashing or renaming a post makes get_permalink()
		 * return the plain ?p=ID form or the new slug, so purging at shutdown alone
		 * would clear an address the CDN never cached and leave the live one served.
		 */
		self::remember_cloudflare_url( $post_before, $config );

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
		 * set_object_terms fires for any object type, and its object IDs are only
		 * unique per taxonomy. A term attached to a user or comment whose ID happens
		 * to match a post ID would otherwise invalidate that unrelated post and every
		 * term it carries. Require the post type to actually belong to this taxonomy.
		 */
		if ( ! self::taxonomy_applies_to_post_type( $taxonomy, $post_type ) ) {
			return;
		}

		self::$pending_post_ids[ $object_id ] = true;
		self::collect_term_taxonomy_ids( array_merge( $old_tt_ids, $tt_ids ), $taxonomy );
		self::schedule_flush();
	}

	/** Apply one atomic Redis version change after every save hook has completed. */
	public static function flush(): void {
		self::$shutdown_scheduled = false;
		if ( empty( self::$pending_post_ids ) && empty( self::$pending_term_taxonomy_ids ) ) {
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
		/* Addresses captured before the update survive the reset below. */
		$captured_urls = array_keys( self::$pending_cloudflare_urls );
		self::reset_pending();

		if ( empty( $resources['post'] ) && empty( $resources['term'] ) ) {
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
			self::purge_cloudflare_urls( $resources, $captured_urls, $config );
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
	 * @param array{post?:int[],term?:int[]} $resources
	 * @param string[]                       $captured_urls Addresses seen before the update.
	 * @param array<string, mixed>           $config
	 */
	private static function purge_cloudflare_urls( array $resources, array $captured_urls, array $config ): void {
		if ( ! Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_post_update', $config ) ) {
			return;
		}

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
		return ! empty( $config['page']['enabled'] ) && (
			! empty( $config['page']['invalidate_on_post_update'] ) ||
			! empty( $config['page']['invalidate_term_archives_on_post_update'] )
		);
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
