<?php

namespace ONA\auth;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\ValidationData;
use Lcobucci\JWT\Signer\Hmac\Sha256;


/*
* Hey, its login time
* This is called by the /login endpoint which should only be used via HTTPS
* user should pass in user and pass in the query
*
* The login with validate user/pass using whatever auth method is configured in $conf['authtype']
* It will then store the user name in the token for later lookup against permissions
* this ensures that even if a token has been granted to a user, it will still be a
* fresh lookup each time it is used to validate perms for that user.
*
*/
class login {

  public function __invoke($request, $response, $next) {
    global $self, $conf;

    // Get the parameters sent in
    $query = $request->getQueryParams();
    // if nothing, check the body
    if (!$query['user'])
      $query = $request->getParsedBody();

    // Perform the actual authentication of user/pass
    list($status,$authmsg) = get_authentication($query['user'],$query['pass']);

    // Assuming user/pass auth works, generate a JWT token and send it to the client for storage
    if ($status === true) {
      // Generate JWT token for this user
      $token = new buildtoken($query['user']);
      $self['error'] = '{"status_code": 0, "status_msg": "Token generation successful", "token": "'. $token. '" }';
    } else {
      $self['error'] = '{"status_code": 1, "status_msg": "'. $authmsg. '" }';
    }

    $response = $response->write($self['error'])
                         ->withHeader('Content-type', 'application/json;charset=utf-8');
    return $response;
  }

}


// Validate the JWT token that was sent in via the Authorization header.
class tokenauth {
 
    public function __invoke($request, $response, $next) {
        global $self, $conf, $onadb;

        // Get the hmac signing info
        $signer = new Sha256();

        $authorized = false;
        $response = $response->withStatus(403)
                             ->withHeader('Content-type', 'application/json;charset=utf-8')
                             ->withHeader('X-Authenticated', 'False');

        // Get the token from the HTTP header
        $token = $request->getHeader('Authorization');

        // If we didnt get a token from the client then error with that message
        if ($token == NULL) {
          $errmsg = 'Authentication token not sent';
          $self['error'] = '{"status_code": 1, "status_msg": "'.$errmsg.'" }';
          printmsg($errmsg, 'error');
          $response = $response->withStatus(401)
                               ->write($self['error']);
          return $response;
        }

        // Parse the token and get the data out of it
        try {
          $newtoken = (new Parser())->parse((string) $token[0]); // Parses from a string
          $data = new ValidationData(); 
        } catch (Exception $e) {
            // Catch any errors with the token
            $errmsg = 'Token error: '. $e->getMessage();
            $self['error'] = '{"status_code": 1, "status_msg": "'. $errmsg. '" }';
            printmsg($errmsg, 'error');
            $response = $response->withStatus(403)->write($self['error']);
            return $response;
        }

        // Get the user name etc from the JWT token and then look up perms
        $username = $newtoken->getClaim('username');

        // If this token is a perminent token, check if it is enabled
        if ($newtoken->getClaim('exp') > 3000000000) {
          $jti = $newtoken->getClaim('jti');
          // Get our perm token from the database
          list($status, $rows, $jwt_perm) = db_get_record($onadb,
                                                         'jwt_perm_tokens',
                                                         "jti like '{$jti}'"
                                            );
          // Update an access time for the perm token
          list($status, $rows, $atime) = db_update_record($onadb,
                                                         'jwt_perm_tokens',
                                                         array('jti' => $jti),
                                                         array('atime' => date_mangle(time()))
                                         );

          // Check the perm token to see if it is enabled
          if ($jwt_perm['enabled'] == 0) {
            $errmsg = "Token access has been revoked: jti={$jti}";
            $self['error'] = '{"status_code": 1, "status_msg": "'. $errmsg. '" }';
            printmsg($errmsg, 'error');
            $response = $response->withStatus(403)->write($self['error']);
            return $response;
          }
        }

        // Check that token audience is the user-clientip that it was made for
        // Validate the token is signed and ok, if so continue with authentication/authorization
        if ($newtoken->getClaim('aud') == "{$username}-{$_SERVER['REMOTE_ADDR']}" ) {
          if ($newtoken->verify($signer, $conf['token_signing_key'])) {
            if ($newtoken->validate($data)) {
              $authorized = true;
              $response = $response->withStatus(200)
                                   ->withHeader('X-Authenticated', 'True');
              // Get permissions from ONA tables
              get_perms($username);
              printmsg("Token validated for user: $username", 'info');
            }
          }
        }
 
        // One last check that we are authorized
        if(!$authorized){
            $response = $response->withStatus(403)
                                 ->write('{"status_code": 1, "status_msg": "Token invalid. Please obtain a new auth token from the /login endpoint"}');
        } else {
          // Token is good, move on to the next part
          $response = $next($request, $response);
        }
        return $response;
    }
}



// This class will create a new JWT token specific for the user
class buildtoken {

  public function __construct($user) {
    global $conf, $onadb;

    // This method should be unique enough for our purposes.
    $jti=uniqid();

    // Get user record
    list($status, $rows, $userrecord) = db_get_record($onadb, 'users', "username like '{$user}'");

    // If the user has token_expire, set a huge future time, otherwise use conf setting
    if ($rows) {
      if ($userrecord['token_expire'] == 0) {
        $expiretime = 2000000000; // Some time past the year 2080

        // Disable current token for this aud
        list($status, $rows) = db_update_record(
          $onadb,
          'jwt_perm_tokens',
          array('aud' => $user.'-'.$_SERVER['REMOTE_ADDR'], 'enabled' => 1), array('enabled' => 0)
        );

        // Add new record that is enabled for this jti
        list($status, $rows) = db_insert_record(
          $onadb,
          'jwt_perm_tokens',
          array('jti' => $jti, 'aud' => $user.'-'.$_SERVER['REMOTE_ADDR'])
        );

      } else {
        $expiretime = $conf['token_expire_time'];
      }
    }

    // Sign token with hmac
    $signer = new Sha256();
    // Create the actual token for this aud and jti
    $this->token = (new Builder())->setIssuer($_SERVER['SERVER_NAME'].'-'.$_SERVER['SERVER_ADDR'])
                            // username and client IP address to be validated during use
                            ->setAudience($user.'-'.$_SERVER['REMOTE_ADDR']) // (aud claim)
                            ->setId($jti , true) // the id (jti claim) to revoke if needed
                            ->setIssuedAt(time()) // time that the token was issue (iat claim)
                            ->setNotBefore(time()) // time that the token can be used (nbf claim)
                            ->setExpiration(time() + $expiretime) // expiration time of the token (nbf claim)
                            ->set('username', $user) // Set username for later lookup
                            ->sign($signer, $conf['token_signing_key'])
                            ->getToken();

  }

  // Send the token back to the login endpoint
  public function __toString() {
    return "$this->token";
  }
}
 


