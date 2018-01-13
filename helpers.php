<?php

/**
 * 
 * Uses Compass and DarkSky to obtain weather values
 *
 * @return (object) $weather Weather data from resource
 */

use GuzzleHttp\Client;
use Forecast\Forecast;

function getWeather()
{

        $configs = include 'configs.php';
        $client = new Client([
                'base_uri' => $configs->compass,
                'query' => [
                        'token' => $configs->compassKey,
                        'geocode' => true
                ]
        ]);

        $response = $client->request('GET', 'last');
        $body = json_decode($response->getBody(), true);
        $lat = $body['geocode']['latitude'];
        $long = $body['geocode']['longitude'];
        $loc = $body['geocode']['best_name'];
        $time = $body['geocode']['localtime'];

        $forecast = new Forecast($configs->forecastKey);
        $weather = $forecast->get(
                $lat,
                $long,
                null,
                array(
                        'units' => 'si',
                        'exclude' => 'minutely,hourly,daily,alert,flags'
                ));

        $response = [];
        $response['loc'] = $loc;
        $response['weather'] = $weather->currently->summary;
        $response['icon'] = $weather->currently->icon;
        $response['temp'] = round($weather->currently->temperature, 1);
        return (object) $response;
}


/**
 * @since 1.2
 * Uses the XRay library to extract rich content from uris
 *
 * @param $url    The uri of the resource to be parsed
 * @param $site   The hostname of the resource to be parsed
 *                Could specify other services in configs.php
 * @return $url_parse Array of parsed data from resource
 */

function xray_machine($url, $site)
{
    $xray = new p3k\XRay();

    if ($site == "twitter.com") {
        // If someone can give me a better way to get these values from external file...
        $configs = include 'configs.php';
        $twAPIkey = $configs->twAPIkey;
        $twAPIsecret = $configs->twAPIsecret;
        $twUserKey = $configs->twUserKey;
        $twUserSecret = $configs->twUserSecret;
        $url_parse = $xray->parse($url,
        [
                'timeout' => 30,
                'twitter_api_key' => $twAPIkey,
                'twitter_api_secret' => $twAPIsecret,
                'twitter_access_token' => $twUserKey,
                'twitter_access_token_secret' => $twUserSecret
                ]
        );
    } else {
        $url_parse = $xray->parse($url);
    }
    return $url_parse;
}
