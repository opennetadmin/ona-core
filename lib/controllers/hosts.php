<?php

namespace ONA\controllers;

class hosts {
   //Constructor
   public function __construct($app) {
       require_once($GLOBALS['base'].'/lib/modules/hosts.php');
   }


   public function Any($request, $response, $args) {

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         $output = process_output(run_module('hosts', $args + (array)$request->getQueryParams()));
         break;
       case 'POST':
         $output = process_output(run_module('host_add', $request->getParsedBody()));
         $response = $response->withStatus(201);
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
         $output = process_output(run_module('host_display', $args + (array)$request->getQueryParams()));
         break;
       case 'DELETE':
         $output = process_output(run_module('host_del', $args));
         break;
       case 'POST':
         $output = process_output(run_module('host_modify', $args + (array)$request->getParsedBody()));
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }




   public function tags($request, $response, $args) {

     require_once($GLOBALS['base'].'/lib/modules/tags.php');

     $args['reference']=$args['host'];
     $args['type']='host';

     // Process various method types
     switch ($request->getMethod()) {
       #case 'GET':
       #  //$output = process_output(run_module('host_display', $args));
       #  break;
       case 'DELETE':
         $output = process_output(run_module('tag_del', $args + (array)$request->getParsedBody()));
         break;
       case 'POST':
         $output = process_output(run_module('tag_add', $args + (array)$request->getParsedBody()));
         $response = $response->withStatus(201);
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }



   public function ca_any($request, $response, $args) {

     require_once($GLOBALS['base'].'/lib/modules/custom_attributes.php');

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         $output = process_output(run_module('custom_attribute_display', $args + (array)$request->getParsedBody()));
         break;
       case 'POST':
         $output = process_output(run_module('custom_attribute_add', $args + (array)$request->getParsedBody()));
         $response = $response->withStatus(201);
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }





   public function ca_specific($request, $response, $args) {

     require_once($GLOBALS['base'].'/lib/modules/custom_attributes.php');

     // Process various method types
     switch ($request->getMethod()) {
       case 'GET':
         $output = process_output(run_module('custom_attribute_display', $args + (array)$request->getParsedBody()));
         break;
       case 'DELETE':
         $output = process_output(run_module('custom_attribute_del', $args + (array)$request->getParsedBody()));
         break;
       case 'POST':
         $args['set_type']=$args['type'];
         $output = process_output(run_module('custom_attribute_modify', $args + (array)$request->getParsedBody()));
         break;
     }

     // update status code on errors
     if ($output['status_code'] > 0) {
       return $response->withJson($output)->withStatus(400);
     }

     return $response->withJson($output);
   }

}
