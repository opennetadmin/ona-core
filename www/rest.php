<?php

// Load our initialization library
require_once('../lib/initialize.php');



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

// Define routes
$app->group('/v1', function () {

  $this->group('/subnets', function () {
    new ONA\controllers\subnets($this);
    $this->map(['GET', 'DELETE', 'POST'], '', 'ONA\controllers\subnets:Any');

    $this->group('/{subnet}', function () {
      $this->map(['GET', 'DELETE', 'POST'], '', 'ONA\controllers\subnets:Specific');
      $this->map(['GET', 'DELETE', 'POST'], '/tags', 'ONA\controllers\subnets:tags');
      // This is an alt method for tags.. seems 'simpler'? decide if we keep it. not 'consistant'
      $this->map(['GET', 'DELETE', 'POST'], '/tags/{name}', 'ONA\controllers\subnets:tags');
      $this->map(['GET', 'DELETE', 'POST'], '/ca', 'ONA\controllers\subnets:ca');
    });
  });

});

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

  // If there is no status message from the module, create a null place holder
  // The assumption here is that if you dont set a message then there was not a useful one to provide.
  // this may or may not imply that the status code is 0.. maybe we test for that?
  if (!isset($newoutput['status_msg'])) $newoutput['status_msg'] = null;

  ksort($newoutput);

  return($newoutput);
}








/*
class SubnetController {
   protected $ci;
   //Constructor
   public function __construct(ContainerInterface $ci) {
       $this->ci = $ci;
       require_once('subnet.inc.php');
   }
   

   public function any($request, $response, $args) {

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         $output = process_output(subnets($args));
         break;
       case 'POST':
         $output = process_output(subnet_add($request->getParsedBody()));
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }


     return $response->withJson($output);
   }



   public function specific($request, $response, $args) {

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         $output = process_output(subnet_display($args));
         break;
       case 'DELETE':
         $output = process_output(subnet_del($args));
         break;
       case 'POST':
         $output = process_output(subnet_modify($args + (array)$request->getParsedBody()));
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }


   
      
   public function getTags($request, $response, $args) {
     $response->getBody()->write('tags');
     return $response;
   }

   public function getCustomAttributes($request, $response, $args) {
     $response->getBody()->write('custom Attributes');
     return $response;
   }
}
*/
