<?php
/**
 * Authorize class
 * Helper functions for extracting tokens from the WP-API team Oauth2 plugin
 */
class IndieAuth_Local_Authorize extends IndieAuth_Authorize {


	public function __construct() {
		$this->load();
	}

	public function get_authorization_endpoint() {
		return rest_url( '/indieauth/1.0/auth' );
	}

	public function get_token_endpoint() {
		return rest_url( '/indieauth/1.0/token' );
	}


	public function verify_access_token( $token ) {
		$tokens = new Token_User( '_indieauth_token_' );
		$return = $tokens->get( $token );
		if ( ! $return ) {
			return new WP_OAuth_Response(
				'invalid_token',
				__( 'Invalid access token', 'indieauth' ),
				401
			);
		}
		if ( is_oauth_error( $return ) ) {
			return $return;
		}
		$return['last_accessed'] = time();
		$tokens->update( $token, $return );
		return $return;
	}

	public static function verify_authorization_code( $code ) {
		$tokens = new Token_User( '_indieauth_code_' );
		$return = $tokens->get( $code );
		if ( ! $return ) {
			return new WP_OAuth_Response(
				'invalid_code',
				__( 'Invalid authorization code', 'indieauth' ),
				401
			);
		}
		// Once the code is verified destroy it
		$tokens->destroy( $code );
		return $return;
	}

}

new IndieAuth_Local_Authorize();
