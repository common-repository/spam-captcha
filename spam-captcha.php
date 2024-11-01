<?php
/**
 *
 * Spam Captcha
 *
 * @package   Spam Captcha
 * @author    Kaizen Coders <hello@kaizencoders.com>
 * @license   GPL-3.0+
 * @link      https://wordpress.org/plugins/spam-captcha/
 * @copyright 2020-22 KaizenCoders
 *
 * @wordpress-plugin
 *
 * Plugin Name:       Spam Captcha
 * Plugin URI:        https://wordpress.org/plugins/spam-captcha/
 * Plugin Tag:        spam, captcha, comments, comment, akismet, block, Contact Form 7, ban, block, Automatic Ban IP
 * Description:       <p>This plugins avoids spam actions on your website (comments and contact form if you use Contact Form 7).</p><p>Captcha image and Akismet API are available to detect spam for this plugin.</p><p>In addition, it is possible to ban spammers using Automatic Ban IP plugin.</p><p>You may configure (for the captcha): </p><ul><li>The color of the background</li><li>The color of the letters</li><li>The size of the image</li><li>The size of the letters</li><li>The slant of the letters</li><li>...</li></ul><p>This plugin is under GPL licence</p>
 * Version:           1.4.4
 * Author:            KaizenCoders
 * Author URI:        https://kaizencoders.com/
 * Tested up to:      6.4.3
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses
 * Domain Path:       /lang
 *
 */

//Including the framework in order to make the plugin work

require_once('core.php') ; 

/** ====================================================================================================================================================
* This class has to be extended from the pluginSedLex class which is defined in the framework
*/
class spam_captcha extends pluginSedLex {

	/** ====================================================================================================================================================
	* Plugin initialization
	* 
	* @return void
	*/
	static $instance = false;

	protected function _init() {
		global $wpdb ; 
		
		// Name of the plugin (Please modify)
		$this->pluginName = 'Spam Captcha' ; 
		
		// The structure of the SQL table
		$this->table_name = $wpdb->prefix . "pluginSL_" . get_class() ; 
		$this->tableSQL = "id int NOT NULL AUTO_INCREMENT, id_comment mediumint(9) NOT NULL, status VARCHAR(10) DEFAULT 'ok', new_status VARCHAR(10) DEFAULT 'ok', author TEXT DEFAULT '', content TEXT DEFAULT '', captcha_info TEXT DEFAULT '', date TIMESTAMP, UNIQUE KEY id_post (id)" ; 
		
		//Configuration of callbacks, shortcode, ... (Please modify)
		// For instance, see 
		//	- add_shortcode (http://codex.wordpress.org/Function_Reference/add_shortcode)
		//	- add_action 
		//		- http://codex.wordpress.org/Function_Reference/add_action
		//		- http://codex.wordpress.org/Plugin_API/Action_Reference
		//	- add_filter 
		//		- http://codex.wordpress.org/Function_Reference/add_filter
		//		- http://codex.wordpress.org/Plugin_API/Filter_Reference
		// Be aware that the second argument should be of the form of array($this,"the_function")
		// For instance add_action( "the_content",  array($this,"modify_content")) : this function will call the function 'modify_content' when the content of a post is displayed

		add_action('parse_request', array($this,'check_if_captcha_image'), 1, 1);
		
		add_action('preprocess_comment', array($this,'check_comment_captcha'), 1, 1);
		add_action('wp_insert_comment', array($this,'check_comment_akismet'), 100, 2);

		// Quand on change le status vers approve
		add_action('comment_spam_to_approved', array($this,'change_to_ham'), 1, 1);
		add_action('comment_spam_to_unapproved', array($this,'change_to_ham'), 1, 1);
		add_action('comment_trash_to_approved', array($this,'change_to_ham'), 1, 1);
		// Quand on change le status vers spam
		add_action('comment_approved_to_spam', array($this,'change_to_spam'), 1, 1);
		add_action('comment_unapproved_to_spam', array($this,'change_to_spam'), 1, 1);

		add_filter('comments_template', array(&$this, 'detect_start'), 1);
		add_action('comment_form', array(&$this, 'detect_end'), 1000);
		add_action('wp_footer', array(&$this, 'detect_end'), 1000);
		
		add_shortcode( 'spam_captcha', array( $this, 'spam_captcha_shortcode' ) );

		// Important variables initialisation (Do not modify)
		$this->path = __FILE__ ; 
		$this->pluginID = get_class() ; 
		
		// activation and deactivation functions (Do not modify)
		register_activation_hook(__FILE__, array($this,'install'));
		register_deactivation_hook(__FILE__, array($this,'deactivate'));
		register_uninstall_hook(__FILE__, array('spam_captcha','uninstall_removedata'));
		
		if ($this->get_param('contactform7')) {
			add_filter( 'wpcf7_form_elements', array(&$this, 'wpcf7_form_elements') );
			add_filter('wpcf7_validate_email*', array(&$this, 'wpcf7_validate_email')) ; 
		}
		
		$this->captcha_ok = true ; 
	}
	

	
	/** ====================================================================================================================================================
	* In order to uninstall the plugin, few things are to be done ... 
	* (do not modify this function)
	* 
	* @return void
	*/
	
	public function uninstall_removedata () {
		global $wpdb ;
		// DELETE OPTIONS
		delete_option('spam_captcha'.'_options') ;
		if (is_multisite()) {
			delete_site_option('spam_captcha'.'_options') ;
		}
		
		// DELETE SQL
		if (function_exists('is_multisite') && is_multisite()){
			$old_blog = $wpdb->blogid;
			$old_prefix = $wpdb->prefix ; 
			// Get all blog ids
			$blogids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM ".$wpdb->blogs));
			foreach ($blogids as $blog_id) {
				switch_to_blog($blog_id);
				$wpdb->query("DROP TABLE ".str_replace($old_prefix, $wpdb->prefix, $wpdb->prefix . "pluginSL_" . 'spam_captcha')) ; 
			}
			switch_to_blog($old_blog);
		} else {
			$wpdb->query("DROP TABLE ".$wpdb->prefix . "pluginSL_" . 'spam_captcha' ) ; 
		}
		
		// DELETE FILES if needed
		//SLFramework_Utils::rm_rec(WP_CONTENT_DIR."/sedlex/my_plugin/"); 
		$plugins_all = 	get_plugins() ; 
		$nb_SL = 0 ; 	
		foreach($plugins_all as $url => $pa) {
			$info = pluginSedlex::get_plugins_data(WP_PLUGIN_DIR."/".$url);
			if ($info['Framework_Email']=="sedlex@sedlex.fr"){
				$nb_SL++ ; 
			}
		}
		if ($nb_SL==1) {
			SLFramework_Utils::rm_rec(WP_CONTENT_DIR."/sedlex/"); 
		}
	}

	/**====================================================================================================================================================
	* Function called when the plugin is activated
	* For instance, you can do stuff regarding the update of the format of the database if needed
	* If you do not need this function, you may delete it.
	*
	* @return void
	*/
	
	public function _update() {
		global $wpdb;
		$table_name = $this->table_name;
		$old_table_name = $wpdb->prefix . $this->pluginID ; 
		
		// This update aims at upgrading older version of shorten-link to enable to create custom shorturl (i.e. with external URL)
		//  For information previous table are :
		// 	id_post mediumint(9) NOT NULL, short_url TEXT DEFAULT '', UNIQUE KEY id_post (id_post)
		// and now it is 
		//	id_post mediumint(9) NOT NULL, short_url TEXT DEFAULT '', url_externe VARCHAR( 255 ) NOT NULL DEFAULT '' ,UNIQUE KEY id_post (id_post, url_externe)
	
		if ( !$wpdb->get_var("SHOW COLUMNS FROM ".$table_name." LIKE 'captcha_info'")  ) {
			$wpdb->query("ALTER TABLE ".$this->table_name." ADD captcha_info  TEXT DEFAULT '';");
		}
		
	}
	
