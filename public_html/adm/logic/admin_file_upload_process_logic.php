<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_file_upload_process_logic($get, $post) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('includes/UploadHandler.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$settings = Globalvars::get_instance();

	$options = array(
		//'script_url' => $this->get_full_url().'/'.$this->basename($this->get_server_var('SCRIPT_NAME')),
		'upload_dir' => $settings->get_setting('upload_dir').'/', /*dirname($this->get_server_var('SCRIPT_FILENAME')).'/files/',*/
		'upload_url' => LibraryFunctions::get_absolute_url('/' . $settings->get_setting('upload_web_dir').'/'), /*$this->get_full_url().'/files/',*/
		'input_stream' => 'php://input',
		'user_dirs' => false,
		'mkdir_mode' => 0755,
		'param_name' => 'files',
		// Set the following option to 'POST', if your server does not support
		// DELETE requests. This is a parameter sent to the client:
		//'delete_type' => 'DELETE',
		'delete_type' => 'POST',
		'access_control_allow_origin' => '*',
		'access_control_allow_credentials' => false,
		'access_control_allow_methods' => array(
			'POST'
			//'OPTIONS',
			//'HEAD',
			//'GET',
			//'POST',
			//'PUT',
			//'PATCH',
			//'DELETE'
		),
		'access_control_allow_headers' => array(
			'Content-Type',
			'Content-Range',
			'Content-Disposition'
		),
		// By default, allow redirects to the referer protocol+host:
		/*'redirect_allow_target' => '/^'.preg_quote(
				parse_url($this->get_server_var('HTTP_REFERER'), PHP_URL_SCHEME)
				.'://'
				.parse_url($this->get_server_var('HTTP_REFERER'), PHP_URL_HOST)
				.'/', // Trailing slash to not match subdomains by mistake
				'/' // preg_quote delimiter param
			).'/',*/
		// Enable to provide file downloads via GET requests to the PHP script:
		//     1. Set to 1 to download files via readfile method through PHP
		//     2. Set to 2 to send a X-Sendfile header for lighttpd/Apache
		//     3. Set to 3 to send a X-Accel-Redirect header for nginx
		// If set to 2 or 3, adjust the upload_url option to the base path of
		// the redirect parameter, e.g. '/files/'.
		'download_via_php' => false,
		// Read files in chunks to avoid memory limits when download_via_php
		// is enabled, set to 0 to disable chunked reading of files:
		'readfile_chunk_size' => 10 * 1024 * 1024, // 10 MiB
		// Defines which files can be displayed inline when downloaded:
		'inline_file_types' => '/\.(gif|jpe?g|png|avif|webp)$/i',
		// Defines which files (based on their names) are accepted for upload.
		// By default, only allows file uploads with image file extensions.
		// Only change this setting after making sure that any allowed file
		// types cannot be executed by the webserver in the files directory,
		// e.g. PHP scripts, nor executed by the browser when downloaded,
		// e.g. HTML files with embedded JavaScript code.
		// Please also read the SECURITY.md document in this repository.

		//'accept_file_types' => '/\.(gif|jpe?g|png|pdf|xls|doc|xlsx|docx|mp3|mp4|m4a)$/i',
		'accept_file_types' => '/\.('.str_replace(',', '|', $settings->get_setting('allowed_upload_extensions')).')$/i',
		// Replaces dots in filenames with the given string.
		// Can be disabled by setting it to false or an empty string.
		// Note that this is a security feature for servers that support
		// multiple file extensions, e.g. the Apache AddHandler Directive:
		// https://httpd.apache.org/docs/current/mod/mod_mime.html#addhandler
		// Before disabling it, make sure that files uploaded with multiple
		// extensions cannot be executed by the webserver, e.g.
		// "example.php.png" with embedded PHP code, nor executed by the
		// browser when downloaded, e.g. "example.html.gif" with embedded
		// JavaScript code.
		'replace_dots_in_filenames' => '-',
		// The php.ini settings upload_max_filesize and post_max_size
		// take precedence over the following max_file_size setting:
		'max_file_size' => null,
		'min_file_size' => 1,
		// The maximum number of files for the upload directory:
		'max_number_of_files' => null,
		// Reads first file bytes to identify and correct file extensions:
		'correct_image_extensions' => false,
		// Image resolution restrictions:
		'max_width' => null,
		'max_height' => null,
		'min_width' => 1,
		'min_height' => 1,
		// Set the following option to false to enable resumable uploads:
		'discard_aborted_uploads' => true,
		// Set to 0 to use the GD library to scale and orient images,
		// set to 1 to use imagick (if installed, falls back to GD),
		// set to 2 to use the ImageMagick convert binary directly:
		'image_library' => 1,
		// Uncomment the following to define an array of resource limits
		// for imagick:
		/*
		'imagick_resource_limits' => array(
			imagick::RESOURCETYPE_MAP => 32,
			imagick::RESOURCETYPE_MEMORY => 32
		),
		*/
		// Command or path for to the ImageMagick convert binary:
		'convert_bin' => 'convert',
		// Uncomment the following to add parameters in front of each
		// ImageMagick convert call (the limit constraints seem only
		// to have an effect if put in front):
		/*
		'convert_params' => '-limit memory 32MiB -limit map 32MiB',
		*/
		// Command or path for to the ImageMagick identify binary:
		'identify_bin' => 'identify',
		'image_versions' => array(
			// The empty image version key defines options for the original image.
			// Keep in mind: these image manipulations are inherited by all other image versions from this point onwards.
			// Also note that the property 'no_cache' is not inherited, since it's not a manipulation.
			'' => array(
				// Automatically rotate images based on EXIF meta data:
				'auto_orient' => true
			),
			// You can add arrays to generate different versions.
			// The name of the key is the name of the version (example: 'medium').
			// the array contains the options to apply.
			//'large' => array(
			//	'max_width' => 1200,
			//	'max_height' => 1000
			//),
			//'medium' => array(
			//	'max_width' => 800,
			//	'max_height' => 600
			//),
			//'small' => array(
			//	'max_width' => 500,
			//	'max_height' => 300
			//),
			//'thumbnail' => array(
				// Uncomment the following to use a defined directory for the thumbnails
				// instead of a subdirectory based on the version identifier.
				// Make sure that this directory doesn't allow execution of files if you
				// don't pose any restrictions on the type of uploaded files, e.g. by
				// copying the .htaccess file from the files directory for Apache:
				//'upload_dir' => dirname($this->get_server_var('SCRIPT_FILENAME')).'/thumb/',
				//'upload_url' => $this->get_full_url().'/thumb/',
				// Uncomment the following to force the max
				// dimensions and e.g. create square thumbnails:
				// 'auto_orient' => true,
				// 'crop' => true,
				// 'jpeg_quality' => 70,
				// 'no_cache' => true, (there's a caching option, but this remembers thumbnail sizes from a previous action!)
				// 'strip' => true, (this strips EXIF tags, such as geolocation)
				//'max_width' => 80, // either specify width, or set to 0. Then width is automatically adjusted - keeping aspect ratio to a specified max_height.
				//'max_height' => 80 // either specify height, or set to 0. Then height is automatically adjusted - keeping aspect ratio to a specified max_width.
			//)
		),
		'print_response' => false
	);

	$upload_handler = new UploadHandler($options);
	$response = $upload_handler->get_response();
	$files = $response['files'];
	$file_count = count($files);

	foreach ($files as $thisfile){
		if (isset($thisfile->error)){
			//print_r($thisfile->error);
			continue;
		}

		if($existing_id = File::get_by_name($thisfile->name)){
			$file =	new File($existing_id, TRUE);
			$file->set('fil_delete_time', NULL);
		}
		else{
			//RENAME THE FILE
			$settings = Globalvars::get_instance();
			$upload_dir = $settings->get_setting('upload_dir');

			$rand_string = '_'.LibraryFunctions::random_string(8).'.';
			$new_name = str_replace('.', $rand_string, $thisfile->name);
			$new_name = str_replace(' ', '_', $new_name);
			// Removes special chars.
			$new_name = preg_replace('/[^A-Za-z0-9\.\-\_]/', '', $new_name);
			// Replaces multiple hyphens with single one.
			$new_name = preg_replace('/_+/', '_', $new_name);

			if(!rename($upload_dir.'/'.$thisfile->name, $upload_dir.'/'.$new_name)){
				throw new SystemDisplayablePermanentError('Unable to save resized image.  Check file permissions.');
			}

			$file =	new File(NULL);
			$file->set('fil_name', $new_name);
			$file->set('fil_title', $thisfile->name);
			$file->set('fil_type', substr($thisfile->type,0,128));
			$file->set('fil_usr_user_id', $session->get_user_id());

		}
		$file->save();
		$file->load();
		$file->resize();

		// Add the file ID to the response object
		$thisfile->file_id = $file->key;

		if(isset($get['evs_event_session_id']) || isset($post['evs_event_session_id'])){
			$evs_event_session_id = isset($get['evs_event_session_id']) ? $get['evs_event_session_id'] : $post['evs_event_session_id'];
			//ATTACH THE FILE TO AN EVENT SESSION
			$event_session = new EventSession($evs_event_session_id, TRUE);
			$event_session->add_file($file->key);
		}

		/*
		print_r($thisfile->name);
		print_r($thisfile->size);
		print_r($thisfile->type);
		print_r($thisfile->url);
		print_r($thisfile->thumbnailUrl);
		*/
	}

	// Output the modified response with file IDs
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($response);

	if(isset($get['fallback']) || isset($post['fallback'])){
		return LogicResult::render(array(
			'file' => $file,
			'show_fallback' => true
		));
	}

	// Exit here to prevent further output
	exit();
}
?>
