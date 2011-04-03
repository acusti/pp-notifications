<?php
/**
 * Plugin's core class
 *
 * handles all plugin functionality
 *
 */

class PC_PP_Notifications
{
	
	private $settings;
	
	private $message_headers;
	
	private $subject_prefix;
	
	/**
	 * Constructor
	 *
	 * @since 0.1
	 * @return void
	 */
	public function PC_PP_Notifications() {		
		
		// Get settings
		$this->settings = get_option( 'pc_pp_notifications' );
		
		// default options, if none are found (false for everything):
		if ( ! $this->settings ) {
			$this->settings['seller'] = $this->settings['buyer'] = false;
		}
		else {
			
			// Add notification actions based on settings
			if ( isset( $this->settings['seller']['sold_buy_now'] ) || isset( $this->settings['seller']['expired'] ) || isset( $this->settings['seller']['sold'] ) || isset( $this->settings['buyer']['won'] ) )
				add_action( 'post_completed', array( &$this, 'ppn_auction_won' ), 11, 1 );
			if ( isset( $this->settings['seller']['invoice_paid'] ) )
				add_action( '', array( &$this, 'ppn_invoice_paid' ), 11, 1 );
			if ( isset( $this->settings['buyer']['outbid'] ) )
				add_action( 'auction_outbid', array( &$this, 'ppn_outbid' ), 11, 4 );
			if ( isset( $this->settings['buyer']['auction_finishing'] ) ) {
				add_action( 'publish_end_date_change', array( &$this, 'ppn_schedule_ending_reminder' ), 11, 2 );
				add_action( '', array( &$this, 'ppn_auction_finishing' ), 11, 1 );
			}
		}
		
		// Add settings actions (tied into Prospress -> General Settings):
		add_action( 'admin_init', array( &$this, 'ppn_register_settings' ) );
		add_action( 'pp_core_settings_page', array( &$this, 'ppn_display_settings' ), 11 );
		
	}
	
	/**
	 * When any auction's end date is changed, update corresponding reminder event
	 * 
	 * see pp_schedule_end_post() and pp_post_save_postdata() in pp-posts.php
	 *
	 * @param string $post_status The status of the changed post
	 * @param string $post_end_date The end date of the post (use strtotime() to convert it)
	 */
	function ppn_schedule_ending_reminder( $post_status, $post_end_date ) {
		// big problem: what about post id?
		// possible solution: should be able to use wpdb->insert_id 
		// ("ID generated for an AUTO_INCREMENT column by the most recent INSERT query."),
		// but needs testing
		global $wpdb, $market_systems;
		$market = $market_systems['auctions'];

		$post_id = $wpdb->insert_id;
		$auction = get_post( $post_id );
		// then check if it's the correct post type ('auctions'),
		// unshedule an existing reminders if it exists,
		// then schedule a new one 1 hour before $post_end_date (or post_end_date_gmt?)
		
	}
	
	function ppn_mail( $to, $subject, $message ) {
		if ( !$this->message_headers ) {
			// Prep variables
			$from_name = wp_specialchars( get_option( 'blogname' ) );
			$this->message_headers = "MIME-Version: 1.0\n" . "From: \"{$from_name}\" <noreply@{$_SERVER['SERVER_NAME']}>\n" . "Content-Type: text/plain; charset=\"" . get_option( 'blog_charset' ) . "\"\n";
			$this->subject_prefix = '[' . $from_name . '] ';
		}
		// possible valedictions
		$valedictions = array( 'Later', 'Cheers', 'Ciao', 'Bye' );
		$message .= "\n\n" . $valedictions[ array_rand($valedictions) ] . ",\n– The team at $from_name\n\n---do not reply to this message---\n\n";
		if ( wp_mail( $to, $this->subject_prefix . $subject, $message, $this->message_headers ) ) {
			// success
			
			return true;
		}
		else {
			// log the failure
			
			return false;
		}
	}

