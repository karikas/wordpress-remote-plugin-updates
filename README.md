# WordPress Remote Plugin Updates

Provide updates for WordPress plugins that are stored outside of the WordPress Plugins Directory.  By setting up the server at a URL and uploading zip file packages of each version of your plugin, WordPress sites using your plugin will automatically prompt for updates and display plugin information, including graphics like banners and screenshots when applicable.

This sort of thing is suitable for plugins that:
1. only apply to certain people and putting them in the Plugin Repository would be silly,
1. are for commercial paid plugins only, or
1. would not be accepted in the WordPress Plugin Directory due to licensing conflicts or another issue.

This came out of a necessity at Neutrino, Inc. for this functionality and a presentation at WordCamp Orange County 2018, which you can find slides and information from here: [github.com/karikas/wcoc-off-grid-plugins](https://github.com/karikas/wcoc-off-grid-plugins).

## Installation

There are two parts, the Server and the Plugin code.

### Server Installation

To setup and configure the server:
1. Upload `index.php` and the `assets`, `lib` and `releases` folders to a PHP-running web server of your choice.
1. Upload your plugin release zip files into the `releases` folder as needed.
1. Upload any visual assets like banners, icons, and screenshots into the `assets` folder.

#### Plugin Files
Your plugin must be packaged as if you were going to upload it to the WordPress Plugin Repository.  That means having valid information in the [plugin header](https://developer.wordpress.org/plugins/the-basics/header-requirements/) and a valid []readme.txt file](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/). 

Your plugin files must be zipped as a folder "the WordPress way", and the zip file must reflect the version number.  Example, say you have a plugin called `arrmatey` and you have versions 1.0.0, 1.1.0 and 1.2.0.  You would have three files:
````
releases/arrmatey-1.0.0.zip
releases/arrmatey-1.1.0.zip
releases/arrmatey-1.2.0.zip
````
Each zip file would contain a folder named `arrmatey` without the version number and the correct files, including a readme.txt, for that version of the plugin.  Plugin details are pulled from the plugin header and readme.txt file for the latest version.

Only [Semantic Versioning](https://semver.org) numbers are supported, as those are used by the PHP function [version_compare](http://php.net/version_compare), which is also used by WordPress for comparing plugin versions. 

#### Banners, Icons and Screenshots
Images to support your plugin (assets) follow the naming conventions outlined in the []WordPress Plugin Handbook](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) and must be placed in the server's `assets` folder.

### Plugin Installation

Copy the file from `_for_wordpress_plugin/offgrid_plugin_update.php` to your plugin folder.  Include and initialize it with code similar to this:
````
// Include and initialize plugin update code
include( plugin_dir_path( __FILE__ ) . 'offgrid_plugin_update.php' );
$PluginUpdater = new MRT_OffGrid_Plugin_Updater( $plugin_version, 'https://updates.myserver.com', plugin_basename( __FILE__ ) );
$PluginUpdater->run();
````

Note that the third argument, the plugin slug name, should match WordPress' plugin slug format, which looks like `arrmatey/arrmatey.php`.

## Credits
Written by [Mike Karikas](https://github.com/karikas) of [Neutrino, Inc.](https://www.neutrinoinc.com), but originally based on code from [Misha Rudrastyh]()https://rudrastyh.com/wordpress/self-hosted-plugin-update.html).