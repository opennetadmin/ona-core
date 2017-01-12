<?php

// Load our initialization library
require_once(__DIR__.'/../lib/initialize.php');



use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\ValidationData;
use Lcobucci\JWT\Signer\Hmac\Sha256;

class TokenAuth {
 
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

        // First check that token audience is the same as the client it comes from
        // Validate the token is signed and ok, if so continue with authentication/authorization
        if ($newtoken->getClaim('aud') == $_SERVER['REMOTE_ADDR'] ) {
          if ($newtoken->verify($signer, $conf['token_signing_key'])) {
            if ($newtoken->validate($data)) {
              $authorized = true;
              $response = $response->withStatus(200)
                                   ->withHeader('X-Authenticated', 'True');
              // Get the user name etc from the JWT token and then look up perms
              $username = $newtoken->getClaim('username');
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



/*

POST = add, update
GET = display
DELETE = del

PUT = full resource update.. all fields.. not how things function without a big rewrite
PATCH = modify (or put but patch seems better)

Both of these options are not possible without big rewrite.  also slim does not pass requestbody for these two, only post.  
I have chosen to use POST for everything, as we are simply passing input to my modules.
its not strictly restful but apparently few actually are.  I will compromize here for now.

All types should return http 400 'bad request' on an error.  I think all errors should? basically anything not a 0 status.

a post that adds a resource should return the data it just created, it should also return a URL(s) that could be used to do a GET on what was just created.  it should also return an HTTP 201 which means successful create.

a post that updates a resource should return 

*/

use ONA\controllers;


// Initiate slim
$app = new \Slim\App($slimconfig);

// Make the root path redirect to document
$app->get('/', function ($request, $response) {
  return $response->withRedirect ((string)($request->getUri()->withPath('/api-doc.html')));
});




/*
* Hey, its login time
* This should only be used via HTTPS 
* user should pass in user and pass in the query
*
* The login with validate user/pass using whatever auth method is configured in $conf['authtype']
* It will then store the user name in the token for later lookup against permissions
* this ensures that even if a token has been granted to a user, it will still be a
* fresh lookup each time it is used to validate perms for that user.
*
*/
$app->post('/v1/login', function ($request, $response) {
  global $conf;

  // Get the parameters sent in
  $query = $request->getQueryParams();

  // Perform the actual authentication of user/pass
  list($status,$authmsg) = get_authentication($query['user'],$query['pass']);

  // assuming user/pass auth works, generate a JWT token and send it to the client for storage
  // need to pass in permission info into the JWT.. or at least the groups so it can be looked up
  if ($status === true) {
    // Sign token with hmac
    $signer = new Sha256();
    $token = (new Builder())->setIssuer($_SERVER['SERVER_NAME'].'-'.$_SERVER['SERVER_ADDR'])
                            // Configures the audience (aud claim)
                            // Using client IP address to be validated during use
                            ->setAudience($_SERVER['REMOTE_ADDR']) 
                            // Not setting jti for now, could use to revoke if needed
  #                          ->setId('4f1g23a12aa', true) // the id (jti claim)
                            ->setIssuedAt(time()) // time that the token was issue (iat claim)
                            ->setNotBefore(time() + 5) // time that the token can be used (nbf claim)
                            ->setExpiration(time() + 3600) // expiration time of the token (nbf claim)
                            ->set('username', $query['user'])
                            ->sign($signer, $conf['token_signing_key'])
                            ->getToken(); // Retrieves the generated token

#    $token->getHeaders(); // Retrieves the token headers
#    $token->getClaims(); // Retrieves the token claims
    $self['error'] = '{"status_code": 0, "status_msg": "Token generation successful", "token": "'. $token. '" }';
  } else {
    $self['error'] = '{"status_code": 1, "status_msg": "'. $authmsg. '" }';
  }

  $response = $response->write($self['error'])
                       ->withHeader('Content-type', 'application/json;charset=utf-8');
  return $response;

});

// Define routes
$app->group('/v1', function () {

  $this->group('/subnets', function () {
    new ONA\controllers\subnets($this);
    $this->map(['GET', 'POST'], '', 'ONA\controllers\subnets:Any');

    $this->group('/{subnet}', function () {
      $this->map(['GET', 'DELETE', 'POST'], '', 'ONA\controllers\subnets:Specific');
      $this->map(['GET', 'DELETE', 'POST'], '/tags', 'ONA\controllers\subnets:tags');
      // This is an alt method for tags.. seems 'simpler'? decide if we keep it. not 'consistant'
     # $this->map(['GET', 'DELETE', 'POST'], '/tags/{name}', 'ONA\controllers\subnets:tags');
      $this->map(['GET', 'DELETE', 'POST'], '/ca', 'ONA\controllers\subnets:ca');
      $this->map(['GET', 'DELETE', 'POST'], '/dhcpserver', 'ONA\controllers\subnets:dhcp');
      $this->map(['GET', 'DELETE', 'POST'], '/dhcppool', 'ONA\controllers\subnets:dhcp');
      $this->map(['GET', 'DELETE', 'POST'], '/dhcpoption', 'ONA\controllers\subnets:dhcp');
    });
  });

})->add(new TokenAuth());
 
// Run our slim app
$app->run();







// This cleans up our output comming from the sub modules
function process_output($output) {

  $newoutput=array();
  // If all we got was a string back from module
  if (is_string($output[1])) {
    $newoutput['status_msg'] = $output[1];
  } else {
    $newoutput = $output[1];
  }
  $newoutput['status_code'] = $output[0];

  // If there is no status message from the module, create an empty place holder
  // The assumption here is that if you dont set a message then there was not a useful one to provide.
  // this may or may not imply that the status code is 0.. maybe we test for that?
  if (!isset($newoutput['status_msg'])) $newoutput['status_msg'] = '';

  ksort($newoutput);

  return($newoutput);
}