	/**
	 * Notification for buyer and/or seller after an auction ends (through normal bids or buy now)
	 *
	 * Checks whether to send email to either/both buyer & seller based on $this->settings
	 * Also handles "Your auction has expired" email
	 */
	function ppn_auction_won( $post_id ) {
		
		global $market_systems;
	
		$buy_now = strtoupper( get_post_meta( $post_id, 'paypal_status', true ) ) == 'COMPLETED';
		
		$expired = ! $market_systems['auctions']->get_bid_count( $post_id );
		
		if ( ( ! $expired || isset( $this->settings['seller']['expired'] ) )
			|| ( ! $buy_now || isset( $this->settings['seller']['sold_buy_now'] ) ) ) {
			
			$auction = get_post( $post_id );
			$auction_link = get_permalink( $post_id );
			
			$seller	= get_userdata( $auction->post_author );
			
			// expired:
			if ( $expired ) {
			
				$new_auction_link = admin_url( 'post-new.php?post_type=auctions' );
				$new_auction_link = apply_filters( 'new_auction_link', $new_auction_link );
				
				$message = "Hi, {$seller->user_nicename}\n\nYour auction “{$auction->post_title}” has ended without any bids.\n\nIf you want to re-post the auction, make sure that you include a concise Title, a complete Description, and a few accurate Tags to represent the item you are auctioning.\n\nAlso, you should review how you categorized the item to make sure you give it the most appropriate Category.\n\nTo view this auction: $auction_link\n\nTo post the auction again: $new_auction_link";
				$this->ppn_mail( $seller->user_email, 'Your auction “' . $auction->post_title . '” has expired', $message );
			}
			else {
				$winning_bid = $market_systems['auctions']->get_winning_bid( $post_id );
				$buyer = get_userdata( $winning_bid->post_author );
				$amount	= '$' . $winning_bid->winning_bid_value;
				// integration with BuddyPress Auctions plugin
				if ( defined( 'BP_AUCTIONS_VERSION' ) ) {
					$seller_link = site_url( '/members/' . $seller->user_nicename . '/auctions/payments-seller/' );
					$buyer_link = site_url( '/members/' . $buyer->user_nicename . '/auctions/payments-buyer/' );
				}
				else {
					$seller_link = admin_url( 'admin.php?page=outgoing_invoices' );
					$buyer_link = admin_url( 'admin.php?page=incoming_invoices' );
				}
				
				if ( $buy_now ) {
					$message = "Hi, {$seller->user_nicename}\n\nYour auction “{$auction->post_title}” was bought at the Buy Now price of $amount.\n\nTo view this auction: $auction_link\n\nTo see who bought the item, and other information about the transaction: $seller_link";
					$this->ppn_mail( $seller->user_email, 'Your auction item “' . $auction->post_title . '” was bought', $message );
					return;
				}
				
				// email to seller
				if ( isset( $this->settings['seller']['sold'] ) ) {
					$message = "Hi, {$seller->user_nicename}\n\nYour auction “{$auction->post_title}” has ended successfully. The winning bid was $amount.\n\nThe winning bidder {$buyer->user_nicename} is waiting for you to send an invoice.\n\nTo prepare and send the invoice for this auction: $seller_link";
					$this->ppn_mail( $seller->user_email, '“' . $auction->post_title . '” sold!', $message );
				}
				
				// email to buyer
				if ( isset( $this->settings['buyer']['won'] ) ) {
					$message = "Hi, {$buyer->user_nicename}\n\nCongratulations! You have won the auction “{$auction->post_title}”. Your final winning bid was $amount.\n\nTo view the auction: $auction_link\n\nThe seller, {$seller->user_nicename}, will be contacting you shortly about payment.\n\nImportant: You are responsible for all shipping fees (C.O.D.) so remember to provide the seller with clear instructions on how to send your new item.";
					$this->ppn_mail( $buyer->user_email, 'You have won the “' . $auction->post_title . '” auction!', $message );
				}
			}
		}
	}
	
	/**
	 * Notification for sellers after an invoice is paid
	 */
	function ppn_invoice_paid() {
		// there is currently no action I can find for when an invoice has been successfully paid.
		// it would need to be added (for paypal, at least) in pp-invoice.php at the make_payment() function
		// around this line: "pp_invoice_update_status( $invoice_id, 'paid' );" (line 270ish)
		
	}
	
	/**
	 * Notification for buyers when they have been outbid
	 */
	function ppn_outbid( $post_id, $bid_value, $bidder_id, $post_max_bid ) {
		global $market_systems;

		// this action happens before the bid is updated, so current winning bid is
		// that which has just been outbid
		$prev_winning_bid = $market_systems['auctions']->get_winning_bid( $post_id );
		$to = get_userdata( $prev_winning_bid->post_author );
		$auction = get_post( $post_id );
		$auction_link = get_permalink( $post_id );
		// to retrieve the new bid value that will be set: $market_systems['auctions']->get_bid_increment( $bid_value );
		$message = "You have been outbid on the auction “{$auction->post_title}”.\n\nTo view the auction or to increase your maximum bid: $auction_link";
		
		$this->ppn_mail( $to->user_email, 'You have been outbid on “' . $auction->post_title . '”', $message );

	}
	
