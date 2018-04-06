<?php
/**
 * Authentication class
 * Helper functions for extracting tokens from the WP-API team Oauth2 plugin
 */
class IndieAuth_Authenticate {

	public $error = null;
	public function __construct() {
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 11 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ) );
		add_filter( 'login_form_defaults', array( $this, 'login_form_defaults' ), 10, 1 );
		add_filter( 'gettext', array( $this, 'register_text' ), 10, 3 );
		add_action( 'authenticate', array( $this, 'authenticate' ), 10, 2 );
		add_action( 'authenticate', array( $this, 'authenticate_url_password' ), 20, 3 );

		add_action( 'send_headers', array( $this, 'http_header' ) );
		add_action( 'wp_head', array( $this, 'html_header' ) );
	}

	public static function http_header() {
		header( sprintf( 'Link: <%s>; rel="authorization_endpoint"', get_option( 'indieauth_authorization_endpoint' ) ), false );
		header( sprintf( 'Link: <%s>; rel="token_endpoint"', get_option( 'indieauth_token_endpoint' ) ), false );
	}
	public static function html_header() {
		printf( '<link rel="authorization_endpoint" href="%s" />' . PHP_EOL, get_option( 'indieauth_authorization_endpoint' ) );
		printf( '<link rel="token_endpoint" href="%s" />' . PHP_EOL, get_option( 'indieauth_token_endpoint' ) );
	}


	/**
	 * Report our errors, if we have any.
	 *
	 * Attached to the rest_authentication_errors filter. Passes through existing
	 * errors registered on the filter.
	 *
	 * @param WP_Error|null Current error, or null.
	 *
	 * @return WP_Error|null Error if one is set, otherwise null.
	 */
	public function rest_authentication_errors( $error = null ) {
		if ( ! empty( $error ) ) {
			return $error;
		}
		return $this->error;
	}

	public function login_form_defaults( $defaults ) {
		$defaults['label_username'] = __( 'Username, Email Address, or URL', 'indieauth' );
		return $defaults;
	}

	public function register_text( $translated_text, $untranslated_text, $domain ) {
		if ( 'Username or Email Address' === $untranslated_text ) {
			$translated_text = __( 'Username, Email Address, or URL', 'indieauth' );
		}
		return $translated_text;
	}

	public function determine_current_user( $user_id ) {
		// If the Indieauth endpoint is being requested do not use this authentication method
		if ( strpos( $_SERVER['REQUEST_URI'], '/indieauth/1.0' ) ) {
			return $user_id;
		}
		$token = $this->get_provided_token();
		if ( ! $token ) {
			return $user_id;
		}
		$me = $this->verify_access_token( $token );
		if ( ! $me ) {
			return $user_id;
		}
		$user = get_user_by_identifier( $me );
		if ( $user instanceof WP_User ) {
			return $user->ID;
		}
		$this->error = new WP_Error(
			'indieauth.user_not_found', __( 'User Not Found on this Site', 'indieauth' ),
			array(
				'status'   => '401',
				'response' => $me,
			)
		);
		return $user_id;

	}

	public function verify_access_token( $token ) {
		$args     = array(
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
		);
		$response = wp_safe_remote_get( get_option( 'indieauth_token_endpoint', rest_url( 'indieauth/1.0/token' ) ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 2 !== (int) ( $code / 100 ) ) {
			$this->error = new WP_Error(
				'indieauth.invalid_access_token',
				__( 'Supplied Token is Invalid', 'indieauth' ),
				array(
					'status'   => $code,
					'response' => $body,
				)
			);
			return false;
		}
		$params = json_decode( $body, true );
		global $indieauth_scopes;
		$indieauth_scopes = explode( ' ', $params['scope'] );
		return $params['me'];
	}

	public static function generate_state() {
		$state = wp_generate_password( 128, false );
		$value = wp_hash( $state, 'nonce' );
		setcookie( 'indieauth_state', $value, current_time( 'timestamp' ) + 120, '/', false, true );
		return $state;
	}

	/**
	 * Redirect to Authorization Endpoint for Authentication
	 *
	 * @param string $me URL parameter
	 * @param string $redirect_uri where to redirect
	 *
	 */
	public function authorization_redirect( $me, $redirect_uri ) {
		$endpoints = indieauth_discover_endpoint( $me );
		if ( ! $endpoints ) {
			return new WP_Error(
				'authentication_failed',
				__( '<strong>ERROR</strong>: Could not discover endpoints', 'indieauth' ),
				array(
					'status' => 401,
				)
			);
		}
		$authorization_endpoint = null;
		if ( isset( $endpoints['authorization_endpoint'] ) ) {
			$authorization_endpoint = $endpoints['authorization_endpoint'];
		}
		$state = $this->generate_state();
		$query = add_query_arg(
			array(
				'me'            => rawurlencode( $me ),
				'redirect_uri'  => rawurlencode( $redirect_uri ),
				'client_id'     => rawurlencode( home_url() ),
				'state'         => $state,
				'response_type' => 'id',
			),
			$authorization_endpoint
		);
		// redirect to authentication endpoint
		wp_redirect( $query );
	}

	public static function verify_authorization_code( $code, $redirect_uri, $client_id = null ) {
		if ( ! $client_id ) {
			$client_id = home_url();
		}
		$args     = array(
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'code'         => $code,
				'redirect_uri' => $redirect_uri,
				'client_id'    => $client_id,
			),
		);
		$response = wp_remote_post( get_option( 'indieauth_authorization_endpoint' ), $args );
		if ( $error = get_oauth_error( $response ) ) {
			// Pass through well-formed error messages from the authorization endpoint
			return $error;
		}
		$code     = wp_remote_retrieve_response_code( $response );
		$response = wp_remote_retrieve_body( $response );

		$response = json_decode( $response, true );
		// check if response was json or not
		if ( ! is_array( $response ) ) {
			return new WP_OAuth_Response( 'server_error', __( 'The authorization endpoint did not return a JSON response', 'indieauth' ), 500 );
		}

		if ( 2 === (int) ( $code / 100 ) && isset( $response['me'] ) ) {
			// The authorization endpoint acknowledged that the authorization code 
			// is valid and returned the authorization info
			return $response;
		}

		// got an unexpected response from the authorization endpoint
		$error = new WP_OAuth_Response( 'server_error', __( 'There was an error verifying the authorization code, the authorization server return an expected response', 'indieauth' ), 500 );
		$error->set_debug( array( 'debug' => $response ));
		return $error;
	}

	/**
	 * Verify State
	 *
	 * @param string $state
	 *
	 * @return boolean|WP_Error
	 */
	public function verify_state( $state ) {
		if ( ! isset( $_COOKIE['indieauth_state'] ) ) {
			return false;
		}
		$value = $_COOKIE['indieauth_state'];
		setcookie( 'indieauth_state', '', current_time( 'timestamp' ) - 1000, '/', false, true );
		if ( wp_hash( $state, 'nonce' ) === $value ) {
			return true;
		}
		return new WP_Error( 'indieauth_state_error', __( 'IndieAuth Server did not return the same state parameter', 'indieauth' ) );
	}

	/**
	 * Authenticate user to WordPress using URL and Password
	 *
	 */
	public function authenticate_url_password( $user, $url, $password ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}
		if ( empty( $url ) || empty( $password ) ) {
			if ( is_wp_error( $user ) ) {
				return $user;
			}
			$error = new WP_Error();

			if ( empty( $url ) ) {
				$error->add( 'empty_username', __( '<strong>ERROR</strong>: The URL field is empty.', 'indieauth' ) ); // Uses 'empty_username' for back-compat with wp_signon()
			}

			if ( empty( $password ) ) {
				$error->add( 'empty_password', __( '<strong>ERROR</strong>: The password field is empty.', 'indieauth' ) );
			}

			return $error;
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return $user;
		}
		$user = get_user_by_identifier( $url );

		if ( ! $user ) {
			return new WP_Error(
				'invalid_url',
				__( '<strong>ERROR</strong>: Invalid URL.', 'indieauth' ) .
				' <a href="' . wp_lostpassword_url() . '">' .
				__( 'Lost your password?', 'indieauth' ) .
				'</a>'
			);
		}

		/** This filter is documented in wp-includes/user.php */
		$user = apply_filters( 'wp_authenticate_user', $user, $password );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return new WP_Error(
				'incorrect_password',
				sprintf(
					/* translators: %s: url */
					__( '<strong>ERROR</strong>: The password you entered for the URL %s is incorrect.', 'indieauth' ),
					'<strong>' . $url . '</strong>'
				) .
				' <a href="' . wp_lostpassword_url() . '">' .
				__( 'Lost your password?', 'indieauth' ) .
				'</a>'
			);
		}

		return $user;
	}

	/**
	 * Authenticate user to WordPress using IndieAuth.
	 *
	 * @action: authenticate
	 * @param mixed $user authenticated user object, or WP_Error or null
	 * @return mixed authenticated user object, or WP_Error or null
	 */
	public function authenticate( $user, $url ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}
		$redirect_to = array_key_exists( 'redirect_to', $_REQUEST ) ? $_REQUEST['redirect_to'] : null;
		$redirect_to = rawurldecode( $redirect_to );
		if ( ! empty( $url ) && array_key_exists( 'indieauth_identifier', $_POST ) ) {
			$me = esc_url_raw( $url );
			// Check for valid URLs https://indieauth.spec.indieweb.org/#user-profile-url
			if ( ! wp_http_validate_url( $me ) ) {
				return new WP_Error( 'indieauth_invalid_url', __( 'Invalid User Profile URL', 'indieauth' ) );
			}
			$return = $this->authorization_redirect( $me, wp_login_url( $redirect_to ) );
			if ( is_wp_error( $return ) ) {
				return $return;
			}
		} elseif ( array_key_exists( 'code', $_REQUEST ) && array_key_exists( 'state', $_REQUEST ) ) {
			$state = $this->verify_state( $_REQUEST['state'] );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$response = $this->verify_authorization_code( $_REQUEST['code'], wp_login_url( $redirect_to ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$user = get_user_by_identifier( $response['me'] );
			if ( ! $user ) {
				$user = new WP_Error( 'indieauth_registration_failure', __( 'Your have entered a valid Domain, but you have no account on this blog.', 'indieauth' ) );
			}
		}
					return $user;
	}

	/**
	 * Get the authorization header
	 *
	 * On certain systems and configurations, the Authorization header will be
	 * stripped out by the server or PHP. Typically this is then used to
	 * generate `PHP_AUTH_USER`/`PHP_AUTH_PASS` but not passed on. We use
	 * `getallheaders` here to try and grab it out instead.
	 *
	 * @return string|null Authorization header if set, null otherwise
	 */
	public function get_authorization_header() {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		}
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			// Check for the authorization header case-insensitively
			foreach ( $headers as $key => $value ) {
				if ( strtolower( $key ) === 'authorization' ) {
					return $value;
				}
			}
		}
		return null;
	}

			/**
	 * Extracts the token from the authorization header or the current request.
	 *
	 * @return string|null Token on success, null on failure.
	 */
	public function get_provided_token() {
		$header = $this->get_authorization_header();
		if ( $header ) {
			return $this->get_token_from_bearer_header( $header );
		}
		$token = $this->get_token_from_request();
		if ( $token ) {
			return $token;
		}
		return null;
	}
			/**
	 * Extracts the token from the given authorization header.
	 *
	 * @param string $header Authorization header.
	 *
	 * @return string|null Token on success, null on failure.
	 */
	public function get_token_from_bearer_header( $header ) {
		if ( is_string( $header ) && preg_match( '/Bearer ([\x20-\x7E]+)/', trim( $header ), $matches ) ) {
			return $matches[1];
		}
		return null;
	}
			/**
	 * Extracts the token from the current request.
	 *
	 * @return string|null Token on success, null on failure.
	 */
	public function get_token_from_request() {
		if ( empty( $_GET['access_token'] ) ) {
			return null;
		}
		$token = $_GET['access_token'];
		if ( is_string( $token ) ) {
			return $token;
		}
		return null;
	}
}
