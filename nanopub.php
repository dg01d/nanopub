<?php
/**
 * nanopub - MicroPub support for Static Blog Engine
 *
 * @author  Daniel Goldsmith <dgold@ascraeus.org>
 * @license https://opensource.org/licenses/FPL-1.0.0 Free Software Licence 1.0
 * @link    https://github.com/dg01d/nanopub
 */


/** 
 *   Load the settings from the configuration file 
 */

$configs = include 'configs.php';
$twAPIkey = $configs->twAPIkey;
$twAPIsecret = $configs->twAPIsecret;
$twUserKey = $configs->twUserKey;
$twUserSecret = $configs->twUserSecret;
$siteUrl = $configs->siteUrl;
$siteFeed = $configs->siteFeed;

/* 
 *   API call function. This could easily be used for any modern writable API
 *
 *   $url    adressable url of the external API
 *   $auth   authorisation header for the API
 *   $adata  php array of the data to be sent
 *
 *   @return     HTTP response from API
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
 *   Simple function to test for associative arrays
 *
 *   @return boolean value
 */
function isAssoc($array)
{
    $array = array_keys($array); 
    return ($array !== array_keys($array));
}

/** 
 *   Validate incoming requests, using IndieAuth
 *   This section largely adopted from rhiaro
 *
 *   $headers    the headers from an incoming connection request
 *
 * @return boolean value true
 */
