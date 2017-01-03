<?php

namespace ONA\controllers;

class Subnets {
   //Constructor
   public function __construct($app) {
       require_once($GLOBALS['base'].'/lib/modules/subnets.php');
   }


   public function Any($request, $response, $args) {

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



   public function Specific($request, $response, $args) {

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




   public function tags($request, $response, $args) {

     // Get the tag module
     require_once($GLOBALS['base'].'/lib/modules/tags.php');

     $args['reference']=$args['subnet'];
     $args['type']='subnet';

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         //$output = process_output(subnet_display($args));
         break;
       case 'DELETE':
         $output = process_output(tag_del($args + (array)$request->getParsedBody()));
         break;
       case 'POST':
         $output = process_output(tag_add($args + (array)$request->getParsedBody()));
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }

   public function ca($request, $response, $args) {
     // Get the custom attribute module
     require_once($GLOBALS['base'].'/lib/modules/custom_attributes.php');

     $args['reference']=$args['subnet'];
     $args['type']='subnet';

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         //$output = process_output(subnet_display($args));
         break;
       case 'DELETE':
         $output = process_output(custom_attribute_del($args + (array)$request->getParsedBody()));
         break;
       case 'POST':
         $output = process_output(custom_attribute_add($args + (array)$request->getParsedBody()));
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }

}
