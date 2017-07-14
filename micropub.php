<?
    ini_set('display_errors', 'On');
    error_reporting(E_ALL);

    $configs = include('configs.php');

// Tell client where I can syndicate to
if(isset($_GET['q']) && $_GET['q'] == "syndicate-to"){
    $array = array(
        "syndicate-to" => array(
            0 => array(
                "uid" => "https://twitter.com/".$configs->twitterName,
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

// Check for post
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
        exit;
    }elseif($me != $configs->siteUrl || $scope != "create"){
        header("HTTP/1.1 403 Forbidden");
        exit;
    // Check that something was posted
    }elseif(empty($_POST['content'])){
        header("HTTP/1.1 400 Bad Request");
        echo "Missing content";
    }else{

        // DO YOUR THING HERE
        //  ie. insert post content and metadata into your store, write it to a file, whatever you do to add it to your site.
        // For demonstration purposes, let's dump the POST request into a file and return the URL of the file.
        $time = time();
        $udate = date('U', $time);
        $cdate = date('c', $time);

        if(inarray("name", $_POST)) {
            if(inarray("mp-slug", $_POST)) {
                $slug = $_POST['mp-slug'];
            } else {
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $_POST['name'])));
            }
            if(inarray("bookmark-of", $_POST)) {
            $fn = "../content/link/".$slug;
            $canonical = $configs->siteUrl."/link/".$slug;
            } else {
            $fn = "../content/article/".$slug;
            $canonical = $configs->siteUrl."/article/".$slug;
            }
            $synText = $_POST['name'];
            $content = $_POST['content'];    
        } else {
            $slug = $udate;
            $fn = "../content/micro/".$slug.".md";
            $canonical = $configs->siteUrl."/micro/".$slug;
        }

        $h = fopen($fn, 'w');
        //foreach($_POST as $k => $v){
        //    $data .= "[$k] => $v<br/>";
        //}
        $data = json_encode($_POST, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        //$filename = (string) $fn;

        //$file = fopen($filename, "w");
        //fwrite($file, $data);
        //fclose($file);

        fwrite($h, $data);
        file_put_contents($fn, $slug, FILE_APPEND | LOCK_EX);
        file_put_contents($fn, $canonical, FILE_APPEND | LOCK_EX); 
        fclose($h); 

        //print_r($data);
        //print_r($fn);

        // Set headers, return location
        header("HTTP/1.1 201 Created");
        header("Location: ".$canonical);
        // header('Location: ' . $canonical, true, 201);
    }
}
?>