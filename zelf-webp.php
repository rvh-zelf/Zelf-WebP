<?php

/**
 * Plugin Name: Zelf WebP
 * Description: Uses the Spatie Image Optimizer package for compressing and converting images to WebP format.
 * Version: 1.0.0
 * Author: Xavier Galdur
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Add this near the top of your file with other checks
if (!class_exists('Imagick') || !method_exists('Imagick', 'identifyImage')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>Smart cropping requires Imagick with face detection support.</p></div>';
    });
    // Don't return here, let the plugin work without smart cropping
}

// Verify Imagick is available
if (!extension_loaded('imagick')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>Zelf WebP requires the Imagick PHP extension to be installed.</p></div>';
    });
    return;
}

// Check minimum PHP version
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>Zelf WebP requires PHP 7.2.0 or higher.</p></div>';
    });
    return;
}

// Include Composer's autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Use necessary classes
use Spatie\ImageOptimizer\OptimizerChainFactory;

// Add these filters at the top of the file, after the initial plugin header and checks
// Prevent WordPress from generating regular image sizes
add_filter('intermediate_image_sizes', '__return_empty_array');
add_filter('intermediate_image_sizes_advanced', '__return_empty_array');

function smart_crop_image($im, $target_width, $target_height) {
    // Get original dimensions
    $orig_width = $im->getImageWidth();
    $orig_height = $im->getImageHeight();
    
    // If image is already smaller than target, don't crop
    if ($orig_width <= $target_width && $orig_height <= $target_height) {
        return;
    }

    try {
        // Try to detect faces
        $faces = $im->identifyImage()['facedetect:haarcascade_frontalface_alt'];
        
        if (!empty($faces)) {
            // Get the center point of all faces
            $face_x = 0;
            $face_y = 0;
            $num_faces = count($faces);
            
            foreach ($faces as $face) {
                $face_x += $face['x'] + ($face['width'] / 2);
                $face_y += $face['y'] + ($face['height'] / 2);
            }
            
            $center_x = $face_x / $num_faces;
            $center_y = $face_y / $num_faces;
            
            // Calculate crop coordinates
            $scale = max($target_width / $orig_width, $target_height / $orig_height);
            $crop_width = $target_width / $scale;
            $crop_height = $target_height / $scale;
            
            $x = max(0, min($center_x - ($crop_width / 2), $orig_width - $crop_width));
            $y = max(0, min($center_y - ($crop_height / 2), $orig_height - $crop_height));
            
            // Crop around faces
            $im->cropImage((int)$crop_width, (int)$crop_height, (int)$x, (int)$y);
            $im->resizeImage($target_width, $target_height, Imagick::FILTER_LANCZOS, 1);
            return;
        }
    } catch (Exception $e) {
        // If face detection fails, fall back to center crop
        error_log('Face detection failed: ' . $e->getMessage());
    }
    
    // Default to center crop if no faces detected or if face detection fails
    $im->cropThumbnailImage($target_width, $target_height);
}

function optimize_and_convert_to_webp($metadata, $attachment_id) {
    // Increase memory limit temporarily
    $current_memory_limit = ini_get('memory_limit');
    ini_set('memory_limit', '256M');
    
    try {
        // Verify user capabilities
        if (!current_user_can('upload_files')) {
            return $metadata;
        }

        // Validate input parameters
        if (!is_array($metadata) || !is_numeric($attachment_id)) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        
        // Validate upload directory
        if (isset($upload_dir['error']) && $upload_dir['error'] !== false) {
            return $metadata;
        }

        $file_path = get_attached_file($attachment_id);
        
        // Check if file exists and is readable
        if (!$file_path || !is_readable($file_path)) {
            return $metadata;
        }

        $file_info = pathinfo($file_path);

        // Validate file extension
        if (!isset($file_info['extension']) || !in_array(strtolower($file_info['extension']), ['jpeg', 'jpg', 'png'], true)) {
            return $metadata;
        }

        // Process original image
        $im = new Imagick($file_path);
        $im->setOption('webp:method', '4');
        $im->setOption('webp:lossless', 'false');
        $im->setOption('webp:low-memory', 'true');
        $im->setImageCompressionQuality(85);
        
        // Get original dimensions
        $current_width = $im->getImageWidth();
        $current_height = $im->getImageHeight();
        
        // Check if image exceeds maximum dimensions
        $max_width = 1920;
        $max_height = 1080;
        
        if ($current_width > $max_width || $current_height > $max_height) {
            $ratio = $current_width / $current_height;
            
            if ($current_width / $max_width > $current_height / $max_height) {
                $new_width = (int)$max_width;
                $new_height = (int)round($max_width / $ratio);
            } else {
                $new_height = (int)$max_height;
                $new_width = (int)round($max_height * $ratio);
            }
            
            $im->resizeImage((int)$new_width, (int)$new_height, Imagick::FILTER_LANCZOS, 1);
            
            // Update metadata with new dimensions
            $metadata['width'] = (int)$new_width;
            $metadata['height'] = (int)$new_height;
            
            // Update current dimensions
            $current_width = $new_width;
            $current_height = $new_height;
        }

        // Create original WebP version
        $im->setImageFormat('webp');
        $webp_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.webp';
        $im->writeImage($webp_path);
        
        // Clear original resources
        $im->clear();
        $im->destroy();

        // Initialize sizes array if not exists
        if (!isset($metadata['sizes'])) {
            $metadata['sizes'] = array();
        }

        // Define the sizes we want to process
        $sizes_to_process = array(
            'thumbnail' => array(
                'width' => get_option('thumbnail_size_w'),
                'height' => get_option('thumbnail_size_h', 0),
                'crop' => false
            ),
            'medium' => array(
                'width' => get_option('medium_size_w'),
                'height' => get_option('medium_size_h'),
                'crop' => false
            ),
            'large' => array(
                'width' => get_option('large_size_w'),
                'height' => get_option('large_size_h'),
                'crop' => false
            )
        );
        
        // Process each size from the WebP original
        foreach ($sizes_to_process as $size => $dimensions) {
            // Create new Imagick instance from the WebP file
            $size_im = new Imagick($webp_path);
            
            $width = (int)$dimensions['width'];
            $height = (int)$dimensions['height'];
            
            // Calculate new dimensions, respecting when height is 0
            if ($height === 0) {
                $ratio = $current_height / $current_width;
                $final_width = $width;
                $final_height = (int)round($width * $ratio);
            } else {
                $ratio = $current_width / $current_height;
                if ($width / $height > $ratio) {
                    $final_width = (int)round($height * $ratio);
                    $final_height = $height;
                } else {
                    $final_width = $width;
                    $final_height = (int)round($width / $ratio);
                }
            }
            
            // Resize the image
            $size_im->resizeImage($final_width, $final_height, Imagick::FILTER_LANCZOS, 1);

            // Generate size-specific filename
            $size_filename = $file_info['filename'] . '-' . $final_width . 'x' . $final_height . '.webp';
            $size_path = $file_info['dirname'] . '/' . $size_filename;
            
            // Save the sized WebP version
            $size_im->writeImage($size_path);

            // Add size to metadata
            $metadata['sizes'][$size] = array(
                'file' => basename($size_filename),
                'width' => $final_width,
                'height' => $final_height,
                'mime-type' => 'image/webp',
                'filesize' => filesize($size_path)
            );

            // Clear resources
            $size_im->clear();
            $size_im->destroy();
        }

        // Update original metadata
        $metadata['file'] = str_replace($upload_dir['basedir'] . '/', '', $webp_path);
        $metadata['mime-type'] = 'image/webp';
        $metadata['filesize'] = filesize($webp_path);
        
    } catch (Exception $e) {
        error_log('Zelf WebP Error: ' . $e->getMessage());
        return $metadata;
    } finally {
        // Restore original memory limit
        ini_set('memory_limit', $current_memory_limit);
    }

    return $metadata;
}

add_filter('wp_generate_attachment_metadata', 'optimize_and_convert_to_webp', 10, 2);
