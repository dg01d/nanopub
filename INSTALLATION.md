
Installation
------------

### System Requirements

PHP 7.1+ is required, and the php environment requires php-curl, php-mbstring & php-ctype.

### From Source
Clone the contents of this repository, then copy the `*.php` and the `composer.*` files into your sites **output** folder. If your Static Site Generator wipes the output folder on each run, then there will be some way to make these files be copied on each run -- Hugo, for example, uses the `static` folder.

Since the 1.2 release, nanopub requires the use of [Composer](https://getcomposer.org/). Getting it to do all the things I wanted it to do was getting _way_ beyond my skill level. Install the dependencies with

```
$ composer install
```

If composer complains about outdated dependencies, just ignore that, the dependencies are set in the `composer.lock` file.

### From .zip

Download the release .zip file from [here](https://github.com/dg01d/nanopub/releases). Unzip, and place the contents in your site's output folder. 

### Configuration

Then edit the included `configs.php` file to enable various features. The full file, including comments is as follows:

```
	// First some settings for the site
	'siteUrl' => 'https://example.com/',			// the URL for your site - note trailing slash
	'sitePath' ==> '',										// the path to your site, appended to URL, note trailing slash
	'timezone' => 'Europe/London',					// http://php.net/manual/en/timezones.php
	'mediaPoint' => 'https://media.org/endpoint',	// Micropub Media Endpoin
    'tokenPoint' => 'https://tokens.indieauth.com/token',	// IndieAuth Token Endpoint
	'storageFolder' => '../content',						// the folder to store the posts in
	'trashFolder' => '../trash',							// the folder to move removed posts into
	
	// Config Block for Twitter -- Used only for XRay for rich context replies
	'twAPIkey' => 'WomtvR2YoT',						// Create an app on dev.twitter.com for your account.
	'twAPIsecret' => 'NILIDJXg1e',					// APIkey & APIsecret are the APP's key & Secret
	'twUserKey' => 'ILs4jUS7a6',					// UserKey & User Secret are under 'Your access token'
	'twUserSecret' => 'NYbGUfuNUh',					// Generate those on dev.twitter.com

	// Config Block for Mastodon
	'mastodonInstance' => 'servername.ext',			// your Mastodon Instance
	'mastodonToken' => 'uWo42Bca91',				// get an auth code using Mastodon docs

	// Config for micro.blog
	'pingMicro' => True, 							// Set to False (boolean) if you don't use micro.blog
	'siteFeed' => 'https://example.com/atom.xml',	// Set to your site's RSS/Atom Feed to notify micro.blog

	// Config for Weather. If you do want weather feature, set to true 
    // The tracker system that the author has used is Aaron Parecki's [Compass](https://github.com/aaronpk/Compass)
    // but other systems are available.
    // This also uses DarkSky's API to get the actual weather data.
    'weatherToggle' => false,
	'compass' => 'https://private.tracker.com/api',
	'compassKey' => 'PrivateAPIkey',
	'forecastKey' => 'DarkSkyApiKey',
	'defaultLat' => '51.5074',
	'defaultLong' => '0.1278',
	'defaultLoc' => 'London',

	// Set Frontmatter Format -- json or yaml
	'frontFormat' => 'json'
```

### Notes

- Mastodon Keys are more command-line driven, but relatively straightforward. See [the Mastodon API documentation](https://github.com/tootsuite/documentation/blob/master/Using-the-API/Testing-with-cURL.md)
- As Mastodon is a federated network, you do need to _explicitly_ specify your Mastodon Instance.
- If using the micro.blog function, you need to specify your site's rss/atom feed.
- To use the weather data, you'll need to add access data for a location-tracking endpoint (like a self-hosted version of [Compass](https://github.com/aaronpk/Compass)) and a DarkSky [API Key](https://darksky.net/dev/docs)
- Twitter Keys can be obtained using the [Create New App](https://apps.twitter.com/app/new) on twitter.
- Since 1.5, you can configure the frontmatter format for your posts. Currently the options are json, used in [Hugo](https://gohugo.io/content-management/front-matter/), or yaml, optionally used in Hugo, but required by other static engines such as [Jekyll](https://jekyllrb.com/docs/frontmatter/) or [Metalsmith](http://www.metalsmith.io). This could be used with other generators, such as [Pelican](docs.getpelican.com/en/stable/content.html), but the script would need to be changed to meet their specific format requirements.


You will need to set your site up with the requisite micropub-enabling headers to:

- Use an identity/token service for user authentication (the configs file uses [IndieAuth](https://indieauth.com/setup))
- Identify `nanopub.php` as your site's [micropub endpoint](https://indieweb.org/Micropub#How_to_implement)

On my site, these headers are provided as follows:

```html

<!-- indieweb components  -->
  <link rel="authorization_endpoint" href="https://indieauth.com/auth" />
  <link rel="token_endpoint" href="https://tokens.indieauth.com/token" />
  <link rel="micropub" href="https://ascraeus.org/nanopub.php" />
```

### Data Structure

The location of the data store - where the script places your posts - is set with the `storageFolder` and `trashFolder` config options. This can be set relative to the working directory, or to an absolute path. On my site, I have configured the data store as follows:-

```
/srv
|-- output
|-- content
|    |-- micro
|    |-- article
|    |-- like
|    |-- link
|-- trash
```
