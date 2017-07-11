<?php

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

?>