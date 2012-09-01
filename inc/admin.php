<?php
! defined( 'ABSPATH' ) AND exit();



if ( ! class_exists( 'CD_APD_Admin' ) )
{

/**
 * Admin/Factory
 * 
 * @author     Christopher Davis, Franz Josef Kaiser
 * @license    GPL
 * @copyright  © Christopher Davis, Franz Josef Kaiser 2011-2012
 * 
 * @package    WordPress
 * @subpackage Additional Plugin Directories: Admin/Factory
 */
class CD_APD_Admin extends CD_APD_Core
{
	/**
	 * Instance
	 * 
	 * @since  0.3
	 * @access protected
	 * @var    object
	 */
	protected static $instance;


	/**
	 * The container for all of our custom plugins
	 * 
	 * @since  0.1
	 * @access protected
	 * @var    array
	 */
	protected $plugins = array();


	/**
	 * What custom actions are we allowed to handle here?
	 * 
	 * @since  0.1
	 * @access protected
	 * @var    array
	 */
	protected $actions = array();


	/**
	 * The original count of the plugins
	 * 
	 * @since  0.1
	 * @access protected
	 * @var    int
	 */
	protected $all_count = 0;


	/**
	 * Creates a new static instance
	 * 
	 * @since  0.3
	 * @static
	 * @return void
	 */
	public static function instance()
	{
		null === self :: $instance AND self :: $instance = new self;
		return self :: $instance;
	}


	/**
	 * Constructor
	 * 
	 * @since  0.1
	 * @return void
	 */
	public function __construct()
	{
		add_action( 'plugins_loaded', array( $this, 'setup_actions' ), 2 );
		add_action( 'load-plugins.php', array( $this, 'init' ) );

		add_action( 'admin_head-plugins.php', array( $this, 'style' ) );

		# add_filter( 'pre_option_active_plugins', array( $this, 'option_active_plugins' ), 20 );

		parent :: __construct();
	}


	public function style()
	{
		?>
		<style type="text/css">
		.subsubsub {
			width: 100%;
		}
		.subsubsub li {
			float: left;
		}
		</style>
		<?php
	}


	/**
	 * Intercept the 'active_plugins' option and show our plugins as "Active"
	 * 
	 * @since  1.2
	 * @param  bool false
	 * @return mixed bool/array $value false by default, array of custom plugins else
	 */
	public function option_active_plugins( $value )
	{
		global $wp_plugin_directories;

		$dir = $this->get_plugin_status();

		// Abort if not in custom directory
		if ( ! $dir )
			return $value;

		if ( $value = get_option( "active_plugins_{$dir}" ) )
			return serialize( $value );

		return $value;
	}


	/**
	 * Sets up which actions we can handle with this plugin. We'll use this
	 * to catch activations and deactivations as the normal way won't work.
	 * Has the filter 'custom_plugin_actions' to allow extensions.
	 * 
	 * @since  0.1
	 * @return void
	 */
	public function setup_actions()
	{
		$this->actions = apply_filters( 
			'custom_plugin_actions',
			array(
				'custom_activate',
				'custom_deactivate'
			)
		);
	}


	/**
	 * Makes the magic happen. Loads all the other hooks to modify the plugin list table
	 * 
	 * @since  0.1
	 * @return void
	 */
	public function init()
	{
		global $wp_plugin_directories;

		$screen = get_current_screen();

		$this->get_plugins();

		add_filter( "views_{$screen->id}", array( $this, 'views' ), 100 );

		// check to see if we're using one of our custom directories
		if ( $this->get_plugin_status() )
		{
			$this->handle_actions();

			add_filter( "views_{$screen->id}", array( $this, 'views_hide' ), 110 );

			// Disable default "advanced" plugins. Inside the callback, 
			// the 2nd arg is either for "Must Use" => 'mustuse' and for "DropIns" => 'dropins'.
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
	 * Adds our custom plugin directories to the list of plugin types
	 *
	 * @since  0.1
	 * @param  array $views
	 * @return array $views
	 */
	public function views( $views )
	{
		global $wp_plugin_directories, $totals;

		// bail if we don't have any extra dirs
		if ( empty( $wp_plugin_directories ) ) 
			return $views;

		// Add our directories to the action links
		foreach ( $wp_plugin_directories as $key => $info )
		{
			$count = count( $this->plugins[ $key ] );

			if ( ! $count ) 
				continue;

			$views[ $key ] = sprintf( 
				 '<a href="%s"%s>%s <span class="count">(%d)</span></a>'
				,add_query_arg( 'plugin_status', $key, 'plugins.php' )
				,$this->get_plugin_status() == $key ? ' class="current" ' : ''
				,esc_html( $info['label'] )
				,$count
			);
		}

		return $views;
	}


	/**
	 * Unset no needed/currently possible views
	 * 
	 * @since  0.1
	 * 
	 * @param  array $views
	 * @return array $views
	 */
	public function views_hide( $views )
	{
		if ( $this->get_plugin_status() )
			unset( $views['inactive'] );

		return $views;
	}


	/**
	 * Filters the plugins list to include all the plugins in our custom directory
	 * 
	 * @since  0.1
	 * @param  array $plugins
	 * @return array $plugins
	 */
	public function filter_plugins( $plugins )
	{
		$key = $this->get_plugin_status();
		if ( $key )
		{
			$this->all_count = count( $plugins );
			$plugins = $this->plugins[ $key ];
		}

		return $plugins;
	}


	/**
	 * Correct some action links so we can actually "activate" plugins
	 * 
	 * @since  0.1
	 * @param  array $links
	 * @param  string $file
	 * @param  array $data
	 * @param  string $context
	 * @return array  $links
	 */
	public function action_links( $links, $file, $data, $context )
	{
		$context = $this->get_plugin_status();

		$active = get_option( "active_plugins_{$context}", array() );

		// let's just start over
		$links = array();
		if ( ! in_array( $file, $active ) )
		{
			$links['activate'] = sprintf(
				 '<a href="%s" title="Activate this plugin">%s</a>'
				,wp_nonce_url( 
					 "plugins.php?action=custom_activate&amp;plugin={$file}&amp;plugin_status=".esc_attr( $context ) 
					,"custom_activate-{$file}" 
				 )
				,__( 'Activate' )
			);
		}

		if ( in_array( $file, $active ) )
		{
			$links['deactivate'] = sprintf(
				 '<a href="%s" title="Deactivate this plugin" class="cd-apd-deactivate">%s</a>'
				,wp_nonce_url(
					 "plugins.php?action=custom_deactivate&amp;plugin={$file}&amp;plugin_status=".esc_attr( $context ) 
					,"custom_deactivate-{$file}" 
				 )
				,__( 'Deactivate' )
			);
		}

		return $links;
	}


	/**
	 * Enqueues on JS file for fun hacks.
	 * 
	 * @uses filemtime() to set the version number of files 
	 * to their last changed date to prevent caching.
	 * 
	 * @since  0.1
	 * @uses   wp_enqueue_script()
	 * @return void
	 */
	public function scripts( $screen )
	{
		if ( 'plugins.php' !== $screen )
			return;

		wp_enqueue_script(
			 'cd-apd-js'
			,$this->scripts_file_cb( 'url' )."apd.js"
			,array( 'jquery' )
			,filemtime( $this->scripts_file_cb( 'path' )."apd.js" )
		);
		wp_localize_script(
			 'cd-apd-js'
			,'cd_apd'
			,array(
				'count' => esc_js( $this->all_count )
			 )
		);
	}


	/**
	 * Callback to get the Path or URl to register scripts.
	 * 
	 * @since  0.7.3
	 * @param  string $case (Valid are:) 'path', 'url'
	 * @param  string $sub_dir Defaults to: 'js'
	 * @return string
	 */
	public function scripts_file_cb( $case, $sub_dir = 'js' )
	{
		$root = 'path' === $case ? plugin_dir_path( __FILE__ ) : plugin_dir_url( __FILE__ );

		return substr_replace( 
			 $root
			,$sub_dir
			,strrpos( $root, basename( $root ) )
			,strlen( basename( $root ) ) 
		);
	}


	/**
	 * Fetch all the custom plugins we have!
	 * 
	 * @since  0.1
	 * @uses   get_plugins_from_cache() To fetch all the custom plugins
	 * @return void
	 */
	public function get_plugins()
	{
		global $wp_plugin_directories;

		empty( $wp_plugin_directories ) AND $wp_plugin_directories = array();

		foreach ( array_keys( $wp_plugin_directories ) as $key )
		{
			$this->plugins[ $key ] = $this->get_plugins_from_cache( $key	 );
		}
	}


	/**
	 * Handle activations and deactivations as the standard way will
	 * fail with "plugin file does not exist
	 *
	 * @since  0.1
	 * @return void
	 */
	public function handle_actions()
	{
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';

		$context = str_replace( ' ', '%20', $this->get_plugin_status() );

		// not allowed to handle this action? bail.
		if ( ! in_array( $action, $this->actions ) )
			return;

		// Get the plugin we're going to activate
		$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : false;

		if ( ! $plugin )
			return;

		switch( $action )
		{
			case 'custom_activate':
				if ( ! current_user_can('activate_plugins') )
					wp_die( __( 'You do not have sufficient permissions to manage plugins for this site.' ) );

				check_admin_referer( "custom_activate-{$plugin}" );

				$result = $this->activate_plugin( $plugin, $context );
				if ( is_wp_error( $result ) ) 
				{
					if ( 'unexpected_output' == $result->get_error_code() ) 
					{
						wp_redirect( add_query_arg( 
							'_error_nonce',
							wp_create_nonce( "plugin-activation-error_{$plugin}" ),
							add_query_arg( 
								 'plugin_status'
								,$context
								,self_admin_url( 'plugins.php' ) 
							) 
						) );
						exit;
					}
					else 
					{
						wp_die( $result );
					}
				}

				wp_redirect( add_query_arg( 
					 array( 
					 	 'plugin_status' => $context
					 	,'activate'      => 'true' 
					 )
					,self_admin_url( 'plugins.php' )
				) );
				exit;
				break;

			case 'custom_deactivate':
				if ( ! current_user_can( 'activate_plugins' ) )
					wp_die( __( 'You do not have sufficient permissions to deactivate plugins for this site.' ) );

				check_admin_referer( "custom_deactivate-{$plugin}" );

				$this->deactivate_plugins( $plugin, $context );

				if ( headers_sent() )
				{
					printf( 
						 "<meta http-equiv='refresh' content='%s' />"
						,esc_attr( "0;url=plugins.php?deactivate=true&plugin_status={$status}&paged={$page}&s={$s}" )
					);
				}
				else
				{
					wp_redirect( self_admin_url( "plugins.php?deactivate=true&plugin_status={$context}" ) );
					exit;
				}
				break;

			default :
				do_action( "custom_plugin_dir_{$action}" );
				break;
		}
	}


	/**
	 * Utility function to get the current `plugin_status`. 
	 * The key returns FALSE if our key isn't in the the custom directories
	 * 
	 * @since  0.1
	 * @return mixed bool|string $rv false on failure, the `$wp_plugin_directories` key on success
	 */
	public function get_plugin_status()
	{
		global $wp_plugin_directories;

		$rv = false;

		if ( ! isset( $wp_plugin_directories ) )
			return $rv;

		if ( 
			isset( $_GET['plugin_status'] )
			AND in_array( $_GET['plugin_status'], array_keys( $wp_plugin_directories ) ) 
			)
		{
			$rv = $_GET['plugin_status'];
		}

		return $rv;
	}
} // END Class CD_APD_Admin

} // endif;
