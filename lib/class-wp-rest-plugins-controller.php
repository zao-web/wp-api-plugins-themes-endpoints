<?php

/**
 * Manage plugins for a WordPress site.
 */
class Zao_REST_Plugins_Controller extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'zao/v1';
		$this->rest_base = 'plugins';
	}

	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->rest_base, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			),
			'schema' => array( $this, 'get_item_schema' ),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<slug>[\w-]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'download_package' => array(
						'description' => __( 'Adding this query param will initiate the plugin package download.', 'zao-wp-api-plugins' ),
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
			),
			'schema' => array( $this, 'get_item_schema' ),
		) );
	}

	/**
	 * Check if a given request has access to read /plugins.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) { // TODO: Something related to plugins. activate_plugin capability seems to not be available for multi-site superadmin (?)
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot view the list of plugins', 'zao-wp-api-plugins' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Get list of plugins' data.
	 *
	 * @since  0.1.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return mixed
	 */
	public function get_items( $request ) {

		$data = array();

		require_once ABSPATH . '/wp-admin/includes/plugin.php';
		$plugins = get_plugins();

		// Exit early if empty set.
		if ( empty( $plugins ) ) {
			return rest_ensure_response( $data );
		}

		// Store pagation values for headers.
		$total_plugins = count( $plugins );
		$per_page = (int) $request['per_page'];
		if ( ! empty( $request['offset'] ) ) {
			$offset = $request['offset'];
		} else {
			$offset = ( $request['page'] - 1 ) * $per_page;
		}
		$max_pages = ceil( $total_plugins / $per_page );
		$page = ceil( ( ( (int) $offset ) / $per_page ) + 1 );

		// Find count to display per page.
		if ( $page > 1 ) {
			$length = $total_plugins - $offset;
			if ( $length > $per_page ) {
				$length = $per_page;
			}
		} else {
			$length = $total_plugins > $per_page ? $per_page : $total_plugins;
		}

		// Split plugins array.
		$plugins = array_slice( $plugins, $offset, $length );

		foreach ( $plugins as $plugin_file => $plugin ) {
			$plugin['plugin_file'] = $plugin_file;
			$prepared = $this->prepare_item_for_response( $plugin, $request );

			if ( is_wp_error( $prepared ) ) {
				continue;
			}

			$response = rest_ensure_response( $prepared );
			$response->add_links( $this->prepare_links( $plugin ) );

			$data[] = $this->prepare_response_for_collection( $response );
		}

		$response = rest_ensure_response( $data );

		// Add pagination headers to response.
		$response->header( 'X-WP-Total', (int) $total_plugins );
		$response->header( 'X-WP-TotalPages', (int) $max_pages );

		// Add pagination link headers to response.
		$base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ) );

		if ( $page > 1 ) {
			$prev_page = $page - 1;
			if ( $prev_page > $max_pages ) {
				$prev_page = $max_pages;
			}
			$prev_link = add_query_arg( 'page', $prev_page, $base );
			$response->link_header( 'prev', $prev_link );
		}
		if ( $max_pages > $page ) {
			$next_page = $page + 1;
			$next_link = add_query_arg( 'page', $next_page, $base );
			$response->link_header( 'next', $next_link );
		}

		// Return requested collection.
		return $response;
	}

	/**
	 * check if a given request has access to read /plugins/{plugin-name}
	 *
	 * @param WP_REST_Request $request
	 * @return WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) { // TODO: Something related to plugins. activate_plugin capability seems to not be available for multi-site superadmin (?)
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you do not have access to this resource', 'zao-wp-api-plugins' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;

	}

	/**
	 * Get the requested plugin's info (or download the package, if 'download_package' param is set).
	 *
	 * @since  0.1.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return mixed.
	 */
	public function get_item( $request ) {
		$slug     = $request['slug'];
		$plugin   = null;

		require_once ABSPATH . '/wp-admin/includes/plugin.php';
		$plugins = get_plugins();

		foreach ( $plugins as $plugin_file => $active_plugin ) {
			$sanitized_title = sanitize_title( $active_plugin['Name'] );
			if ( $slug === $sanitized_title ) {
				$plugin = $active_plugin;
				break;
			}
		}

		if ( ! $plugin ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid plugin id.', 'zao-wp-api-plugins' ), array( 'status' => 404 ) );
		}

		$plugin['plugin_file'] = $plugin_file;

		if ( ! empty( $request['download_package'] ) ) {
			return self::trigger_package_download( $plugin );
		}

		$data     = $this->prepare_item_for_response( $plugin, $request );
		$response = rest_ensure_response( $data );
		$response->add_links( $this->prepare_links( $plugin, true ) );

		return $response;
	}

	/**
	 * check if a given request has access to delete a plugin
	 *
	 * @param WP_REST_Request $request
	 * @return WP_Error|boolean
	 */
	public function delete_item_permissions_check( $request ) {

		if ( ! current_user_can( 'delete_plugins' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot delete this plugin', 'zao-wp-api-plugins' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;

	}

	/**
	 * Delete a plugin.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_Error|WP_REST_Response
	 */
	public function delete_item( $request ) {

	}

	/**
	 * Return an array of contextual links for plugin/plugins.
	 *
	 * @since  0.1.0
	 *
	 * @param  array $plugin Plugin data.
	 * @return array         Array of links.
	 */
	protected function prepare_links( $plugin, $include_collection = false ) {
		$links = array(
			// Standard Link Relations -- http://v2.wp-api.org/extending/linking/
			'self' => array(
				'href' => $this->get_plugin_api_uri( $plugin ),
			),
			// Enclosure is a proper way to link to the package, but does not show up in the Schema,
			// so leaving in the plugin response 'package_uri' data.
			// 'enclosure' => array(
			// 	'href' => $this->get_package_uri( $plugin ),
			// 	// TODO: we should provide the "length" attribute: https://tools.ietf.org/html/rfc4287#page-22
			// 	// 'length' => '',
			// ),
		);

		if ( $include_collection ) {
			$links['collection'] = array(
				'href' => rest_url( $this->namespace . '/' . $this->rest_base ),
			);
		}

		return $links;
	}

	/**
	 * Retrieves the plugin item's schema, conforming to JSON Schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array Plugin item schema data.
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'plugin',
			'type'       => 'object',
			'properties' => array(
				'name'        => array(
					'description' => __( 'The name of the plugin.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'plugin_uri'  => array(
					'description' => __( 'The uri of the plugin.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
				'version'     => array(
					'description' => __( 'The plugin version.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'description' => array(
					'description' => __( 'A short description of the plugin.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'author'      => array(
					'description' => __( 'Name of plugin author.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'author_uri'  => array(
					'description' => __( 'Plugin author uri.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
				'text_domain' => array(
					'description' => __( 'Plugin text domain.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'domain_path' => array(
					'description' => __( 'Path for text domain.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'network'     => array(
					'description' => __( 'Whether the plugin is forced to be active on the network via plugin headers. This does not indicate whether the plugin is active on the network.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'title'       => array(
					'description' => __( 'The title for the resource.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				// @TODO Possibly delete this from schema as it is somewhat duplicate data.
				'author_name' => array(
					'description' => __( 'Name of plugin author.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'status' => array(
					'description' => __( 'Whether plugin is active on the site or the network.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'default'     => 'inactive',
				),
				'update' => array(
					'description' => __( 'Whether plugin has an available update.', 'zao-wp-api-plugins' ),
					'type'        => 'boolean',
					'default'     => false,
				),
				'update_version' => array(
					'description' => __( 'The version available if plugin has an available update.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
				),
				'package_uri' => array(
					'description' => __( 'Plugin package download URI.', 'zao-wp-api-plugins' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Retrieves the query params for the plugin collection.
	 *
	 * @since 0.1.0
	 *
	 * @return array Query parameters for the plugin collection.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['offset'] = array(
			'description'        => __( 'Offset the result set by a specific number of items.', 'zao-wp-api-plugins' ),
			'type'               => 'integer',
			'sanitize_callback'  => 'absint',
			'validate_callback'  => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * Prepares the plugin for the REST response.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $plugin  Plugin data.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function prepare_item_for_response( $plugin, $request ) {
		$data = array();

		$schema = $this->get_item_schema();

		if ( isset( $schema['properties']['name'] ) ) {
			$data['name'] = $plugin['Name'];
		}

		if ( isset( $schema['properties']['plugin_uri'] ) ) {
			$data['plugin_uri'] = $plugin['PluginURI'];
		}

		if ( isset( $schema['properties']['version'] ) ) {
			$data['version'] = $plugin['Version'];
		}

		if ( isset( $schema['properties']['description'] ) ) {
			$data['description'] = $plugin['Description'];
		}

		if ( isset( $schema['properties']['author'] ) ) {
			$data['author'] = $plugin['Author'];
		}

		if ( isset( $schema['properties']['author_uri'] ) ) {
			$data['author_uri'] = $plugin['AuthorURI'];
		}

		if ( isset( $schema['properties']['text_domain'] ) ) {
			$data['text_domain'] = $plugin['TextDomain'];
		}

		if ( isset( $schema['properties']['domain_path'] ) ) {
			$data['domain_path'] = $plugin['DomainPath'];
		}

		if ( isset( $schema['properties']['network'] ) ) {
			$data['network'] = $plugin['Network'];
		}

		if ( isset( $schema['properties']['title'] ) ) {
			$data['title'] = $plugin['Title'];
		}

		if ( isset( $schema['properties']['author_name'] ) ) {
			$data['author_name'] = $plugin['AuthorName'];
		}

		if ( isset( $schema['properties']['status'] ) ) {
			$data['status'] = self::get_status( $plugin['plugin_file'] );
		}

		$update_info = self::get_update_info( $plugin['plugin_file'] );

		if ( isset( $schema['properties']['update'] ) ) {
			$data['update'] = (bool) $update_info;
		}

		if ( isset( $schema['properties']['update_version'] ) ) {
			$data['update_version'] = $update_info['new_version'];
		}

		if ( isset( $schema['properties']['package_uri'] ) ) {
			$data['package_uri'] = $this->get_package_uri( $plugin );
		}

		return $data;
	}

	/**
	 * Get the plugin's status in the network/site.
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $file The plugin file/id.
	 *
	 * @return string        'active-network', 'active', or 'inactive'.
	 */
	protected static function get_status( $file ) {
		if ( is_plugin_active_for_network( $file ) ) {
			return 'active-network';
		}

		if ( is_plugin_active( $file ) ) {
			return 'active';
		}

		return 'inactive';
	}

	/**
	 * Get the URI for single plugin request.
	 *
	 * @since  0.1.0
	 *
	 * @param  array  $plugin Plugin data.
	 *
	 * @return string         The plugin package api URI.
	 */
	protected function get_plugin_api_uri( $plugin ) {
		$api_slug = sanitize_title( $plugin['Name'] );
		return rest_url( $this->namespace . '/' . $this->rest_base . '/' . $api_slug );
	}

	/**
	 * Get the URI for downloading the plugin package.
	 *
	 * @since  0.1.0
	 *
	 * @param  array  $plugin Plugin data.
	 *
	 * @return string         The plugin package download URI.
	 */
	protected function get_package_uri( $plugin ) {
		$url = add_query_arg( 'download_package', 1, $this->get_plugin_api_uri( $plugin ) );

		return apply_filters( 'zao_plugins_download_package_file_url', $url, $plugin, $this );
	}

	/**
	 * Check whether a plugin has an update available or not.
	 *
	 * @param string $slug The plugin slug.
	 *
	 * @return bool
	 */
	protected static function has_update( $slug ) {
		$update_list = get_site_transient( 'update_plugins' );

		return isset( $update_list->response[ $slug ] );
	}

	/**
	 * Get the available plugin update info.
	 *
	 * @param string $slug The plugin slug
	 *
	 * @return array|null
	 */
	protected static function get_update_info( $slug ) {
		$update_list = get_site_transient( 'update_plugins' );

		return isset( $update_list->response[ $slug ] )
			? (array) $update_list->response[ $slug ]
			: null;
	}

	/**
	 * Check if we can find the WordPress Plugin repo API data for given plugin,
	 * either from the cached update data, or from the API itself.
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $slug The slug that would be used in the WordPress plugin repo.
	 *
	 * @return string|bool   False if plugin data/url was not found.
	 */
	public static function get_plugin_package_url_from_api( $slug ) {
		// Check plugin update information for package info
		$package_url = self::check_plugin_update_for_package( $slug );

		if ( ! $package_url ) {

			// Check WP Plugin API for package info
			$package_url = self::check_plugin_api_for_package( $slug );
		}

		return $package_url;
	}

	/**
	 * Check if we have cached WordPress Plugin repo API package data for given plugin.
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $slug The slug that would be used in the WordPress plugin repo.
	 *
	 * @return string|bool   False if plugin data/url was not found.
	 */
	protected static function check_plugin_update_for_package( $slug ) {
		$update_info = self::get_update_info( $slug );
		if ( ! empty( $update_info['package'] ) ) {
			return $update_info['package'];
		}

		return false;
	}

	/**
	 * Check the WordPress Plugin repo API for package data for given plugin.
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $slug The slug that would be used in the WordPress plugin repo.
	 *
	 * @return string|bool   False if plugin data/url was not found.
	 */
	protected static function check_plugin_api_for_package( $slug ) {
		require_once( ABSPATH . 'wp-admin/includes/plugin-install.php' );

		$args = array(
			'slug' => wp_unslash( dirname( $slug ) ),
			'is_ssl' => true,
			'fields' => array(
				'short_description' => false,
				'description'       => false,
				'sections'          => false,
				'tested'            => false,
				'requires'          => false,
				'rating'            => false,
				'ratings'           => false,
				'downloaded'        => false,
				'downloadlink'      => false,
				'last_updated'      => false,
				'added'             => false,
				'tags'              => false,
				'compatibility'     => false,
				'homepage'          => false,
				'versions'          => false,
				'donate_link'       => false,
				'reviews'           => false,
				'banners'           => false,
				'icons'             => false,
				'active_installs'   => false,
				'group'             => false,
				'contributors'      => false,
			)
		);
		$api = plugins_api( 'plugin_information', $args );

		if ( ! is_wp_error( $api ) && ! empty( $api->download_link ) ) {
			return $api->download_link;
		}

		return false;
	}

	/**
	 * If requesting the download_package API url, then trigger the package download.
	 *
	 * @since  0.1.0
	 *
	 * @param  array  $plugin Plugin data.
	 *
	 * @return void|WP_Error  Error if plugin package could not be downloaded.
	 */
	protected static function trigger_package_download( $plugin ) {
		$slug = $plugin['plugin_file'];

		$package_url = self::get_plugin_package_url_from_api( $slug );

		if ( $package_url ) {
			wp_redirect( esc_url_raw( $package_url ) );
			exit;
		}

		// Create package Zip file on the fly, download, and then delete it.
		$package_url = self::generate_download( $plugin );

		if ( ! $package_url ) {
			return new WP_Error( 'rest_plugin_package_download_error', sprintf( __( 'Sorry, plugin package for "%s" cannot be downloaded.', 'zao-wp-api-plugins' ), $plugin['Name'] ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Generates a zip package and downloads it.
	 *
	 * @since  0.1.0
	 *
	 * @param  array  $plugin Plugin data.
	 *
	 * @return void|bool      False if plugin path does not exist.
	 */
	protected static function generate_download( $plugin ) {
		$plugin_dir_name = dirname( $plugin['plugin_file'] );
		$path            = trailingslashit( trailingslashit( WP_PLUGIN_DIR ) . $plugin_dir_name );

		if ( ! file_exists( $path ) ) {
			return false;
		}

		// Initialize archive objectZ
		$zip = new ZipArchive();
		// Creates new zip archive
		if ( true !== $zip->open( $plugin_dir_name, ZIPARCHIVE::CREATE ) ) {
			exit( sprintf( __( "Cannot open <%s>\n", 'zao-wp-api-plugins' ), $filename ) );
		}

		// Create recursive directory iterator
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path )
		);

		$stuff = '';
		foreach ( $files as $name => $file ) {
			// $stuff .= '<xmp>'. __LINE__ .') $file: '. print_r( $file, true ) .'</xmp>';
			// Skip directories (they would be added automatically)
			if ( $file->isDir() ) {
				continue;
			}

			// Do not include certain blacklisted directories and files.
			if ( self::is_blacklisted( $file, $path ) ) {
				continue;
			}

			$file_path = $file->getRealPath();
			$relative_path = trailingslashit( $plugin_dir_name ) . substr( $file_path, strlen( $path ) );

			$zip->addFile( $file_path, $relative_path );
		}

		$zip->close();

		$zip_file_name = sanitize_file_name( $plugin['Name'] . ' ' . $plugin['Version'] ) . '.zip';
		self::download_temp_file( $plugin_dir_name, $zip_file_name );
	}

	/**
	 * Determine if a file is blacklisted from being included in the zip package.
	 *
	 * @since  0.1.0
	 *
	 * @param  SplFileInfo $file File handle object.
	 * @param  string      $path The plugin's path.
	 *
	 * @return boolean           Whether file is blacklisted from being included in zip package.
	 */
	protected static function is_blacklisted( SplFileInfo $file, $path ) {
		$is_blacklisted = false;
		$dir_blacklist = apply_filters( 'zao_plugins_download_package_directory_blacklist', array(
			'.git/',
			'node_modules/',
		) );

		$file_blacklist = apply_filters( 'zao_plugins_download_package_file_blacklist', array(
			'.gitattributes' => 1,
			'.gitignore' => 1,
		) );

		$path = trailingslashit( ltrim( str_replace( $path, '', $file->getPath() ), '/' ) );
		foreach ( $dir_blacklist as $blacklisted ) {
			if ( false !== strpos( $path, $blacklisted ) ) {
				$is_blacklisted = true;
				break;
			}
		}

		if ( isset( $file_blacklist[ $file->getFilename() ] ) ) {
			$is_blacklisted = true;
		}

		return apply_filters( 'zao_plugins_download_file_is_blacklisted', $is_blacklisted, $file, $path );
	}

	/**
	 * Downloads file specified and then deletes it.
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $file     File path.
	 * @param  string  $filename Downloaded file name.
	 *
	 * @return void
	 */
	protected static function download_temp_file( $file, $filename = '' ) {
		if ( empty( $filename ) ) {
			$filename = basename( $file );
		}
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $file ) );
		ob_clean();
		flush();
		readfile( $file );
		unlink( $file );
		exit;
	}

}
