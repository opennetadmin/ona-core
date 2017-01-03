conversion
==========




These are items that should be covered when converting modules to the new ona-core

* rename the file so it does not include .inc, when you move it to lib
* update all printmsg.
  * remove any DEBUG => type text
  * change or add a level.. should be a string, not an int.

* switch human text output to return an array
* update version to be a 2.x.. basically reset to 2.00
* ensure you are returning a 'status_code' and a 'status_msg'
* remove options['help'].. it is goin to be a swagger doc
* update function comment/document section
* write a swagger doc entry for the API


v1 to ona-core
==============

* need to take values out of sys_config table and put them in etc/config.php $conf.
  * is there a helper tool to do this for us that could be created?