function indieAuth($headers) 
{
    /**
     * Check token is valid 
     */
    $token = $headers['0']['authorization'];
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
 *   Function to replace keys in an array with values from a different one.
 *   
 *   Used here to rewrite keys from Hugo's format to microformats2 syntax.
 *
 *   $array      the array of Hugo key => value frontmatter elements
 *   $keys       an associative array, pairing key values
 *   $filter     boolean switch, if true, values not present in $keys are removed
 *
 * @return associative array with keys in mf2 values
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
 *   Function which reads existing Hugo files and rearranges them to the
 *   format required by the micropub specification.
 *
 *   $textFile   the Hugo content file, loaded with json frontmatter
 *   $mfArray    Array of Hugo <=> mf2 field equivalents
 *   $bool       boolean to determine if non-equivalent keys are stripped
 *
 * @return structured array from a text file with json frontmatter 
 */
function decode_input($textFile, $mfArray, $bool) 
{    
    $topArray = explode("\n\n", $textFile);
    $jsonArray = json_decode($topArray[0], true);
    $jsonArray["content"] = rtrim($topArray[1]);
    $newArray = array();
    /**
 * All values must be arrays in mf2 syntax 
**/
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
 *   Function which rewrites micropub-compliant structure as a Hugo file.
 *
 *   $array      array of mf2-compliant fieldnames
 *   $mfArray    array of Hugo <=> mf2 field equivalents
 *
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

    $json_resp = json_encode($array);

    header('Content-Type: application/json');
    echo $json_resp;
    exit;
}

// Offer micropub clients full configuration
if (isset($_GET['q']) && $_GET['q'] == "config") {
    $array = array(
        "media-endpoint" => "https://media.ascraeus.org/micropub.php",
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

    $json_resp = json_encode($array);

    header('Content-Type: application/json');
    echo $json_resp;
    exit;
}

// Take headers and other incoming data
$headers = array(getallheaders());
$headers = array_change_key_case($headers, CASE_LOWER);
$data = array ();
if (!empty($headers['0']['content-Type'])) {
    $contentType = $headers['0']['content-Type'];
}
if (!empty($_POST['access_token'])) {
    $token = "Bearer ".$_POST['access_token'];
    $headers['0']["authorization"] = $token;
}
if ($contentType == 'application/json') {
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

if (!empty($_POST) || !empty($data)) {
    if (indieAuth($headers)) {
        if (empty($_POST['content']) && empty($data['properties']['content']['0']) && empty($data['properties']['checkin']['0']['type']['0'])) {
            // If this is a POST and there's no action listed, 400 exit
            if (empty($_POST['action']) && empty($data['action'])) {
                header("HTTP/1.1 400 Bad Request");
                echo "Missing content";
                exit; 
            } else {
                // now we need to start getting the information for actions
                if (!empty($data['action'])) {
                    $action = $data['action'];
                    $subj = urldecode($data['url']);
                }
                if (!empty($_POST['action'])) {
                    $action = $_POST['action'];
                    $subj = urldecode($_POST['url']);
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
                        $json = json_encode($jsonArray, JSON_PRETTY_PRINT)."\n\n";
                        $fn = "../content/".$srcUri.".md";
                        $h = fopen($fn, 'w');
                        fwrite($h, $json);
                        file_put_contents($fn, $content, FILE_APPEND | LOCK_EX);
                        fclose($h); 
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
            $time = time();
            $udate = date('U', $time);
            $cdate = date('c', $time);
            
            // Start with JSON requests. 
            if ($contentType == "application/json") {
                // Starting with checkins. These require a lot of metadata.
                // Structure is based on OwnYourSwarm's json payload
                if (!empty($data['properties']['checkin'])) {
                    $chkProperties = [$data['properties']['checkin']['0']['properties']];
                    if (!empty($chkProperties['url']['1'])) {
                        $checkurl = $chkProperties['url']['1'];
                    } else {
                        $checkurl = $chkProperties['url']['0'];
                    }
                    $checkurl = $chkProperties['url']['0'];
                    $checkloc = $chkProperties['name']['0'];
                    if ($chkProperties['locality']['0'] != $chkProperties['region']['0']) {
                        $checkadd = $chkProperties['locality']['0'] . ', ' . $chkProperties['region']['0'];
                    } else {
                        $checkadd = $chkProperties['street-address']['0'] . ', ' . $chkProperties['locality']['0'];
                    }
                    $lat = $chkProperties['latitude']['0'];
                    $long = $chkProperties['longitude']['0'];
                    $mapname = 'images/file-'.date('YmdHis').'-'.mt_rand(1000, 9999).'.png';
                    $url = 'http://atlas.p3k.io/map/img?marker[]=lat:'.$lat.';lng:'.$long.';icon:small-red-cutout&basemap=osm&attribution=none&width=600&height=240&zoom=14';
                    file_put_contents($mapname, file_get_contents($url));
                    if (!empty($data['properties']['content']['0'])) {
                        $pcontent = $data['properties']['content']['0'];
                    } else {
                        $pcontent = ' ';
                    }
                    $foursq = $data['properties']['syndication']['0'];
                    $cdate = $data['properties']['published']['0'];
                } else {
                    // Non-notes tend to have a name or title
                    if (!empty($data['properties']['name']['0'])) {
                        $pname = $data['properties']['name']['0'];
                    }
                    // Bookmark-of could be replaced with 'like-of'
                    if (!empty($data['properties']['bookmark-of']['0'])) {
                        $pbook = $data['properties']['bookmark-of']['0'];
                    }
                    // server allows client to set a slug
                    if (!empty($data['properties']['mp-slug']['0'])) {
                        $pslug = $data['properties']['mp-slug']['0'];
                    }
                    $pcontent = $data['properties']['content']['0'];
                    if (is_array($pcontent)) {
                        $pcontent = $pcontent['html'];
                    }
                    // indieweb replies needs url & site
                    if (!empty($data['properties']['in-reply-to'])) {
                        $replytourl = $data['properties']['in-reply-to'];
                    }
                    if (!empty($replytourl)) {
                        $replysite = parse_url($replytourl)['host'];
                    }
                    // server allows clients to set category, treats as tags 
                    if (!empty($data['properties']['category'])) {
                        $ptags = $data['properties']['category'];
                    }
                    if (!empty($data['properties']['photo'])) {
                        $photo = $data['properties']['photo'];
                    }
                    // Specific logic here for OwnYourGram
                    if (!empty($data['properties']['syndication']) && in_array("https://www.instagram.com/p", $data['properties']['syndication'])) {
                        $instagram = $data['properties']['syndication']['0'];
                    }
                    // PESOS (like OYG / OYS) already has a datestamp
                    if (!empty($data['properties']['published'])) {
                        $cdate = $data['properties']['published']['0'];
                    }
                    // Client has syndication powers!
                    if (!empty($data['properties']['mp-syndicate-to'])) {
                        $synds = $data['properties']['mp-syndicate-to'];
                    }
                }
            } else {
                // Now we proceed to handle form-encoded POSTS, these are less
                // feature-complete.
                if (!empty($_POST['name'])) {
                    $pname = $_POST['name'];
                }
                if (!empty($_POST['bookmark-of'])) {
                    $pbook = $_POST['bookmark-of'];
                }
                if (!empty($_POST['mp-slug'])) {
                    $pslug = $_POST['mp-slug'];
                }
                $pcontent = $_POST['content'];
                if (!empty($_POST['in-reply-to'])) {
                    $replytourl = $_POST['in-reply-to'];
                }
                if (!empty($_POST['category'])) {
                    $ptags = $_POST['category'];
                }
                if (!empty($_POST['photo'])) {
                    $photo = array($_POST['photo']);
                }
                // Get the array of syndication points from the submitted post
                if (!empty($_POST['mp-syndicate-to'])) {
                    $synds = $_POST['mp-syndicate-to'];
                }
                if (!empty($replytourl)) {
                    $replysite = parse_url($replytourl)['host'];
                }
            }

            /*  First established the type of Post in nested order bookmark->article->note
             *  Note that this is hardcoded to my site structure and post-kinds. Obviously,
             *  $fn will need to be changed for different structures/kinds 
             */

            if (!empty($pname)) { 
                if (!empty($pslug)) {
                    $slug = $pslug;
                } else {
                    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $pname)));
                }
                // File locations are specific to my site for now.
                if (!empty($pbook)) {
                    $floc = "../content/link/";
                    $fn = $floc . $slug . ".md";
                    $cn = "link/" . $slug;
                    $canonical = $configs->siteUrl . $cn;
                } else {
                    $floc = "../content/article/";
                    $fn = $floc . $slug . ".md";
                    $cn = "article/" . $slug;
                    $canonical = $configs->siteUrl . $cn;
                }
                $synText = $pname;
                $content = $pcontent . "\n";    
            } else {
                $slug = $udate;
                $floc = "../content/micro/";
                $fn = $floc . $slug . ".md";
                $cn = "micro/" . $slug;
                $canonical = $configs->siteUrl . $cn;
                $content = $pcontent . "\n";
                $synText = $pcontent;
            }

            // Syndication Posting to different services

            // first Mastodon, count limit 500
            if (!empty($synds)) {

                if (in_array("https://".$configs->mastodonInstance, $synds)) {
                    $syntest = "mastodon";
                     
                    $MastodonText = str_replace("\'", "'", $synText);
                    $MastodonText = str_replace("\&quot;", "\"", $MastodonText);
                    $MastodonText = urlencode($MastodonText);

                    if (strlen($MastodonText) > 450 ) {
                        $MastodonText = substr($MastodonText, 0, 450);
                        $MastodonText = $MastodonText.'… '.$canonical;
                    } else {    
                        $MastodonText = $MastodonText.' '.$canonical;
                    }

                    $mastodonToken = "bearer " . $configs->mastodonToken;
                    $mastodonUrl = "https://" . $configs->mastodonInstance . "/api/v1/statuses";
                    $mdata = array(
                        "status" => $MastodonText,
                    );

                    // Calls the simple API from way back at the start

                    $result_mastodon = post_to_api($mastodonUrl, $mastodonToken, $mdata);
                    $array_mastodon = json_decode($result_mastodon, true);

                    // Sets up a variable linking to the toot
                    $mastodonlink = $array_mastodon['url'];
                }

                // then twitter, with its useless 140 chars
                if (in_array("https://twitter.com", $synds)) {
                    if (strlen($synText) > 110) {
                        $Twtext = substr($synText, 0, 110);
                        $Twtext = $Twtext.'… '.$canonical;
                    } else {
                        $Twtext = $synText.' '.$canonical;
                    }
                    // Calls the external Twitter Library

                    include_once 'TwitterAPIExchange.php';
                    
                    $settings = array(
                        'consumer_key' => $twAPIkey,
                        'consumer_secret' => $twAPIsecret,
                        'oauth_access_token' => $twUserKey,
                        'oauth_access_token_secret' => $twUserSecret
                        );

                    $url = 'https://api.twitter.com/1.1/statuses/update.json';
                    $requestMethod = 'POST';

                    $postfields = array(
                        'status' => $Twtext
                        );

                    
                    //Perform a POST request and echo the response 
                    
                    $twitter = new TwitterAPIExchange($settings);   
                    $twarray = json_decode(
                        $twitter->buildOauth($url, $requestMethod)
                            ->setPostfields($postfields)
                            ->performRequest()
                    );
                    $str = $twarray->id_str;
                    $nym = $twarray->user->screen_name;

                    $twitlink = "https://twitter.com/" . $nym . "/status/" . $str;
                }
            }

            // All values obtained, we format the new post per Hugo's JSON format

            $frontmatter = array();
            if (!empty($pname)) {
                $frontmatter['title'] = $pname;
            }
            if (!empty($pbook)) {
                $frontmatter['link'] = $pbook;
            }
            if (!empty($ptags)) {
                $frontmatter['tags'] = $ptags;
            }
            if (!empty($mastodonlink)) {
                $frontmatter['masto'] = $mastodonlink;    
            }
            if (!empty($twitlink)) {
                $frontmatter['twit'] = $twitlink;
            }
            if (!empty($replytourl)) {
                $frontmatter['replyto'] = $replytourl;
                $frontmatter['replysite'] = $replysite;
            }
            if (!empty($instagram)) {
                $frontmatter['instagram'] = $instagram;
            }
            if (!empty($photo)) {
                $frontmatter['photo'] = $photo;
            }
            if (!empty($foursq)) {
                $frontmatter['checkin'] = $foursq;
                $frontmatter['map'] = $mapname;
                $frontmatter['checkloc'] = $checkloc;
                $frontmatter['checkadd'] = $checkadd;
                $frontmatter['checkurl'] = $checkurl;
            }
            $frontmatter['slug'] = $slug;
            $frontmatter['date'] = $cdate;
            $frontjson = json_encode($frontmatter, JSON_PRETTY_PRINT)."\n\n";


            // Last part - writing the file to disk...
            $h = fopen($fn, 'w');

            fwrite($h, $frontjson);

            file_put_contents($fn, $content, FILE_APPEND | LOCK_EX);

            fclose($h); 

            // Some way of triggering Site Generator needs to go in here.

            // ping! First one to micro.blog
            if ($configs->pingMicro) {
                $feedArray = array ("url" => $siteFeed);
                post_to_api("https://micro.blog/ping", "null", "$feedArray");
            }
            // ping! second one to switchboard
            $switchArray = array ("hub.mode" => "publish", "hub.url" => $siteUrl);
            post_to_api("https://switchboard.p3k.io/", "null", $switchArray);

            // ... and Setting headers, return location to client.
            header("HTTP/1.1 201 Created");
            header("Location: ".$canonical);
        }
    }
}