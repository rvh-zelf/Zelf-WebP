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

// Remove the complete blocking of image editors
// add_filter('wp_image_editors', function($editors) {
//     return array();
// });

// Instead, let's hook into the intermediate image sizes generation
add_filter('intermediate_image_sizes_advanced', function($sizes) {
    return $sizes; // Allow WordPress to calculate the sizes
});

function optimize_and_convert_to_webp($metadata, $attachment_id) {
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

    try {
        // Instantiate the optimizer
        $optimizerChain = OptimizerChainFactory::create();

        // Optimize and convert original to WebP
        $optimizerChain->optimize($file_path);
        $im = new Imagick($file_path);
        
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
        }

        // Create WebP version
        $im->setImageFormat('webp');
        $webp_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.webp';
        $im->writeImage($webp_path);
        
        // Clear Imagick resources
        $im->clear();
        $im->destroy();
        unset($im);
        
        // Force garbage collection
        gc_collect_cycles();
        
        // Update WordPress to use the WebP file BEFORE deleting the original
        update_attached_file($attachment_id, $webp_path);
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        // Now try to delete the original
        clearstatcache(true, $file_path);
        error_log('Zelf WebP: File path before deletion: ' . $file_path);
        error_log('Zelf WebP: File exists before deletion: ' . (file_exists($file_path) ? 'yes' : 'no'));
        
        if (file_exists($file_path)) {
            @chmod($file_path, 0777);
            if (@unlink($file_path)) {
                error_log('Zelf WebP: Successfully deleted original file');
            } else {
                error_log('Zelf WebP: Failed to delete file - Error: ' . error_get_last()['message']);
            }
        }
        
        // Double check if file was recreated
        clearstatcache(true, $file_path);
        if (file_exists($file_path)) {
            error_log('Zelf WebP: File still exists after deletion attempt');
        }
        
        // Update metadata
        $metadata['file'] = str_replace($upload_dir['basedir'] . '/', '', $webp_path);
        $metadata['mime-type'] = 'image/webp';
        $metadata['filesize'] = filesize($webp_path);

        // Process all image sizes
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $size_info) {
                // Validate size info
                if (!isset($size_info['file']) || !isset($size_info['width']) || !isset($size_info['height'])) {
                    continue;
                }

                // Get paths
                $size_file_path = $upload_dir['path'] . '/' . $size_info['file'];
                $size_file_info = pathinfo($size_info['file']);
                
                // Convert directly to WebP
                $im = new Imagick($webp_path);
                
                // Get the current dimensions
                $current_width = $im->getImageWidth();
                $current_height = $im->getImageHeight();
                
                // Get target dimensions
                $target_width = $size_info['width'];
                $target_height = $size_info['height'];
                
                // Check if this size should be cropped
                $size_data = get_image_size_data($size);
                $crop = !empty($size_data['crop']);
                
                if ($crop) {
                    $im->cropThumbnailImage($target_width, $target_height);
                } else {
                    $ratio_orig = $current_width / $current_height;
                    
                    if ($target_width / $target_height > $ratio_orig) {
                        $target_width = (int)round($target_height * $ratio_orig);
                    } else {
                        $target_height = (int)round($target_width / $ratio_orig);
                    }
                    
                    $im->resizeImage((int)$target_width, (int)$target_height, Imagick::FILTER_LANCZOS, 1);
                }
                
                // Save as WebP
                $size_webp_path = $upload_dir['path'] . '/' . $size_file_info['filename'] . '.webp';
                $im->writeImage($size_webp_path);
                $im->clear();
                $im->destroy();
                
                // Delete the original sized file
                if (file_exists($size_file_path)) {
                    unlink($size_file_path);
                }
                
                // Update metadata to use webp for this size
                $metadata['sizes'][$size]['file'] = $size_file_info['filename'] . '.webp';
                $metadata['sizes'][$size]['mime-type'] = 'image/webp';
                $metadata['sizes'][$size]['filesize'] = filesize($size_webp_path);
                $metadata['sizes'][$size]['width'] = (int)$target_width;
                $metadata['sizes'][$size]['height'] = (int)$target_height;
            }
        }
    } catch (Exception $e) {
        // Log error and return original metadata
        error_log('Zelf WebP Error: ' . $e->getMessage());
        return $metadata;
    }

    return $metadata;
}

function get_image_size_data($size) {
    // Sanitize size name
    $size = sanitize_key($size);
    
    global $_wp_additional_image_sizes;
    
    if (in_array($size, array('thumbnail', 'medium', 'medium_large', 'large'), true)) {
        return array(
            'width' => absint(get_option($size . '_size_w')),
            'height' => absint(get_option($size . '_size_h')),
            'crop' => get_option($size . '_crop')
        );
    } elseif (isset($_wp_additional_image_sizes[$size])) {
        return $_wp_additional_image_sizes[$size];
    }
    
    return array('crop' => false);
}

add_filter('wp_generate_attachment_metadata', 'optimize_and_convert_to_webp', 10, 2);