	/**
	 * Notification for buyers when an auction they have bid on is finishing soon
	 */
	function ppn_auction_finishing() {
		//
		
	}
	
	/**
	 * Register Prospress notifications settings
	 *
	 * @package Prospress
	 * @since 1.01
	 */
	function ppn_register_settings() {
		register_setting( 'pp_core_options', 'pc_pp_notifications'/*, array( &$this, 'ppn_capabilities_roleset' )*/ );
	}
	
	/**
	 * Displays the fields for handling email notification options in the Core Prospress Settings admin page.
	 *
	 * @see pp_settings_page()
	 **/
	function ppn_display_settings() {
		?>
		<h3><?php _e( 'Email Notifications' , 'prospress' )?></h3>
		<p><?php _e( 'Choose when users will receive notification emails about actions on the site.' , 'prospress' ); ?></p>
		<h4><?php _e( 'Send email to sellers when:' , 'prospress' )?></h4>
		<ul>
			<li>
				<label for="pc_pp_notifications[seller][sold_buy_now]">
					<input type="checkbox" value="1" id="pc_pp_notifications[seller][sold_buy_now]" name="pc_pp_notifications[seller][sold_buy_now]"<?php checked( isset($this->settings['seller']['sold_buy_now']) ); ?> />
						  <?php _e( 'Auction ended through a buy now' , 'prospress' ); ?>
				</label>
			</li>
			<li>
				<label for="pc_pp_notifications[seller][expired]">
					<input type="checkbox" value="1" id="pc_pp_notifications[seller][expired]" name="pc_pp_notifications[seller][expired]"<?php checked( isset($this->settings['seller']['expired']) ); ?> />
						  <?php _e( 'Auction expired' , 'prospress' ); ?>
				</label>
			</li>
			<li>
				<label for="pc_pp_notifications[seller][sold]">
					<input type="checkbox" value="1" id="pc_pp_notifications[seller][sold]" name="pc_pp_notifications[seller][sold]"<?php checked( isset($this->settings['seller']['sold']) ); ?> />
						  <?php _e( 'Auction has ended successfully' , 'prospress' ); ?>
				</label>
			</li>
			<li>
				<label for="pc_pp_notifications[seller][invoice_paid]">
					<input type="checkbox" value="1" id="pc_pp_notifications[seller][invoice_paid]" name="pc_pp_notifications[seller][invoice_paid]"<?php checked( isset($this->settings['seller']['invoice_paid']) ); ?> />
						  <?php _e( 'Invoice has been paid' , 'prospress' ); ?>
				</label>
			</li>
		</ul>
		<h4><?php _e( 'Send email to buyers when:' , 'prospress' )?></h4>
		<ul>
			<li>
				<label for="pc_pp_notifications[buyer][outbid]">
					<input type="checkbox" value="1" id="pc_pp_notifications[buyer][outbid]" name="pc_pp_notifications[buyer][outbid]"<?php checked( isset($this->settings['buyer']['outbid']) ); ?> />
						  <?php _e( 'You have been outbid on an auction' , 'prospress' ); ?>
				</label>
			</li>
			<li>
				<label for="pc_pp_notifications[buyer][auction_finishing]">
					<input type="checkbox" value="1" id="pc_pp_notifications[buyer][auction_finishing]" name="pc_pp_notifications[buyer][auction_finishing]"<?php checked( isset($this->settings['buyer']['auction_finishing']) ); ?> />
						  <?php _e( 'X hours remain before auction that you have bid on ends (in development)' , 'prospress' ); ?>
				</label>
			</li>
			<li>
				<label for="pc_pp_notifications[buyer][won]">
					<input type="checkbox" value="1" id="pc_pp_notifications[buyer][won]" name="pc_pp_notifications[buyer][won]"<?php checked( isset($this->settings['buyer']['won']) ); ?> />
						  <?php _e( 'You have won an auction' , 'prospress' ); ?>
				</label>
			</li>
		</ul>
	<?php
	}

}

$pc_pp_notifier = new PC_PP_Notifications();

?>