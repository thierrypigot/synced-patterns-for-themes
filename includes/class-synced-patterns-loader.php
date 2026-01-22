<?php

require_once __DIR__ . '/class-pattern-builder-post-type.php';

class Synced_Patterns_Loader {

private static $synced_theme_patterns = [];

	public function __construct() 
	{
		add_action( 'init', array( $this, 'register_patterns' ) );
		add_filter('rest_request_after_callbacks', [$this, 'inject_theme_synced_patterns'], 10, 3);
		add_filter('rest_request_after_callbacks', [$this, 'handle_hijack_block_update'], 10, 3);
		add_filter('pre_render_block', [$this, 'pass_pattern_content_to_referenced_patterns'], 10, 2);
	}

	public function register_patterns() {

		$pattern_registry = WP_Block_Patterns_Registry::get_instance();

		$pattern_files = $this->get_synced_patterns_from_theme_files();

		foreach ($pattern_files as $pattern_file_data) {

			$pattern_slug = $pattern_file_data['slug'];
			$pattern_file = $pattern_file_data['file'];

			$pattern_post = get_page_by_path(sanitize_title($pattern_slug), OBJECT, 'pb_block');

			if ( $pattern_post) {
				$post_id = $pattern_post->ID;
				self::$synced_theme_patterns[$pattern_slug] = $post_id;

				// Check if the file has been modified
				$file_mtime = filemtime($pattern_file);
				$stored_mtime = get_post_meta($post_id, '_pattern_file_mtime', true);

				// If the file hasn't changed, no need to update
				if ($file_mtime != $stored_mtime) {
					// File modified, render the pattern and calculate the hash
					$pattern_content = self::render_pattern($pattern_file);
					$content_hash = md5($pattern_content);
					$stored_hash = get_post_meta($post_id, '_pattern_content_hash', true);

					// Check if the content has actually changed
					if ($content_hash !== $stored_hash) {
						// Content modified, update the post
						$pattern_post->post_title = $pattern_file_data['title'];
						$pattern_post->post_content = $pattern_content;
						wp_update_post($pattern_post);
						
						// Update metadata
						update_post_meta($post_id, '_pattern_file_mtime', $file_mtime);
						update_post_meta($post_id, '_pattern_content_hash', $content_hash);
						update_post_meta($post_id, '_pattern_file_path', $pattern_file);
					} else {
						// Content identical despite different filemtime (can happen)
						update_post_meta($post_id, '_pattern_file_mtime', $file_mtime);
					}
				}

				// Check if the title has changed (even if content is identical)
				if ($pattern_post->post_title !== $pattern_file_data['title']) {
					$pattern_post->post_title = $pattern_file_data['title'];
					wp_update_post($pattern_post);
				}
			} 
			else {
				// New pattern, create the post
				$pattern_content = self::render_pattern($pattern_file);
				$file_mtime = filemtime($pattern_file);
				$content_hash = md5($pattern_content);

				$post_id = wp_insert_post(array(
					'post_title' => $pattern_file_data['title'],
					'post_name' => $pattern_slug,
					'post_content' => $pattern_content,
					'post_type' => 'pb_block',
					'post_status' => 'publish',
					'ping_status' => 'closed',
					'comment_status' => 'closed'
				));

				if ($post_id && !is_wp_error($post_id)) {
					self::$synced_theme_patterns[$pattern_slug] = $post_id;
					
					// Store metadata
					update_post_meta($post_id, '_pattern_file_mtime', $file_mtime);
					update_post_meta($post_id, '_pattern_content_hash', $content_hash);
					update_post_meta($post_id, '_pattern_file_path', $pattern_file);
				}
			}

			// Optimize category updates
			if (!empty($pattern_file_data['categories']) && isset($post_id) && $post_id) {
				$current_terms = wp_get_object_terms($post_id, 'wp_pattern_category', array('fields' => 'slugs'));
				if (is_wp_error($current_terms)) {
					$current_terms = [];
				}
				
				$new_categories = is_array($pattern_file_data['categories']) 
					? $pattern_file_data['categories'] 
					: explode(',', $pattern_file_data['categories']);
				
				// Normalize categories (trim)
				$new_categories = array_map('trim', $new_categories);
				$new_categories = array_filter($new_categories);
				
				// Compare categories
				sort($current_terms);
				sort($new_categories);
				
				if ($current_terms !== $new_categories) {
					wp_set_object_terms($post_id, $new_categories, 'wp_pattern_category');
				}
			}

			// UN register the unsynced pattern and RE register it with the reference to the synced pattern
			// this pattern injects a synced pattern block as the content.
			// and allows it to be used by anything that uses the wp:pattern with its slug

			// Only continue if post_id is valid
			if (isset($post_id) && $post_id) {
				if ($pattern_registry->is_registered($pattern_slug)) {
					$pattern_registry->unregister($pattern_slug);
				}
				
				$pattern_registry->register(
					$pattern_slug,
					array(
						'title'   => $pattern_file_data['title'],
						'slug'   => $pattern_slug,
						'inserter' => false,
						'content' => '<!-- wp:block {"ref":' . $post_id . '} /-->',
					)
				);
			}
		}
	}

