var hooks = require('hooks');
var stash = {};

hooks.log('Starting ONA hooks');

// hook to retrieve session on a login
hooks.after('Login > Auth > Login', function (transaction) {
  stash['token'] = JSON.parse(transaction.real.body)['token'];
  //hooks.log('found token: ' + stash['token']);
});

// hook to set the session cookie in all following requests
hooks.beforeEach(function (transaction) {
  if(stash['token'] != undefined){
    transaction.request['headers']['Authorization'] = stash['token'];
  };
});
