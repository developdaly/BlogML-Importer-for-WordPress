<?php
/*
Plugin Name: BlogML Importer
Plugin URI: http://wordpress.org/extend/plugins/blogml-importer
Description: Import posts, comments, users, and categories from a BlogML file. Based on BlogML importer written by Aaron Lerch (http://www.aaronlerch.com/) and modified to work with Wordpress 3.0. Plugin will also generate a CSV file with URL mappings to assist with URL rewriting.
Author: Sean Patterson
Author URI: http://dillieodigital.net/2010/07/03/blogml-importer
Version: 1.0
Stable tag: 1.0
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

require_once('XPath.class.php');

/**
 * BlogML Importer
 *
 * @package WordPress
 * @subpackage Importer
 */

/**
 * BLogML Importer
 *
 * Will import posts, comments, users, and categories from a BlogML into 
 * WordPress. Will also generate a CSV file with URL mappings. 
 *
 * @since unknown
 */
if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

if ( class_exists( 'WP_Importer' ) ) {
class BlogML_Import {

	var $xPath;
	var $posts;
	var $posts_processed = array (); // Array of arrays. [[0] => XML fragment, [1] => New post ID]
    var $old_new_post_mapping = array (); // Key is old permalink URL, value is new permalink URL
    var $authors = array ();
    var $categories = array ();
	var $file;
	var $id;
	var $blogmlnames = array ();
	var $newauthornames = array ();
	var $j = -1;

	function header() {
		echo '<div class="wrap">';
		echo '<h2>'.__('Import BlogML').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__('Upload your <a href="http://blogml.codeplex.com/">BlogML</a> file and we&#8217;ll import the posts, comments, authors, and categories into this blog.').'</p>';
		echo '<p>'.__('Choose a BlogML file to upload, then click Upload file and import.').'</p>';
		wp_import_upload_form("admin.php?import=blogml&amp;step=1");
		echo '</div>';
	}

	function users_form($n) {
		global $wpdb, $testing;
		$users = $wpdb->get_results("SELECT * FROM $wpdb->users ORDER BY ID");
?><select name="userselect[<?php echo $n; ?>]">
	<option value="#NONE#">- Select -</option>
	<?php
		foreach ($users as $user) {
			echo '<option value="'.$user->user_login.'">'.$user->user_login.'</option>';
		}
?>
	</select>
	<?php
	}

	//function to check the authorname and do the mapping
	function checkauthor($author) {
		global $wpdb;
		//blogmlnames is an array with the names in the blogml import file
		$pass = 'changeme';
		if (!(in_array($author, $this->blogmlnames))) { //a new blogml author name is found
			++ $this->j;
			$this->blogmlnames[$this->j] = $author; //add that new blogml author name to an array
			$user_id = username_exists($this->newauthornames[$this->j]); //check if the new author name defined by the user is a pre-existing wp user
			if (!$user_id) { //banging my head against the desk now.
				if ($this->newauthornames[$this->j] == 'left_blank') { //check if the user does not want to change the authorname
					$user_id = wp_create_user($author, $pass);
					$this->newauthornames[$this->j] = $author; //now we have a name, in the place of left_blank.
				} else {
					$user_id = wp_create_user($this->newauthornames[$this->j], $pass);
				}
			} else {
				return $user_id; // return pre-existing wp username if it exists
			}
		} else {
			$key = array_search($author, $this->blogmlnames); //find the array key for $author in the $blogmlnames array
			$user_id = username_exists($this->newauthornames[$key]); //use that key to get the value of the author's name from $newauthornames
		}

		return $user_id;
	}
	
	function parse_blogml()
	{
		$sXml = file_get_contents($this->file);
		$this->xPath = new XPath();
		$this->xPath->importFromString($sXml);
		
		// Get authors
		$result = $this->xPath->match("/blog/authors/author");
		$countResult = count($result);
		for ($i = 0; $i < $countResult; $i++)
		{
			$authorId = $this->xPath->getAttributes($result[$i], "id");
			$this->authors[$i] = $authorId;
		}
		
		// Get Categories
		$result = $this->xPath->match("/blog/categories/category");
		$countResult = count($result);
		for ($i = 0; $i < $countResult; $i++)
		{
			$categoryId = $this->xPath->getAttributes($result[$i], "id");
			$this->categories[$i] = $categoryId;
		}		
		
		// Get posts
		$this->posts = $this->xPath->match("/blog/posts/post");
	}

	function get_authors_from_post() {
		$formnames = array ();
		$selectnames = array ();

		foreach ($_POST['user'] as $key => $line) {
			$newname = trim(stripslashes($line));
			if ($newname == '')
				$newname = 'left_blank'; //passing author names from step 1 to step 2 is accomplished by using POST. left_blank denotes an empty entry in the form.
			array_push($formnames, "$newname");
		} // $formnames is the array with the form entered names

		foreach ($_POST['userselect'] as $user => $key) {
			$selected = trim(stripslashes($key));
			array_push($selectnames, "$selected");
		}

		$count = count($formnames);
		for ($i = 0; $i < $count; $i ++) {
			if ($selectnames[$i] != '#NONE#') { //if no name was selected from the select menu, use the name entered in the form
				array_push($this->newauthornames, "$selectnames[$i]");
			} else {
				array_push($this->newauthornames, "$formnames[$i]");
			}
		}
	}

	function wp_authors_form() {
?>
<h2><?php _e('Assign Authors'); ?></h2>
<p><?php _e('To make it easier for you to edit and save the imported posts and drafts, you may want to change the name of the author of the posts. For example, you may want to import all the entries as <code>admin</code>s entries.'); ?></p>
<p><?php _e('If a new user is created by WordPress, the password will be set, by default, to "changeme". Quite suggestive, eh? ;)'); ?></p>
	<?php

		echo '<ol id="authors">';
		echo '<form action="?import=blogml&amp;step=2&amp;id=' . $this->id . '" method="post">';
		wp_nonce_field('import-blogml');
		$j = -1;
		foreach ($this->authors as $author) {
			++ $j;
			echo '<li>'.__('Current author:').' <strong>'.$author.'</strong><br />'.sprintf(__('Create user %1$s or map to existing'), ' <input type="text" value="'.$author.'" name="'.'user[]'.'" maxlength="30"> <br />');
			$this->users_form($j);
			echo '</li>';
		}

		echo '<input type="submit" value="Submit">'.'<br/>';
		echo '</form>';
		echo '</ol>';

	}

	function select_authors() {
		$file = wp_import_handle_upload();
		if ( isset($file['error']) ) {
			echo '<p>'.__('Sorry, there has been an error.').'</p>';
			echo '<p><strong>' . $file['error'] . '</strong></p>';
			return;
		}
		$this->file = $file['file'];
		$this->id = (int) $file['id'];

		$this->parse_blogml();
		$this->wp_authors_form();
	}

	function process_categories() {
		global $wpdb;
		
		$cat_names = (array) $wpdb->get_col("SELECT name FROM $wpdb->terms");

		while ( $cat_name = array_shift($this->categories) ) {

			// If the category exists we leave it alone
			if ( in_array($cat_name, $cat_names) )
				continue;

			// These are not yet supported coming from BlogML, but this remains as a placeholder
			$category_nicename	= $cat_name;
			$posts_private		= 0;
			$links_private		= 0;
			$category_parent	= '0';
			
			// TODO: Nested categories are not supported yet
			//$parent = // Get the parent category

			//if ( empty($parent) )
			//	$category_parent = '0';
			//else
			//	$category_parent = category_exists($parent);

			$catarr = compact('category_nicename', 'category_parent', 'posts_private', 'links_private', 'posts_private', 'cat_name');

			$cat_ID = wp_insert_category($catarr);
		}
	}

	function process_posts() {
		$i = -1;
		echo '<ol>';

		$numPosts = count($this->posts);
		//Kavinda: Uncomment the next line to test the import with only 10 post.
		//$numPosts = 10;
		for ($i = 0; $i < $numPosts; $i++) {
			$this->process_post($this->posts[$i]);
		}

		echo '</ol>';

		wp_import_cleanup($this->id);
		
		// Write out a CSV file with URL mappings - this should persist beyond this import,
		// so write it out to disk ourselves
		if (count($this->old_new_post_mapping) > 0)
		{
			$output_filename = 'permalinkmap.csv';
		
			// Delete old permalink file
			if (file_exists($output_filename))
			{
				unlink($output_filename);
			}
		
			$csv_file_contents = "OldPermalink,NewPermalink\n";
			foreach ($this->old_new_post_mapping as $key => $value)
			{
				// Append the items - escape any commas
				$csv_file_contents .= sprintf("%s,%s\n", str_replace(',', ',,', $key), str_replace(',', ',,', $value));
			}
			
			$fhandle = fopen($output_filename, 'w');
			fwrite($fhandle, $csv_file_contents);
			fclose($fhandle);
			
			echo '<a href="permalinkmap.csv">Click here to download a CSV file containing mappings from imported Permalinks to the new WordPress Permalinks</a><br />Note that this file is statically generated, it will need to be manually deleted.<br />';
		}

		echo '<h3>'.sprintf(__('All done.').' <a href="%s">'.__('Have fun!').'</a>', get_option('home')).'</h3>';
	}
  
	function process_post($post) {
		global $wpdb;

		$post_URL = $this->xPath->getAttributes($post, "post-url");
  		if ( $post_URL && !empty($this->posts_processed[$post_URL][1]) ) // Processed already
			return 0;
      
		// There are only ever one of these
		$post_title_node           = $this->xPath->match('title[1]', $post);
		$post_title                = $wpdb->escape(str_replace(array ('<![CDATA[', ']]>'), '', $this->xPath->getData($post_title_node[0])));
		$post_date                 = $this->getDate($this->xPath->getAttributes($post, "date-created"));
		$post_date_gmt             = $this->getDate($this->xPath->getAttributes($post, "date-created"));
		$comment_status            = 'open'; // Not supported yet - hard-coded to "open"
		$ping_status               = 'open'; // Not supported yet - hard-coded to "open"
		$blogml_post_approved      = (bool)$this->xPath->getAttributes($post, "approved");
		$post_status               = ($blogml_post_approved) ? "publish" : "draft"; // hard-code to either publsished or draft (draft could be changed to "private")
		$post_name_nodes           = $this->xPath->match('child::post-name[1]', $post);
		$post_name                 = $wpdb->escape($this->xPath->getData(str_replace(array ('<![CDATA[', ']]>'), '',$post_name_nodes[0])));
		$post_parent               = '0'; // not supported
		$menu_order                = '0'; // not supported
		$post_type                 = 'post'; // only support posts now - could be changed to support posts and articles
		$guid                      = $post_URL; // For now, it's the URL
		$primary_post_author_nodes = $this->xPath->match('authors/author[1]', $post);
		$post_author               = $wpdb->escape($this->xPath->getAttributes($primary_post_author_nodes[0], 'ref'));
		
		$contentNodes = $this->xPath->match("content[1]", $post);
		$is_base64_encoded = $this->getBoolean($this->xPath->getAttributes($contentNodes[0], "base64"));
		$post_content = $this->xPath->getData($contentNodes[0]);
		if ($is_base64_encoded) {
			$post_content = base64_decode($post_content);
		}
		$post_content = $wpdb->escape(str_replace(array ('<![CDATA[', ']]>'), '',$post_content));
		
		$categories = array ();
		$cat_results = $this->xPath->match("categories/category", $post);
		$cat_count = count($cat_results);
		for ($cat_index = 0; $cat_index < $cat_count; $cat_index++) {
			$categories[$cat_index] = $wpdb->escape($this->xPath->getAttributes($cat_results[$cat_index], 'ref'));
		}

		if ($post_id = post_exists($post_title, '', '')) {
			echo '<li>';
			printf(__('Post <i>%s</i> already exists.'), stripslashes($post_title));
		} else {
			echo '<li>';

			printf(__('Importing post <i>%s</i>...'), stripslashes($post_title));
			$post_author = $this->checkauthor($post_author); //just so that if a post already exists, new users are not created by checkauthor

			$postdata = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'post_name', 'comment_status', 'ping_status', 'post_modified', 'post_modified_gmt', 'guid', 'post_parent', 'menu_order', 'post_type');
			$comment_post_ID = $post_id = wp_insert_post($postdata);
			
			// Get the post permalink and associate with the old post URL
			$post_permalink = get_permalink($post_id);
			
			$this->old_new_post_mapping[$post_URL] = $post_permalink;

			// Memorize old and new ID.
			if ( $post_id && $post_URL && $this->posts_processed[$post_URL] )
				$this->posts_processed[$post_URL][1] = $post_id; // New ID.
			
			// Add categories.
			if (count($categories) > 0) {
				$post_cats = array();
				foreach ($categories as $category) {
					$cat_ID = (int) $wpdb->get_var("SELECT term_id FROM $wpdb->terms WHERE name = '$category'");
					if ($cat_ID == 0) {
						$cat_ID = wp_insert_category(array('cat_name' => $category));
					}
					$post_cats[] = $cat_ID;
				}
				wp_set_post_categories($post_id, $post_cats);
			}	
		}
		// Now for comments
		$commentsNodes = $this->xPath->match("comments/comment", $post);
		$num_comments = 0;
		if ( count($commentsNodes) > 0 ) { foreach ($commentsNodes as $comment) {
			$comment_author       = $wpdb->escape($this->xPath->getAttributes($comment, 'user-name'));
			$comment_author_email = $wpdb->escape($this->xPath->getAttributes($comment, 'user-email'));
			$comment_author_IP    = ''; // Unsupported
			$comment_author_url   = $wpdb->escape($this->xPath->getAttributes($comment, 'user-url'));
			$comment_date         = $this->getDate($this->xPath->getAttributes($comment, 'date-created'));
			$comment_date_gmt     = $this->getDate($this->xPath->getAttributes($comment, 'date-created'));
			$commentContentNodes = $this->xPath->match("content[1]", $comment);
			$is_comment_base64_encoded = $this->getBoolean($this->xPath->getAttributes($commentContentNodes[0], "base64"));
			$comment_content = $this->xPath->getData($commentContentNodes[0]);
			if ($is_comment_base64_encoded) {
				$comment_content = base64_decode($comment_content);
			}
			$comment_content = $wpdb->escape(str_replace(array ('<![CDATA[', ']]>'), '',$comment_content));
			$is_comment_approved  = (bool) $this->xPath->getAttributes($comment, 'approved');
			$comment_approved     = ($is_comment_approved) ? '1' : '0';
			$comment_type         = ''; // I can't tell what data this is looking for - 
										// the wordpress export has it as empty, so we do too. :)
			$comment_parent       = '0'; // we don't currently support parented comments

			if ( !comment_exists($comment_author, $comment_date) ) {
				$commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_url', 'comment_author_email', 'comment_author_IP', 'comment_date', 'comment_date_gmt', 'comment_content', 'comment_approved', 'comment_type', 'comment_parent');
				wp_insert_comment($commentdata);
				$num_comments++;
			}
		} }

		if ( $num_comments )
			printf(' '.__('(%s comments)'), $num_comments);
			
		echo '</li>';

	}
	
	function getBoolean($string_value)
	{
		if (strcasecmp($string_value, 'false') == 0)
		{
			return false;
		}
		else if (strcasecmp($string_value, 'true') == 0)
		{
			return true;
		}
		else
		{
			return (bool)$string_value;
		}
	}
	
	function getDate($date_string)
	{
		// BlogML can output date formats
		// that aren't properly input by mysql
		// For example, "2007-07-20T11:14:00.7027456-04:00"
		// "7027456" is too large, but "702745" works - so we manually
		// strip off the last digit, if it's 7 characters.
		// (yucky)
		if (preg_match('#([0-9]{4})\-([0-9]{2})\-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})\.([0-9]{7})\-([0-9]{2}):([0-9]{2})#', $date_string, $date_bits))
		{
			if (!empty($date_bits[7]))
			{
				// Reformat the date to strip the last digit off of group #7
				$date_string = sprintf("%s-%s-%sT%s:%s:%s.%s-%s:%s", $date_bits[1], $date_bits[2], $date_bits[3], $date_bits[4], $date_bits[5], $date_bits[6], substr($date_bits[7], 0, 6), $date_bits[8], $date_bits[9]);
			}
		}
		
		return $date_string;
	}

	function import() {
		$this->id = (int) $_GET['id'];

		$this->file = get_attached_file($this->id);
		$this->get_authors_from_post();
		$this->parse_blogml();
		$this->process_categories();
		$this->process_posts();
	}

	function dispatch() {
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		$this->header();
		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				$this->select_authors();
				break;
			case 2:
				check_admin_referer('import-blogml');
				$this->import();
				break;
		}
		$this->footer();
	}

	function BlogML_Import() {
		// Nothing.
	}
 }
}

// Instantiate and register the importer
$blogml_import = new BlogML_Import();
register_importer('blogml', __('BlogML', 'blogml-importer'), __('Import posts, comments, users, and categories from a BlogML file', 'blogml-importer'), array ($blogml_import, 'dispatch'));

function blogml_importer_init() {
    load_plugin_textdomain( 'blogml-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'blogml_importer_init' );

?>