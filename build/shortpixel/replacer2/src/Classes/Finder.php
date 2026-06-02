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
    


		public function posts($args = [])
		{
			global $wpdb;

			$defaults = [
				'post_ids' => [], 
			]; 


			$base_url = $this->base_url;
			/* Search and replace in WP_POSTS */
			// Removed $wpdb->remove_placeholder_escape from here, not compatible with WP 4.8
	
			$posts_sql = 
				"SELECT ID as post_id, post_content as content FROM $wpdb->posts WHERE post_status in ('publish', 'future', 'draft', 'pending', 'private')
					AND post_content LIKE %s"; 
				
			//);
			$prepare[]  = '%' . $base_url . '%'; 

			if (is_array($args['post_ids']) && count($args['post_ids']) > 0) {
				$post_ids = $args['post_ids']; 
				$placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

				$posts_sql .= " AND ID IN ($placeholders) "; 
				$prepare = array_merge($prepare, $post_ids);
			}

			$wpdb->prepare($posts_sql, $prepare); 

	
			$rs = $wpdb->get_results($posts_sql, ARRAY_A);
	

			// @todo before this filter results?  pass results to some worker
			call_user_func_array($this->callback, ['results' => $rs, 'args' => $this->return_data]);

			return $rs;
		}

		public function postmeta()
		{
			 
		}

		
		
    
}