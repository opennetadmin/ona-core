API
===

The API that is exposed is a rest api. The following details will help you use it.

location: https://<onacoreserver>/rest.php/v1/

supports json input/ouput.. That is the primary format and others will not be supported at this time.  it should be easy enough for any client to do a conversion to other formats as desired.

The following HTTP methods are used
- GET: Gather and display information
- POST: Allows you to add or modify data within the system
- DELETE: Of coures will delete data within the system
- PUT: This is not currently a supported method. Use POST. While not strictly restful, the modules are not currently written to do full resource updates. 
- PATCH: This is not currently supported. Again the POST method should be used.

All methods should return http 400 'bad request' on an error.  Basically anything not a 0 status.

A POST that adds a resource should return the data it just created, it should also return a URL(s) that could be used to do a GET on what was just created.  it should also return an HTTP 201 which means successful create.

All ONA modules should return a 'status_msg' containing a human readable message explaining that status. Some responces could return a null status message value. This is typically the case for messages that were successful.

Additially a 'status_code' will also be returned. A code of zero is a successful status code and indicates all is well. Any other value > 0 indicates some sort of error.  You can use this in clients to determine quickly if things are working properly.

Authentication
==============

* first off.. use https.. it will not be secure without it.
* All transactions must be authenticated with a JWT API Token.
* To get a token pass a username and password to the login endpoint
  * `POST https://onaserver/rest.php/v1/login?user=username&pass=password`
* There are two two types of user accounts
  * Standard
    * A typical human user. They will get a token that expires at an interval defined in configuration. That default timeframe is 8 hours.
  * Service account
    * These are app tokens used for server to server communications. The tokens for these users do not expire.  The token ID is tracked both for access time and if it is enabled or not.  Only one token per user per client is allowed to be active.
    * if a new token is issued for the user on a client that already had a token issued, the old token will be automatically disabled and a new token issued
    * The last access time of a perminant token is tracked whether enabled or disabled. This facilitates managment of these tokens over time.

