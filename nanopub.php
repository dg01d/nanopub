<?php
/**
 * nanopub - MicroPub support for Static Blog Engine
 *
 * @author  Daniel Goldsmith <dgold@ascraeus.org>
 * @license https://opensource.org/licenses/FPL-1.0.0
 * @link    https://github.com/dg01d/nanopub
 * @category Micropub
 * @version 1.1
 */

ini_set('display_errors', 'On');
error_reporting(E_ALL);
/** 
 * Load the settings from the configuration file 
 */

require('vendor/autoload.php');
require('helpers.php');
use GuzzleHttp\Client;
use Forecast\Forecast;

$configs = include 'configs.php';
$twAPIkey = $configs->twAPIkey;
$twAPIsecret = $configs->twAPIsecret;
$twUserKey = $configs->twUserKey;
$twUserSecret = $configs->twUserSecret;
$siteUrl = $configs->siteUrl;
$siteFeed = $configs->siteFeed;
date_default_timezone_set($configs->timezone);
$udate = date('U', time());
$cdate = date('c', time());

$xray = new p3k\XRay();

/** 
 * API call function. This could easily be used for any modern writable API
 *
 * @param $url    adressable url of the external API
 * @param $auth   authorisation header for the API
 * @param $adata  php array of the data to be sent
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
 * getallheaders() replacement for nginx
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
 * @param array $headers    All headers from an incoming connection request
 *
 * @return boolean true if authorised
 */
