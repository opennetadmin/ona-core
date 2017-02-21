ONA CLI Usage
===============

In the past ONA provided a CLI tool called `dcm.pl`.  This had many shortcomings when it came to security and was really just a wrapper around HTTP requests.  Its goal was to gather the human input and pass it to ONA, then take the output and hand it back to a human.  It also helped provide a bit of documentation of what modules were available and their options.

Now that there is a true rest interface a few things will change as it relates to a CLI interface for humans.

To start with, I intend to use a tool called [http://github.com/micha/resty](resty).  This tool provides a simple wrapper to generating `curl` commands to the rest endpoint.  I then intend to use the tool [http://github.com/stedolan/jq](jq) to help with the json parsing and pretty printing.

Since the rest interface itself does not provide documentation inline like the old `dcm.pl` tool did, you will have to rely on the API docs located at the rest.php endpoint location.  This is typically http://localhost/rest.php.  This documentation shows the endpoint and which options you can pass in as parameters, and which would be input such as form-data.

Keep in mind that the rest endpoint requires authentication using JWT tokens.  This means that you must use the `/login` endpoint to obtain a token, store that token securely and then use it as part of your `resty` calls.  Below I show an example usage to log in, set `resty` to the token and then query subnets, add a subnet, delete a subnet.

For now, the ONA rest API only requres the use of POST, GET, DELETE operations.

Please read the `resty` documentation for alternate ways to configure `resty` as well as pass in options.  The examples below are not the only ways to interface with the API.  Also the `jq` tool provides many options to parsing and utilizing the JSON data.

Install
-------
* apt-get install jq
* curl -L http://github.com/micha/resty/raw/master/resty > resty

Usage Examples
--------------

* Configure secure storage for your token
    touch ~/.onatoken;chmod 600 ~/.onatoken
* Tell this shell instance where the base API endpoint is that `resty` should use
    . resty -W http://localhost/rest.php/v1

* Log in to get your token and store it in a secure place
    POST /login -q "user=admin&pass=admin"|jq -r .token > ~/.onatoken
* Tell `resty` to send your token each time you make a call
    resty http://localhost/rest.php/v1 -H "Authorization: $(cat ~/.onatoken)"

* A short form of login without using a local file is this. It likely assumes you have already have run dotted in resty and set a base url.. this would be for re-login?
    resty http://localhost/rest.php/v1 -H "Authorization: $(POST /login -q "user=admin&pass=admin"|jq -r .token)"


* Get a list of all the subnets and pretty print it
    GET /subnets|jq
* Get a list of all subnets but only return the id and name for each one.
    GET /subnets -q "fields=id,name" |jq .subnets
* Add a new subnet using form-data in URL format
    POST /subnets -d 'name=BLAHTEST&type=13&ip=192.168.10.0&netmask=255.255.255.0'|jq
* or use discreate `-d` data fields
    POST /subnets -d name=BLAHTEST -d type=13 -d ip=192.168.10.0 -d netmask=255.255.255.0|jq
* Delete the subnet we just added
    DELETE /subnets/blahtest|jq
* Get a list of hostnames for all hosts that have a manufacturer of Cisco
    GET /hosts -q manufacturer=Cisco|jq -r '.hosts | .[].fqdn'
