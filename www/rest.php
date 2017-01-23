<?php

// Load our initialization library
require_once(__DIR__.'/../lib/initialize.php');


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
use ONA\auth;


// Initiate slim
$app = new \Slim\App($slimconfig);



// Load all the dynamic plugin controllers
$plugin_controllers = plugin_list('controller');
foreach ($plugin_controllers as $p) {
  require_once($p['path']);
}





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
$app->post('/v1/login', 'ONA\auth\login');

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

})->add(new ONA\auth\tokenauth());
 
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