	/**====================================================================================================================================================
	* Function to instantiate the class and make it a singleton
	* This function is not supposed to be modified or called (the only call is declared at the end of this file)
	*
	* @return void
	*/
	
	public static function getInstance() {
		if ( !self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}
	
	/** ====================================================================================================================================================
	* Init javascript for the admin side
	* If you want to load a script, please type :
	* 	<code>wp_enqueue_script( 'jsapi', 'https://www.google.com/jsapi');</code> or 
	*	<code>wp_enqueue_script('my_plugin_script', plugins_url('/script.js', __FILE__));</code>
	*
	* @return void
	*/
	
	function _admin_js_load() {	
		$this->add_js(plugin_dir_url("/").'/'.str_replace(basename( __FILE__),"",plugin_basename( __FILE__)) .'js/raphael-min.js') ; 
		$this->add_js(plugin_dir_url("/").'/'.str_replace(basename( __FILE__),"",plugin_basename( __FILE__)) .'js/elycharts.min.js') ; 

		return ; 
	}
		
	/** ====================================================================================================================================================
	* Define the default option values of the plugin
	* This function is called when the $this->get_param function do not find any value fo the given option
	* Please note that the default return value will define the type of input form: if the default return value is a: 
	* 	- string, the input form will be an input text
	*	- integer, the input form will be an input text accepting only integer
	*	- string beggining with a '*', the input form will be a textarea
	* 	- boolean, the input form will be a checkbox 
	* 
	* @param string $option the name of the option
	* @return variant of the option
	*/
	public function get_default_option($option) {
		switch ($option) {
			// Alternative default return values (Please modify)
			case 'akismet_id' 		: return "" 		; break ; 
			case 'akismet_enable' 		: return false 	; break ; 
			
			case 'captcha_enable' 		: return false 	; break ; 
			case 'captcha_text' 		: return true 	; break ; 
			case 'captcha_addition' 	: return false 	; break ; 
			case 'captcha_logged' 		: return false 	; break ; 
			case 'captcha_number' 		: return 5 	; break ; 
			case 'captcha_height' 		: return 80 	; break ; 
			case 'captcha_width' 		: return 200 	; break ; 
			case 'captcha_angle' 		: return 35 	; break ; 
			case 'captcha_size' 		: return 30 	; break ; 
			case 'captcha_background' 		: return "555555" 	; break ; 
			case 'captcha_font_color' 		: return "BBBBBB" 	; break ; 
			case 'captcha_color_variation_percentage' 		: return 10	; break ; 
			case 'captcha_noise' 		: return true 	; break ; 
			case 'captcha_color_variation' 		: return true 	; break ; 
			case 'captcha_wave' 		: return false 	; break ; 
			case 'captcha_wave_period' 		: return 20 	; break ; 
			case 'captcha_wave_amplitude' 		: return 10 	; break ; 
			
			case 'flush_nb_jours' 		: return 30 	; break ; 
			case 'flush_max_entry' 		: return 10000 	; break ; 
			
			case 'contactform7' 		: return false 	; break ; 
			
			case 'ban_ip_cookies' 		: return false 	; break ; 
			case 'ban_ip_akismet' 		: return false 	; break ; 

			case 'captcha_html' 		: return "*<div class='captcha_image'> 
<p>Please type the characters of this captcha image in the input box</p>
%image% 
<input type='text' id='captcha_comment' name='captcha_comment' />
</div>" 	; break ; 

			case 'captcha_css' 		: return "*.captcha_image {
	
}
.captcha_comment {
	
}" 	; break ; 
		}
		return null ;
	}
	
	/** ====================================================================================================================================================
	* Init css for the public side
	* If you want to load a style sheet, please type :
	*	<code>$this->add_inline_css($css_text);</code>
	*	<code>$this->add_css($css_url_file);</code>
	*
	* @return void
	*/
	
	function _public_css_load() {	
		$this->add_inline_css($this->get_param('captcha_css')) ; 
	}

	/** ====================================================================================================================================================
	* The admin configuration page
	* This function will be called when you select the plugin in the admin backend 
	*
	* @return void
	*/
	
