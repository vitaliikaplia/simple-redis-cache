<?php
/**
 * Native WordPress plugin updates from the public GitHub repository.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_GitHub_Updater {
	private const REPOSITORY = 'vitaliikaplia/simple-redis-cache';
	private const BRANCH = 'master';
	private const SLUG = 'simple-redis-cache';
	private const CACHE_KEY = 'simple_redis_cache_github_update_data';
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	private const FAILURE_TTL = HOUR_IN_SECONDS;

	private bool $remote_check_performed = false;

	/** @var array<string, int|string>|null */
	private ?array $remote_check_result = null;

	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins_transient' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'filter_update_plugins_transient' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_github_source_directory' ), 11, 4 );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cached_update_data' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	/**
	 * Never materialize an update transient that WordPress does not have.
	 *
	 * Returning a fresh stdClass for a missing transient turns core's `false` into a
	 * truthy value. delete_plugins() checks the raw value, so it would then take its
	 * "there is update data" branch and write back a transient with no last_checked.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function filter_update_plugins_transient( mixed $transient ): mixed {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$local_version = $transient->checked[ SIMPLE_REDIS_CACHE_BASENAME ] ?? SIMPLE_REDIS_CACHE_VERSION;
		$remote_data   = $this->get_remote_update_data( $this->should_force_check() );
		if ( null === $remote_data ) {
			return $transient;
		}

		if ( empty( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( empty( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$update = $this->build_update_response( $remote_data );
		if ( version_compare( $remote_data['version'], (string) $local_version, '>' ) ) {
			$transient->response[ SIMPLE_REDIS_CACHE_BASENAME ] = $update;
			unset( $transient->no_update[ SIMPLE_REDIS_CACHE_BASENAME ] );
		} else {
			$transient->no_update[ SIMPLE_REDIS_CACHE_BASENAME ] = $update;
			unset( $transient->response[ SIMPLE_REDIS_CACHE_BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Supply the standard WordPress plugin-details modal for this GitHub plugin.
	 *
	 * @param mixed $result
	 * @param mixed $args
	 * @return mixed
	 */
	public function filter_plugins_api( mixed $result, string $action, mixed $args ): mixed {
		if (
			'plugin_information' !== $action
			|| ! is_object( $args )
			|| empty( $args->slug )
			|| self::SLUG !== $args->slug
		) {
			return $result;
		}

		$remote_data = $this->get_remote_update_data( $this->should_force_check() );
		$version     = $remote_data['version'] ?? SIMPLE_REDIS_CACHE_VERSION;

		return (object) array(
			'name'          => 'Simple Redis Cache',
			'slug'          => self::SLUG,
			'version'       => $version,
			'author'        => 'Vitalii Kaplia',
			'homepage'      => $this->get_repository_url(),
			'requires'      => '6.5',
			'requires_php'  => '8.1',
			'download_link' => $remote_data['package'] ?? '',
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Redis-only persistent object caching and full-page HTML caching for a single-site WordPress installation.', 'simple-redis-cache' ) . '</p>',
				'changelog'   => '<p>' . esc_html__( 'Version 0.8.0 fixes the findings of a code review: changes lost during a Redis outage no longer come back afterwards, a stalled Redis is skipped for 15 seconds, the home page, archives, search and 404 pages are invalidated when content is published, menus, widgets, the theme and updates clear the page cache, 404 and search pages live at most 10 minutes, stored pages are compressed and signed, a copied site gets its own cache namespace, and updates are pinned to one commit.', 'simple-redis-cache' ) . '</p>',
			),
		);
	}

	/**
	 * GitHub commit archives unpack as simple-redis-cache-<commit SHA> (branch
	 * archives as simple-redis-cache-master). Rename the temporary source to the
	 * currently installed plugin directory so updates also preserve activation
	 * when the first install kept GitHub's ZIP suffix.
	 *
	 * @param mixed               $source
	 * @param mixed               $remote_source
	 * @param mixed               $upgrader
	 * @param array<string,mixed> $hook_extra
	 * @return mixed
	 */
	public function normalize_github_source_directory( mixed $source, mixed $remote_source, mixed $upgrader, array $hook_extra = array() ): mixed {
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( empty( $hook_extra['plugin'] ) || SIMPLE_REDIS_CACHE_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		$source_path         = untrailingslashit( (string) $source );
		$source_name         = basename( $source_path );
		$installed_directory = $this->get_installed_directory();
		if ( $installed_directory === $source_name ) {
			return trailingslashit( $source_path );
		}
		if ( self::SLUG !== $source_name && ! str_starts_with( $source_name, self::SLUG . '-' ) ) {
			return $source;
		}

		$target = trailingslashit( dirname( $source_path ) ) . $installed_directory;
		if ( $target === $source_path ) {
			return $source;
		}

		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		} elseif ( file_exists( $target ) || is_link( $target ) ) {
			$this->delete_directory( $target );
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source_path, $target, true ) ) {
			return trailingslashit( $target );
		}
		if ( @rename( $source_path, $target ) ) {
			return trailingslashit( $target );
		}

		return $source;
	}

	/** @param mixed $upgrader @param array<string,mixed> $hook_extra */
	public function clear_cache_after_update( mixed $upgrader, array $hook_extra ): void {
		if ( 'update' !== ( $hook_extra['action'] ?? '' ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$plugins = isset( $hook_extra['plugins'] )
			? (array) $hook_extra['plugins']
			: array( $hook_extra['plugin'] ?? '' );
		if ( in_array( SIMPLE_REDIS_CACHE_BASENAME, $plugins, true ) ) {
			$this->clear_cached_update_data();
		}
	}

	public function clear_cached_update_data(): void {
		delete_site_transient( self::CACHE_KEY );
		$this->remote_check_performed = false;
		$this->remote_check_result    = null;
	}

	/** @return array<string, int|string>|null */
	private function get_remote_update_data( bool $force = false ): ?array {
		if ( $this->remote_check_performed ) {
			return $this->remote_check_result;
		}

		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				if ( empty( $cached['version'] ) ) {
					return null;
				}
				// Data cached before the package was pinned to a commit, or damaged
				// data, is never offered: check again instead.
				if ( $this->is_pinned_update_data( $cached ) ) {
					return $cached;
				}
			}
		}

		$this->remote_check_performed = true;

		/*
		 * Resolve the branch to one commit first and read both the version and the
		 * package from that commit, so a push between the check and the download
		 * cannot make WordPress install code other than what the check saw.
		 */
		$sha = $this->fetch_branch_commit_sha();
		if ( null === $sha ) {
			$this->cache_failed_check();
			return null;
		}

		$plugin_file = $this->fetch_remote_body( $this->get_remote_plugin_file_url( $sha ), 'text/plain', 65536 );
		$version     = null !== $plugin_file ? $this->parse_plugin_version( $plugin_file ) : null;
		if ( null === $version ) {
			$this->cache_failed_check();
			return null;
		}

		$data = array(
			'version'      => $version,
			'sha'          => $sha,
			'package'      => $this->get_package_url( $sha ),
			'url'          => $this->get_repository_url(),
			'branch'       => self::BRANCH,
			'last_checked' => time(),
		);

		$this->remote_check_result = $data;
		set_site_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	private function fetch_branch_commit_sha(): ?string {
		$sha = $this->fetch_remote_body( $this->get_branch_commit_url(), 'application/vnd.github.sha', 1024 );

		return null !== $sha && $this->is_commit_sha( $sha ) ? $sha : null;
	}

	/** Body of a 2xx response, or null for a transport error or any other status. */
	private function fetch_remote_body( string $url, string $accept, int $max_bytes ): ?string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'limit_response_size' => $max_bytes,
				'headers'             => array(
					'Accept'     => $accept,
					'User-Agent' => 'Simple-Redis-Cache/' . SIMPLE_REDIS_CACHE_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	private function is_commit_sha( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{40}$/D', $value );
	}

	/** @param array<mixed> $data */
	private function is_pinned_update_data( array $data ): bool {
		return ! empty( $data['version'] )
			&& isset( $data['sha'], $data['package'] )
			&& $this->is_commit_sha( $data['sha'] )
			&& $this->get_package_url( $data['sha'] ) === $data['package'];
	}

	/** @param array<string, int|string> $remote_data */
	private function build_update_response( array $remote_data ): stdClass {
		return (object) array(
			'id'           => $this->get_repository_url(),
			'slug'         => self::SLUG,
			'plugin'       => SIMPLE_REDIS_CACHE_BASENAME,
			'new_version'  => $remote_data['version'],
			'url'          => $remote_data['url'],
			'package'      => $remote_data['package'],
			'requires'     => '6.5',
			'requires_php' => '8.1',
		);
	}

	private function parse_plugin_version( string $plugin_file_contents ): ?string {
		if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $plugin_file_contents, $matches ) ) {
			return null;
		}

		$version = trim( $matches[1] );
		if ( '' === $version || strlen( $version ) > 64 ) {
			return null;
		}

		return preg_match( '/^[0-9][0-9A-Za-z._+-]*$/D', $version ) ? $version : null;
	}

	private function should_force_check(): bool {
		$force_check = isset( $_GET['force-check'] )
			? sanitize_text_field( wp_unslash( $_GET['force-check'] ) )
			: '';

		return is_admin() && current_user_can( 'update_plugins' ) && '1' === $force_check;
	}

	private function cache_failed_check(): void {
		set_site_transient(
			self::CACHE_KEY,
			array(
				'version'      => '',
				'last_checked' => time(),
			),
			self::FAILURE_TTL
		);
	}

	private function delete_directory( string $directory ): void {
		if ( is_link( $directory ) || is_file( $directory ) ) {
			@unlink( $directory );
			return;
		}
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$items = scandir( $directory );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$this->delete_directory( $directory . DIRECTORY_SEPARATOR . $item );
		}
		@rmdir( $directory );
	}

	private function get_branch_commit_url(): string {
		return sprintf(
			'https://api.github.com/repos/%s/commits/%s',
			self::REPOSITORY,
			$this->get_url_branch()
		);
	}

	private function get_remote_plugin_file_url( string $sha ): string {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/simple-redis-cache.php',
			self::REPOSITORY,
			rawurlencode( $sha )
		);
	}

	private function get_package_url( string $sha ): string {
		return sprintf(
			'https://github.com/%s/archive/%s.zip',
			self::REPOSITORY,
			rawurlencode( $sha )
		);
	}

	private function get_repository_url(): string {
		return 'https://github.com/' . self::REPOSITORY;
	}

	private function get_installed_directory(): string {
		$directory = dirname( SIMPLE_REDIS_CACHE_BASENAME );
		if ( '.' === $directory || '' === $directory ) {
			return self::SLUG;
		}

		$directory = basename( str_replace( '\\', '/', $directory ) );
		return '' !== $directory && '.' !== $directory ? $directory : self::SLUG;
	}

	private function get_url_branch(): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', self::BRANCH ) ) );
	}
}
