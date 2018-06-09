<?php

/**
 * WordPress plugin update server
 * To provide updates, information, and past versions of a particular plugin
 * Based on information from Misha Rudrastyh, https://rudrastyh.com/wordpress/self-hosted-plugin-update.html
 *
 * Note that for this to work correctly versioning must be done in accordance to semantic versioning - see semver.org
 *
 * Zip files including version numbers of your plugin should go in the /releaes/ subdirectory
 * Either a single file may be placed there of the latest version, like plugin.zip, or multiple versioned files
 * with the convention plugin-1.0.0.zip
 */
class WordPress_Plugin_Server {

	protected $releases_dir;

	protected $plugin;

	protected $plugin_stub;

	protected $latest_version;

	protected $readme;

	protected $plugin_code;

	function __construct() {
		if ( ! empty( $_REQUEST['plugin'] ) ) {
			$this->plugin      = preg_replace( '[^a-zA-Z\-_0-9/]', '', $_REQUEST['plugin'] );
			$this->plugin_stub = dirname($this->plugin);
		} else {
			$this->abort();
		}

		// Define 'releases' subdirectory, this is where your zips go
		$this->releases_dir = __DIR__ . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR;

		// Set default "latest version" to the beginning of time
		$this->latest_version = '0.0.0';
	}

	/**
	 * Finds and loads data for the latest version found from the files in the /releases directory
	 * If a file named $plugin.zip is found, the version in that will be sniffed
	 * Otherwise, versioned releases will be looped over until the highest version is determined
	 * Follows x.x.x semantic versioning protocol from semver.org
	 *
	 * @return string
	 */
	function load_latest_release() {
		if ( file_exists( "{$this->releases_dir}{$this->plugin_stub}.zip" ) ) {
			$this->parse_readme( "{$this->releases_dir}{$this->plugin_stub}.zip" );
		} else {
			// Loop over files looking for a version number
			foreach ( glob( "{$this->releases_dir}{$this->plugin_stub}*.zip" ) as $filename ) {
				preg_match( "/^{$this->plugin_stub}\-([0-9\.]+)\.zip$/", basename( $filename ), $matches );
				if ( isset( $matches[1] ) ) {
					$this_version = $matches[1];
					if ( version_compare( $this_version, $this->latest_version ) == 1 ) {
						$this->latest_version = $this_version;
					}
				}
			}

			if ( $this->latest_version != '0.0.0' ) {
				$this->parse_readme( "{$this->releases_dir}{$this->plugin_stub}-{$this->latest_version}.zip" );
			} else {
				$this->abort();
			}
		}
	}

