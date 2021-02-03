<?php
/*
   By Joyce Johnston
   http://www.uncontentio.us
   April 18, 2009

   Modified by Jeremy Tunnell

*/

// Ignore warnings from recoverable errors in JPEG files
ini_set('gd.jpeg_ignore_warning', true);

class PhotoException extends Exception {}
class PhotoTooSmallException extends Exception {}

class Photo {

	private $tmp_name;
	private $name;
	private $error;
	private $max_size = 500000;
	private $accepted_mime_types = array('image/jpeg','image/png','image/gif');
	private $tmp;
	private $src;
	private $src_x;
	private $src_y;
	private $dst_x;
	private $dst_y;
	private $orig_width;
	private $orig_height;
	private $src_width;
	private $src_height;
	private $dst_width;
	private $dst_height;
	private $crop_x_start;
	private $crop_y_start;

	function __construct($old_name, $new_name, $max_size='', $extension=NULL) {

		$this->tmp_name = $old_name;
		$this->name = $new_name;
		$info = getimagesize($old_name);

		if ($info === FALSE) {
			throw new PhotoException('The given file is invalid.');
		}

		if ($extension === NULL) {
			$extension = substr($old_name, -3);
		}

		switch($extension) {
			case 'jpg':
				$this->mime = 'image/jpeg';
				break;
			case 'gif':
				$this->mime = 'image/gif';
				break;
			case 'png':
				$this->mime = 'image/png';
				break;
			default:
				throw new PhotoException('Unsupported image type: ' . $extension);
		}

		$this->orig_width = $info[0];
		$this->orig_height = $info[1];
		if($max_size != '') { $this->max_size = $max_size; }
	}

	function getFileType() {
		return $this->mime;
	}

	function getWidth() {
		return $this->orig_width;
	}

	function getHeight() {
		return $this->orig_height;
	}

	function getDimensionRatio() {
		return $this->orig_width/$this->orig_height;
	}

	public function doResize($max_dimension) {

		$this->setDimensions($max_dimension);
		if (!$this->resize()) { 
			throw new PhotoException('Sorry! I couldn\'t resize your image.  Please try again later.');
		}
	}

	public function doResizeMax($max_x, $max_y) {

		$this->setDimensionsWidth($max_x, $max_y);
		if (!$this->resize()) { 
			throw new PhotoException('Sorry! I couldn\'t resize your image.  Please try again later.');
		}
	}

	public function doThumb($thumb_size) {

		$this->setThumbDimensions($thumb_size);
		if (!$this->resize()) { 
			throw new PhotoException('Sorry! I couldn\'t make a thumbnail for your image.  Please try again later.');
		}
	}

