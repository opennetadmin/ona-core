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
        global $self, $conf;

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
            $errmsg = 'Token issue: '. $e->getMessage();
            $self['error'] = '{"status_code": 1, "status_msg": "'. $errmsg. '" }';
            printmsg($errmsg, 'error');
            $response = $response->withStatus(403)->write($self['error']);
            return $response;
        }

              // Get the user name etc from the JWT token and then look up perms
              $username = $newtoken->getClaim('username');

        // First check that token audience is the same as the client it comes from
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

  public function __construct($user,$noexpire) {
    global $conf;

    // This method should be unique enough for our purposes.
    $jti=uniqid();

    // Sign token with hmac
    $signer = new Sha256();
    $this->token = (new Builder())->setIssuer($_SERVER['SERVER_NAME'].'-'.$_SERVER['SERVER_ADDR'])
                            // Using client IP address to be validated during use
                            ->setAudience($user.'-'.$_SERVER['REMOTE_ADDR']) // (aud claim)
                            ->setId($jti , true) // the id (jti claim) to revoke if needed
                            ->setIssuedAt(time()) // time that the token was issue (iat claim)
                            ->setNotBefore(time()) // time that the token can be used (nbf claim)
                            ->setExpiration(time() + 3600) // expiration time of the token (nbf claim)
                            ->set('username', $user) // Set username for later lookup
                            ->sign($signer, $conf['token_signing_key'])
                            ->getToken();
  }


  // Send the token back to the login endpoint
  public function __toString() {
    return "$this->token";
  }
}
 


