<?php 
namespace ShortPixel\Replacer\Classes; 


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}



class Finder 
{

		protected $callback; 
		protected $base_url;
		protected $return_data;

		public function __construct($args = [])
		{

			$defaults = [
				'callback' => array($this, 'doReplaceQuery'), // placeholder, should prolly communicate with replacer class 
				'base_url' => false, 
				'return_data' => [], 
				
			];
			
			$args = wp_parse_args($args, $defaults); 
			$this->callback = $args['callback'];
			$this->base_url = $args['base_url'];
			$this->return_data = $args['return_data'];
		}
    


		public function posts()
		{
			global $wpdb;
			$base_url = $this->base_url;
			/* Search and replace in WP_POSTS */
			// Removed $wpdb->remove_placeholder_escape from here, not compatible with WP 4.8
	
			$posts_sql = $wpdb->prepare(
				"SELECT ID as post_id, post_content as content FROM $wpdb->posts WHERE post_status in ('publish', 'future', 'draft', 'pending', 'private')
					AND post_content LIKE %s",
				'%' . $base_url . '%'
			);
	
			$rs = $wpdb->get_results($posts_sql, ARRAY_A);
			$number_of_updates = 0;
	

			// @todo before this filter results?  pass results to some worker
			call_user_func_array($this->callback, ['results' => $rs, 'args' => $this->return_data]);

			/*if (! empty($rs)) {
				foreach ($rs as $rows) {
					$number_of_updates = $number_of_updates + 1;
					// replace old URLs with new URLs.
	
					$post_content = $rows["post_content"];
					$post_id = $rows['ID'];
					$replaced_content = $this->replaceContent($post_content, $search_urls, $replace_urls, false, true);
	
					if ($replaced_content !== $post_content) {
	
						//  $result = wp_update_post($post_ar);
						$sql = 'UPDATE ' . $wpdb->posts . ' SET post_content = %s WHERE ID = %d';
						$sql = $wpdb->prepare($sql, $replaced_content, $post_id);
	
						$result = $wpdb->query($sql);
	
						if ($result === false) {
							// Notice::addError('Something went wrong while replacing' .  $result->get_error_message() );
							Log::addError('WP-Error during post update', $result);
						}
					}
				}
			} */
	
		//	$number_of_updates += $this->handleMetaData($base_url, $search_urls, $replace_urls);
		//	return $number_of_updates;
		}

		public function postmeta()
		{
			 
		}

		
		
    
}