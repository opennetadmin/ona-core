Developer info and standards
============================

This doc will likely be a mess but the goal is to try and document some development methods I'm using here.


releasing
=========
I'm currently using a date based version.. vYY.MM.DD.

When a release is ready to go it should be tagged.  I intend to have a githook that will update the version file with that tag version info.  So it really should come down to following std git practice and doing propper tagging to release versions.

The command `git describe` should get what you need


Modules:
========

modules are the name of the functions that implement the actual work.. blah.. words.

Module versions for all functions related to the new ona-core environment should be in the 2.x range. The original modules are of the 1.x series.

Module versions should be updated each time a change is made to that module. For example you would change version 2.01 to 2.02 as part of your code update to that module.