	public function doCenterCrop($x,$y) {

		$success = $this->setCenterCropDimensions($x,$y);
		$success = $success && $this->resize();
		if (!$success) { 
			throw new PhotoException(
				'Sorry! I couldn\'t crop your image.  Check your image dimensions.
				If it is less than '.$x.'px by '.$y.'px, it is too small to crop.');
		}
	}

	public function doFullCrop($x,$y,$width,$height) {

		$success = $this->setFullCropDimensions($x,$y,$width,$height);
		$success = $success && $this->resize();
		if (!$success) { 
			throw new PhotoException(
				'Sorry! I couldn\'t crop your image.  Check your image dimensions.
				If it is less than '.$x.'px by '.$y.'px, it is too small to crop.');
		}
	}

	public function doResizeCrop($w, $h) { 
		$this->setZoomCrop($w, $h);
		$success = $this->resize();
		$this->setFullCropDimensions($this->crop_x_start, $this->crop_y_start, $w, $h);
		$this->tmp_name = $this->name;
		$success = $success && $this->resize();
		if (!$success) { 
			throw new PhotoException('Unable to resize image.');
		}
	}

	//ENSURES THAT IT FITS IN A SQUARE
	private function setDimensions($max_dimension) {

		if($this->getDimensionRatio() > 1) {
			//our image is wider than it is tall
			$this->dst_width = $max_dimension;
			$this->dst_height=($this->orig_height/$this->orig_width)*$this->dst_width;
		}
		else {
			$this->dst_height = $max_dimension;
			$this->dst_width = ($this->orig_width*$this->dst_height)/$this->orig_height;

		}
		$this->src_width = $this->orig_width;
		$this->src_height = $this->orig_height;
		$this->src_x = 0;
		$this->src_y = 0;
		$this->dst_x = 0;
		$this->dst_y = 0;
	}

	//ENSURES THAT IT FITS IN A RECTANGLE AND MAXIMIZES WIDTH
	private function setDimensionsWidth($max_x,$max_y) {

		$this->dst_width = $max_x;
		$this->dst_height=($this->orig_height/$this->orig_width)*$this->dst_width;

		if($this->dst_height > $max_y){
			$this->dst_height = $max_y;
			$this->dst_width = ($this->orig_width*$this->dst_height)/$this->orig_height;
		}
		$this->src_width = $this->orig_width;
		$this->src_height = $this->orig_height;
		$this->src_x = 0;
		$this->src_y = 0;
		$this->dst_x = 0;
		$this->dst_y = 0;

	}

	//ENSURES THAT IT FITS IN A RECTANGLE AND USES ALL SPACE IN THE MINIMUM DIMENSION
	private function setZoomCrop($max_x,$max_y) {

		// Test the case where we maximize width
		// and ensure that the height doesn't get too small
		
		$this->dst_width = $max_x;
		$this->dst_height = ($this->orig_height/$this->orig_width)*$this->dst_width;

		if ($this->dst_height < $max_y) {
			// stretch the height up
			$this->dst_height = $max_y;
			$this->dst_width = ($this->orig_width*$this->dst_height)/$this->orig_height;
			$this->crop_x_start = .5*($this->dst_width - $max_x);
			$this->crop_y_start = 0;
			$this->src_x = 0; 
			$this->src_y = 0;
		} else { 
			$this->src_x = 0;
			$this->src_y = 0; 
			$this->crop_x_start = 0;
			$this->crop_y_start = .5*($this->dst_height - $max_y);
		}

		$this->dst_x = 0;
		$this->dst_y = 0;
		$this->src_width = $this->orig_width;
		$this->src_height = $this->orig_height;
	}

	private function setThumbDimensions($thumb_size) {

		//find the larger dimension, height or width
		if($this->getDimensionRatio() > 1) {
			//our src image is wider than it is tall
			$this->src_x = 0 + (($this->orig_width-$this->orig_height)/2);
			$this->src_y = 0;
			$this->src_width = $this->orig_height;
			$this->src_height = $this->orig_height;

		}
		else {
			$this->src_x = 0;
			$this->src_y = 0 + (($this->orig_height-$this->orig_width)/2);
			$this->src_height = $this->orig_width;
			$this->src_width = $this->orig_width;
		}
		$this->dst_width = $thumb_size;
		$this->dst_height = $thumb_size;
		$this->dst_x = 0;
		$this->dst_y = 0;

	}

	private function setCenterCropDimensions($x,$y) {

		//if the image is smaller than crop size
		if($this->orig_width < $x || $this->orig_height < $y) {
			throw new PhotoTooSmallException();
		}
		//were always going to crop from center
		$this->src_x = 0 + (($this->orig_width-$x)/2);
		$this->src_y = 0 + (($this->orig_height-$y)/2);
		$this->dst_x = 0;
		$this->dst_y = 0;
		$this->dst_width = $x;
		$this->dst_height = $y;
		$this->src_width = $x;
		$this->src_height = $y;
		return true;
	}

	private function setFullCropDimensions($x,$y,$width,$height) {

		$this->src_x = $x;
		$this->src_y = $y;
		$this->dst_x = 0;
		$this->dst_y = 0;
		$this->dst_width = $width;
		$this->dst_height = $height;
		$this->src_width = $width;
		$this->src_height = $height;
		return true;
	}

	private function resize() {

		if ($this->orig_width < $this->dst_width && $this->orig_height < $this->dst_height) { 
			throw new PhotoTooSmallException();
		}

		// create an Image to resize
		$this->createImage();

		// get all of the sizes
		$width=$this->src_width;
		$height=$this->src_height;
		$newheight=$this->dst_height;
		$newwidth=$this->dst_width;
		$src_x = $this->src_x;
		$src_y = $this->src_y;
		$this->tmp= @imagecreatetruecolor($newwidth,$newheight);

		// resize image

		$success = @imagecopyresampled($this->tmp,$this->src,0,0,$src_x,$src_y,$newwidth,$newheight,$width,$height);

		// write the resized image to disk.
		$filename = $this->name;

		switch ($this->mime) {
			case 'image/jpeg':
				$success =   @imagejpeg($this->tmp,$filename);
				break;
			case 'image/gif':
				$success =   @imagegif($this->tmp,$filename);
				break;
			case 'image/png':
				$success =   @imagepng($this->tmp,$filename);
				break;
		}

		// clean up
		imagedestroy($this->src);
		imagedestroy($this->tmp);
		return $success;
	}



	private function createImage() {

		switch ($this->mime) {
			case 'image/jpeg':
				$this->src = @imagecreatefromjpeg($this->tmp_name);
				break;
			case 'image/gif':
				$this->src = @imagecreatefromgif($this->tmp_name);
				break;
			case 'image/png':
				$this->src = @imagecreatefrompng($this->tmp_name);
				break;
			default:
				throw new PhotoException('Unsupported image mime type: ' . $extension);
		}

		if ($this->src === FALSE) {
			throw new PhotoException(
					'Given image is invalid.');
		}
	}
}
?>
