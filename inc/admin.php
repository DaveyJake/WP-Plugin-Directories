<?php
/**
 * Additional Plugin Directories: Admin Area
 *
 * @author Davey Jacobson, Christopher Davis, Franz Josef Kaiser
 *
 * @package Davey_Jacobson
 * @subpackage Additional_Plugin_Directory_Admin
 */

defined( 'ABSPATH' ) || exit();

class DJ_APD_Admin extends DJ_APD_Core {
	/**
	 * Instance
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @var object
	 */
	protected static $instance;

	/**
	 * The container for all of our custom plugins
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @var array
	 */
	protected $plugins = array();

	/**
	 * What custom actions are we allowed to handle here?
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @var array
	 */
	protected $actions = array();

	/**
	 * The original count of the plugins
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @var int
	 */
	protected $all_count = 0;

	/**
	 * Creates a new static instance
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return DJ_APD_Admin
	 */
	public static function init() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->setup_actions();

		add_action( 'load-plugins.php', array( $this, 'setup' ) );

		add_action( 'admin_head-plugins.php', array( $this, 'style' ) );

		add_filter( 'option_active_plugins', array( $this, 'option_active_plugins' ), 20, 2 );

		parent::__construct();
	}

	/**
	 * Sets up which actions we can handle with this plugin. We'll use this
	 * to catch activations && deactivations as the normal way won't work.
	 * Has the filter 'custom_plugin_actions' to allow extensions.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function setup_actions() {
		$this->actions = apply_filters(
			'custom_plugin_actions',
			array(
				'custom_activate',
				'custom_deactivate',
			 )
		);
	}

	/**
	 * Makes the magic happen. Loads all the other hooks to modify the plugin list table.
	 *
	 * @since 1.0.0
	 */
	public function setup() {
		global $wp_plugin_directories;

		$screen = get_current_screen();

		$this->get_plugins();

		add_filter( "views_{$screen->id}", array( $this, 'views' ), 100 );

		// Add each directory as Sub Menu item
		add_action( 'load-plugins.php', array( $this, 'add_submenu_items' ), 20 );

		// check to see if we're using one of our custom directories
		if ( $this->get_plugin_status() ) {
			$this->handle_actions();

			add_filter( "views_{$screen->id}", array( $this, 'views_hide' ), 110 );

			// Disable default "advanced" plugins. Inside the callback, the 2nd
			// arg is either for "Must Use" => 'mustuse' && for "DropIns" => 'dropins'.
			#add_filter( 'show_advanced_plugins', '__return_false', 10, 2 );

			// Include plugins from custom Dir in list
			add_filter( 'all_plugins', array( $this, 'filter_plugins' ) );

			// @TODO: support bulk actions
			add_filter( "bulk_actions-{$screen->id}", '__return_empty_array' );

			// Activate/Deactivate links
			add_filter( 'plugin_action_links', array( $this, 'action_links' ), 10, 4 );
			#add_filter( 'network_admin_plugin_action_links', array( $this, 'action_links' ), 10, 4 );

			// Custom UI stuff
			add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
		}
	}

	/**
	 * Styles to allow the display of multiple rows of plugin dirs
	 *
	 * @since 1.0.0
	 */
	public function style() {
		echo '<style type="text/css"> .subsubsub { width: 100%; } .subsubsub li { float: left; } </style>';
	}

    /**
     * Filters the value of an existing option via {@see 'option_{$option}'}.
     *
     * The dynamic portion of the hook name, `$option`, refers to the option name.
     *
     * @since 1.0.0
     *
     * @link https://developer.wordpress.org/reference/hooks/option_option/
     *
     * @global array $wp_plugin_directories All known plugin directories.
     *
     * @param mixed  $value  Value of the option. If stored serialized, it will be
     *                       unserialized prior to being returned.
     * @param string $option Option name.
     *
     * @return array Option value.
     */
	public function option_active_plugins( $value, $option ) {
		global $wp_plugin_directories;

		$dir = $this->get_plugin_status();

		// Abort if not in custom directory.
		if ( ! $dir ) {
			return $value;
		}

		$context = $_REQUEST['plugin_status'];

		if ( "active_plugins_{$context}" === $option ) {
			$default_active_plugins = get_option( 'active_plugins', array() );

			$value = array_merge( $default_active_plugins, $value );
		}

		return $value;
	}

	/**
	 * Adds our custom plugin directories to the list of plugin types.
	 *
	 * @since 1.0.0
	 *
	 * @param array $views Associative array of all indexed directory views.
	 *
	 * @return array Views indexed by their custom key.
	 */
	public function views( $views ) {
		global $wp_plugin_directories, $totals;

		// Bail if we don't have any extra dirs.
		if ( empty( $wp_plugin_directories ) ) {
			return $views;
		}

		// Add our directories to the action links.
		foreach ( $wp_plugin_directories as $key => $info ) {
			$count = count( $this->plugins[ $key ] );

			if ( ! $count ) {
				continue;
			}

			$views[ $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',

				add_query_arg( 'plugin_status', $key, 'plugins.php' ),

				$this->get_plugin_status() == $key ? ' class="current" ' : '',

				esc_html( $info['label'] ),

				$count
			);
		}

		return $views;
	}

	/**
	 * Unset no needed/currently possible views.
	 *
	 * @since 1.0.0
	 *
	 * @param array $views
	 *
	 * @return array
	 */
	public function views_hide( $views ) {
		if ( $this->get_plugin_status() ) {
			unset( $views['inactive'] );
		}

		return $views;
	}

	/**
	 * Filters the plugins list to include all the plugins in our custom directory.
	 *
	 * @since 1.0.0
	 *
	 * @param array $plugins All known plugins.
	 *
	 * @return array
	 */
	public function filter_plugins( $plugins ) {
		if ( ! $key = $this->get_plugin_status() ) {
			return $plugins;
		}

		$this->all_count = count( $plugins );

		return $this->plugins[ $key ];
	}

	/**
	 * Correct some action links so we can actually "activate" plugins via {@see 'plugin_action_links'}.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $links
	 * @param string $file
	 * @param array  $data
	 * @param string $context
	 *
	 * @return array All known action links.
	 */
	public function action_links( $links, $file, $data, $context ) {
		$context = $this->get_plugin_status();

		$active = get_option( "active_plugins_{$context}", array() );

		$url_defaults = array(
			'action'        => '',
			'plugin'        => $file,
			'plugin_status' => esc_attr( $context ),
		);

		// Let's just start over.
		$links = array();

		if ( ! in_array( $file, $active ) ) {
			$url_defaults['action'] = 'custom_activate';

			$plugin_activation_url = add_query_arg( $url_defaults, 'plugins.php' );

			$links['activate'] = sprintf(
				'<a href="%s" title="Activate this plugin">%s</a>',
				wp_nonce_url( $plugin_activation_url, "custom_activate-{$file}" ),
				__( 'Activate' )
			);
		} else {
			$url_defaults['action'] = 'custom_deactivate';

			$plugin_deactivation_url = add_query_arg( $url_defaults, 'plugins.php' );

			$links['deactivate'] = sprintf(
				'<a href="%s" title="Deactivate this plugin" class="dj-apd-deactivate">%s</a>',
				wp_nonce_url( $plugin_deactivation_url, "custom_deactivate-{$file}" ),
				__( 'Deactivate' )
			);
		}

		return $links;
	}

	/**
	 * Enqueues on JS file for fun hacks.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Screen|object $screen Current screen being viewed.
	 */
	public function scripts( $screen ) {
		if ( 'plugins.php' !== $screen ) {
			return;
		}

		$script_url  = $this->scripts_file_cb( 'url' ) . 'apd.js';
		$script_path = $this->scripts_file_cb( 'path' ) . 'apd.js';

		wp_enqueue_script( 'dj-apd-js', $script_url, array( 'jquery' ), filemtime( $script_path ), true );
		wp_localize_script( 'dj-apd-js', 'dj_apd', array( 'count' => esc_js( $this->all_count ) ) );
	}

	/**
	 * Callback to get the Path or URl to register scripts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $case    Accepts 'path', 'url'.
	 * @param string $sub_dir Defaults to: 'js'.
	 *
	 * @return string
	 */
	public function scripts_file_cb( $case, $sub_dir = 'js' ) {
		$root = 'path' === $case ? plugin_dir_path( __FILE__ ) : plugin_dir_url( __FILE__ );

		return substr_replace( $root, $sub_dir, strrpos( $root, basename( $root ) ), strlen( basename( $root ) ) );
	}

	/**
	 * Fetch all the custom plugins we have!
	 *
	 * @since 1.0.0
	 *
	 * @global array $wp_plugin_directories All known plugin directories.
	 */
	public function get_plugins() {
		global $wp_plugin_directories;

		empty( $wp_plugin_directories ) && $wp_plugin_directories = array();

		foreach ( array_keys( $wp_plugin_directories ) as $key ) {
			$this->plugins[ $key ] = $this->get_plugins_from_cache( $key );
		}
	}

	/**
	 * Adds submenu items to the plugins menu
	 *
	 * @since  1.3
	 * @return void
	 */
	public function add_submenu_items() {
		global $wp_plugin_directories, $pagenow;

		// Fill/Fix the global that adds the "current" class to a submenu item.
		$GLOBALS['submenu_file'] = "{$pagenow}?plugin_status={$this->get_plugin_status()}";

		// Add submenu pages
		foreach ( $wp_plugin_directories as $dir => $data )
		{
			add_plugins_page(
				$data['label'],
				$data['label'],
				'activate_plugins',
				"plugins.php?plugin_status={$dir}"
			);
		}
	}


	/**
	 * Handle activations & deactivations as the standard way will fail with
	 * "plugin file does not exist".
	 *
	 * @since 1.0.0
	 */
	public function handle_actions() {
		$action  = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
		$context = $this->get_plugin_status();

		// Not allowed to handle this action? Bail.
		if ( ! in_array( $action, $this->actions ) ) {
			return;
		}

		// Get the plugin we're going to activate.
		$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : false;

		if ( ! $plugin ) {
			return;
		}

		switch( $action ) {
			case 'custom_activate':
				if ( ! current_user_can('activate_plugins') ) {
					wp_die( __( 'You do not have sufficient permissions to manage plugins for this site.' ) );
				}

				check_admin_referer( "custom_activate-{$plugin}" );

				$result = $this->activate_plugin( $plugin, $context );

				if ( is_wp_error( $result ) ) {
					if ( 'unexpected_output' == $result->get_error_code() ) {
						wp_redirect(
							add_query_arg(
								'_error_nonce',
								wp_create_nonce( "plugin-activation-error_{$plugin}" ),
								add_query_arg( 'plugin_status', $context, self_admin_url( 'plugins.php' ) )
							)
						);
						exit;
					} else {
						wp_die( $result );
					}
				}

				wp_redirect(
					add_query_arg(
						array(
							'plugin_status' => $context,
							'activate'      => 'true',
						),
						self_admin_url( 'plugins.php' )
					)
				);
				exit;
				break;
			case 'custom_deactivate':
				if ( ! current_user_can( 'activate_plugins' ) ) {
					wp_die( __( 'You do not have sufficient permissions to deactivate plugins for this site.' ) );
				}

				check_admin_referer( "custom_deactivate-{$plugin}" );

				$this->deactivate_plugins( $plugin, $context );

				if ( headers_sent() ) {
					$url = add_query_arg(
						array(
							'deactivate'    => 'true',
							'plugin_status' => $context,
							'paged'         => $page,
							's'             => $s,
						),
						'plugins.php'
					);

					printf( "<meta http-equiv='refresh' content='%s' />", esc_attr( "0;url={$url}" ) );
				} else {
					$url = add_query_arg(
						array(
							'deactivate'    => 'true',
							'plugin_status' => $context,
						),
						'plugins.php'
					);

					wp_redirect( self_admin_url( $url ) );
					exit;
				}
				break;
			default:
				do_action( "custom_plugin_dir_{$action}" );
				break;
		}
	}


	/**
	 * Utility function to get the current `plugin_status`.
	 *
	 * The key returns FALSE if our key isn't in the the custom directories
	 *
	 * @since 1.0.0
	 *
	 * @global array $wp_plugin_directories All known plugin directories.
	 *
	 * @return mixed bool|string $rv false on failure, the `$wp_plugin_directories` key on success
	 */
	public function get_plugin_status() {
		global $wp_plugin_directories;

		$rv = false;

		if ( ! isset( $wp_plugin_directories ) ) {
			return $rv;
		}

		if (
			isset( $_GET['plugin_status'] )
			&& in_array( $_GET['plugin_status'], array_keys( $wp_plugin_directories ) )
		) {
			$rv = $_GET['plugin_status'];
		}

		return $rv;
	}
} // END Class DJ_APD_Admin