	public function configuration_page() {
		global $wpdb;
		?>
		<div class="plugin-titleSL">
			<h2><?php echo $this->pluginName ?></h2>
		</div>
		
		<div class="plugin-contentSL">		
			<?php echo $this->signature ; ?>

			<?php
			//===============================================================================================
			// After this comment, you may modify whatever you want
			
			// On verifie que les droits sont corrects
			$this->check_folder_rights( array() ) ; 
			
			$tabs = new SLFramework_Tabs() ; 
			
			ob_start() ; 
				echo "<h3>".__("Pie chart summary", $this->pluginID)."</h3>" ; 
				
				// We set the javascript 
				$nb_captcha_no_enter = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='captcha' AND captcha_info LIKE '%\"\"%'") ; 
				$nb_captcha_no_cookie = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='captcha' AND captcha_info NOT LIKE '%\"\"%' AND captcha_info LIKE '%should be '") ; 
				$nb_captcha_other = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='captcha' AND captcha_info NOT LIKE '%\"\"%' AND captcha_info NOT LIKE '%should be '") ; 
				
				$nb_spam = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='spam' AND new_status='spam'") ; 
				$nb_ham = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='ok' AND new_status='ok'") ; 
				$nb_false_spam = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='spam' AND new_status='ok'") ; 
				$nb_false_ham = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='ok' AND new_status='spam'") ; 
				?>
							<div id="spam_report" style="margin: 0; width:800px; height:500px;"></div>
							<script type="text/javascript">
							jQuery(function() {
								jQuery.elycharts.templates['spam_pie'] = {
									type : "pie",
									defaultSeries : {
										plotProps : {
											stroke : "white",
											"stroke-width" : 1,
											opacity : 0.8
										},
										highlight : {
											move : 10
										},
										tooltip : {
											frameProps : {
												opacity: 0.95,
												fill: "#292929",
												stroke: "#CDCDCD",
												'stroke-width': 1
											},
											height : 20,
											width : 300,
											padding: [3, 3],
											offset : [10, 0],
											contentStyle : {
												"font-weight": "normal",
												"font-family": "sans-serif, Verdana", 
												color: "#FFFFFF",
												"text-align": "center"
											}
										}
									},
									features : {
										legend : {
											horizontal : false,
											width : 300,
											height : 200,
											x : 1,
											y : 60,
											borderProps : {
												"fill-opacity" : 0.3
											}
										}
									}
								};
								
								jQuery('#spam_report').chart({
									template : "spam_pie",
									values : {
										serie1 : [<?php
											echo $nb_captcha_no_enter."," ; 
											echo $nb_captcha_no_cookie."," ; 
											echo $nb_captcha_other."," ; 
											echo $nb_spam."," ; 
											echo $nb_false_ham."," ; 
											echo $nb_ham."," ; 
											echo $nb_false_spam ; 
										?>]
									},
									labels : [<?php
										echo "'".__('Blocked by CAPTCHA (nothing entered)', $this->pluginID)."'," ; 
										echo "'".__('Blocked by CAPTCHA (no session cookie)', $this->pluginID)."'," ; 
										echo "'".__('Blocked by CAPTCHA (mismatch)', $this->pluginID)."'," ; 
										echo "'".__('Blocked by AKISMET', $this->pluginID)."'," ; 
										echo "'".__('Not Blocked by AKISMET but spam', $this->pluginID)."'," ; 
										echo "'".__('Normal comment', $this->pluginID)."'," ; 
										echo "'".__('Blocked by AKISMET but normal', $this->pluginID)."'" ; 
									?>],
									legend : [<?php
										echo "'".__('Blocked by CAPTCHA (nothing entered)', $this->pluginID)."'," ; 
										echo "'".__('Blocked by CAPTCHA (no session cookie)', $this->pluginID)."'," ; 
										echo "'".__('Blocked by CAPTCHA (mismatch)', $this->pluginID)."'," ; 
										echo "'".__('Blocked by AKISMET', $this->pluginID)."'," ; 
										echo "'".__('Not Blocked by AKISMET but spam', $this->pluginID)."'," ; 
										echo "'".__('Normal comment', $this->pluginID)."'," ; 
										echo "'".__('Blocked by AKISMET but normal', $this->pluginID)."'" ; 
									?>],
									tooltips : {
										serie1 : [<?php
										echo "'".__('Blocked by CAPTCHA (nothing entered)', $this->pluginID).': '.$nb_captcha_no_enter."'," ; 
										echo "'".__('Blocked by CAPTCHA (no session cookie)', $this->pluginID).': '.$nb_captcha_no_cookie."'," ; 
										echo "'".__('Blocked by CAPTCHA (mismatch)', $this->pluginID).': '.$nb_captcha_other."'," ; 
										echo "'".__('Blocked by AKISMET', $this->pluginID).': '.$nb_spam."'," ; 
										echo "'".__('Not Blocked by AKISMET but spam', $this->pluginID).': '.$nb_false_ham."'," ; 
										echo "'".__('Normal comment', $this->pluginID).': '.$nb_ham."'," ; 
										echo "'".__('Blocked by AKISMET but normal', $this->pluginID).': '.$nb_false_spam."'" ; 
										?>]
									},
									defaultSeries : {
										values : [<?php
											echo '{plotProps : {fill : "#FF6060" }},' ; 
											echo '{plotProps : {fill : "#E65656" }},' ; 
											echo '{plotProps : {fill : "#CF4D4D" }},' ; 
											echo '{plotProps : {fill : "#FF9D26" }},' ; 
											echo '{plotProps : {fill : "#FF4F4F" }},' ; 
											echo '{plotProps : {fill : "#14FF56" }},' ; 
											echo '{plotProps : {fill : "#9DFF1E" }}' ; 
										?>]
									}
								});
							});
							</script>
				<?php
				
				$nb_jours = 15 ; 
				
				echo "<h3>".sprintf(__("Last %s days summary", $this->pluginID), $nb_jours)."</h3>" ; 
				
				$history = "" ; 
				$first = true ; 
				
				$max_value = 0 ; 
				
				$rows_series1 = "" ;
				$rows_series2 = "" ;
				$rows_series3 = "" ;
				$rows_series4 = "" ;
				$rows_series5 = "" ;
				$rows_series1_t = "" ;
				$rows_series2_t = "" ;
				$rows_series3_t = "" ;
				$rows_series4_t = "" ;
				$rows_series5_t = "" ;
				$rows_legend = "" ;
				
				for ($i=0 ; $i<$nb_jours ; $i++) {
					if (!$first) {
						$rows_series1 = ",".$rows_series1 ;
						$rows_series2 = ",".$rows_series2 ;
						$rows_series3 = ",".$rows_series3 ;
						$rows_series4 = ",".$rows_series4 ;
						$rows_series5 = ",".$rows_series5 ;
						$rows_series1_t = ",".$rows_series1_t ;
						$rows_series2_t = ",".$rows_series2_t ;
						$rows_series3_t = ",".$rows_series3_t ;
						$rows_series4_t = ",".$rows_series4_t ;
						$rows_series5_t = ",".$rows_series5_t ;
						$rows_legend = ",".$rows_legend ;
					}
					$first = false ;  
					$nb_captcha_no_enter = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='captcha' AND captcha_info LIKE '%\"\"%' AND DATE(date)=DATE(DATE_SUB(NOW(), INTERVAL ".$i." DAY))") ; 
					$nb_captcha_no_cookie = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='captcha' AND captcha_info NOT LIKE '%\"\"%' AND captcha_info LIKE '%should be ' AND DATE(date)=DATE(DATE_SUB(NOW(), INTERVAL ".$i." DAY))") ; 
					$nb_captcha_other = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='captcha' AND captcha_info NOT LIKE '%\"\"%' AND captcha_info NOT LIKE '%should be ' AND DATE(date)=DATE(DATE_SUB(NOW(), INTERVAL ".$i." DAY))") ; 
				
					$nb_ok = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='ok' AND DATE(date)=DATE(DATE_SUB(NOW(), INTERVAL ".$i." DAY))") ; 
					$date_mesure = date_i18n(get_option('date_format') , strtotime("-".$i." day")) ; 
					
					$rows_legend = "'".$date_mesure."'".$rows_legend ; 
					$rows_series1 = $nb_ok.$rows_series1 ; 
					$rows_series2 = $nb_captcha_no_enter.$rows_series2 ; 
					$rows_series3 = $nb_captcha_no_cookie.$rows_series3 ; 
					$rows_series4 = $nb_captcha_other.$rows_series4 ; 
					$rows_series5 = $nb_spam.$rows_series5 ; 
					$rows_series1_t = "'".__('Normal comment', $this->pluginID).": ".$nb_ok."'".$rows_series1_t ; 
					$rows_series2_t = "'".__('Blocked by CAPTCHA (nothing entered)', $this->pluginID).": ".$nb_captcha_no_enter."'".$rows_series2_t ; 
					$rows_series3_t = "'".__('Blocked by CAPTCHA (no session cookie)', $this->pluginID).": ".$nb_captcha_no_cookie."'".$rows_series3_t ; 
					$rows_series4_t = "'".__('Blocked by CAPTCHA (mismatch)', $this->pluginID).": ".$nb_captcha_other."'".$rows_series4_t ; 
					$rows_series5_t = "'".__('Comment blocked by Akismet', $this->pluginID).": ".$nb_spam."'".$rows_series5_t ; 
					
					$max_value = max($max_value, $nb_ok, $nb_captcha_no_enter, $nb_captcha_no_cookie, $nb_captcha_other, $nb_spam) ; 
				}
											
				?>
				<div id="catcha_count" style="margin: 0px auto; width:800px; height:500px;"></div>
				<script  type="text/javascript">
					jQuery(function(){
						jQuery.elycharts.templates['spam_count'] = {
							type : "line",
							margins : [10, 10, 80, 50],
							defaultSeries : {
								plotProps : {
									opacity : 0.6
								},
								highlight : {
									overlayProps : {
										fill : "white",
										opacity : 0.2
									}
								},
								tooltip : {
									frameProps : {
										opacity: 0.95,
										fill: "#292929",
										stroke: "#CDCDCD",
										'stroke-width': 1
									},
									height : 20,
									width : 300,
									padding: [3, 3],
									offset : [10, 0],
									contentStyle : {
										"font-weight": "normal",
										"font-family": "sans-serif, Verdana", 
										color: "#FFFFFF",
										"text-align": "center"
									}
								},
							},
							series : {
								serie1 : {
									color : "#14FF56"
								},
								serie2 : {
									color : "#FF6060"
								},
								serie3 : {
									color : "#E65656"
								},
								serie4 : {
									color : "#CF4D4D"
								},
								serie5 : {
									color : "#FF9D26"
								}
							},
							defaultAxis : {
								labels : true
							},
							axis : {
								l  : {
									max:<?php echo $max_value?>, 
									labelsProps : {
									font : "10px Verdana"
									}
								}, 
								x : {
									labelsRotate : 35,
									labelsProps : {
									font : "10px bold Verdana"
									}
								}
							},
							features : {
								grid : {
									draw : [true, false],
									forceBorder : false,
									evenHProps : {
										fill : "#FFFFFF",
										opacity : 0.2
									},
									oddHProps : {
										fill : "#AAAAAA",
										opacity : 0.2
									}
								}
							}
						};
					
						jQuery("#catcha_count").chart({
							 template : "spam_count",
							 tooltips : {
							  serie1 : <?php echo "[$rows_series1_t]" ; ?>,
							  serie2 : <?php echo "[$rows_series2_t]" ; ?>,
							  serie3 : <?php echo "[$rows_series3_t]" ; ?>,
							  serie4 : <?php echo "[$rows_series4_t]" ; ?>,
							  serie5 : <?php echo "[$rows_series5_t]" ; ?>
							 },
							 values : {
							  serie1 : <?php echo "[$rows_series1]" ; ?>,
							  serie2 : <?php echo "[$rows_series2]" ; ?>,
							  serie3 : <?php echo "[$rows_series3]" ; ?>,
							  serie4 : <?php echo "[$rows_series4]" ; ?>,
							  serie5 : <?php echo "[$rows_series5]" ; ?>
							 },
							 labels : <?php echo "[$rows_legend]" ; ?>,
							 defaultSeries : {
							  type : "bar"
							 },
							 barMargins : 10
						});
					});								
				</script>
				<?php
				
				$nb_captcha = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='captcha'") ; 
				$nb_spam = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='spam' AND new_status='spam'") ; 
				$nb_ham = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='ok' AND new_status='ok'") ; 
				$nb_false_spam = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='spam' AND new_status='ok'") ; 
				$nb_false_ham = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='ok' AND new_status='spam'") ; 

				echo "<h3>".__("Detailled summary", $this->pluginID)."</h3>" ; 
				$table = new SLFramework_Table() ; 
				$table->title(array(__("Type of protection", $this->pluginID), __("Summary", $this->pluginID))) ; 
				$cel1 = new adminCell("<p>".__("CAPTCHA protection:", $this->pluginID)."</p>") ;	
				$cel2 = new adminCell("<p>".sprintf(__("%s messages have been blocked as the CAPTCHA test failed", $this->pluginID), "<b>".$nb_captcha."</b>")."</p>") ; 		
				$table->add_line(array($cel1, $cel2), '1') ; 
				$cel1 = new adminCell("<p>".__("AKISMET protection:", $this->pluginID)."</p>") ; 	
				$cel2 = new adminCell("<p>".sprintf(__('This plugin have stopped %s spam-comments (and missed %s that you have manually indicated as being spam)', $this->pluginID), "<b>".$nb_spam."</b>", "<b>".$nb_false_ham."</b>")."</p><p>".sprintf(__('Moreover, this plugin processes %s normal comments (and marked as spam %s that you have manually indicated as being normal)', $this->pluginID), "<b>".$nb_ham."</b>", "<b>".$nb_false_spam."</b>")."</p>") ; 		
				$table->add_line(array($cel1, $cel2), '2') ; 
				echo $table->flush() ; 
			$tabs->add_tab(__('Summary of Protection',  $this->pluginID), ob_get_clean() ) ; 	
			
			ob_start() ; 	
				$params = new SLFramework_Parameters($this, "tab-parameters") ; 
				
				$params->add_title(__("Do you want to enable CAPTCHA for posting comments?", $this->pluginID)) ; 
				if  ( (!function_exists("imagecreate")) || (!function_exists("imagefill")) || (!function_exists("imagecolorallocate")) || (!function_exists("imagettftext")) || (!function_exists("imageline")) || (!function_exists("imagepng")) ) {
					$params->add_comment(sprintf(__("Your server does not seems to have GD installed with the following functions: %s", $this->pluginID), "<code>imagecreate</code>, <code>imagefill</code>, <code>imagecolorallocate</code>, <code>imagettftext</code>, <code>imageline</code>, <code>imagepng</code>")) ; 
					$params->add_comment(__("Thus, it is not possible to activate this option... sorry !", $this->pluginID)) ; 
				} else {
					$params->add_param('captcha_enable', __('Yes/No (for commenting only):', $this->pluginID), "", "", array('captcha_logged', 'captcha_number', 'captcha_width', 'captcha_height', 'captcha_angle', 'captcha_size', 'captcha_background', 'captcha_font_color', 'captcha_color_variation', 'captcha_noise', 'captcha_html', 'captcha_text', 'captcha_addition', 'captcha_wave', 'captcha_wave_period', 'captcha_wave_amplitude')) ; 
					if (is_multisite()) {
						$blogurl = home_url()  ; 
					} else {
						$blogurl = network_home_url()  ; 
					}
					$params->add_comment(sprintf(__("The captcha will be like that : %s", $this->pluginID), "<img src='".$blogurl."/?display_captcha=true'>")) ; 
					$params->add_param('captcha_logged', __('Use Capctha even if user is logged:', $this->pluginID)) ; 					
					$params->add_comment(sprintf(__("WARNING: If you enable this option, the Captcha will be only effective with users with the following role: %s", $this->pluginID)." <br/>".__("Then the Captcha will be displayed (for instance, to make sure that the display/CSS/etc. is correct) but ineffective with users with the following role: %s", $this->pluginID), "<em>Subscriber</em>, <em>Contributor</em>, <em>Author</em>", "<em>Editor</em>, <em>Administrator</em>")) ; 
					$params->add_param('captcha_text', __('The captcha proposes a sequence of lower-case letters:', $this->pluginID)) ; 
					$params->add_param('captcha_addition', __('The captcha proposes an addition operation to solve:', $this->pluginID)) ; 
					$params->add_param('captcha_number', __('Number of letters:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_number'))) ; 
					$params->add_param('captcha_width', __('Width of image:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_width'))) ; 
					$params->add_param('captcha_height', __('Height of image:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_height'))) ; 
					$params->add_param('captcha_angle', __('Maximum +/- angle of letters:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_angle'))) ; 
					$params->add_param('captcha_size', __('Size of letters:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_size'))) ; 
					$params->add_param('captcha_background', __('Color of the background:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_background'))) ; 
					$params->add_param('captcha_font_color', __('Color of the font:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_font_color'))) ; 
					$params->add_param('captcha_color_variation', __('Variation of the color of the letters:', $this->pluginID)) ; 
					$params->add_comment(__("The color of the letters are not homogenous", $this->pluginID)) ; 
					$params->add_param('captcha_noise', __('Variation of the color of the background:', $this->pluginID)) ; 
					$params->add_comment(__("The color of the background are not homogenous", $this->pluginID)) ; 
					$params->add_param('captcha_color_variation_percentage', __('Percentage of variation (alea) of the colors (background and letters):', $this->pluginID)) ; 
					$params->add_param('captcha_html', __('The HTML that will be inserted in your page to display captcha image:', $this->pluginID)) ; 
					$params->add_comment(__("The default HTML is: ", $this->pluginID)) ;
					$params->add_comment_default_value('captcha_html') ;  
					$params->add_param('captcha_css', __('The CSS that will be inserted in your page to display captcha image:', $this->pluginID)) ; 
					$params->add_comment(__("The default CSS is: ", $this->pluginID)) ;
					$params->add_comment_default_value('captcha_css') ;  
					$params->add_comment(__("Please note that %s will be replace with the captcha image.", $this->pluginID)) ; 
					$params->add_comment(__("You may add some comment, for instance to make clear that the addition should be responded with the result of the operation.", $this->pluginID)) ; 
					$params->add_param('captcha_wave', __('The image will be slightly distorded:', $this->pluginID), "", "", array('captcha_wave_period','captcha_wave_amplitude')) ; 
					$params->add_param('captcha_wave_period', __('The period of the wave:', $this->pluginID)) ; 
					$params->add_param('captcha_wave_amplitude', __('The amplitude of the wave:', $this->pluginID)) ; 
				}
				
				$params->add_title(__("Do you want to use Akismet API to check spam against posted comments?", $this->pluginID)) ; 
				$params->add_param('akismet_enable', __('Yes/No:', $this->pluginID), "", "", array('akismet_id')) ; 
				$params->add_param('akismet_id', __('The Akismet ID:', $this->pluginID)) ; 
				if ($this->get_param('akismet_id')!="") {
					if ($this->verify_akismet_key($this->get_param('akismet_id'))) {
						$params->add_comment(__("Your Akismet ID is correct.", $this->pluginID)) ; 
					} else {
						$params->add_comment(__("Your Akismet ID does not seem to be correct.", $this->pluginID)."<br/>".sprintf(__("To get an Akismet ID, see %s here %s.", $this->pluginID),"<a href='http://akismet.com/get/'>", "</a>")) ; 
					}
				} else {
					$params->add_comment(sprintf(__("To get an Akismet ID, see %s here %s.", $this->pluginID),"<a href='http://akismet.com/get/'>", "</a>")) ; 
				}
				
				$params->add_title(__("Captcha in Contact Forms", $this->pluginID)) ; 
				$params->add_param('contactform7', sprintf(__('Add a captcha on %s:', $this->pluginID), '<code>Contact Form 7</code>')) ; 
				$params->add_comment(sprintf(__("Please install first this plugin %s.", $this->pluginID), '<code>Contact Form 7</code>')) ; 
				$params->add_comment(sprintf(__("In the form, please use the shortcode %s to display the captcha.", $this->pluginID), '<code>[spam_captcha]</code>')) ; 

				$params->add_title(__("Ban Spammer's IP addresses", $this->pluginID)) ; 
				$params->add_param('ban_ip_cookies', sprintf(__('Block IP of spammers that fails the CAPTCHA and do not have cookies (using %s plugin):', $this->pluginID), '<code>Automatic Ban IP</code>')) ; 
				if (class_exists('automatic_ban_ip')){
					$params->add_comment(sprintf(__("%s plugin is correctly installed and activated.", $this->pluginID), '<code>Automatic Ban IP</code>')) ; 
				} else {
					$params->add_comment(sprintf(__("Please install and activate %s plugin.", $this->pluginID), '<code>Automatic Ban IP</code>')) ; 					
				}				
				$params->add_param('ban_ip_akismet', sprintf(__('Block IP of spammers for which %s has detect spam (via %s plugin):', $this->pluginID), 'Akismet', '<code>Automatic Ban IP</code>')) ; 
				if (class_exists('automatic_ban_ip')){
					$params->add_comment(sprintf(__("The plugin %s is correctly installed and activated.", $this->pluginID), '<code>Automatic Ban IP</code>')) ; 
				} else {
					$params->add_comment(sprintf(__("Please install and activate %s plugin.", $this->pluginID), '<code>Automatic Ban IP</code>')) ; 					
				}				

				$params->add_title(__("Advanced parameters", $this->pluginID)) ; 
				$params->add_param('flush_nb_jours', __('Remove stats older than (in days):', $this->pluginID)) ; 
				$params->add_comment(__("Stats older than the predetermined number of days will be deleted to save database space.", $this->pluginID)) ; 
				$params->add_comment(__("0 means that no entry is deleted.", $this->pluginID)) ; 
				$params->add_param('flush_max_entry', __('Remove stats if there are more than (entries):', $this->pluginID)) ; 
				$params->add_comment(__("In order to avoid the SQL saturation.", $this->pluginID)) ; 
				

				$params->flush() ; 
			$tabs->add_tab(__('Parameters',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_param.png") ; 	

			ob_start() ;
				echo "<p>".__('This plugin enables the verification that a comment is not a spam.', $this->pluginID)."</p>" ;
			$howto1 = new SLFramework_Box (__("Purpose of that plugin", $this->pluginID), ob_get_clean()) ; 
			ob_start() ;
				echo "<p>".__('You can configure the look of the captcha by modifying different parameters available in the configuration tab.', $this->pluginID)."</p>" ;
				echo "<p>".__('I recommend that you test differents values for the options in order to render the image complex enough for a machine but simple for a human.', $this->pluginID)."</p>" ;
			$howto2 = new SLFramework_Box (__("There are many parameters, no?", $this->pluginID), ob_get_clean()) ; 
			ob_start() ;
				echo "<p>".sprintf(__('The present plugin may be connected to the %s plugin i order to add the IP address of the spammer in the ban list.', $this->pluginID), "<code>Automatic Ban IP</code>")."</p>" ;
			$howto3 = new SLFramework_Box (__("Block spammer", $this->pluginID), ob_get_clean()) ; 
			ob_start() ;
				 echo $howto1->flush() ; 
				 echo $howto2->flush() ; 
				 echo $howto3->flush() ; 
			$tabs->add_tab(__('How To',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_how.png") ; 				


			ob_start() ; 
				$plugin = str_replace("/","",str_replace(basename(__FILE__),"",plugin_basename( __FILE__))) ; 
				$trans = new SLFramework_Translation($this->pluginID, $plugin) ; 
				$trans->enable_translation() ; 
			$tabs->add_tab(__('Manage translations',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_trad.png") ; 	

			ob_start() ; 
				$plugin = str_replace("/","",str_replace(basename(__FILE__),"",plugin_basename( __FILE__))) ; 
				$trans = new SLFramework_Feedback($plugin, $this->pluginID) ; 
				$trans->enable_feedback() ; 
			$tabs->add_tab(__('Give feedback',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_mail.png") ; 	
			
			ob_start() ; 
				$trans = new SLFramework_OtherPlugins("sedLex", array('wp-pirates-search')) ; 
				$trans->list_plugins() ; 
			$tabs->add_tab(__('Other plugins',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_plug.png") ; 	

			echo $tabs->flush() ; 
			
			
			// Before this comment, you may modify whatever you want
			//===============================================================================================
			?>
			<?php echo $this->signature ; ?>
		</div>
		<?php
	}
	
	/** ====================================================================================================================================================
	* Called before a new comment is stored in the database
	*
	*/
	function check_comment_captcha($comment) {
		global $wpdb;
		session_start() ; 
		
		// on ne fait rien pour les trackbacks et les pingback (car il ne sont pas cense avoir des captcha...)
		if ($comment['comment_type'] == 'pingback' || $comment['comment_type'] == 'trackback') {
			return $comment ; 
		}
		
		// on efface les entrées trop vieilles
		if ((is_int($this->get_param('flush_nb_jours')))&&($this->get_param('flush_nb_jours')>0)) {
			$wpdb->query("DELETE FROM ".$this->table_name." WHERE date<DATE_SUB(NOW(), INTERVAL ".$this->get_param('flush_nb_jours')." DAY)") ; 
		}
		// on efface les entrées trop vieilles
		if ((is_int($this->get_param('flush_max_entry')))&&($this->get_param('flush_max_entry')>0)) {
			$wpdb->query("DELETE FROM ".$this->table_name." WHERE id NOT IN (SELECT id FROM ".$this->table_name." ORDER BY date DESC LIMIT ".$this->get_param('flush_max_entry').")") ; 
		}
				
		if (($this->get_param('captcha_enable')==true)&&((!is_user_logged_in())||($this->get_param('captcha_logged')))) {
			
			// First we check if the user may edit comment... if so we return the comment 
			if ((is_numeric($comment['user_ID']))&&($comment['user_ID']>0)) {
				$user = new WP_User( $comment['user_ID'] );
				if ( ! empty( $user->roles ) && is_array( $user->roles ) ) {
					foreach ( $user->roles as $role ) {
						if (($role=="administrator")||($role=="editor")){
							return $comment ; 
						}
					}
				}
			}
			
			// If not we check the captcha
			if ((!isset($_POST['captcha_comment']))||($_POST['captcha_comment']=="")||(!isset($_SESSION['keyCaptcha']))||((isset($_SESSION['keyCaptcha']))&&($_SESSION['keyCaptcha']==""))||((isset($_SESSION['keyCaptcha']))&&($_SESSION['keyCaptcha']!=$_POST['captcha_comment']))) {
				// we save it...
				if (isset($_SESSION['keyCaptcha'])) {
					$wpdb->query("INSERT INTO ".$this->table_name." (id_comment, status, new_status, author, content, date, captcha_info) VALUES (0, 'captcha', 'captcha', '".esc_sql($comment['comment_author'])."', '".esc_sql($comment['comment_content'])."', NOW(), 'The user enters \"".esc_sql($_POST['captcha_comment'])."\" but should be ".$_SESSION['keyCaptcha']."')") ; 
				} else {
					$wpdb->query("INSERT INTO ".$this->table_name." (id_comment, status, new_status, author, content, date, captcha_info) VALUES (0, 'captcha', 'captcha', '".esc_sql($comment['comment_author'])."', '".esc_sql($comment['comment_content'])."', NOW(), 'The user enters \"".esc_sql($_POST['captcha_comment'])."\" but no session captcha is set')") ; 
				}
				// If Automatic Ban IP exists and that no session exists (it is a spam) !
				if (($this->get_param('ban_ip_cookies'))&&(class_exists('automatic_ban_ip'))&&(trim($_SESSION['keyCaptcha'])=="")) {
					$automatic_ban_ip = automatic_ban_ip::getInstance();
					$automatic_ban_ip->blockIP("SPAM-CAPTCHA: Captch mismatch - no cookie") ; 
				}
										
				$permalink = get_permalink( $comment['comment_post_ID'] );
				wp_redirect(add_query_arg(array("error_checker"=>"captcha", "author_spam"=>urlencode($comment['comment_author']), "email_spam"=>urlencode($comment['comment_author_email']), "url_spam"=>urlencode($comment['comment_author_url']), "comment_spam"=>urlencode($comment['comment_content'])),$permalink."#error", 302)) ; 
				die();
			}
		}
		return $comment ; 
	}
	
	/** ====================================================================================================================================================
	* Called when the comment form is displayed in order to add the captcha
	*
	*/
	
	/**
	 * Start of the comment form
	 */
	 
	function detect_start($file = null) {
		ob_start(array(&$this, '_modify_form'));
		$this->ended = false;		
		return $file;
	}

	/**
	 * End the comment form
	 */
	 
	function detect_end() {
		if ((isset($this->ended))&&(!$this->ended)) {
			$this->ended = true;
			ob_end_flush();
		}
	}
	
	/**
	 * Modify the comment form
	 */
	function _modify_form($content) {
	
		$error = "" ; 
		if (isset($_GET['error_checker'])) {
			if ($_GET['error_checker']=="spam") {
				$error .= "<a name='error'><div class='error_spam_captcha'><p>" ; 
				$error .=  __('You have submitted a comment which is considered as a spam... If not, please modify it and retry', $this->pluginID) ; 
				$error .=  "</p></div>" ; 
			}
			if ($_GET['error_checker']=="captcha") {
				$error .=  "<a name='error'><div class='error_spam_captcha'><p>" ; 
				$error .=  __('You have mistyped the captcha : to prove that your are not a spam machine, please retry!', $this->pluginID) ; 
				$error .=  "</p></div>" ; 
			}
		}
		
		$comment="" ; 
		if (isset($_GET['comment_spam'])) {
			$comment = htmlentities(stripslashes($_GET['comment_spam'])) ; 
		}
		
		$author="" ; 
		if (isset($_GET['author_spam'])) {
			$author = addslashes(htmlentities($_GET['author_spam'])) ; 
		}

		$url="" ; 
		if (isset($_GET['url_spam'])) {
			$url = addslashes(htmlentities($_GET['url_spam'])) ; 
		}

		$email="" ; 
		if (isset($_GET['email_spam'])) {
			$email = addslashes(htmlentities($_GET['email_spam'])) ; 
		}
		
		$captcha = "" ; 
		if (($this->get_param('captcha_enable')==true)&&((!is_user_logged_in())||($this->get_param('captcha_logged')))) {
			$html = $this->get_param('captcha_html') ; 
			$captcha = str_replace("%image%", "<img src='".add_query_arg(array("display_captcha"=>"true"))."' alt='".__("Please type the characters of this captcha image in the input box", $this->pluginID)."'>", $html) ; 
		}

		$content = preg_replace('%</[^>]*?textarea[^>]*?>%Ui', $comment.'</textarea>'.$error.$captcha, $content);
		// Author
		$content = preg_replace('%<input([^>]*?)name="author"([^>]*?)value=""([^>]*?)>%Ui', '<input\1name="author"\2value="'.$author.'"\3>', $content);
		$content = preg_replace('%<input([^>]*?)value=""([^>]*?)name="author"([^>]*?)>%Ui', '<input\1name="author"\2value="'.$author.'"\3>', $content);
		$content = preg_replace("%<input([^>]*?)name='author'([^>]*?)value=''([^>]*?)>%Ui", "<input\1name='author'\2value='".$author."'\3>", $content);
		$content = preg_replace("%<input([^>]*?)value=''([^>]*?)name='author'([^>]*?)>%Ui", "<input\1name='author'\2value='".$author."'\3>", $content);
		// Email
		$content = preg_replace('%<input([^>]*?)name="email"([^>]*?)value=""([^>]*?)>%Ui', '<input\1name="email"\2value="'.$email.'"\3>', $content);
		$content = preg_replace('%<input([^>]*?)value=""([^>]*?)name="email"([^>]*?)>%Ui', '<input\1name="email"\2value="'.$email.'"\3>', $content);
		$content = preg_replace("%<input([^>]*?)name='email'([^>]*?)value=''([^>]*?)>%Ui", "<input\1name='email'\2value='".$email."'\3>", $content);
		$content = preg_replace("%<input([^>]*?)value=''([^>]*?)name='email'([^>]*?)>%Ui", "<input\1name='email'\2value='".$email."'\3>", $content);
		// Url
		$content = preg_replace('%<input([^>]*?)name="url"([^>]*?)value=""([^>]*?)>%Ui', '<input\1name="url"\2value="'.$url.'"\3>', $content);
		$content = preg_replace('%<input([^>]*?)value=""([^>]*?)name="url"([^>]*?)>%Ui', '<input\1name="url"\2value="'.$url.'"\3>', $content);
		$content = preg_replace("%<input([^>]*?)name='url'([^>]*?)value=''([^>]*?)>%Ui", "<input\1name='url'\2value='".$url."'\3>", $content);
		$content = preg_replace("%<input([^>]*?)value=''([^>]*?)name='url'([^>]*?)>%Ui", "<input\1name='url'\2value='".$url."'\3>", $content);
		return $content;
	}
	
	/** ====================================================================================================================================================
	* Display the captcha in the Content Form 7
	*
	*/
	function spam_captcha_shortcode ( $_atts, $string ) {
		$result = "<img src='".add_query_arg(array("display_captcha"=>"true"))."' alt='".__("Please type the characters of this captcha image in the input box", $this->pluginID)."'>" ; 
		$result .= '<br/><span class="wpcf7-form-control-wrap your-captcha"><input name="your-captcha" value="" size="40" class="wpcf7-form-control wpcf7-text" aria-required="true" aria-invalid="false" type="captcha"></span>' ; 
		return $result ; 
	}
	
	function wpcf7_form_elements($content) {
		$content = do_shortcode( $content );
		return $content ; 
	}

	function wpcf7_validate_email($result, $tag="") {
		global $wpdb ; 
		@session_start() ; 
		
		// If not we check the captcha
		if ((!isset($_SESSION['keyCaptcha']))||($_SESSION['keyCaptcha']=="")||($_SESSION['keyCaptcha']!=$_POST['your-captcha'])) {
			// we save it...
			$wpdb->query("INSERT INTO ".$this->table_name." (id_comment, status, new_status, author, content, date, captcha_info) VALUES (0, 'captcha', 'captcha', 'CONTACT FORM 7: ".esc_sql($_POST['your-name'])."', '".esc_sql($_POST['your-subject'])." : ".esc_sql($_POST['your-message'])."', NOW(), 'The user enters \"".esc_sql($_POST['your-captcha'])."\" but should be ".$_SESSION['keyCaptcha']."')") ; 
			$result['valid'] = false;
			$result['reason']['your-captcha'] = __("You do not have typed the correct captcha", $this->pluginID) ; 
		}
		
		return $result	;	
	}
	/** ====================================================================================================================================================
	* Display the image
	*
	*/
	function check_if_captcha_image() {
		
		if (isset($_GET['display_captcha'])) {

			session_start() ; 
			$maxX = $this->get_param('captcha_width') ; 
			$maxY = $this->get_param('captcha_height') ; 
			$nbLetter = $this->get_param('captcha_number') ; 
			$angle = $this->get_param('captcha_angle') ; 
			$sizeLetter = $this->get_param('captcha_size') ; 
			
			if (($this->get_param('captcha_text'))&&(!$this->get_param('captcha_addition'))) {
				$text = SLFramework_Utils::rand_str($nbLetter, "abcdefghijklmnopqrstuvwxyz") ; 
				$text_to_type = $text ; 
			} else if ((!$this->get_param('captcha_text'))&&($this->get_param('captcha_addition'))) {
				if ($nbLetter>=3) {
					$nb1 = rand(1, pow(10,($nbLetter-2))-1) ; 
					$nb2 = rand(pow(10,($nbLetter-strlen("".$nb1)-2)), pow(10,($nbLetter-strlen("".$nb1)-1))-1) ; 
					$text = $nb1."+".$nb2 ; 
					$text_to_type = $nb1+$nb2 ; 
				} else {
					$text = SLFramework_Utils::rand_str($nbLetter, "abcdefghijklmnopqrstuvwxyz") ; 
					$text_to_type = $text ; 
				}
			} else if (($this->get_param('captcha_text'))&&($this->get_param('captcha_addition'))) {
				if (rand(0,1)==0) {
					$text = SLFramework_Utils::rand_str($nbLetter, "abcdefghijklmnopqrstuvwxyz") ; 
					$text_to_type = $text ; 
				} else {
					if ($nbLetter>=3) {
						$nb1 = rand(1, pow(10,($nbLetter-2))-1) ; 
						$nb2 = rand(pow(10,($nbLetter-strlen("".$nb1)-2)), pow(10,($nbLetter-strlen("".$nb1)-1))-1) ; 
						$text = $nb1."+".$nb2 ; 
						$text_to_type = $nb1+$nb2 ; 
					} else {
						$text = SLFramework_Utils::rand_str($nbLetter, "abcdefghijklmnopqrstuvwxyz") ; 
						$text_to_type = $text ; 
					}
				}
			} else {
				$text = SLFramework_Utils::rand_str($nbLetter, "abcdefghijklmnopqrstuvwxyz") ; 	
				$text_to_type = $text ; 		
			} 
			
			$captcha = imagecreate($maxX, $maxY);
  			$r1 = hexdec(substr($this->get_param('captcha_background'), 0, 2)) ; 
  			$g1 = hexdec(substr($this->get_param('captcha_background'), 2, 2)) ; 
  			$b1 = hexdec(substr($this->get_param('captcha_background'), 4, 2)) ; 
  			
			$r2 = hexdec(substr($this->get_param('captcha_font_color'), 0, 2)) ; 
  			$g2 = hexdec(substr($this->get_param('captcha_font_color'), 2, 2)) ; 
  			$b2 = hexdec(substr($this->get_param('captcha_font_color'), 4, 2)) ; 
			
			//
			// We generate colors in array to avoid memory overload
			//
			$color_back = array(imagecolorallocate($captcha, $r1, $g1, $b1)) ; 
			$color_font = array(imagecolorallocate($captcha, $r2, $g2, $b2)) ;
			
			$max_ratio = $this->get_param('captcha_color_variation_percentage') ; 
			
			if ( ($this->get_param('captcha_noise')) ) {
				// 50 for the background
				for ($i=0 ; $i<10 ; $i++) {
					$ratio_color = rand(0,$max_ratio) ; 
					$r3 = min(255, max(0, floor($r1+($ratio_color-$max_ratio/2)/100*255))) ; 
					$g3 = min(255, max(0, floor($g1+($ratio_color-$max_ratio/2)/100*255))) ; 
					$b3 = min(255, max(0, floor($b1+($ratio_color-$max_ratio/2)/100*255))) ; 
					$color_back[] = imagecolorallocate($captcha, $r3 , $g3, $b3 );
				}
			}
			
			if ( ($this->get_param('captcha_color_variation')) ) {
				// 50 for the font
				for ($i=0 ; $i<10 ; $i++) {
					$ratio_color = rand(0,$max_ratio) ; 
					$r3 = min(255, max(0, floor($r2+($ratio_color-$max_ratio/2)/100*255))) ; 
					$g3 = min(255, max(0, floor($g2+($ratio_color-$max_ratio/2)/100*255))) ; 
					$b3 = min(255, max(0, floor($b2+($ratio_color-$max_ratio/2)/100*255))) ; 
					$color_font[] = imagecolorallocate($captcha, $r3 , $g3, $b3 );
				}
			} 
			
			if ( ($this->get_param('captcha_noise')) ) {
				for ($i=0 ; $i<($maxX/10*$maxY/10)*$max_ratio  ; $i++) {
					$x = rand(0,$maxX) ; 
					$y = rand(0,$maxY) ; 
					$size = rand(3, floor($maxY/2)) ; 
					
					$color = rand(0, count($color_back)-1) ; 
					imagefilledellipse ( $captcha , $x , $y , $size , $size ,$color_back[$color] ) ; 
				}
			}
			
			for ($i=0 ; $i<$nbLetter ; $i++) {
				
				$polices = array() ; 
				$files = scandir(WP_PLUGIN_DIR."/".str_replace(basename( __FILE__),"",plugin_basename(__FILE__)));
				foreach($files as $f) {
					if (preg_match("/ttf$/i", $f)) {
						$polices[] = $f ; 
					}
				}
				
				// ANGLE
				$slant = rand(-$angle, $angle) ; 
				// X
				$x = floor( $maxX/(2*$nbLetter) - $sizeLetter/2 + $i*$maxX/($nbLetter)) ; 
				$delta_x = rand(-floor( $maxX/(6*$nbLetter) - $sizeLetter/4 ), floor( $maxX/(6*$nbLetter) - $sizeLetter/4 )) ;
				// Y
				$y = floor( $maxY/2 + $sizeLetter/2 ); 
				$delta_y = rand(-floor( $maxY/6 - $sizeLetter/4 ), floor( $maxY/6 - $sizeLetter/4 )) ; 
				// SIZE
				$delta_size = rand(0, floor( $maxY/6 - $sizeLetter/4 )) ;
				
				$color = rand(0, count($color_font)-1) ; 
				
				$return = imagettftext($captcha, $sizeLetter+$delta_size, $slant, $x+$delta_x, $y+$delta_y, $color_font[$color], WP_PLUGIN_DIR."/".str_replace(basename( __FILE__),"",plugin_basename(__FILE__)).$polices[rand(0, count($polices)-1)], substr($text, $i, 1));
			}
			
			
			if ( ($this->get_param('captcha_wave')) ) {
				// Make a copy of the image twice the size
				$height2 = $maxY * 2;
				$width2 = $maxX * 2;
				$img2 = imagecreatetruecolor($width2, $height2);
				imagecopyresampled($img2, $captcha, 0, 0, 0, 0, $width2, $height2, $maxX, $maxY);
				$period = $this->get_param('captcha_wave_period') ; 
				$amplitude = $this->get_param('captcha_wave_amplitude') ; 
				
				if($period == 0) 
					$period = 1;
				// Wave it
				for($i = 0; $i < ($width2); $i += 2)
					imagecopy($img2, $img2, $i - 2, sin($i / $period) * $amplitude, $i, 0, 2, $height2);
				// Resample it down again
				imagecopyresampled($captcha, $img2, 0, 0, 0, 0, $maxX, $maxY, $width2, $height2);
				imagedestroy($img2);
			}  
			
			$_SESSION['keyCaptcha'] = $text_to_type;
			header("Content-type: image/png");
			// Do not cache this image
			header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
			header("Cache-Control: no-cache");
			header("Pragma: no-cache");
			imagepng($captcha);
			imagedestroy($captcha) ; 
			die() ; 
		}
	}
	
	/** ====================================================================================================================================================
	* Called when a new comment is submitted (after its storing)
	*
	*/
	function check_comment_akismet($id, $comment) {
		global $wpdb;

		if ($this->get_param('akismet_enable')) {
			if ($this->get_param('akismet_id') != "") {
				$com['blog']       = get_option('home');
				$com['user_ip']    = $comment->comment_author_IP;
				$com['user_agent'] = $comment->comment_agent;
				$com['referrer']   = $_SERVER['HTTP_REFERER'];
				$com['permalink']  = get_permalink($comment->comment_post_ID);
				$com['comment_type']  = $comment->comment_type ;
				$com['comment_author']  = $comment->comment_author;
				$com['comment_author_email']  = $comment->comment_author_email ;
				$com['comment_author_url']  = $comment->comment_author_url; 
				$com['comment_content']  = $comment->comment_content;
				
				$query_string = "" ;
				foreach ( $com as $key => $data ) {
					$query_string .= $key . '=' . urlencode( stripslashes($data) ) . '&';
				}
				
				if ($this->check_spam_akismet($query_string)==true) {
					// we mark as spam
					wp_set_comment_status( $id, "spam" ) ; 
					// we save it...
					$wpdb->query("INSERT INTO ".$this->table_name." (id_comment, status, new_status, author, content, date, captcha_info) VALUES (".$id.", 'spam', 'spam', '".esc_sql($comment->comment_author)."', '".esc_sql($comment->comment_content)."', NOW(), 'The user enters \"".esc_sql($_POST['captcha_comment'])."\" ! Should be ".$_SESSION['keyCaptcha']."')") ; 
					
					// If Automatic Ban IP exists and that no session exists (it is a spam) !
					if (($this->get_param('ban_ip_akismet'))&&(class_exists('automatic_ban_ip'))) {
						$automatic_ban_ip = automatic_ban_ip::getInstance();
						$automatic_ban_ip->blockIP("SPAM-CAPTCHA: Akismet says it is a spam") ; 
					}

					// we redirect the page to inform the user
					wp_redirect(add_query_arg(array("error_checker"=>"spam", "author_spam"=>urlencode($comment->comment_author), "email_spam"=>urlencode($comment->comment_author_email), "url_spam"=>urlencode($comment->comment_author_url), "comment_spam"=>urlencode($comment->comment_content) ),get_permalink($comment->comment_post_ID)."#error"),302);
					die() ; 
				} else {
					// we save it...
					$wpdb->query("INSERT INTO ".$this->table_name." (id_comment, status, new_status, author, content, date, captcha_info) VALUES (".$id.", 'ok', 'ok', '".esc_sql($comment->comment_author)."', '".esc_sql($comment->comment_content)."', NOW(), 'The user enters \"".esc_sql($_POST['captcha_comment'])."\" ! Should be ".$_SESSION['keyCaptcha']."')") ; 
				}
			}
		}
	}
	
	/** ====================================================================================================================================================
	* Called when the comment is change to ham
	*
	*/
	function change_to_ham($comment) {
		global $wpdb;
		$wpdb->query("UPDATE ".$this->table_name." SET new_status='ok' WHERE id_comment='".$comment->comment_ID."'") ; 
	}
	
	/** ====================================================================================================================================================
	* Called when the comment is change to spam
	*
	*/
	function change_to_spam($comment) {
		global $wpdb;
		$wpdb->query("UPDATE ".$this->table_name." SET new_status='spam' WHERE id_comment='".$comment->comment_ID."'") ; 
	}
		
	/** ====================================================================================================================================================
	* To check comment against akismet
	*
	* $request = 'blog='. urlencode($data['blog']) .
          *     '&user_ip='. urlencode($data['user_ip']) .
          *     '&user_agent='. urlencode($data['user_agent']) .
          *     '&referrer='. urlencode($data['referrer']) .
          *     '&permalink='. urlencode($data['permalink']) .
          *     '&comment_type='. urlencode($data['comment_type']) .
          *     '&comment_author='. urlencode($data['comment_author']) .
          *     '&comment_author_email='. urlencode($data['comment_author_email']) .
          *     '&comment_author_url='. urlencode($data['comment_author_url']) .
          *     '&comment_content='. urlencode($data['comment_content']);
	*
	* @param string request the request to check
	* @return boolean true if it is a spam 
	*/
	
	function check_spam_akismet($request) {
		global $wp_version;

		$http_args = array(
			'body'		=> $request,
			'headers'		=> array(
				'Content-Type'	=> 'application/x-www-form-urlencoded; charset=' . get_option( 'blog_charset' ),
				'User-Agent'	=> "WordPress/{$wp_version} | Akismet/2.5.3"
			),
			'httpversion'	=> '1.0',
			'timeout'		=> 15
		);
		if (($this->get_param('akismet_enable')) && ($this->get_param('akismet_id')!="") ) {
			$akismet_url = "http://".$this->get_param('akismet_id').".rest.akismet.com/1.1/comment-check";
			$response = wp_remote_post( $akismet_url, $http_args );
			
			if ( is_wp_error( $response ) )
				return false;
			
			if ($response['body']=="true") {
				return true ; 
			}
			return false ; 
		} else {
			return false ;
		}
	}
	
	/** ====================================================================================================================================================
	* To check if the key is valid or not 
	*
	* @param string key the key to check
	* @return boolean true if it is a spam 
	*/
	
	function verify_akismet_key($key) {
		global $wp_version ; 
		$request = 'key='. $key .'&blog='. get_option('home');
		
		$http_args = array(
			'body'		=> $request,
			'headers'		=> array(
				'Content-Type'	=> 'application/x-www-form-urlencoded; charset=' . get_option( 'blog_charset' ),
				'User-Agent'	=> "WordPress/{$wp_version} | Akismet/2.5.3"
			),
			'httpversion'	=> '1.0',
			'timeout'		=> 15
		);
		
		$akismet_url = "http://rest.akismet.com/1.1/verify-key";
		$response = wp_remote_post( $akismet_url, $http_args );
		if ( is_wp_error( $response ) )
			return false;
		 if ($response['body']=='valid') 
			return true ; 
		return false ; 

	}
}



if ( ! defined( 'KC_SC_MIN_PHP_VER' ) ) {
	define( 'KC_SC_MIN_PHP_VER', '5.6' );
}

if ( ! defined( 'KC_SC_MAX_PHP_VER' ) ) {
	define( 'KC_SC_MAX_PHP_VER', '7.4.22' );
}

if ( ! function_exists( 'kc_sc_fail_php_version_notice' ) ) {

	function kc_sc_fail_php_version_notice() {
		/* translators: %s: PHP version */
		$message      = sprintf( esc_html__( 'Spam Captcha plugin requires PHP version between %s & %s, plugin is currently NOT RUNNING.', 'shorten-url' ), KC_SC_MIN_PHP_VER,  KC_SC_MAX_PHP_VER);
		$html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
		echo wp_kses_post( $html_message );
	}
}

if ( ! version_compare( PHP_VERSION, KC_SC_MIN_PHP_VER, '>=' ) || ! version_compare( PHP_VERSION, KC_SC_MAX_PHP_VER, '<=' )) {
	add_action( 'admin_notices', 'kc_sc_fail_php_version_notice' );

	return;
}

$spam_captcha = spam_captcha::getInstance();

?>