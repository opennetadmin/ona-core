Developer info and standards
============================

This doc will likely be a mess but the goal is to try and document some development methods I'm using here.

API Documentation
=================

I am currently using apiary.io API schema method (api-blueprint).  The file `apiary.apib` should be updated to reflect any changes to the API specification.

Once the file is updated, an updated version of the html document should be produced. Currently using aglio to do this:
* `aglio -i apiary.apib -o www/docs.html`

## API design

I tried to follow many of the suggestions listed here: http://www.vinaysahni.com/best-practices-for-a-pragmatic-restful-api
Another link that provides good information is this: http://blog.mwaysolutions.com/2014/06/05/10-best-practices-for-better-restful-api/

Items I am aware of but have specifically left for later
* sort:  I like the option, not sure it is really needed?  probably will come back and implement later.
* paging: Again seems useful but I'm not sure the volumes of data warrent its need.  Again I'll probably implement later.
* next/prev: this fits with paging.. would be good to have.. might even be able to use it on say, a single subnet could show the previous and next subnets numericly?
* url links: should do more link information to help with flow. should this be in headers or payload? get a better strategy on when to provide them and what they look like.
* versioning: should it be done in the URL or in the header.. good arguments for both, for now I'm in path.
* filtering: Gahh.. so first off, most of the querying that exists currently is string based anyway.  no real need for gt,lt,range operations there. Exceptions would be dates, ip addresses (numeric and ranges), possible how many hosts/interfaces/dns records etc are related. It seems most of this is just nice to haves??? What I do need is an ability to search for multiple tags/ca for instance, not just one.

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


