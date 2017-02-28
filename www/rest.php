<?php


/*
some future ideas:
* make the GUI itself just a plugin to ona-core.  if it is installed the rest.php file will
  take you to it instead of the api-docs.  this may mean its better to just forgo the symlink and rest.php name
* what does this mean for a stand alone GUI install?  it would need to be in /opt/ona-core/www/local/plugins/gui?? or can it stand on its own with a vhost config
*/

// Load our initialization library
require_once(__DIR__.'/../lib/initialize.php');

// Load up our ONA namespace bits
use ONA\controllers;
use ONA\auth;

// Initiate slim
$app = new \Slim\App($slimconfig);

// Load all the dynamic plugin controllers
// This must be done after $app is initialized as a new Slim App
$plugin_controllers = plugin_list('controller');
foreach ($plugin_controllers as $p) {
  require_once($p['path']);
}

// Make the root path and base version paths redirect to documention
$app->get('/{version:|v1|v2}', function ($request, $response) {
  return $response->withRedirect ((string)($request->getUri()->withPath('/docs.html')));
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
      $this->map(['GET'], '/nextip', 'ONA\controllers\subnets:nextip');
    });
  });

  $this->group('/domains', function () {
    new ONA\controllers\domains($this);
    $this->map(['GET', 'POST'], '', 'ONA\controllers\domains:Any');

    $this->group('/{domain}', function () {
      $this->map(['GET', 'DELETE', 'POST'], '', 'ONA\controllers\domains:Specific');
    });
  });

  $this->group('/dns_records', function () {
    new ONA\controllers\dns_records($this);
    $this->map(['GET', 'POST'], '', 'ONA\controllers\dns_records:Any');

    $this->group('/{dns_record}', function () {
      $this->map(['GET', 'DELETE', 'POST'], '', 'ONA\controllers\dns_records:Specific');
    });
  });

  $this->group('/interfaces', function () {
    new ONA\controllers\interfaces($this);
    $this->map(['GET', 'POST'], '', 'ONA\controllers\interfaces:Any');

    $this->group('/{interface}', function () {
      $this->map(['GET', 'DELETE', 'POST'], '', 'ONA\controllers\interfaces:Specific');
    });
  });

  $this->group('/hosts', function () {
    new ONA\controllers\hosts($this);
    $this->map(['GET', 'POST'], '', 'ONA\controllers\hosts:Any');

    $this->group('/{host}', function () {
      $this->map(['GET', 'DELETE', 'POST'], '', 'ONA\controllers\hosts:Specific');
      $this->map(['GET', 'DELETE', 'POST'], '/tags', 'ONA\controllers\hosts:tags');
      // This is an alt method for tags.. seems 'simpler'? decide if we keep it. not 'consistant'
     # $this->map(['GET', 'DELETE', 'POST'], '/tags/{name}', 'ONA\controllers\hosts:tags');
      $this->map(['GET', 'DELETE', 'POST'], '/ca', 'ONA\controllers\hosts:ca');

      $this->group('/interfaces', function () {
        new ONA\controllers\interfaces($this);
        $this->map(['GET', 'POST'], '', 'ONA\controllers\interfaces:Any');

        ## This could get weird, the host portion of the path really doesnt matter here??
        $this->group('/{interface}', function () {
          $this->map(['GET', 'DELETE', 'POST'], '', 'ONA\controllers\interfaces:Specific');
        });
      });
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


