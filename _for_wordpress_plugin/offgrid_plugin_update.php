<?php
/**
 * Class MRT_OffGrid_Plugin_Updater
 * Connect to remote server for Off-Grid Plugin Updates
 *
 * Call like this when your plugin initializes:
 * include( plugin_dir_path( __FILE__ ) . 'offgrid_plugin_update.php' );
 * $PluginUpdater = new MRT_OffGrid_Plugin_Updater( $version, 'https://full.server.path/', plugin_basename( __FILE__ ) );
 * $PluginUpdater->run();
 *
 * Data is stored in 12-hour cache, clear transients if you need to see changes on something
 *
 * Author: Mike Karikas
 * Author URI: https://www.neutrinoinc.com
 * Version: 1.0
 * Based on plugin update code by Misha Rudrastyh at https://rudrastyh.com/wordpress/self-hosted-plugin-update.html
 */
class MRT_OffGrid_Plugin_Updater {
	protected $current_version;
	protected $remote_server_url;
	protected $plugin_name;
	protected $transient_name;

	function __construct( $current_version, $remote_server_url, $plugin_name ) {
		$this->current_version   = $current_version;
		$this->remote_server_url = $remote_server_url;
		$this->plugin_name       = $plugin_name;

		$this->transient_name = dirname( $plugin_name ) . '-upgrade-check';
	}

	/**
	 * Initialize plugins to make this work
	 * Not doing this in the constructor in case we ever need to include this without hooking into WordPress
	 */
	function run() {
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'site_transient_update_plugins', [ $this, 'push_update' ] );
		add_action( 'upgrader_process_complete', [ $this, 'after_update' ], 10, 2 );
	}

	/**
	 * Return the "view details" information about our plugin including all info - banners, contributors, remarks, etc
	 * @param $res object empty at this step
	 * @param $action string
	 * @param $args object stdClass Object ( [slug] => woocommerce [is_ssl] => [fields] => Array ( [banners] => 1 [reviews] => 1 [downloaded] => [active_installs] => 1 ) [per_page] => 24 [locale] => en_US )
	 *
	 * @return bool|stdClass
	 */
	function plugin_info( $res, $action, $args ) {

		// Do nothing if this is not about getting plugin information
		if ( $action !== 'plugin_information' ) {
			return false;
		}

		// Do nothing if it is not our plugin
		if ( dirname( $this->plugin_name ) !== $args->slug ) {
			return false;
		}

		// Try to get from cache first
		if ( false == $remote = get_transient( $this->transient_name ) ) {

			// Connect to server and receive json data
			$remote = wp_remote_get( "{$this->remote_server_url}?plugin={$this->plugin_name}", array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/json'
					)
				)
			);

			if ( ! is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && ! empty( $remote['body'] ) ) {
				set_transient( $this->transient_name, $remote, 43200 ); // 12 hours cache
			}

		}

		// Got our data, build and return our object data to WordPress to make a real purty plugin page
		if ( $remote ) {

			$remote                 = json_decode( $remote['body'] );
			$res                    = new stdClass();
			$res->name              = $remote->name;
			$res->tags              = $remote->tags; // ?
			$res->requires          = $remote->requires_at_least;
			$res->tested            = $remote->tested_up_to;
			$res->contributors      = $remote->contributors;
			$res->donate_link       = $remote->donate_link;
			$res->short_description = $remote->short_description; // ?
			$res->upgrade_notice    = $remote->upgrade_notice; // ?
			$res->version           = $remote->new_version;
			$res->author            = $remote->author;
			$res->author_profile    = $remote->author_profile;
			$res->download_link     = $remote->download_link;
			$res->last_updated      = $remote->last_updated;
			$res->trunk             = $remote->trunk;
			$res->homepage          = $remote->homepage;
			$res->versions          = $remote->versions; // ?
			$res->slug              = $remote->slug;
			$res->plugin            = $remote->plugin;
			$res->sections          = (array) $remote->sections;

			if ( ! empty( $remote->banners ) ) {
				$res->banners = (array) $remote->banners;
			}
			if ( ! empty( $remote->icons ) ) {
				$res->icons = (array) $remote->icons;
			}

			return $res;

		}

		return false;

	}

	/**
	 * Return basic information for Dashboard -> Updates page or Plugins page, to check version and get minor details
	 * @param $transient object
	 *
	 * @return mixed
	 */
	function push_update( $transient ) {

		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// trying to get from cache first
		if ( false == $remote = get_transient( $this->transient_name ) ) {

			// Connect to server and receive json data
			$remote = wp_remote_get( "{$this->remote_server_url}?plugin={$this->plugin_name}", array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/json'
					)
				)
			);

			if ( ! is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && ! empty( $remote['body'] ) ) {
				set_transient( $this->transient_name, $remote, 43200 ); // 12 hours cache
			}

		}

		// Got our data, build and return our object data to WordPress in a way that makes sense to it
		if ( $remote ) {

			$remote = json_decode( $remote['body'] );
			if ( $remote && version_compare( $this->current_version, $remote->new_version, '<' )
			     && version_compare( $remote->requires_at_least, get_bloginfo( 'version' ), '<' )
			) {
				$res                                 = new stdClass();
				$res->slug                           = $remote->slug;
				$res->plugin                         = $remote->plugin;
				$res->new_version                    = $remote->new_version;
				$res->tested                         = $remote->tested_up_to;
				$res->package                        = $remote->download_link;
				$res->url                            = $remote->homepage;
				$res->compatibility                  = new stdClass();
				$transient->response[ $res->plugin ] = $res;
				//$transient->checked[$res->plugin] = $remote->new_version;

				if ( ! empty( $remote->icons ) ) {
					$res->icons = (array) $remote->icons;
				}
			}

		}

		return $transient;
	}

	/**
	 * After an update we can clear our transient cache
	 *
	 * @param $upgrader_object
	 * @param $options array
	 */
	function after_update( $upgrader_object, $options ) {
		if ( $options['action'] == 'update' && $options['type'] === 'plugin' ) {
			delete_transient( $this->transient_name );
		}
	}

}