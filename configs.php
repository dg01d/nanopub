<?php

return (object) array(
	// First some settings for the site
	'siteUrl' => 'yourlivebloghere.com',			// the URL for your liveBlog
	'timezone' => 'America/Vancouver',				// http://php.net/manual/en/timezones.php

	// Config Block for Twitter
	'twitterName' => 'poopyCakes',					// your twitter account name, don't use the @
	'twAPIkey' => 'WomtvR2YoT',						// Create an app on dev.twitter.com for your account.
	'twAPIsecret' => 'NILIDJXg1e',					// APIkey & APIsecret are the APP's key & Secret
	'twUserKey' => 'ILs4jUS7a6',					// UserKey & User Secret are under 'Your access token'
	'twUserSecret' => 'NYbGUfuNUh',					// Generate those on dev.twitter.com

	// Config Block for 10Centuries
	'tenCauthtoken' => 'yopEPNfq34',				// your 10Centuries.org API key. see 10C API guide.

	// Config Block for Mastodon
	'mastodonInstance' => 'servername.ext',			// your Mastodon Instance
	'mastodonToken' => 'uWo42Bca91',				// get an auth code using Mastodon docs

	// Config for micro.blog
	'pingMicro' => True 							// Set to False (boolean) if you don't use micro.blog
);

?>