	public function inject_theme_synced_patterns($response, $server, $request)
	{
		// Requesting a single pattern.  Inject the synced theme pattern.
		if (preg_match('#/wp/v2/blocks/(?P<id>\d+)#', $request->get_route(), $matches)) {
			$block_id = intval($matches['id']);
			$pb_block = get_post($block_id);
			if ($pb_block && $pb_block->post_type === 'pb_block') {
				$data = $this->format_pb_block_response($pb_block, $request);
				$response = new WP_REST_Response($data);
			}
		}

		// Requesting all patterns.  Inject all of the synced theme patterns.
		else if ($request->get_route() === '/wp/v2/blocks') {

			$data = $response->get_data();
			// Reuse cache instead of calling get_synced_patterns_from_theme_files() again
			$pattern_files = $this->get_synced_patterns_from_theme_files();

			foreach ($pattern_files as $pattern) {
				$post = get_page_by_path(sanitize_title($pattern['slug']), OBJECT, 'pb_block');
				// Handle case where $post is null
				if ($post && $post->post_type === 'pb_block') {
					$data[] = $this->format_pb_block_response($post, $request);
				}
			}

			$response->set_data($data);
		}

		return $response;
	}

	public function format_pb_block_response($post, $request)
	{
		// Use WordPress core's REST controller for proper formatting
		$controller = new WP_REST_Blocks_Controller('wp_block');

		// Change the post type to wp_block for proper magic making
		$post->post_type = 'wp_block';

		// Use the controller's prepare_item_for_response method
		$response = $controller->prepare_item_for_response($post, $request);
		$data = $response->get_data();

		return $data;
	}

	public function handle_hijack_block_update($response, $handler, $request)
	{
		$route = $request->get_route();

		if (preg_match('#^/wp/v2/blocks/(\d+)$#', $route, $matches) && $request->get_method() === 'PUT') {

			$id = intval($matches[1]);
			$post = get_post($id);

			if ($post && $post->post_type === 'pb_block') {
				// pb_blocks cannot be saved.  return an error response
				return new WP_Error(
					'rest_cannot_update_pb_block',
					__('Synced Theme Patterns cannot be updated in the editor without additional tools.', 'synced-patterns-for-themes'),
					array('status' => 403)
				);
			}
		}
		return $response;
	}

	function render_pattern($pattern_file)
	{
		ob_start();
		include $pattern_file;
		return ob_get_clean();
	}

	/**
	 * Generates a unique cache key based on the patterns directory
	 *
	 * @return string Cache key
	 */
	private function get_patterns_cache_key()
	{
		$patterns_dir = get_stylesheet_directory() . '/patterns';
		$cache_key = 'synced_patterns_list_' . md5($patterns_dir);
		return $cache_key;
	}

