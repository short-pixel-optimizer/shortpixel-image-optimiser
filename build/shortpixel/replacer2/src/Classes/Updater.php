<?php 
namespace ShortPixel\Replacer\Classes; 


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}



class Updater
{

    protected static $updatesNumber = 0; 

    public function updatePost($post_id, $content)
    {
        global $wpdb; 
        
        $sql = 'UPDATE ' . $wpdb->posts . ' SET post_content = %s WHERE ID = %d';
        $sql = $wpdb->prepare($sql, $content, $post_id);
    
	$result = $wpdb->query($sql);

	//Also flush object cache to ensure the content is updated properly
	wp_cache_delete($post_id, 'posts');
    
        if ($result === false) {
            // Notice::addError('Something went wrong while replacing' .  $result->get_error_message() );
            Log::addError('WP-Error during post update', $result);
        }

        self::$updatesNumber++; 
    }



}