	function output_info() {
		// Prep latest version and load readme.txt
		$this->load_latest_release();

		// json output, yo
		header( 'Content-Type: application/json' );

		// Set output array, this will become json output
		$output = [];

		// Now, run through the readme
		$r    = new WordPress_Readme_Parser();
		$data = $r->parse_readme_contents( $this->readme );

		// Set some special fields from the plugin header and various data sources
		// Load version number from plugin file itself if needed
		if ( $this->latest_version == '0.0.0' ) {
			preg_match( "/.*\*.*Version:[^0-9]*([0-9\.]+).*/", $this->plugin_code, $matches );
			if ( isset( $matches[1] ) && ! empty( $matches[1] ) ) {
				$this->latest_version = $matches[1];
			}
		}
		$data['new_version'] = $this->latest_version;

		// Author and link if applicable
		preg_match( "/.*\*.*Author:\s*(.+)\s*/", $this->plugin_code, $matches );
		if ( isset( $matches[1] ) && ! empty( $matches[1] ) ) {
			// Check for link
			preg_match( "/.*\*.*Author URI:\s*(.+)\s*/", $this->plugin_code, $more_matches );
			if ( isset( $more_matches[1] ) && ! empty( $more_matches[1] ) ) {
				// Author link found
				$data['author'] = '<a href="' . $more_matches[1] . '">' . $matches[1] . '</a>';
			} else {
				// Just author name
				$data['author'] = $matches[1];
			}
		}

		// Author profile - based on first contributor value in readme.txt
		if ( ! empty( $data['contributors'] ) && ! empty( $data['contributors'][0] ) ) {
			$data['author_profile'] = 'https://profiles.wordpress.org/' . $data['contributors'][0];
		}

		// Modernize list of contributors to include links
		if ( ! empty( $data['contributors'] ) && ! empty( $data['contributors'][0] ) ) {
			$contribs = [];
			foreach ( $data['contributors'] as $person ) {
				$contribs[ $person ] = 'https://profiles.wordpress.org/' . $person;
			}
			$data['contributors'] = $contribs;
		}


		// Download Link and Last Updated - sure hope these $_SERVER values are consistent
		if ( $_SERVER['HTTPS'] == 'on' ) {
			$download_link_base = 'https://';
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' ) {
			// AWS ssl -> http forwarding
			$download_link_base = 'https://';
		} else {
			$download_link_base = 'http://';
		}
		$download_link_base .= $_SERVER['HTTP_HOST'];
		$download_link_base .= '/releases/';
		if ( file_exists( "{$this->releases_dir}{$this->plugin_stub}.zip" ) ) {
			$data['download_link'] = $download_link_base . "{$this->plugin_stub}.zip";
			$data['last_updated']  = date( 'Y-m-d H:i:s', filectime( "{$this->releases_dir}{$this->plugin_stub}.zip" ) ); // like 2018-06-09 08:20:00
		} elseif ( file_exists( "{$this->releases_dir}{$this->plugin_stub}-{$this->latest_version}.zip" ) ) {
			$data['download_link'] = $download_link_base . "{$this->plugin_stub}-{$this->latest_version}.zip";
			$data['last_updated']  = date( 'Y-m-d H:i:s', filectime( "{$this->releases_dir}{$this->plugin_stub}-{$this->latest_version}.zip" ) );
		} else {
			$this->abort();
		}
		$data['trunk'] = $data['download_link'];

		// Homepage
		preg_match( "/.*\*.*Plugin URI:[\s]*(.+)/", $this->plugin_code, $matches );
		if ( isset( $matches[1] ) && ! empty( $matches[1] ) ) {
			$data['homepage'] = $matches[1];
		}

		// Versions
		$data['versions'] = [
			'trunk' => $data['trunk']
		];
		foreach ( glob( "{$this->releases_dir}{$this->plugin_stub}*.zip" ) as $filename ) {
			preg_match( "/^{$this->plugin_stub}\-([0-9\.]+)\.zip$/", basename( $filename ), $matches );
			if ( isset( $matches[1] ) ) {
				$data['versions'][ $matches[1] ] = $download_link_base . basename( $filename );
			}
		}

		// Slug
		$data['slug'] = $this->plugin_stub;

		// Plugin
		$data['plugin'] = $this->plugin;

		// Banner and icon support based on files in assets
		$assets_dir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;
		$data['banners'] = [];
		foreach(['.png', '.jpg'] as $ext) {
			if (file_exists("{$assets_dir}banner-722x250{$ext}")) {
				$data['banners']['low'] = str_replace('releases', 'assets', $download_link_base)."banner-722x250{$ext}";
			}
			if (file_exists("{$assets_dir}banner-1544x500{$ext}")) {
				$data['banners']['high'] = str_replace('releases', 'assets', $download_link_base)."banner-1544x500{$ext}";
			}
		}

		// default, 1x, 2x, svg
		$data['icons'] = [];
		foreach(['.png', '.jpg'] as $ext) {
			if (file_exists("{$assets_dir}icon-128x128{$ext}")) {
				$data['icons']['1x'] = str_replace('releases', 'assets', $download_link_base)."icon-128x128{$ext}";
				$data['icons']['default'] = $data['icons']['1x'];
			}
			if (file_exists("{$assets_dir}icon-256x256{$ext}")) {
				$data['icons']['2x'] = str_replace('releases', 'assets', $download_link_base)."icon-256x256{$ext}";
				$data['icons']['default'] = $data['icons']['2x'];
			}
			if (file_exists("{$assets_dir}icon.svg")) {
				$data['icons']['svg'] = str_replace('releases', 'assets', $download_link_base)."icon.svg";
				$data['icons']['default'] = $data['icons']['svg'];
			}
		}

		// Skipping: downloaded, rating, ratings, num_ratings, support_threads, support_threads_resolved, active_installs, reviews, date_added
		// For more details on adding these values see https://rudrastyh.com/wordpress/self-hosted-plugin-update.html
		// Or refer to wp-admin/plugin-install.php::install_plugin_information()

		//print_r( $data );
		echo json_encode( $data );
	}

	/**
	 * Abort program, end of line.
	 */
	function abort() {
		echo 'Nope.';
		exit;
	}

	/**
	 * Crack open the zip file provided with $filename and read raw contents of its readme.txt file into $this->readme
	 * Also loads plugin information into $this->plugin_code for values like version, author, etc
	 *
	 * @param $filename string
	 */
	function parse_readme( $filename ) {
		// Open up the zip and snag the readme file
		$zip = new ZipArchive();
		$zip->open( $filename );
		for ( $i = 0; $i < $zip->numFiles; $i ++ ) {
			$stat = $zip->statIndex( $i );

			if ( strtolower( $stat['name'] ) == strtolower( "{$this->plugin_stub}/readme.txt" ) ) {
				// Found it!  Open from inside zip and Load it up
				// TODO: Cache this sucker
				$contents = '';
				$fp       = $zip->getStream( $stat['name'] );
				if ( ! $fp ) {
					$this->abort();
				}

				while ( ! feof( $fp ) ) {
					$contents .= fread( $fp, 2 );
				}

				fclose( $fp );
				$this->readme = $contents;
			}

			// Do we have our version number loaded yet?  If not, pull it from the main plugin file
			if ( strtolower( $stat['name'] ) == strtolower( $this->plugin ) ) {

				// Found it!  Open from inside zip and Load it up
				// TODO: Cache this sucker, too
				$fp = $zip->getStream( $stat['name'] );
				if ( ! $fp ) {
					$this->abort();
				}

				while ( ! feof( $fp ) ) {
					$contents .= fread( $fp, 2 );
				}

				fclose( $fp );
				$this->plugin_code = $contents;
			}
		}
	}
}

include( __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'parse-readme.php' );
$PluginServer = new WordPress_Plugin_Server();
$PluginServer->output_info();

// TODO: Pull out any banner/icon files of latest version