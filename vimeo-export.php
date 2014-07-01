<?php
include 'vimeo-config.php';
include 'vimeo-lib/vimeo.php';

function download($url, $file_target) {
    set_time_limit(0);
	$fp = fopen (dirname(__FILE__) . '/'. $file_target, 'w+');//This is the file where we save the    information
	$ch = curl_init(str_replace(" ","%20",$url));//Here is the file we are downloading, replace spaces with %20
	curl_setopt($ch, CURLOPT_TIMEOUT, 50);
	curl_setopt($ch, CURLOPT_FILE, $fp); // write curl response to file
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_exec($ch); // get curl response
	curl_close($ch);
	fclose($fp);
}

$link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$link_update = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$lib = new Vimeo(oauth_clientid, oauth_clientsecret, oauth_token); 

//$me = $lib->request('/me');
//print_r($me["body"]["upload_quota"]["space"]["free"]); die();

// list videos in WP, with MOV Attachment
$wp_videos = $link->query("
SELECT 
	p.ID, p.post_title, p.post_content, movmeta.meta_value as mov_file, imgmeta.meta_value as img_file, vimeo.meta_value as vimeo_url
FROM wp_posts p
	left outer join wp_postmeta vimeo on 
		vimeo.post_id = p.ID AND
		vimeo.meta_key = 'vimeo_url'
	inner join wp_posts mov on 
		mov.post_parent = p.ID AND 
		mov.post_type = 'attachment' AND mov.post_mime_type = 'video/quicktime'
	inner join wp_postmeta movmeta on 
		movmeta.post_id = mov.ID AND
		movmeta.meta_key = '_wp_attached_file'
	inner join wp_posts img on 
		img.post_parent = p.ID AND 
		img.post_type = 'attachment' AND img.post_mime_type = 'image/jpeg'
	inner join wp_postmeta imgmeta on 
		imgmeta.post_id = img.ID AND
		imgmeta.meta_key = '_wp_attached_file'
where
	p.post_type = 'mp_video'
");

// iterate WP videos
while ($row = $wp_videos->fetch_assoc()) {
	echo "Working on ". $row['post_title'] ."\n";
	
	$vimeo_id = 0;
	preg_match("/\/(\d+)$/", $row["vimeo_url"], $vimeo_match);
	if($vimeo_match && $vimeo_match[1]) 
		$vimeo_id = $vimeo_match[1];
		
	preg_match("/\d{4}\/\d{2}\/([^\/]+)/", $row["mov_file"], $mov_matches);

	if(!$mov_matches || !$mov_matches[1]) continue;
	$mov = $mov_matches[1];
	
		
	// if not in Vimeo
	if(!$vimeo_id){
		$remote_path = "http://marshallplante.com/wp-content/uploads/".$row["mov_file"];
		$local_path = "temp/".$mov;
		
		if(!file_exists($local_path)){
			print 'Downloading ' . $remote_path . "\n";
			download($remote_path, $local_path);
		}
		
		// Upload from local
		print 'Uploading ' . $local_path . "\n";
		try {
			$uri = $lib->upload($local_path);
			
			// Save Vimeo ID on WP video
			preg_match("/\/(\d+)$/", $uri, $vimeo_match);
			print_r($uri);
			$vimeo_id = $vimeo_match[1];
		} catch (VimeoUploadException $e) {
			//  We may have had an error.  We can't resolve it here necessarily, so report it to the user.
			print 'Error uploading ' . $local_path . "\n";
			print 'Server reported: ' . $e->getMessage() . "\n";
			
			if($e->getMessage() == "Unable to get an upload ticket."){
				die("Unable to upload, over quota?");
			}
			
			continue;
		}
		unlink($local_path);
	}
	
	try {
		echo "Updating MetaData $vimeo_id \n";
		
		// Set Category, Description, Title
		$patch_response = $lib->request("/videos/".$vimeo_id, array(
			'name' => $row['post_title'],
			'description' => $row['post_content']
		), 'PATCH');
		
		if($patch_response["status"] == 204){
			echo "Updating WordPress $vimeo_id \n";
			$link_update->query("DELETE FROM wp_postmeta WHERE post_id = ".$row["ID"]." and meta_key = 'vimeo_url'; INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (".$row["ID"].", 'vimeo_url', 'http://vimeo.com/".$vimeo_id."')");
		}
	} catch (Exception $e) {
		//  We may have had an error.  We can't resolve it here necessarily, so report it to the user.
		print 'Error setting metadata ' . $row['post_title'] . "\n";
		print 'Server reported: ' . $e->getMessage() . "\n";
		
		continue;
	}
}