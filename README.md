MicroPub support for Static Blog Engine
=======================================

This php script provides micropub support for the [Hugo Static Blog Engine](https://gohugo.io). Incoming posts are rewritten to a [JSON-header Hugo Format](http://gohugo.io/content/front-matter/) and saved to the user's content store.

Currently, the script will handle notes, articles, replies, checkins and bookmarks. It is not configured at this time for RSVPs.

Script additionally syndicates content to external silos. Currently it provides syndication to [Twitter](https://twitter.com) and [Mastodon](https://mastodon.social).

Note that this is a WIP, and it provides a solution to my specific requirements. The code is all pretty self-explanatory, and can be adjusted easily to meet different needs.

Installation
------------

Clone the contents into the `static` folder of your Hugo installation.

You'll need to set your site up with the requisite headers to:

- Use [IndieAuth](https://indieauth.com/setup) as an identity/token service
- Identify `micropub.php` as your site's [micropub endpoint](https://indieweb.org/Micropub#How_to_implement)

On my site, these headers are provided as follows:

```html

<!-- indieweb components 
  ------------------------------------------------- -->
  <link rel="authorization_endpoint" href="https://indieauth.com/auth" />
  <link rel="token_endpoint" href="https://tokens.indieauth.com/token" />
  <link rel="micropub" href="https://ascraeus.org/nanopub.php" />

```

Then you'll have to configure the options in `configs.php`

- Twitter Keys can be obtained using the [Create New App](https://apps.twitter.com/app/new) on twitter.
- Mastodon Keys are more command-line driven, but relatively straightforward. See [the Mastodon API documentation](https://github.com/tootsuite/documentation/blob/master/Using-the-API/Testing-with-cURL.md)
- As Mastodon is a federated network, you do need to explicitly specify your Mastodon Instance.

TODO
----

* [X] Actually get the content to save in the correct format.
* [ ] Trigger Hugo on succesful operation.
* [ ] Make it work with a more complete set of micropub features
* [ ] Make a `setup.php` script to complete the required configuration settings.

See
---
* Author: Daniel Goldsmith <https://ascraeus.org>
* The IndieAuth validation sequence was taken from [Amy Guy's Minimal Micropub](https://rhiaro.co.uk/2015/04/minimum-viable-micropub), without which I couldn't have done this.

Licences
--------

This is released under the Free Public Licence 1.0.0. The included and unmodified [TwitterAPIExchange.php](https://github.com/J7mbo/twitter-api-php) is Copyright (c) 2013 James Mallison (j7mbo.co.uk) and under an MIT Licence.
 