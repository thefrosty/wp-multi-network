<?php
/**
 * WP_MS_Network_Plugins class
 *
 * @package WPMN
 * @since 2.7.0
 */

declare(strict_types=1);

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class used to show what plugins are active on which blog sites.
 *
 * @since 2.7.0
 */
class WP_MS_Network_Plugins {

	public const ALLOWED_PLUGIN_STATUS = array( 'all', 'active', 'inactive' );
	public const COLUMN                = 'active_sites';
	public const TRANSIENT             = 'blogs_plugins';
	public const WP_KSES_ALLOWED_HTML  = array(
		'br'   => array(),
		'span' => array(
			'class' => array(),
		),
		'ul'   => array(
			'class' => array(),
			'id'    => array(),
			'style' => array(),
		),
		'li'   => array(
			'class' => array(),
			'title' => array(),
		),
		'a'    => array(
			'href'    => array(),
			'onclick' => array(),
			'title'   => array(),
		),
		'p'    => array(
			'data-toggle-id' => array(),
			'onclick'        => array(),
			'style'          => array(),
		),
	);
	/**
	 * Value to get sites in the Network.
	 *
	 * @var int $sites_limit
	 */
	private int $sites_limit = 10000;
	/**
	 * Member variable to store data about active plugins for each blog.
	 *
	 * @var array<int, array<string, mixed>> $blogs_plugins
	 */
	private array $blogs_plugins;

	/**
	 * WP_MS_Network_Plugins constructor.
	 */
	public function __construct() {
		$this->sites_limit = (int) apply_filters( 'wp_multi_network_sites_limit', $this->sites_limit );
	}

