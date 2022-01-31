<?php
/*
Plugin Name: Additional Plugin Directories 3
Plugin URI: http://github.com/DaveyJake
Description: A framework to allow adding additional plugin directories to WordPress.
Version: 1.0.0
Author: Davey Jacobson
Author URI: https://daveyjake.dev
Contributors: Christopher Davis, Franz Josef Kaiser, Julien Chaumond
License: GNU GPL 2
*/

/**
 * Bootstrap for additional plugin directories.
 *
 * @package Davey_Jacobson
 * @subpackage Additional_Plugin_Directories_Bootstrap
 */
class DJ_APD_Bootstrap {
	/**
	 * Instance
	 *
	 * @access protected
	 * @var object
	 */
	static protected $instance;

	/**
	 * The files that need to get included
	 *
	 * @since     0.8
	 * @access    public
	 * @static
	 * @var array string Class Name w/o prefix (Hint: Naming convention!)
	 *                   Use the value to define, if the the class should get hooked.
	 */
	static public $includes = array(
		'api'   => false,
		'core'  => false,
		'admin' => true,
	);

	/**
	 * Used for update notices
	 * Fetches the readme file from the official plugin repo trunk.
	 * Adds to the "in_plugin_update_message-$file" hook
	 *
	 * @var string
	 */
	public $remote_changelog = 'https://raw.github.com/chrisguitarguy/WP-Plugin-Directories/master/changelog.html';

	/**
	 * Creates a new static instance.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return DJ_APD_Bootstrap
	 */
	static public function init() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wp_plugin_directories;
		$wp_plugin_directories = array();

		// Localize.
		load_theme_textdomain( 'dj_apd_textdomain', plugin_dir_path( __FILE__ ) . 'lang' );

		// Load at the end of /wp-admin/admin.php.
		foreach ( self::$includes as $inc => $init ) {
			// Load file: trailingslashed by core
			# Tested: calling plugin_dir_path() directly saves 1/2 time
			# instead of saving the plugin_dir_path() in a $var and recalling here
			require_once plugin_dir_path( __FILE__ ) . "inc/{$inc}.php";

			if ( ! $init ) {
				continue;
			}

			// Build class name
			$class = 'DJ_APD_' . ucwords( $inc );

			class_exists( $class ) AND $class::init();
		}

		// Updates from GitHub
		// $ git submodule add git://github.com/franz-josef-kaiser/WordPress-GitHub-Plugin-Updater inc/updater
		# add_action( 'load-plugins.php', array( $this, 'update_from_github' ), 0 );
		# add_action( 'load-plugin_install.php', array( $this, 'update_from_github' ), 0 );
	}

	/**
	 * Update from Github.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function update_from_github() {
		global $wp_version;

		// Load the updater.
		include_once plugin_dir_path( __FILE__ ).'inc/updater/updater.php';

		// Fix this strange WP bug(?).
		add_action( 'http_request_args', array( $this, 'update_request_args' ), 0, 2 );

		$host = 'github.com';
		$http = 'https://';
		$name = 'chrisguitarguy';
		$repo = 'WP-Plugin-Directories';

		new wp_github_updater(
			array(
				'slug'               => plugin_basename( __FILE__ ),
				'proper_folder_name' => dirname( plugin_basename(__FILE__) ),
				'api_url'            => "{$http}api.{$host}/repos/{$name}/{$repo}",
				'raw_url'            => "{$http}raw.{$host}/{$name}/{$repo}/master",
				'github_url'         => "{$http}{$host}/{$name}/{$repo}",
				'zip_url'            => "{$http}{$host}/{$name}/{$repo}/zipball/master",
				'sslverify'          => true,
				'requires'           => $wp_version,
				'tested'             => $wp_version,
				'readme_file'        => 'readme.md',
				'description'        => array(
					'changelog' => $this->update_message(),
				),
			)
		);
	}

	/**
	 * Callback to set the SSL verification for HTTP requests to GitHub to false
	 *
	 * @since 1.0.0
	 *
	 * @param  array  $args
	 * @param  string $url
	 *
	 * @return array
	 */
	public function update_request_args( $args, $url ) {
		// Only needed once - this saves us checking the $url.
		remove_filter( current_filter(), __FUNCTION__ );

		return array_merge(
			$args,
			array(
				'sslverify' => false
			)
		);
	}

	/**
	 * Displays an update message for plugin list screens.
	 * Shows only the version updates from the current until the newest version
	 *
	 * @since 1.0.0
	 *
	 * @return string The actual Output message
	 */
	public function update_message() {
		// Get `changelog.txt` from GitHub via WP HTTP API.
		$remote_data = wp_remote_get( $this->remote_changelog, false );

		// Die silently.
		$response = wp_remote_retrieve_response_code( $remote_data );

		if ( is_wp_error( $remote_data ) ) {
			return _e( 'No changelog could get fetched.', 'cd_apd_textdomain' );
		}

		if ( 404 === $response ) {
			return $remote_data['response']['message'];
		}

		return sprintf(
			 "<p style='font-weight:normal;'>%s</p>"
			,wp_remote_retrieve_body( $remote_data )
		);
	}
} // END Class DJ_APD_Bootstrap

new DJ_APD_Bootstrap();

do_action( 'after_setup_plugindirs' );
