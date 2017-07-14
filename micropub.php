<?
    ini_set('display_errors', 'On');
    error_reporting(E_ALL);

    // Load the settings from the configuration file
    $configs = include('configs.php');
    $twAPIkey = $configs->twAPIkey;
    $twAPIsecret = $configs->twAPIsecret;
    $twUserKey = $configs->twUserKey;
    $twUserSecret = $configs->twUserSecret;


    // Simple API call function. This could easily be used for any modern writable API
    function post_to_api($url, $auth, $data) {
        $fields = '';
        foreach($data as $key => $value) { 
            $fields .= $key . '=' . $value . '&'; 
        }
        rtrim($fields, '&');
        
        $post = curl_init();

        curl_setopt($post, CURLOPT_URL, $url);
        curl_setopt($post, CURLOPT_POST, count($data));
        curl_setopt($post, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($post, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($post, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded', 
            'Authorization: '.$auth
            ));

        $result = curl_exec($post);

        curl_close($post);
        return $result;
    }


// Tell Micropub clients where I can syndicate to
if(isset($_GET['q']) && $_GET['q'] == "syndicate-to"){
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

    header('Content-type: application/json');
    echo $json_resp;
    exit;
}

// Validate incoming POST requests, using IndieAuth
// This section largely taken from rhiaro
if(!empty($_POST)){
    $headers = apache_request_headers();
    // Check token is valid
    $token = $headers['Authorization'];
    $ch = curl_init("https://tokens.indieauth.com/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, Array(
         "Content-Type: application/x-www-form-urlencoded"
        ,"Authorization: $token"
    ));
    $response = Array();
    parse_str(curl_exec($ch), $response);
    curl_close($ch);
    // Check for scope=create
    // Check for me=$configs->siteUrl
    $me = $response['me'];
    $iss = $response['issued_by'];
    $client = $response['client_id'];
    $scope = $response['scope'];
    if(empty($response)){
        header("HTTP/1.1 401 Unauthorized");
        echo 'Missing auth response';
        exit;
    } elseif($me != "https://ascraeus.org/" || !stristr($values['scope'], 'create')) {
        header("HTTP/1.1 403 Forbidden");
        echo 'Mismatch auth token / Missing create value';
        exit;
    // Check that something was posted
    } elseif(empty($_POST['content'])){
        header("HTTP/1.1 400 Bad Request");
        echo "Missing content";
    } else {

        // All tests passed, time to get to work

        // Starts setting up some variables used throughout
        $time = time();
        $udate = date('U', $time);
        $cdate = date('c', $time);
        $pname = $_POST['name'];
        $pbook = $_POST['bookmark-of'];
        $pslug = $_POST['mp-slug'];

        /*  First established the type of Post in nested order bookmark->article->
            Note that I have my content folders inside my site structure. Obviously,
            if you don't then $fn will need to be changed */

        if(!empty($pname)){ 
            if(!empty($pslug)){
                $slug = $pslug;
            } else {
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $_POST['name'])));
            }
            if(!empty($pbook)) {
                $fn = "content/link/".$slug.".md";
                $canonical = $configs->siteUrl."/link/".$slug;
            } else {
                $fn = "content/article/".$slug.".md";
                $canonical = $configs->siteUrl."/article/".$slug;
            }
            $synText = $_POST['name'];
            $content = $_POST['content'];    
        } else {
            $slug = $udate;
            $fn = "content/micro/".$slug.".md";
            $canonical = $configs->siteUrl."/micro/".$slug;
            $content = $_POST['content'];
            $synText = $_POST['content'];
        }

        // Syndication Posting to different services
        // Get the array of syndication points from the submitted post
 
        $synds = $_POST['mp-syndicate-to'];

        // first Mastodon, count limit 500
        if(!empty($synds)) {

            if(in_array("https://".$configs->mastodonInstance, $synds)) {
                $syntest = "mastodon";
                 
                $MastodonText = str_replace("\'", "'", $synText);
                $MastodonText = str_replace("\&quot;", "\"", $MastodonText);
                $MastodonText = urlencode($MastodonText);

                if (strlen($MastodonText) > 450 ){
                    $MastodonText = substr($MastodonText, 0, 450);
                    $MastodonText = $MastodonText.'… '.$canonical;
                } else {
                    $MastodonText = $MastodonText.' '.$canonical;
                }

                $mastodonToken = "bearer " . $configs->mastodonToken;
                $mastodonUrl = "https://" . $configs->mastodonInstance . "/api/v1/statuses";
                $data = array(
                    "status" => $MastodonText,
                );

                // Calls the simple API from way back at the start
                
                $result_mastodon = post_to_api($mastodonUrl, $mastodonToken, $data);
                $array_mastodon = json_decode($result_mastodon, true);

                // Sets up a variable linking to the toot
                $mastodonlink = $array_mastodon['url'];
            }

            // then twitter, with its useless 140 chars
            if(in_array("https://twitter.com", $synds)) {
                if (strlen($synText) > 110){
                    $Twtext = substr($synText, 0, 110);
                    $Twtext = $Twtext.'… '.$canonical;
                } else {
                    $Twtext = $synText.' '.$canonical;
                }
                // Calls the external Twitter Library

                require_once('TwitterAPIExchange.php');
                
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

                /** Perform a POST request and echo the response **/
                $twitter = new TwitterAPIExchange($settings);   
                $twarray = json_decode($twitter->buildOauth($url, $requestMethod)
                            ->setPostfields($postfields)
                            ->performRequest());
                $str = $twarray->id_str;
                $nym = $twarray->user->screen_name;

                $twitlink = "https://twitter.com/" . $nym . "/status/" . $str;
            }
        }

        // Formats the new post per Hugo's YAML format

        $h = fopen($fn, 'w');

        fwrite($h, "---\n");

        $sep = "---\n";

        if(!empty($pname)) {
            $name_string = "title: \"".$pname."\"\n";
            file_put_contents($fn, $name_string, FILE_APPEND | LOCK_EX);
        }

        if(!empty($pbook)) {
            $book_string = "link: \"".$pbook."\"\n";
            file_put_contents($fn, $book_string, FILE_APPEND | LOCK_EX);
        }

        $slug_string = "slug: \"".$slug."\"\n";
        file_put_contents($fn, $slug_string, FILE_APPEND | LOCK_EX);

        $date_string = "date: \"".$cdate."\"\n";
        file_put_contents($fn, $date_string, FILE_APPEND | LOCK_EX);

        if(isset($twitlink)) {
            $tw_string = "twit: \"".$twitlink."\"\n";
            file_put_contents($fn, $tw_string, FILE_APPEND | LOCK_EX);
        }

        if(isset($mastodonlink)) {
            $ma_string = "masto: \"".$mastodonlink."\"\n";
            file_put_contents($fn, $ma_string, FILE_APPEND | LOCK_EX);
        }

        file_put_contents($fn, $sep, FILE_APPEND | LOCK_EX);

        file_put_contents($fn, $content, FILE_APPEND | LOCK_EX);

        fclose($h); 

        // Some way of triggering Hugo needs to go in here.

        // Set headers, return location
        header("HTTP/1.1 201 Created");
        header("Location: ".$canonical);
        // header('Location: ' . $canonical, true, 201);
    }
}
?>

