Developer info and standards
============================

This doc will likely be a mess but the goal is to try and document some development methods I'm using here.

API Documentation
=================

I am currently using apiary.io API schema method (api-blueprint).  The file `ona-api-spec.apib` should be updated to reflect any changes to the API specification.

Once the file is updated, an updated version of the html document should be produced. Currently using aglio to do this:
* `aglio -i apiary.apib -o www/api-doc.html`

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


