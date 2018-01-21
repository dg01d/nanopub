<?php

use GuzzleHttp\Client;
use Forecast\Forecast;

/**
 * 
 * Uses Compass and DarkSky to obtain weather values
 *
 * @return (object) $weather Weather data from resource
 */

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
 * @since 1.4
 * Tries to obtain metadata from a given url
 *
 * @param $url    The uri of the resource to be parsed
 *
 * @return $resp Array of parsed data from resource
 */


function tagRead($url) 
{
    $tags = array();
    $site_html=  file_get_contents($url);
    $meta_tags = get_meta_tags($url);
    $tags['meta'] = $meta_tags;
    $og_matches=null;
    preg_match_all('~<\s*meta\s+property="(og:[^"]+)"\s+content="([^"]*)~i', $site_html,$og_matches);
    $og_tags=array();
    for($i=0;$i<count($og_matches[1]);$i++)
    {
        $og_tags[$og_matches[1][$i]]=$og_matches[2][$i];
    }
    $tags['og'] = $og_tags;

    if (isset($tags['meta']['author'])) {
        $resp['author'] = $tags['meta']['author'];
    } elseif (isset($tags['og']['og:site_name'])) {
        $resp['author'] = $tags['og']['og:site_name'];
    } else {
        $resp['author'] = hostname_of_uri($url);
    }
    if (isset($tags['meta']['title'])) {
        $resp['name'] = $tags['meta']['title'];
    } elseif (isset($tags['og']['og:title'])) {
        $resp['name'] = $tags['og']['og:title'];
    } elseif (isset($tags['og']['og:description'])) {
        $resp['name'] = $tags['og']['description'];
    } elseif (isset($tags['meta']['description'])) {
        $resp['name'] = $tags['meta']['description'];
    } elseif (isset($tags['meta']['twitter:title'])) {
        $resp['name'] = $tags['meta']['twitter:title'];
    } else {
        $resp['name'] = null;
    }
    if (isset($tags['og']['og:site_name'])) {
        $resp['site'] = $tags['og']['og:site_name'];
    } elseif (isset($tags['meta']['twitter:site'])) {
        $resp['site'] = $tags['meta']['twitter:site'];
    } else {
        $resp['site'] = null;
    }

    return $resp;
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
    $configs = parse_ini_file('config.ini');
    $twAPIkey = $configs['twAPIkey'];
    $twAPIsecret = $configs['twAPIsecret'];
    $twUserKey = $configs['twUserKey'];
    $twUserSecret = $configs['twUserSecret'];
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
    if (empty($url_parse['data']['published'])) {
        $tag_read = tagRead($url);
        $result['xAuthor'] = $tag_read['author'];
        $result['xContent'] = $tag_read['name'];
        $result['xSite'] = $tag_read['site'];
    } else {
        $result['xAuthor'] = $url_parse['data']['author']['name'];
        $result['xAuthorUrl'] = $url_parse['data']['author']['url'];
        $result['xAuthorPhoto'] = $url_parse['data']['author']['photo'];
        if (isset($url_parse['data']['name'])) {
            $result['xContent'] = $url_parse['data']['name'];
        } elseif (isset($url_parse['data']['content']['html'])) {
            $result['xContent'] = $url_parse['data']['content']['html'];
        } else {
            $xContent = isset($url_parse['data']['content']['text']) ? $url_parse['data']['content']['text'] : null;
            $result['xContent'] = auto_link($xContent, false);
        }
        $result['xPublished'] = $url_parse['data']['published'];
        if (isset($url_parse['data']['category'])) {
            $result['tags'] = $url_parse['data']['category'];
        }
    }
    return $result;
}