	/**
	 * Clears the site transient.
	 */
	public static function delete_site_transient(): void {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Initialize the class.
	 */
	public function add_hooks(): void {
		add_action( 'activated_plugin', array( $this, 'activated_deactivated' ) );
		add_action( 'deactivated_plugin', array( $this, 'activated_deactivated' ) );
		add_action( 'load-plugins.php', function (): void {
			if ( ! $this->is_debug() ) {
				return;
			}

			self::delete_site_transient();
			add_action( 'network_admin_notices', array( $this, 'notice_about_clear_cache' ) );
		} );
		add_filter( 'manage_plugins-network_columns', array( $this, 'add_plugins_column' ) );
		add_action( 'manage_plugins_custom_column', array( $this, 'manage_plugins_custom_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	/**
	 * On plugin Activation/Deactivation, force refresh the cache.
	 */
	public function activated_deactivated(): void {
		add_action( 'shutdown', function (): void {
			$this->get_sites_plugins( true );
		});
	}

	/**
	 * Print Network Admin Notices to inform, that the transient are deleted.
	 */
	public function notice_about_clear_cache(): void {
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html__(
				'WP Multi Network: plugin usage information is not cached while `WP_DEBUG` is true.',
				'wp-multi-network'
			)
		);
	}

	/**
	 * Add in a column header.
	 *
	 * @param  array<string, string> $columns An array of displayed site columns.
	 * @return array<string, string>
	 */
	public function add_plugins_column( array $columns ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['plugin_status'] ) ) {
			$status = esc_attr( wp_unslash( sanitize_key( $_GET['plugin_status'] ) ) );
		}

		if ( ! isset( $_GET['plugin_status'] ) || ( isset( $status ) && in_array( $status, self::ALLOWED_PLUGIN_STATUS, true ) ) ) {
			$columns[ self::COLUMN ] = _x( 'Usage', 'column name', 'wp-multi-network' );
		} // phpcs:enable

		return $columns;
	}

	/**
	 * Get data for each row on each plugin.
	 *
	 * @param string $column_name Name of the column.
	 * @param string $plugin_file Path to the plugin file.
	 */
	public function manage_plugins_custom_column( string $column_name, string $plugin_file ): void {
		if ( self::COLUMN !== $column_name ) {
			return;
		}

		$output = '';
		if ( is_plugin_active_for_network( $plugin_file ) ) {
			$output .= sprintf(
				'<span style="white-space:nowrap">%s</span>',
				esc_html__( 'Network Active', 'wp-multi-network' )
			);
		} else {
			// Is this plugin active on any sites in this network.
			$active_on_blogs = $this->is_plugin_active_on_sites( $plugin_file );
			if ( empty( $active_on_blogs ) ) {
				$output .= sprintf(
					'<span style="white-space:nowrap">%s</span>',
					esc_html__( 'Not Active', 'wp-multi-network' )
				);
			} else {
				$active_count = count( $active_on_blogs );

				$output .= sprintf(
					'<p data-toggle-id="siteslist_%2$s" onclick="toggleSiteList(this);" style="cursor: pointer"><span class="dashicons dashicons-arrow-right">&nbsp;</span>%1$s</p>',
					sprintf(
						// Translators: The placeholder will be replaced by the count of sites there use that plugin.
						_n( 'Active on %d site', 'Active on %d sites', $active_count, 'wp-multi-network' ),
						$active_count
					),
					esc_attr( $plugin_file )
				);
				$output .= sprintf(
					'<ul id="siteslist_%s" class="siteslist" style="display: %s">',
					esc_attr( $plugin_file ),
					$active_count >= 4 ? 'none' : 'block'
				);
				foreach ( $active_on_blogs as $site_id => $site ) {
					if ( $this->is_archived( $site_id ) ) {
						$class = 'site-archived';
						$hint  = ', ' . __( 'Archived', 'wp-multi-network' );
					}
					if ( $this->is_deleted( $site_id ) ) {
						$class = 'site-deleted';
						$hint  = ', ' . __( 'Deleted', 'wp-multi-network' );
					}
					$output .= sprintf(
						'<li class="%1$s" title="Blog ID: %2$s"><span class="non-breaking"><a href="%3$s">%4$s%5$s</a></span></li>',
						sanitize_html_class( $class ?? '' ),
						esc_attr( (string) $site_id ),
						esc_url( get_admin_url( $site_id, 'plugins.php' ) ),
						esc_html( $site['name'] ),
						esc_html( $hint ?? '' )
					);
				}
				$output .= '</ul>';
			}
		}

		echo wp_kses( $output, self::WP_KSES_ALLOWED_HTML );
	}

	/**
	 * Enqueue our script(s).
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function admin_enqueue_scripts( string $hook_suffix ): void {
		if ( 'plugins.php' !== $hook_suffix ) {
			return;
		}

		wp_register_script( 'wp-multi-network', '', args: array( 'in_footer' => true ) ); // phpcs:ignore
		wp_enqueue_script( 'wp-multi-network' );
		$script = <<<'SCRIPT'
document.addEventListener( 'DOMContentLoaded', function() {
  const sitesLists = document.querySelectorAll(`[id^="siteslist_"]`);
  if (sitesLists) {
    sitesLists.forEach(sitesList => {
      sitesList.style.display = 'none';
    })
  }
});
function toggleSiteList(plugin) {
  const id = plugin.getAttribute('data-toggle-id');
  if (!id) return;
  const element = document.getElementById(id);
  if (!element) return;
  const child = plugin.firstElementChild;
  if (child.classList.contains('dashicons-arrow-right')) {
    child.classList.remove('dashicons-arrow-right');
    child.classList.add('dashicons-arrow-down');
  } else {
    child.classList.remove('dashicons-arrow-down');
    child.classList.add('dashicons-arrow-right');
  }
  if (element.style.display === 'none') {
    element.style.display = 'block';
  } else {
    element.style.display = 'none';
  }
}
SCRIPT;
		wp_add_inline_script( 'wp-multi-network', $script );
	}

	/**
	 * Is plugin active in site(s).
	 *
	 * @param string $plugin_file The plugin file.
	 * @return array<int, array<string, string>>
	 */
	protected function is_plugin_active_on_sites( string $plugin_file ): array {
		$blogs_plugins_data = $this->get_sites_plugins();
		$active_in_plugins  = array();
		foreach ( $blogs_plugins_data as $blog_id => $data ) {
			if ( ! in_array( $plugin_file, $data['active_plugins'], true ) ) {
				continue;
			}
			$active_in_plugins[ $blog_id ] = array(
				'name' => $data['blogname'],
				'path' => $data['path'],
			);
		}

		return $active_in_plugins;
	}

	/**
	 * Gets an array of site data including active plugins.
	 *
	 * @param bool $force Force re-cache.
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_sites_plugins( bool $force = false ): array {
		if ( ! empty( $this->blogs_plugins ) ) {
			return $this->blogs_plugins;
		}

		$blogs_plugins = get_site_transient( self::TRANSIENT );
		if ( $force || false === $blogs_plugins ) {
			$blogs_plugins = array();

			$blogs = get_sites( array( 'number' => $this->sites_limit ) );

			/**
			 * Data to each site of the network, blogs.
			 *
			 * @var WP_Site $blog
			 */
			foreach ( $blogs as $blog ) {
				$blog_id = (int) $blog->blog_id;

				$blogs_plugins[ $blog_id ] = $blog->to_array();
				// Add dynamic properties.
				$blogs_plugins[ $blog_id ]['blogname']       = $blog->blogname;
				$blogs_plugins[ $blog_id ]['active_plugins'] = array();
				// Get active plugins.
				$plugins = get_blog_option( $blog_id, 'active_plugins' );
				if ( $plugins ) {
					foreach ( $plugins as $plugin_file ) {
						$blogs_plugins[ $blog_id ]['active_plugins'][] = $plugin_file;
					}
				}
			}

			if ( ! $this->is_debug() ) {
				if ( $force ) {
					self::delete_site_transient();
				}
				set_site_transient( self::TRANSIENT, $blogs_plugins, WEEK_IN_SECONDS );
			}
			$this->blogs_plugins = $blogs_plugins;
		}

		// Data should be here, if loaded from transient or DB.
		return $this->blogs_plugins;
	}

	/**
	 * Check, if the status of the site archived.
	 *
	 * @param int|string $site_id ID of the site.
	 *
	 * @return bool
	 */
	private function is_archived( int|string $site_id ): bool {
		return (bool) get_blog_details( (int) $site_id )->archived;
	}

	/**
	 * Check, if the status of the site deleted.
	 *
	 * @param int|string $site_id ID of the site.
	 * @return bool
	 */
	private function is_deleted( int|string $site_id ): bool {
		return (bool) get_blog_details( (int) $site_id )->deleted;
	}

	/**
	 * Is WP_DEBUG enabled or are we filtering debug?
	 */
	private function is_debug(): bool {
		return apply_filters( 'wp_multi_network_debug', (
			defined( 'WP_DEBUG' ) &&
			filter_var( constant( 'WP_DEBUG' ), FILTER_VALIDATE_BOOLEAN ) === true
		) ) === true;
	}
}