function indieAuth($headers) 
{
    /**
     * Check token is valid 
     */
    $token = $headers['authorization'];
    $ch = curl_init("https://tokens.indieauth.com/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt(
        $ch, CURLOPT_HTTPHEADER, Array(
        //"Content-Type: application/x-www-form-urlencoded",
        "Authorization: $token"
        )
    );
    $response = Array();
    parse_str(curl_exec($ch), $response);
    curl_close($ch);

    $me = $response['me'];
    $iss = $response['issued_by'];
    $client = $response['client_id'];
    $scope = $response['scope'];
    $scopes = explode(' ', $scope); 

    if (empty($response)) {
        header("HTTP/1.1 401 Unauthorized");
        echo 'The request lacks authentication credentials';
        exit;
    } elseif ($me != $GLOBALS["siteUrl"]) {
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
 * @param $array      the array of Hugo key => value frontmatter elements
 * @param $keys       an associative array, pairing key values
 * @param $filter     boolean switch, if true, values not present in $keys are removed
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
 * @param $textFile   the Hugo content file, loaded with json frontmatter
 * @param $mfArray    Array of Hugo <=> mf2 field equivalents
 * @param $bool       boolean to determine if non-equivalent keys are stripped
 *
 * @return array structured array from a text file with json frontmatter 
 */
function decode_input($textFile, $mfArray, $bool) 
{    
    $topArray = explode("\n\n", $textFile);
    $jsonArray = json_decode($topArray[0], true);
    $jsonArray["content"] = rtrim($topArray[1]);
    $newArray = array();
    /*
     * All values must be arrays in mf2 syntax 
     */
    foreach ($jsonArray as $key => $value) {
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
 * @param $array      array of mf2-compliant fieldnames
 * @param $mfArray    array of Hugo <=> mf2 field equivalents
 * @return array with Hugo fieldnames
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
 * @since 1.1
 * Writes dataset to file.
 * Put here to allow extension for different post-types in future.
 *
 * @return boolean 
 */
function write_file($frontmatter, $content, $fn)
{
    $frontmatter = array_filter($frontmatter);
    $frontjson = json_encode($frontmatter, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK ) . "\n\n";
    file_put_contents($fn, $frontjson);
    file_put_contents($fn, $content, FILE_APPEND | LOCK_EX);
}


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
                "uid" => "https://twitter.com",
                "name" => "Twitter"
            ),
            1 => array(
                "uid" => "https://".$configs->mastodonInstance,
                "name" => "Mastodon"
            )
        )
    );

    header('Content-Type: application/json');
    echo json_encode($array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit;
}

// Offer micropub clients full configuration
if (isset($_GET['q']) && $_GET['q'] == "config") {
    $array = array(
        "media-endpoint" => $configs->mediaPoint,
        "syndicate-to" => array(
            0 => array(
                "uid" => "https://twitter.com",
                "name" => "Twitter"
            ),
            1 => array(
                "uid" => "https://".$configs->mastodonInstance,
                "name" => "Mastodon"
            )
        )
    );

    header('Content-Type: application/json');
    echo json_encode($array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
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
        'properties' => array_map(function($a){ return is_array($a) ? $a : [$a]; }, $_POST)
    ];
} else {
    $data = json_decode(file_get_contents('php://input'), true);
}


// Offer micropub clients source material
if (isset($_GET['q']) && $_GET['q'] == 'source') {
    // As this is requesting the source of a post,
    // seek indieAuth validation of the request
    //if (indieAuth($headers)) {        
        if (!empty($_GET['url'])) {
            $subj = urldecode($_GET['url']);
            $pattern = "#$siteUrl#";
            $repl = "";
            $srcUri = preg_replace($pattern, $repl, $subj);
            $srcUri = rtrim($srcUri, "/");
            if ($textFile = file_get_contents("../content/$srcUri.md")) {

                //send file for decoding
                $jsonArray = decode_input($textFile, $mfArray, true);
                
                $respArray = array (
                    "type" => ["h-entry"],
                    "properties" => $jsonArray
                    );
                header('Content-Type: application/json');        
                echo json_encode($respArray, JSON_PRETTY_PRINT);
                exit;
            } else {
                header("HTTP/1.1 404 Not Found");
                echo "Source file not found";
                exit;
            }
        }
    //}
}

if (!empty($data)) {
    if (indieAuth($headers)) {
        if (empty($data['properties']['content']['0']) && empty($data['properties']['like-of']['0']) && empty($data['properties']['repost-of']['0']) && empty($data['properties']['checkin']['0']['type']['0'])) {
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
                // This is based on _my_ content position. This converts URLs to local disk
                $pattern = "#".$siteUrl."#";
                $repl = "";
                $srcUri = preg_replace($pattern, $repl, $subj);
                $srcUri = rtrim($srcUri, "/");
                // First delete if asked to
                if ($action == "delete") {
                    rename("../content/$srcUri.md", "../trash/$srcUri.md");
                    header("HTTP/1.1 204 No Content");
                    exit;
                }
                // then an undelete
                if ($action == "undelete") {
                    rename("../trash/$srcUri.md", "../content/$srcUri.md");
                    header("HTTP/1.1 201 Created");
                    header("Location: ".$siteUrl.$srcUri);
                    exit;
                }
                // Update can be one of a number of different actions
                if ($action == "update") {
                    // Updating, so need to read the existing file
                    if ($textFile = file_get_contents("../content/$srcUri.md")) {
                        //send file for decoding
                        $jsonArray = decode_input($textFile, $mfArray, false);
                        
                        // Now we perform the different update actions, Replace being first.

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
                        $fn = "../content/".$srcUri.".md";
                        write_file($jsonArray, $content, $fn);
                        header("HTTP/1.1 200 OK");
                        echo json_encode($jsonArray, JSON_PRETTY_PRINT);
 
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
                unset($chkProperties['url']);
                $frontmatter['checkloc'] = $chkProperties['name']['0'];
                unset($chkProperties['name']);
                if ($chkProperties['locality']['0'] != $chkProperties['region']['0']) {
                    $frontmatter['checkadd'] = $chkProperties['locality']['0'] . ', ' . $chkProperties['region']['0'];
                } else {
                    $frontmatter['checkadd'] = $chkProperties['street-address']['0'] . ', ' . $chkProperties['locality']['0'];
                }
                unset($chkProperties['region'], $chkProperties['locality'], $chkProperties['street-address']);
                $frontmatter['latitude'] = $chkProperties['latitude']['0'];
                $frontmatter['longitude'] = $chkProperties['longitude']['0'];
                unset($chkProperties['latitude'], $chkProperties['longitude']);

                // Next bit creates a map and uploads it to media endpoint

                $mapname = 'images/file-'.date('YmdHis').'-'.mt_rand(1000, 9999).'.png';
                $url = 'http://atlas.p3k.io/map/img?marker[]=lat:'.$frontmatter['latitude'].';lng:'.$frontmatter['longitude'].';icon:small-red-cutout&basemap=osm&attribution=none&width=600&height=240&zoom=14';
                file_put_contents($mapname, file_get_contents($url));
                $frontmatter['map'] = $mapname;

                // Now to take out the checkins usual properties

                $content = isset($data['properties']['content']['0']) ? $data['properties']['content']['0'] : null;
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
                $synds = isset($props['mp-syndicate-to']) ? $props['mp-syndicate-to'] : null;
                unset($props['mp-syndicate-to']);

                // Non-notes tend to have a name or title
                $frontmatter['title'] = isset($props['name']['0']) ? $props['name']['0'] : null;
                unset($props['name']);

                // Bookmark-of 
                $frontmatter['link'] = isset($props['bookmark-of']['0']) ? $props['bookmark-of']['0'] : null;
                unset($props['bookmark-of']);

                // First attempt at 'like-of'
                $frontmatter['like_of'] = isset($props['like-of']) ? $props['like-of'] : null;
                if (is_array($frontmatter['like_of'])) {
                    $frontmatter['like_of'] = $frontmatter['like_of']['0'];
                }
                $frontmatter['like_site'] = isset($frontmatter['like_of']) ? hostname_of_uri($frontmatter['like_of']) : null;
                if (isset($frontmatter['like_of'])) {
                    $url_parse = xray_machine($frontmatter['like_of'], $frontmatter['like_site']);
                }
                if ($frontmatter['like_site'] == 'twitter.com') {
                    $synds['0'] = "https://twitter.com";
                }
                unset($props['like-of']);

                // First attempt at 'repost-of'
                $frontmatter['repost_of'] = isset($props['repost-of']) ? $props['repost-of'] : null;
                if (is_array($frontmatter['repost_of'])) {
                    $frontmatter['repost_of'] = $frontmatter['repost_of']['0'];
                }
                $frontmatter['repost_site'] = isset($frontmatter['repost_of']) ? hostname_of_uri($frontmatter['repost_of']) : null;
                if (isset($frontmatter['repost_of'])) {
                    $url_parse = xray_machine($frontmatter['repost_of'], $frontmatter['repost_site']);
                }
                if ($frontmatter['repost_site'] == 'twitter.com') {
                    $synds['0'] = "https://twitter.com";
                }
                unset($props['repost-of']);

                // indieweb 'reply-to'
                $frontmatter['replytourl'] = isset($props['in-reply-to']) ? $props['in-reply-to'] : null;               
                if (is_array($frontmatter['replytourl'])) {
                    $frontmatter['replytourl'] = $frontmatter['replytourl']['0'];
                }
                $frontmatter['replysite'] = isset($frontmatter['replytourl']) ? hostname_of_uri($frontmatter['replytourl']) : null;
                if (isset($frontmatter['replytourl'])) {
                    $url_parse = xray_machine($frontmatter['replytourl'], $frontmatter['replysite']);
                }
                if ($frontmatter['replysite'] == 'twitter.com') {
                    $synds['0'] = "https://twitter.com";
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

                // Hugo does not store content in the frontmatter 
                $content = isset($props['content']['0']) ? $props['content']['0'] : null;
                unset($props['content']);
                if (is_array($content)) {
                    $content = $content['html'];
                }
                $frontmatter['summary'] = isset($props['summary']['0']) ? $props['summary']['0'] : null; 
                unset($props['summary']);
       
                // server allows clients to set category, treats as tags 
                $frontmatter['tags'] = isset($props['category']) ? $props['category'] : null;
                unset($props['category']);

                // Specific logic here for OwnYourGram            
                $frontmatter['photo'] = isset($props['photo']) ? $props['photo'] : null;
                unset($props['photo']);
                
                $frontmatter['instagram'] = (isset($props['syndication']) && in_array("https://www.instagram.com/p", $props['syndication']['0'])) ? $props['syndication']['0'] : null;
                
                // PESOS (like OYG / OYS) already has a datestamp
                $frontmatter['date'] = isset($props['published']['0']) ? $props['published']['0'] : $cdate;
                unset($props['published']);

                // First Attempt at Weather Data
                $weather = getWeather();
                $frontmatter['loc'] = $weather->loc;
                $frontmatter['weather'] = $weather->weather;
                $frontmatter['temp'] = $weather->temp;
                $frontmatter['wicon'] = $weather->icon;

                foreach ($props as $key => $value) {
                    $frontmatter[$key] = $value;
                }

                if (isset($url_parse)) {
                    $frontmatter['xAuthor'] = isset($url_parse['data']['author']['name']) ? $url_parse['data']['author']['name'] : null;
                    $frontmatter['xAuthorUrl'] = isset($url_parse['data']['author']['url']) ? $url_parse['data']['author']['url'] : null;
                    $frontmatter['xPhoto'] = isset($url_parse['data']['author']['photo']) ? $url_parse['data']['author']['photo'] : null;
                    if (isset($url_parse['data']['name'])) {
                        $frontmatter['xContent'] = $url_parse['data']['name'];
                    } elseif (isset($url_parse['data']['content']['html'])) {
                        $frontmatter['xContent'] = $url_parse['data']['content']['html'];
                    } else {
                        $xContent = isset($url_parse['data']['content']['text']) ? $url_parse['data']['content']['text'] : null;
                        $frontmatter['xContent'] = auto_link($xContent, false);
                    }
                    $frontmatter['xPublished'] = $url_parse['data']['published'];
                    if (isset($url_parse['data']['category'])) {
                        $frontmatter['tags'] = $url_parse['data']['category'];
                    }
                }
            }

            /*  First established the type of Post in nested order bookmark->article->note
             *  Note that this is hardcoded to my site structure and post-kinds. Obviously,
             *  $fn will need to be changed for different structures/kinds 
             */

            if (!empty($frontmatter['title'])) { 
                // File locations are specific to my site for now.
                if (!empty($frontmatter['link'])) {
                    $fn = "../content/link/" . $frontmatter['slug'] . ".md";
                    $canonical = $configs->siteUrl . "link/" . $frontmatter['slug'];
                    $synText = $frontmatter['title'];
                } else {
                    $fn = "../content/article/" . $frontmatter['slug'] . ".md";
                    $canonical = $configs->siteUrl . "article/" . $frontmatter['slug'];
                    $synText = $frontmatter['title'];
                }
            } else { 
                if (!empty($frontmatter['repost_of'])) {
                    $fn = "../content/like/" . $frontmatter['slug'] . ".md";
                    $canonical = $configs->siteUrl . "like/" . $frontmatter['slug'];
                    $synText = $content;
                } elseif (!empty($frontmatter['like_of'])) {
                    $fn = "../content/like/" . $frontmatter['slug'] . ".md";
                    $canonical = $configs->siteUrl . "like/" . $frontmatter['slug'];
                    $synText = $content;
                } else {
                    $fn = "../content/micro/" . $frontmatter['slug'] . ".md";
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

                // then twitter, with its useless 140 chars
                if (in_array("https://twitter.com", $synds)) {

                    $TwText = substr($synText, 0, 260) . '… '. $canonical;

                    // Calls the external Twitter Library

                    $settings = array(
                        'consumer_key' => $twAPIkey,
                        'consumer_secret' => $twAPIsecret,
                        'oauth_access_token' => $twUserKey,
                        'oauth_access_token_secret' => $twUserSecret
                        );

                    $url = 'https://api.twitter.com/1.1/statuses/update.json';
                    $requestMethod = 'POST';
                    $postfields = array(
                        'status' => $TwText
                        );

                    if ((isset($frontmatter['replytourl']) && $frontmatter['replysite'] == "twitter.com")) {
                        $postfields['in_reply_to_status_id'] = tw_url_to_status_id($frontmatter['replytourl']);
                    }
                    if ((isset($frontmatter['like_of'])) && ($frontmatter['like_site'] == "twitter.com")) {
                        $url = 'https://api.twitter.com/1.1/favorites/create.json';
                        $postfields['id'] = tw_url_to_status_id($frontmatter['like_of']);
                        unset($postfields['status']);
                    }                    
                    if ((isset($frontmatter['repost_of'])) && ($frontmatter['repost_site'] == "twitter.com")) {
                        $id = tw_url_to_status_id($frontmatter['repost_of']);
                        $url = 'https://api.twitter.com/1.1/statuses/retweet/' . $id . '.json';
                        $postfields['id'] = $id;
                        unset($postfields['status']);
                    }

                    //Perform a POST request and echo the response 
                    
                    $twitter = new TwitterAPIExchange($settings);   
                    $twarray = json_decode(
                        $twitter->buildOauth($url, $requestMethod)
                            ->setPostfields($postfields)
                            ->performRequest()
                    );
                    if ((isset($frontmatter['repost_of'])) || (isset($frontmatter['like_of']))) {
                        $frontmatter['twitlink'] = isset($frontmatter['repost_of']) ? $frontmatter['repost_of'] : $frontmatter['like_of'];
                    } else {
                        $str = $twarray->id_str;
                        $nym = $twarray->user->screen_name;
                        $frontmatter['twitlink'] = "https://twitter.com/" . $nym . "/status/" . $str;
                    }
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