	/**
	 * Gets the modification time of the patterns directory for cache invalidation
	 *
	 * @return int|false Modification timestamp or false if directory doesn't exist
	 */
	private function get_patterns_dir_mtime()
	{
		$patterns_dir = get_stylesheet_directory() . '/patterns';
		if (!is_dir($patterns_dir)) {
			return false;
		}
		return filemtime($patterns_dir);
	}

	private function get_synced_patterns_from_theme_files()
	{
		$cache_key = $this->get_patterns_cache_key();
		$current_dir_mtime = $this->get_patterns_dir_mtime();
		$cached_dir_mtime = get_transient($cache_key . '_mtime');
		
		// Check if cache is valid
		if ($cached_dir_mtime !== false && $current_dir_mtime !== false && $cached_dir_mtime === $current_dir_mtime) {
			$cached_patterns = get_transient($cache_key);
			if ($cached_patterns !== false) {
				return $cached_patterns;
			}
		}

		// Cache invalid or missing, scan files
		$pattern_files = glob(get_stylesheet_directory() . '/patterns/*.php');
		$patterns = [];

		if ($pattern_files === false) {
			$pattern_files = [];
		}

		foreach ($pattern_files as $pattern_file) {
			$pattern_data = get_file_data($pattern_file, array(
				'title'         => 'Title',
				'slug'          => 'Slug',
				'description'   => 'Description',
				'viewportWidth' => 'Viewport Width',
				'inserter'      => 'Inserter',
				'categories'    => 'Categories',
				'keywords'      => 'Keywords',
				'blockTypes'    => 'Block Types',
				'postTypes'     => 'Post Types',
				'templateTypes' => 'Template Types',
				'synced'	=> 'Synced',
			));

			// if the pattern is not synced skip it 
			if ($pattern_data['synced'] !== 'yes') {
				continue;
			}

			$pattern_data['file'] = $pattern_file;

			$patterns[] = $pattern_data;
		}

		// Cache the results (12 hours)
		if ($current_dir_mtime !== false) {
			set_transient($cache_key, $patterns, 12 * HOUR_IN_SECONDS);
			set_transient($cache_key . '_mtime', $current_dir_mtime, 12 * HOUR_IN_SECONDS);
		}

		return $patterns;
	}

	/**
	 * Filters pattern block data to apply attributes to nested wp:block.
	 * This allows patterns with content attributes to pass those attributes down to the nested wp:block.
	 *
	 * @param array $parsed_block The parsed block data.
	 * @param array $source_block The original block data.
	 * @return array Modified block data.
	 */
	public function pass_pattern_content_to_referenced_patterns($pre_render, $parsed_block)
	{
		// Only process wp:pattern blocks
		if ($parsed_block['blockName'] !== 'core/pattern') {
			return $pre_render;
		}

		// Extract attributes from the pattern block
		$pattern_attrs = isset($parsed_block['attrs']) ? $parsed_block['attrs'] : [];

		$slug = $pattern_attrs['slug'] ?? '';

		// Remove attributes we don't want to pass down
		unset($pattern_attrs['slug']);

		// If no attributes to apply, return as-is
		if (empty($pattern_attrs)) {
			return $pre_render;
		}

		$synced_pattern_id = self::$synced_theme_patterns[$slug] ?? null;

		// if there is a synced_pattern_id then contruct the block with a reference to the synced pattern that also has the rest of the pattern's attributes and render it.
		if ($synced_pattern_id) {
			$block_attributes = array_merge(
				['ref' => $synced_pattern_id],
				$pattern_attrs
			);
			$block_attributes = wp_json_encode($block_attributes);
			$block_string = "<!-- wp:block $block_attributes /-->";
			return do_blocks($block_string);
		}

		return $pre_render;
	}
}