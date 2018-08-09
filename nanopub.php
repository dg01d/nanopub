<?php
/**
 * nanopub - MicroPub support for Static Blog Engine
 *
 * PHP version 7
 *
 * @author   Daniel Goldsmith <dgold@ascraeus.org>
 * @license  https://opensource.org/licenses/FPL-1.0.0 0BSD
 * @link     https://github.com/dg01d/nanopub
 * @category Micropub
 * @version  2.0.0
 */

require 'vendor/autoload.php';
require 'helpers.php';
use GuzzleHttp\Client;
use Forecast\Forecast;
use Symfony\Component\Yaml\Yaml;

/**
 * Load the settings from the configuration file
 */

$configs = include 'configs.php';
$siteUrl = $configs->siteUrl;
$siteFeed = $configs->siteFeed;
$weatherToggle = $configs->weatherToggle;
date_default_timezone_set($configs->timezone);
define("FRONT", $configs->frontFormat);
$udate = date('U', time());
$cdate = date('c', time());

$xray = new p3k\XRay();

/**
 * API call function. This could easily be used for any modern writable API
 *
 * @param string $url   adressable url of the external API
 * @param string $auth  authorisation header for the API
 * @param array $adata  php array of the data to be sent
 *
 * @return HTTP response from API
 */
function post_to_api($url, $auth, $adata)
{
    $fields = '';
    foreach ($adata as $key => $value) {
        $fields .= $key . '=' . $value . '&';
    }
    rtrim($fields, '&');
    $post = curl_init();
    curl_setopt($post, CURLOPT_URL, $url);
    curl_setopt($post, CURLOPT_POST, count($adata));
    curl_setopt($post, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($post, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt(
        $post, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: '.$auth
        )
    );
    $result = curl_exec($post);
    curl_close($post);
    return $result;
}

/**
 * Replaces getallheaders() for nginx
 *
 * Replaces the getallheaders function which relies on Apache
 *
 * @return array incoming headers from _POST
 */

if (!function_exists('getallheaders')) {
    function getallheaders() 
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

/**
 * Test for associative arrays
 *
 * @param array $array to be tested
 *
 * @return boolean true if associative
 */
function isAssoc($array)
{
    $array = array_keys($array);
    return ($array !== array_keys($array));
}

/**
 * Validate incoming requests, using IndieAuth
 *
 * This section largely adopted from rhiaro
 *
 * @param array $headers All headers from an incoming connection request
 *
 * @return boolean true if authorised
 */
function indieAuth($headers)
{
    global $configs;
    /**
     * Check token is valid
     */
    $token = $headers['authorization'];
    $ch = curl_init($configs->tokenPoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt(
        $ch, CURLOPT_HTTPHEADER, Array(
        //"Content-Type: application/x-www-form-urlencoded",
        "Accept: application/json",
        "Authorization: $token"
        )
    );
    $responseBody = strval(curl_exec($ch));
    curl_close($ch);

    $response = json_decode($responseBody, true, 2);
    if (!is_array($response) || json_last_error() !== \JSON_ERROR_NONE) {
        parse_str($responseBody, $response);
    }

    $scopes = isset($response['scope']) ? explode(' ', $response['scope']) : array();

    if (empty($response) || isset($response['error'])) {
        header("HTTP/1.1 401 Unauthorized");
        echo 'The request lacks authentication credentials';
        exit;
    } elseif (!isset($response['me']) || $response['me'] !== $configs->siteUrl) {
        header("HTTP/1.1 401 Unauthorized");
        echo 'The request lacks valid authentication credentials';
        exit;
    } elseif (!in_array('create', $scopes) && !in_array('post', $scopes)) {
        header("HTTP/1.1 403 Forbidden");
        echo 'Client does not have access to this resource';
        exit;
    } else {
        return true;
    }
}

/**
 * Function to replace keys in an array with values from a different one.
 *
 * Used here to rewrite keys from Hugo's format to microformats2 syntax.
 *
 * @param array $array      the array of Hugo key => value frontmatter elements
 * @param array $keys       an associative array, pairing key values
 * @param boolean $filter   boolean switch, if true, values not present in $keys are removed
 *
 * @return array associative with keys in mf2 values
 */
function array_replace_keys($array, $keys, $filter)
{
    $newArray = array();
    foreach ($array as $key => $value) {
        if (isset($keys[$key])) {
            $newArray[$keys[$key]] = $value;
        } elseif (!$filter) {
            $newArray[$key] = $value;
        }
    }
    return $newArray;
}

/**
 * Reads existing Hugo files and rearranges them to the
 * format required by the micropub specification.
 *
 * @param string $textFile the content file, with json/yaml frontmatter
 * @param array $mfArray   Array of SSG <=> mf2 field equivalents
 * @param boolean $bool    boolean to determine if non-equivalent keys are stripped
 *
 * @return array structured array from a text file with json frontmatter
 */
function decode_input($textFile, $mfArray, $bool)
{
    $topArray = explode("\n\n", $textFile);
    if (FRONT == "yaml") {
        $fileArray = Yaml::parse($topArray[0]);
    } else {
        $fileArray = json_decode($topArray[0], true);
    }
    $fileArray["content"] = rtrim($topArray[1]);
    $newArray = array();
    /*
     * All values must be arrays in mf2 syntax
     */
    foreach ($fileArray as $key => $value) {
        if (!is_array($value)) {
            $value = [$value];
        }
        $newArray[$key] = $value;
    }
    $newArray = array_replace_keys($newArray, $mfArray, $bool);
    return $newArray;
}

/**
 * Rewrites micropub-compliant structure as a Hugo file.
 *
 * @param  array $array      array of mf2-compliant fieldnames
 * @param  array $mfArray    array of SSG <=> mf2 field equivalents
 * @return array $postarray  with SSG fieldnames
 */
function recode_output($array, $mfArray) 
{
    $postArray = array();
    // These cannot be arrays in Hugo syntax, but are in mf2
    $singles = array("name", "published", "slug", "content");
    foreach ($array as $key => $value) {
        if (in_array($key, $singles)) {
            $value = $value[0];
        }
        $postArray[$key] = $value;
    }
    $postArray = array_replace_keys($postArray, $mfArray, false);
    return $postArray;
}

/**
 * Writes dataset to file.
 * @since 1.1
 * Put here to allow extension for different post-types in future.
 *
 * @param array $frontmatter Frontmatter for the file (e.g. title, slug)
 * @param string $content    The content of the file/post/reply
 * @param string $fn         The filename of the file to be written
 */
function write_file($frontmatter, $content, $fn)
{
    $frontmatter = array_filter($frontmatter);
    if (FRONT == "yaml") {
        $yaml = Yaml::dump($frontmatter);
        $frontFinal = "---\n" . $yaml . "---\n\n";
    } else {
        $frontFinal = json_encode($frontmatter, $jsonFormat) . "\n\n";
    }

    $dir = pathinfo($fn, PATHINFO_DIRNAME);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($fn, $frontFinal);
    file_put_contents($fn, $content, FILE_APPEND | LOCK_EX);
}

// This variable is used for the json_encode() functions later in the script.
// You can change these depending on your needs.

$jsonFormat = JSON_PRETTY_PRINT | JSON_NUMBER_CHECK | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

// This array pairs Hugo namespace with mf2 namespace.
$mfArray = array(
    "date" => "published",
    "tags" => "category",
    "replyto" => "in-reply-to",
    "link" => "bookmark-of",
    "title" => "name",
    "content" => "content"
);

// GET Requests:- config, syndicate to & source

// Tell Micropub clients where I can syndicate to
if (isset($_GET['q']) && $_GET['q'] == "syndicate-to") {
    $array = array(
        "syndicate-to" => array(
            0 => array(
                "uid" => "https://".$configs->mastodonInstance,
                "name" => "Mastodon"
            )
        )
    );

    header('Content-Type: application/json');
    echo json_encode($array, $jsonFormat);
    exit;
}

// Offer micropub clients full configuration
if (isset($_GET['q']) && $_GET['q'] == "config") {
    $array = array(
        "media-endpoint" => $configs->mediaPoint,
        "syndicate-to" => array(
            0 => array(
                "uid" => "https://".$configs->mastodonInstance,
                "name" => "Mastodon"
            )
        )
    );

    header('Content-Type: application/json');
    echo json_encode($array, $jsonFormat);
    exit;
}

// Take headers and other incoming data
$headers = getallheaders();
if ($headers === false ) {
    header("HTTP/1.1 400 Bad Request");
    echo 'The request lacks valid headers';
    exit;
}
$headers = array_change_key_case($headers, CASE_LOWER);
$data = array();
if (!empty($_POST['access_token'])) {
    $token = "Bearer ".$_POST['access_token'];
    $headers["authorization"] = $token;
}
if (isset($_POST['h'])) {
    $h = $_POST['h'];
    unset($_POST['h']);
    $data = [
        'type' => ['h-'.$h],
        'properties' => array_map(
            function ($a) {
                return is_array($a) ? $a : [$a];
            }, $_POST
        )
    ];
} else {
    $data = json_decode(file_get_contents('php://input'), true);
}


// Offer micropub clients source material
if (isset($_GET['q']) && $_GET['q'] == 'source') {
    // As this is requesting the source of a post,
    // seek indieAuth validation of the request
    if (indieAuth($headers)) {
        if (!empty($_GET['url'])) {
            $subj = urldecode($_GET['url']);
            $pattern = "#$siteUrl#";
            $repl = "";
            $srcUri = preg_replace($pattern, $repl, $subj);
            $srcUri = rtrim($srcUri, "/");
            if ($textFile = file_get_contents($configs->storageFolder . "/$srcUri.md")) {

                //send file for decoding
                $jsonArray = decode_input($textFile, $mfArray, true);

                $respArray = array (
                    "type" => ["h-entry"],
                    "properties" => $jsonArray
                    );
                header('Content-Type: application/json');
                echo json_encode($respArray, 128);
                exit;
            } else {
                header("HTTP/1.1 404 Not Found");
                echo "Source file not found";
                exit;
            }
        }
    }
}

if (!empty($data)) {
    if (indieAuth($headers)) {
        if (empty($data['properties']['content']['0']) 
            && empty($data['properties']['like-of']['0']) 
            && empty($data['properties']['repost-of']['0']) 
            && empty($data['properties']['checkin']['0']['type']['0'])
        ) {
            // If this is a POST and there's no action listed, 400 exit
            if (empty($data['action'])) {
                header("HTTP/1.1 400 Bad Request");
                echo "Missing content";
                exit; 
            } else {
                // now we need to start getting the information for actions
                if (!empty($data['action'])) {
                    $action = $data['action'];
                    $subj = urldecode($data['url']);
                }
                // This is based on _my_ content position. This converts
                // URLs to local disk
                $pattern = "#".$siteUrl."#";
                $repl = "";
                $srcUri = preg_replace($pattern, $repl, $subj);
                $srcUri = rtrim($srcUri, "/");
                // First delete if asked to
                if ($action == "delete") {
                    if (!is_dir($configs->trashFolder)) {
                        mkdir($configs->trashFolder, 0777, true);
                    }
                    rename($configs->storageFolder . "/$srcUri.md", $configs->trashFolder . "/$srcUri.md");
                    header("HTTP/1.1 204 No Content");
                    exit;
                }
                // then an undelete
                if ($action == "undelete") {
                    rename($configs->trashFolder . "/$srcUri.md", $configs->storageFolder . "/$srcUri.md");
                    header("HTTP/1.1 201 Created");
                    header("Location: ".$siteUrl.$srcUri);
                    exit;
                }
                // Update can be one of a number of different actions
                if ($action == "update") {
                    // Updating, so need to read the existing file
                    if ($textFile = file_get_contents($configs->storageFolder . "/$srcUri.md")) {
                        //send file for decoding
                        $jsonArray = decode_input($textFile, $mfArray, false);

                        // Now we perform the different update actions,
                        // Replace being first.

                        if (array_key_exists("replace", $data)) {
                            if (is_array($data['replace'])) {
                                foreach ($data['replace'] as $key => $value) {
                                    $newVal = [$key => $value];
                                    $jsonArray = array_replace($jsonArray, $newVal);
                                }
                            } else {
                                header("HTTP/1.1 400 Bad Request");
                                echo "Value of replace key must be an array";
                                exit;
                            }
                        }

                        // Adding a value

                        if (array_key_exists("add", $data)) {
                            if (is_array($data['add'])) {
                                foreach ($data['add'] as $key => $value) {
                                    $newVal = [$key => $value['0']];
                                    $jsonArray = array_merge_recursive($jsonArray, $newVal);
                                }
                            } else {
                                header("HTTP/1.1 400 Bad Request");
                                echo "Value of add key must be an array";
                                exit;
                            }
                        }

                        // Delete a property based on key

                        if (array_key_exists("delete", $data)) {
                            if (is_array($data['delete'])) {
                                if (isAssoc($data['delete'])) {
                                    foreach ($data['delete'] as $key => $value) {
                                        $newVal = [$key => $value['0']];
                                        $pos = array_keys($newVal)['0'];
                                        $jsonArray[$pos] = array_diff($jsonArray[$pos], $newVal);
                                    }
                                } else { // delete an overall property
                                    $pos = $data['delete']['0'];
                                    unset($jsonArray[$pos]);
                                }
                            } else {
                                header("HTTP/1.1 400 Bad Request");
                                echo "Value of delete key must be an array";
                                exit;
                            }
                        }

                        // Tasks completed, write back to original file

                        $jsonArray = recode_output($jsonArray, array_flip($mfArray));

                        $content = $jsonArray['content'];
                        unset($jsonArray['content']);
                        $fn = $configs->storageFolder . "/$srcUri.md";
                        write_file($jsonArray, $content, $fn);
                        header("HTTP/1.1 200 OK");
                        echo json_encode($jsonArray, 128);

                    } else {
                        header("HTTP/1.1 404 Not Found");
                        echo "That url does not exist";
                    }
                }
            }
        } else {
            // This handles new publications.
            // Starts setting up some variables used throughout

            $frontmatter = [];

            // Starting with checkins. These require a lot of metadata.
            // Structure is based on OwnYourSwarm's json payload

            if (!empty($data['properties']['checkin'])) {
                $chkProperties = $data['properties']['checkin']['0']['properties'];
                if (!empty($chkProperties['url']['1'])) {
                    $frontmatter['checkurl'] = $chkProperties['url']['1'];
                } else {
                    $frontmatter['checkurl'] = $chkProperties['url']['0'];
                }

                $frontmatter['checkloc'] = $chkProperties['name']['0'];

                if ($chkProperties['locality']['0'] != $chkProperties['region']['0']) {
                    $frontmatter['checkadd'] = $chkProperties['locality']['0'] . ', ' . $chkProperties['region']['0'];
                } else {
                    $frontmatter['checkadd'] = $chkProperties['street-address']['0'] . ', ' . $chkProperties['locality']['0'];
                }

                $frontmatter['latitude'] = $chkProperties['latitude']['0'];
                $frontmatter['longitude'] = $chkProperties['longitude']['0'];

                // All properties are extracted, so the checkin can be deleted
                unset($data['properties']['checkin']);

                // Next bit creates a map and uploads it to media endpoint

                $mapname = 'images/file-'.date('YmdHis').'-'.mt_rand(1000, 9999).'.png';
                $url = 'http://atlas.p3k.io/map/img?marker[]=lat:'.$frontmatter['latitude'].';lng:'.$frontmatter['longitude'].';icon:small-red-cutout&basemap=osm&attribution=none&width=600&height=240&zoom=14';
                file_put_contents($mapname, file_get_contents($url));
                $frontmatter['map'] = $mapname;

                // Now to take out the checkins usual properties

                $content = $data['properties']['content']['0'] ?? null;
                unset($data['properties']['content']);
                $frontmatter['checkin'] = $data['properties']['syndication']['0'];
                unset($data['properties']['syndication']);
                $frontmatter['date'] = $data['properties']['published']['0'];
                unset($data['properties']['published']['0']);
                $frontmatter['slug'] = $udate;
                unset($data['properties']['access_token']);
                foreach ($data['properties'] as $key => $value) {
                    $frontmatter[$key] = $value;
                }
            } else {
                // Begin Processing non-checkin material
                $props = $data['properties'];
                unset($props['access_token']);

                // Client has syndication powers!
                $synds = $props['mp-syndicate-to'] ?? null;
                unset($props['mp-syndicate-to']);

                // Non-notes tend to have a name or title
                $frontmatter['title'] = $props['name']['0'] ?? null;
                unset($props['name']);

                // Bookmark-of 
                $frontmatter['link'] = $props['bookmark-of']['0'] ?? null;
                unset($props['bookmark-of']);

                // First attempt at 'like-of'
                $frontmatter['like_of'] = $props['like-of'] ?? null;
                if (is_array($frontmatter['like_of'])) {
                    $frontmatter['like_of'] = $frontmatter['like_of']['0'];
                }

                if (isset($frontmatter['like_of'])) {
                    $frontmatter['like_site'] = hostname_of_uri($frontmatter['like_of']);
                    $url_parse = xray_machine($frontmatter['like_of'], $frontmatter['like_site']);
                    if ($frontmatter['like_site'] == 'twitter.com') {
                        $synds['0'] = "https://twitter.com";
                    }
                }
                unset($props['like-of']);

                // First attempt at 'repost-of'
                $frontmatter['repost_of'] = $props['repost-of'] ?? null;
                if (is_array($frontmatter['repost_of'])) {
                    $frontmatter['repost_of'] = $frontmatter['repost_of']['0'];
                }
                if (isset($frontmatter['repost_of'])) {
                    $frontmatter['repost_site'] = hostname_of_uri($frontmatter['repost_of']);
                    $url_parse = xray_machine($frontmatter['repost_of'], $frontmatter['repost_site']);
                }
                unset($props['repost-of']);

                // indieweb 'reply-to'
                $frontmatter['replytourl'] = $props['in-reply-to'] ?? null;
                if (is_array($frontmatter['replytourl'])) {
                    $frontmatter['replytourl'] = $frontmatter['replytourl']['0'];
                }
                if (isset($frontmatter['replytourl'])) {
                    $frontmatter['replysite'] = hostname_of_uri($frontmatter['replytourl']);
                    $url_parse = xray_machine($frontmatter['replytourl'], $frontmatter['replysite']);
                }
                unset($props['in-reply-to']);

                // server allows client to set a slug
                if (!empty($props['mp-slug']['0'])) {
                    $frontmatter['slug'] = $props['mp-slug']['0'];
                    unset($props['mp-slug']);
                } elseif (!empty($frontmatter['title']['0'])) {
                    $frontmatter['slug'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $frontmatter['title'])));
                } else {
                    $frontmatter['slug'] = $udate;
                }
                // Bug: Issue #8 / #7 probably linked
                if (is_array($frontmatter['slug'])) {
                    $frontmatter['slug'] = $frontmatter['slug']['0'];
                }

                // Hugo does not store content in the frontmatter
                $content = $props['content']['0'] ?? null;
                unset($props['content']);
                if (is_array($content)) {
                    $content = $content['html'];
                }
                $frontmatter['summary'] = $props['summary']['0'] ?? null; 
                unset($props['summary']);

                // server allows clients to set category, treats as tags 
                $frontmatter['tags'] = $props['category'] ?? null;
                unset($props['category']);

                // Specific logic here for OwnYourGram
                $frontmatter['photo'] = $props['photo'] ?? null;
                unset($props['photo']);

                $frontmatter['instagram'] = (isset($props['syndication']) && in_array("https://www.instagram.com/p", $props['syndication']['0'])) ? $props['syndication']['0'] : null;

                // PESOS (like OYG / OYS) already has a datestamp
                $frontmatter['date'] = $props['published']['0'] ?? $cdate;
                unset($props['published']);

                foreach ($props as $key => $value) {
                    $frontmatter[$key] = $value;
                }

                // First Attempt at Weather Data
                if ($weatherToggle) {
                    $weather = getWeather();
                    $frontmatter = array_merge($frontmatter, $weather);
                }
                if (isset($url_parse)) {
                    $frontmatter = array_merge($frontmatter, $url_parse);
                }
            }

            /*  First established the type of Post in nested order bookmark->article->note
             *  Note that this is hardcoded to my site structure and post-kinds. Obviously,
             *  $fn will need to be changed for different structures/kinds
             */

            if (!empty($frontmatter['title'])) {
                // File locations are specific to my site for now.
                if (!empty($frontmatter['link'])) {
                    $fn = $configs->storageFolder . "/link/" . $frontmatter['slug'] . ".md";
                    $canonical = $configs->siteUrl . "link/" . $frontmatter['slug'];
                    $synText = $frontmatter['title'];
                } else {
                    $fn = $configs->storageFolder . "/article/" . $frontmatter['slug'] . ".md";
                    $canonical = $configs->siteUrl . "article/" . $frontmatter['slug'];
                    $synText = $frontmatter['title'];
                }
            } else { 
                if (!empty($frontmatter['repost_of'])) {
                    $fn = $configs->storageFolder . "/like/" . $frontmatter['slug'] . ".md";
                    $canonical = $configs->siteUrl . "like/" . $frontmatter['slug'];
                    $synText = $content;
                } elseif (!empty($frontmatter['like_of'])) {
                    $fn = $configs->storageFolder . "/like/" . $frontmatter['slug'] . ".md";
                    $canonical = $configs->siteUrl . "like/" . $frontmatter['slug'];
                    $synText = $content;
                } else {
                    $fn = $configs->storageFolder . "/micro/" . $frontmatter['slug'] . ".md";
                    $canonical = $configs->siteUrl . "micro/" . $frontmatter['slug'];
                    $synText = $content;
                }
            }

            // Syndication Posting to different services

            // first Mastodon, count limit 500
            if (!empty($synds)) {
                if (in_array("https://".$configs->mastodonInstance, $synds)) {

                    $MastodonText = str_replace("\'", "'", $synText);
                    $MastodonText = str_replace("\&quot;", "\"", $MastodonText);
                    $MastodonText = urlencode($MastodonText);
                    $MastodonText = substr($MastodonText, 0, 450) . '… '. $canonical;

                    $mastodonToken = "bearer " . $configs->mastodonToken;
                    $mastodonUrl = "https://" . $configs->mastodonInstance . "/api/v1/statuses";
                    $mdata = array(
                        "status" => $MastodonText,
                    );
                    // Calls the simple API from way back at the start
                    $result_mastodon = post_to_api($mastodonUrl, $mastodonToken, $mdata);
                    $array_mastodon = json_decode($result_mastodon, true);
                    // Sets up a variable linking to the toot
                    $frontmatter['mastodonlink'] = $array_mastodon['url'];
                }
            }

            // All values obtained, we tidy up the array and convert to json
            // Last part - writing the file to disk...

            write_file($frontmatter, $content, $fn);

            // Some way of triggering Site Generator needs to go in here.

            // ping! First one to micro.blog
            //if ($configs->pingMicro) {
            //    $feedArray = array ("url" => $siteFeed);
            //    post_to_api("https://micro.blog/ping", "null", "$feedArray");
            //}
            // ping! second one to switchboard
            $switchArray = array ("hub.mode" => "publish", "hub.url" => $siteUrl);
            post_to_api("https://switchboard.p3k.io/", "null", $switchArray);

            // ... and Setting headers, return location to client.
            header("HTTP/1.1 201 Created");
            header("Location: ". $canonical);
        }
    }
}
