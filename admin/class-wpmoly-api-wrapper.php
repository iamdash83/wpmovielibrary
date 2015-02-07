<?php
/**
 * WPMovieLibrary_Admin Class extension.
 * 
 * Layer for TMDb Class.
 * 
 * @package   WPMovieLibrary
 * @author    Charlie MERLAND <charlie@caercam.org>
 * @license   GPL-3.0
 * @link      http://www.caercam.org/
 * @copyright 2014 CaerCam.org
 */

if ( ! class_exists( 'WPMOLY_TMDb' ) ) :

	class WPMOLY_TMDb extends WPMOLY_Module {

		/**
		 * TMDb API Config
		 *
		 * @since   1.0
		 * @var     array
		 */
		protected $config = null;

		/**
		 * TMDb API
		 *
		 * @since   1.0
		 * @var     string
		 */
		protected $tmdb = '';

		/**
		 * TMDb Error notify
		 *
		 * @since   1.0
		 * @var     string
		 */
		protected $error = '';

		public function __construct() {

			if ( ! is_admin() )
				return false;

			$this->register_hook_callbacks();
		}

		/**
		 * Register callbacks for actions and filters
		 * 
		 * @since    1.0
		 */
		public function register_hook_callbacks() {

			add_action( 'admin_init', array( $this, 'init' ) );

			add_action( 'wp_ajax_wpmoly_search_movie', __CLASS__ . '::search_movie_callback' );
			add_action( 'wp_ajax_wpmoly_check_api_key', __CLASS__ . '::check_api_key_callback' );
		}

		/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
		 *
		 *                             Callbacks
		 * 
		 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

		/**
		 * API check callback. Check key validity and return a status.
		 * 
		 * An invalid key will result in an error from the API with the
		 * status code '7'. If we get that error, use a WP_Error instance
		 * to handle the error and add it to the WPMOLY_Ajax instance we
		 * use to pass data to the JS part.
		 * 
		 * If the key appears to be valid, send a validation message.
		 *
		 * @since    1.0
		 */
		public static function check_api_key_callback() {

			wpmoly_check_ajax_referer( 'check-api-key' );

			if ( ! isset( $_GET['key'] ) || '' == $_GET['key'] || 32 !== strlen( $_GET['key'] ) )
				return new WP_Error( 'invalid', __( 'Invalid API key - the key should be an alphanumerica 32 chars long string.', 'wpmovielibrary' ) );

			$check = self::check_api_key( esc_attr( $_GET['key'] ) );

			if ( is_wp_error( $check ) )
				$response = new WP_Error( 'invalid', __( 'Invalid API key - You must be granted a valid key', 'wpmovielibrary' ) );
			else
				$response = array( 'message' => __( 'Valid API key - Save your settings and have fun!', 'wpmovielibrary' ) );

			wpmoly_ajax_response( $response );
		}

		/**
		 * Search callback
		 *
		 * @since    1.0
		 */
		public static function search_movie_callback() {

			wpmoly_check_ajax_referer( 'search-movies' );

			$defaults = array(
				's'     => null,
				'lang'  => wpmoly_o( 'api-language' ),
				'adult' => wpmoly_o( 'api-adult' ),
				'year'  => null,
				'pyear' => null,
				'page'  => 1
			);

			$post_id = ( isset( $_POST['post_id'] ) && '' != $_POST['post_id'] ? intval( $_POST['post_id'] ) : null );
			$query   = ( isset( $_POST['query'] )   && '' != $_POST['query']   ? $_POST['query'] : null );

			$query = wp_parse_args( $query, $defaults );

			if ( is_null( $query['s'] ) ) {
				$response = new WP_Error( 'empty_search', __( 'Empty search query.', 'wpmovielibrary' ) );
				wp_send_json_error( $response );
			}

			if ( preg_match( '/(tt\d{5,7})/i', $query['s'], $m ) ) {
				$type = 'id';
			} elseif ( preg_match( '/(\d{1,7})/i', $query['s'], $m ) ) {
				$type = 'id';
			} else {
				$type = 'title';
			}

			if ( 'title' == $type )
				$response = self::get_movie_by_title( $query );
			else if ( 'id' == $type )
				$response = self::get_movie_by_id( $query );

			if ( empty( $response ) ) {
				$response = new WP_Error( 'empty', __( 'Search returned no result. Try a different query?', 'wpmovielibrary' ) );
				wp_send_json_error( $response );
			}

			if ( is_wp_error( $response ) )
				wp_send_json_error( $response );

			wp_send_json_success( $response );
		}

		/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
		 *
		 *                             Methods
		 * 
		 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

		/**
		 * Test the submitted API key using a dummy TMDb instance to fetch
		 * API's configuration. Return the request result array.
		 *
		 * @since    1.0
		 * 
		 * @param    string    $key API Key
		 *
		 * @return   array     API configuration request result
		 */
		private static function check_api_key( $key ) {

			$tmdb = new TMDb( $config = true, $dummy = false );
			$check = $tmdb->checkApiKey( $key );

			return $check;
		}

		/**
		 * Generate base url for requested image type and size.
		 * 
		 * @since    1.0
		 * 
		 * @param    string    $filepath Filepath to image
		 * @param    const     $imagetype Image type
		 * @param    string    $size Valid size for the image
		 * 
		 * @return   string    base url
		 */
		public static function get_image_url( $filepath = null, $imagetype = null, $size = null ) {

			$tmdb = new TMDb();

			$url = $tmdb->getImageUrl( $filepath, $imagetype, $size );

			return $url;
		}


		/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
		 *
		 *                             Internal
		 * 
		 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

		/**
		 * Cache method for _get_movie_by_title.
		 * 
		 * @see _get_movie_by_title()
		 * 
		 * @since    1.0
		 * 
		 * @param    array     $query Search parameters
		 * @param    string    $lang Lang to use in the query. Deprecated since 2.2
		 * @param    int       $post_id Related Post ID. Deprecated since 2.2
		 * 
		 * @return   
		 */
		private static function get_movie_by_title( $query, $lang = null, $post_id = null ) {

			$hash   = md5( serialize( $query ) );
			$movies = ( wpmoly_o( 'enable-cache' ) ? get_transient( "wpmoly_movie_{$hash}" ) : false );

			if ( false === $movies ) {
				$movies = self::_get_movie_by_title( $query );

				if ( true === wpmoly_o( 'enable-cache' ) && ! is_wp_error( $movies ) ) {
					$expire = (int) ( 86400 * wpmoly_o( 'cache-expire' ) );
					set_transient( "wpmoly_movies_{$hash}", $movies, $expire );
				}
			}

			return $movies;
		}

		/**
		 * Cache method for _get_movie_by_id.
		 * 
		 * @see _get_movie_by_id()
		 * 
		 * @since    1.0
		 * 
		 * @param    string    $query Search parameters
		 * @param    string    $lang Lang to use in the query. Deprecated since 2.2
		 * @param    int       $post_id Related Post ID. Deprecated since 2.2
		 */
		public static function get_movie_by_id( $query, $lang = null, $post_id = null ) {

			$hash  = md5( serialize( $query ) );
			$movie = ( wpmoly_o( 'enable-cache' ) ? get_transient( "wpmoly_movie_{$hash}" ) : false );

			if ( false === $movie ) {
				$movie = self::_get_movie_by_id( $query );

				if ( true === wpmoly_o( 'enable-cache' ) && ! is_wp_error( $movie ) ) {
					$expire = (int) ( 86400 * wpmoly_o( 'cache-expire' ) );
					set_transient( "wpmoly_movie_{$hash}", $movie, 3600 * 24 );
				}
			}

			return $movie;
		}

		/**
		 * List all movies matching submitted title using the API's search
		 * method.
		 * 
		 * If no result were returned, display a notification. More than one
		 * results means the search is not accurate, display first results in
		 * case one of them matches the search and add a notification to try a
		 * more specific search. If only on movie showed up, it should be the
		 * one, call the API using the movie ID.
		 * 
		 * If more than one result, all movies listed will link to a new AJAX
		 * call to load the movie by ID.
		 *
		 * @since    1.0
		 * 
		 * @param    string    $query Search parameters
		 * @param    string    $lang Lang to use in the query. Deprecated since 2.2
		 * @param    int       $post_id Related Post ID. Deprecated since 2.2
		 */
		private static function _get_movie_by_title( $query, $lang = null, $post_id = null ) {

			$tmdb = new TMDb;
			$config = $tmdb->getConfig();

			$query = array(
				's'       => esc_attr( $query['s'] ),
				'lang'    => esc_attr( $query['lang'] ),
				'adult'   => esc_attr( $query['adult'] ),
				'year'    => esc_attr( $query['year'] ),
				'pyear'   => esc_attr( $query['pyear'] ),
				'post_id' => intval( $query['post_id'] ),
				'page'    => intval( $query['page'] )
			);

			$title  = preg_replace( '/[^\p{L}\p{N}\s]/u', '', trim( $query['s'] ) );
			$data   = $tmdb->searchMovie( $query );

			$error = new WP_Error();

			if ( is_wp_error( $data ) )
				return $data;

			$movies = array();

			if ( isset( $data['status_code'] ) ) {

				$error->add( esc_attr( $data['status_code'] ), esc_attr( $data['status_message'] ), $data );
			}
			else if ( ! isset( $data['total_results'] ) ) {

				$error->add( 'empty',  __( 'Sorry, your search returned no result. Try a more specific query?', 'wpmovielibrary' ), $data );
			}
			else if ( 1 == $data['total_results'] ) {

				$query['s'] = $data['results'][0]['id'];
				$movies = self::get_movie_by_id( $query );
			}
			else if ( $data['total_results'] > 1 ) {

				foreach ( $data['results'] as $movie ) {

					if ( ! is_null( $movie['poster_path'] ) )
						$movie['poster_path'] = self::get_image_url( $movie['poster_path'], 'poster', 'small' );
					else
						$movie['poster_path'] = str_replace( '{size}', '-medium', WPMOLY_DEFAULT_POSTER_URL );

					$movies[] = array(
						'id'             => $movie['id'],
						'poster'         => $movie['poster_path'],
						'title'          => $movie['title'],
						'original_title' => $movie['original_title'],
						'year'           => apply_filters( 'wpmoly_format_movie_date', $movie['release_date'], 'Y' ),
						'release_date'   => $movie['release_date'],
						'adult'          => $movie['adult']
					);
				}
			}

			return $movies;
		}

		/**
		 * Get movie by ID. Load casts and images too.
		 * 
		 * Return a JSON string containing fetched data. Apply some filtering
		 * to extract specific crew jobs like director or producer.
		 *
		 * @since    1.0
		 * 
		 * @param    string    $query Search parameters
		 * @param    string    $lang Lang to use in the query. Deprecated since 2.2
		 * @param    int       $post_id Related Post ID. Deprecated since 2.2
		 *
		 * @return   string    JSON formatted results.
		 */
		private static function _get_movie_by_id( $query, $lang = null, $post_id = null ) {

			$tmdb = new TMDb;

			$query = array(
				's'       => intval( $query['s'] ),
				'lang'    => esc_attr( $query['lang'] ),
				'adult'   => esc_attr( $query['adult'] ),
				'year'    => esc_attr( $query['year'] ),
				'pyear'   => esc_attr( $query['pyear'] ),
				'post_id' => intval( $query['post_id'] )
			);

			$data = array(
				'movie'   => $tmdb->getMovie( $query ),
				'casts'   => $tmdb->getMovieCast( $query['s'] ),
				'images'  => $tmdb->getMovieImages( $query['s'], $query['lang'] ),
				'release' => $tmdb->getMovieRelease( $query['s'] )
			);

			foreach ( $data as $d )
				if ( is_wp_error( $d ) )
					return $d;

			extract( $data, EXTR_SKIP );

			$poster_path = $movie['poster_path'];
			$tmdb_id = $movie['id'];
			$movie = apply_filters( 'wpmoly_filter_meta_data', $movie );
			$casts = apply_filters( 'wpmoly_filter_crew_data', $casts );
			$meta  = array_merge( $movie, $casts );
			$meta['tmdb_id'] = $tmdb_id;
			$meta['certification'] = '';

			if ( isset( $release['countries'] ) ) {
				$certification_alt = '';
				foreach ( $release['countries'] as $country ) {
					if ( $country['iso_3166_1'] == wpmoly_o( 'api-country' ) ) {
						$meta['certification'] = $country['certification'];
						$meta['local_release_date'] = $country['release_date'];
					}
					else if ( $country['iso_3166_1'] == wpmoly_o( 'api-country-alt' ) ) {
						$certification_alt = $country['certification'];
					}
				}

				if ( '' == $meta['certification'] )
					$meta['certification'] = $certification_alt;

				if ( '' == $meta['local_release_date'] )
					$meta['local_release_date'] = '';
			}

			if ( is_null( $poster_path ) )
				$poster_path = str_replace( '{size}', '-medium', WPMOLY_DEFAULT_POSTER_URL );

			if ( is_null( $poster_path ) )
				$poster = $poster_path;
			else
				$poster = self::get_image_url( $poster_path, 'poster', 'small' );

			if ( ! is_null( $post_id ) )
				$post = get_post( $post_id );

			// Prepare attachments
			$posters = apply_filters( 'wpmoly_jsonify_movie_images', $images['posters'], $post, $image_type = 'poster', $meta );
			$images  = apply_filters( 'wpmoly_jsonify_movie_images', $images['backdrops'], $post, $image_type = 'backdrop', $meta );

			// Prepare Taxonomies
			$actors = array();
			$genres = array();

			if ( ! empty( $meta['cast'] ) && wpmoly_o( 'enable-actor' ) && wpmoly_o( 'actor-autocomplete' ) ) {
				foreach ( $meta['cast'] as $actor ) {
					$actors[] = $actor;
				}
			}

			if ( ! empty( $meta['genres'] ) && wpmoly_o( 'enable-genre' ) && wpmoly_o( 'genre-autocomplete' ) ) {
				foreach ( $meta['genres'] as $genre ) {
					$genres[] = $genre;
				}
			}

			if ( ! empty( $meta['director'] ) && wpmoly_o( 'enable-collection' ) && wpmoly_o( 'collection-autocomplete' ) ) {
				foreach ( $meta['director'] as $director ) {
					$collections[] = $director;
				}
			}

			$taxonomies = compact( 'collections', 'genres', 'actors' );

			// Final data
			$movie = compact( 'meta', 'images', 'posters', 'taxonomies', 'poster', 'poster_path' );

			return $movie;
		}

		/**
		 * Load all available Images for a movie.
		 * 
		 * Filter the images returned by the API to exclude the ones we
		 * have already imported.
		 *
		 * @since    1.0
		 *
		 * @param    int    Movie TMDb ID
		 * 
		 * @return   array  All fetched images minus the ones already imported
		 */
		public static function get_movie_images( $tmdb_id ) {

			$tmdb = new TMDb;

			if ( is_null( $tmdb_id ) )
				return false;

			$images = $tmdb->getMovieImages( $tmdb_id, '' );
			if ( ! isset( $images['backdrops'] ) )
				return array();

			$images = $images['backdrops'];
			foreach ( $images as $i => $image ) {
				$file_path = substr( $image['file_path'], 1 );
				$exists = apply_filters( 'wpmoly_check_for_existing_images', $tmdb_id, 'image', $file_path );
				if ( false !== $exists )
					unset( $images[ $i ] );
			}

			return $images;
		}

		/**
		 * Load all available Posters for a movie.
		 * 
		 * Filter the posters returned by the API to exclude the ones we
		 * have already imported.
		 *
		 * @since    1.0
		 *
		 * @param    int    Movie TMDb ID
		 * 
		 * @return   array  All fetched posters minus the ones already imported
		 */
		public static function get_movie_posters( $tmdb_id ) {

			$tmdb = new TMDb;

			if ( is_null( $tmdb_id ) )
				return false;

			$images = $tmdb->getMovieImages( $tmdb_id, '' );
			if ( ! isset( $images['posters'] ) )
				return array();

			$images = $images['posters'];
			foreach ( $images as $i => $image ) {
				$file_path = substr( $image['file_path'], 1 );
				$exists = apply_filters( 'wpmoly_check_for_existing_images', $tmdb_id, 'poster', $file_path );
				if ( false !== $exists )
					unset( $images[ $i ] );
			}

			return $images;
		}

		/**
		 * Prepares sites to use the plugin during single or network-wide activation
		 *
		 * @since    1.0
		 *
		 * @param    bool    $network_wide
		 */
		public function activate( $network_wide ) {}

		/**
		 * Rolls back activation procedures when de-activating the plugin
		 *
		 * @since    1.0
		 */
		public function deactivate() {}

		/**
		 * Initializes variables
		 *
		 * @since    1.0
		 */
		public function init() {}

	}

endif;