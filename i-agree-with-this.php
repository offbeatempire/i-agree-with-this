<?php
/*
Plugin Name: I Agree With This 
Description: Allow users to agree with comments using AJAX/form submission. 
Version: 0.5
Author: Jennifer M. Dodd
Author URI: http://uncommoncontent.com/
Textdomain: i-agree-with-this
*/

/*
	Copyright 2012 Jennifer M. Dodd <jmdodd@gmail.com>

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, see <http://www.gnu.org/licenses/>.
*/


if ( ! defined( 'ABSPATH' ) ) exit;


if ( ! class_exists( 'UCC_I_Agree_With_This' ) ) {
class UCC_I_Agree_With_This {
	public static $instance;
	public static $version;
	public static $this_text;
	public static $unthis_text;

	public function __construct() {
		self::$instance = $this;
		$this->version = '2012060201';

		$this->this_text = apply_filters( 'ucc_iawt_this_text', __( 'This!', 'i-agree-with-this' ) );
		$this->unthis_text = apply_filters( 'ucc_iawt_unthis_text', __( 'Unthis!', 'i-agree-with-this' ) );

		// Front-end display.
		if ( !is_admin() && apply_filters( 'ucc_iawt_auto_append', true ) )
			add_action( 'iawtc_markup', array( $this, 'append_to_comment_text' ), 10, 4 );

		// Front-end scripts on single posts only.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );	

		// Regular form callbacks.
		add_action( 'wp', array( &$this, 'do_this' ) );

		// AJAX callbacks.
		add_action( 'wp_ajax_nopriv_ucc_iawt_this', array( &$this, 'do_this' ) );
		add_action( 'wp_ajax_ucc_iawt_this', array( &$this, 'do_this' ) );
		add_action( 'wp_ajax_nopriv_ucc_iawt_init', array( &$this, 'init_this' ) );
		add_action( 'wp_ajax_ucc_iawt_init', array( &$this, 'init_this' ) );
	}

	public function append_to_comment_text( $comment_text ) {
		$form = $this->get_form();
		echo "{$comment_text} <div class='ucc-iawt-container'>{$form}</div>";
	}

	public function get_form( $mode = 'add', $comment_id = null ) {
		if ( empty( $comment_id ) ) {
			global $comment;
			$comment_id = $comment->comment_ID;
		}

		if ( $comment_id != absint( $comment_id ) )
			return false;

		$nonce = $this->create_nonce( '_ucc_iawt_nonce' );
		$count = get_comment_meta( $comment_id, '_ucc_iawt_votes', true );

		$this_text = $this->this_text;
		$unthis_text = $this->unthis_text;
		if ( $mode == 'delete' )
			$this_text = $unthis_text;

		$count_text = apply_filters( 'ucc_iawt_this_count', sprintf( _n( '%d person agrees.', '%d people agree.', $count, 'i-agree-with-this' ), $count ), $count );

		$form = "<form action='' method='post'><input type='submit' name='ucc_iawt_this' value='{$this_text}' class='btn ucc-iawt-this' /> {$count_text} <input type='hidden' name='ucc_iawt_comment' value='{$comment_id}' class='ucc-iawt-comment' /><input type='hidden' name='ucc_iawt_nonce' value='{$nonce}' class='ucc-iawt-nonce' />";
		if ( $mode == 'add' )
			$form .= "<input type='hidden' name='ucc_iawt_mode' value='add' class='ucc-iawt-mode' />";
		else
			$form .= "<input type='hidden' name='ucc_iawt_mode' value='delete' class='ucc-iawt-mode' />";

		$form .= "</form>";
		return apply_filters( 'ucc_iawt_get_form', $form, $mode, $comment_id );
	}

	public function enqueue_scripts() {
		if ( is_single() ) {
			$nonce = $this->create_nonce( '_ucc_iawt_nonce' );
			$unthis_text = $this->unthis_text;
			wp_enqueue_script( 'ucc-iawt-this', plugins_url( '/includes/js/this.js', __FILE__ ), array( 'jquery' ), $this->version );
			wp_localize_script( 'ucc-iawt-this', 'ucc_iawt', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'nonce' => $nonce, 'unthis' => $unthis_text ) );
		}
	}

	// Stacked to avoid return() versus exit() until the end.
	public function do_this() {
		if ( isset( $_REQUEST['ucc_iawt_nonce'] ) && isset( $_REQUEST['ucc_iawt_comment'] ) ) {
			$nonce = $_REQUEST['ucc_iawt_nonce'];
			$comment_id = $_REQUEST['ucc_iawt_comment'];
			if ( $this->verify_nonce( $nonce, '_ucc_iawt_nonce' ) && ( $comment_id = absint( $comment_id ) ) ) {
				$user_id = ucc_uof_get_user_id();
				$user_ip = ucc_uof_get_user_ip();

				$mode = ( isset( $_REQUEST['ucc_iawt_mode'] ) && $_REQUEST['ucc_iawt_mode'] == 'delete' ) ? 'delete' : 'add';
				$this->tick_this( $mode, $user_id, $user_ip, $comment_id );

				if ( $mode == 'add' )
					$form = $this->get_form( 'delete', $comment_id );
				else
					$form = $this->get_form( 'add', $comment_id );

				if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) == 'xmlhttprequest' ) {
					$result = json_encode( array( 'newform' => $form ) );
					echo $result;
					die();
				}
			}
		}

		// Failed all checks.
		if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) == 'xmlhttprequest' ) 
			exit();
		else
			return;
	}

	public function tick_this( $mode, $user_id = 0, $user_ip = 0, $comment_id ) {
		global $wpdb;

		if ( $user_id != 0 && ! $user_id = absint( $user_id ) )
			return false;

		if ( $user_ip != 0 && ! $user_ip = absint( $user_ip ) )
			return false;

		if ( ! $comment_id = absint( $comment_id ) )
			return false;

		$comment = get_comment( $comment_id );
		if ( ! $comment )
			return false;

		$relationship = ucc_uof_get_relationship( $user_id, $user_ip, $comment_id, 10 );
		if ( empty( $relationship ) )
			$relationship = ucc_uof_add_relationship( $user_id, $user_ip, $comment_id, 10 );

		// Add user_object_meta; increment commentmeta count.
		if ( $mode == 'delete' ) {
			update_metadata( 'uof_user_object', $relationship, '_ucc_iawt_vote', false );
		} else {
			update_metadata( 'uof_user_object', $relationship, '_ucc_iawt_vote', true );
		}

		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->uof_user_object AS t1, $wpdb->uof_user_objectmeta AS t2
			WHERE t1.relationship_id = t2.uof_user_object_id
				AND t1.object_id = %d 
				AND t1.object_ref = 10
				AND t2.meta_value = 1",
			$comment_id );
		$count = $wpdb->get_var( $sql );
		update_comment_meta( $comment_id, '_ucc_iawt_votes', $count );

		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->uof_user_objectmeta, $wpdb->uof_user_object, $wpdb->comments
			WHERE $wpdb->uof_user_objectmeta.meta_key = '_ucc_iawt_vote'
				AND $wpdb->uof_user_objectmeta.meta_value = 1
				AND $wpdb->uof_user_object.relationship_id = $wpdb->uof_user_objectmeta.uof_user_object_id
				AND $wpdb->uof_user_object.object_ref = 10
				AND $wpdb->comments.comment_ID = $wpdb->uof_user_object.object_id
				AND $wpdb->comments.comment_post_ID = %d",
			$comment->comment_post_ID );
		$post_count = $wpdb->get_var( $sql );
		update_post_meta( $comment->comment_post_ID, '_ucc_iawt_comment_votes', $post_count );

		if ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
			w3tc_pgcache_flush_post( $comment->comment_post_ID );
		}

		return $count;
	}

	public function init_this() {
		if ( isset( $_REQUEST['ucc_iawt_comments'] ) && ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) == 'xmlhttprequest' ) {
			$comment_ids = (array) $_REQUEST['ucc_iawt_comments'];
			$user_id = ucc_uof_get_user_id();
			$user_ip = ucc_uof_get_user_ip();

			$states = array();
			foreach ( (array) $comment_ids as $comment_id ) {
				$relationship = ucc_uof_get_relationship( $user_id, $user_ip, $comment_id, 10 );
				$meta_value = get_metadata( 'uof_user_object', $relationship, '_ucc_iawt_vote', true );
				if ( $meta_value > 0 )
					$states[$comment_id] = true;
				else
					$states[$comment_id] = false;
			}
			$result = json_encode( $states );
			echo $result;
			die();
		}
	}

	// Source: wp-includes/pluggable.php
	// Set $uid to 0 for all users; cache buster for logged-in users viewing cached page.
	function create_nonce( $action = -1 ) {
		$uid = 0; 

		$i = wp_nonce_tick();

		return substr( wp_hash( $i . $action . $uid, 'nonce' ), -12, 10 );
	}

	// Source: wp-includes/pluggable.php
	// Set $uid to 0 for all users; cache buster for logged-in users viewing cached page.
	function verify_nonce( $nonce, $action = -1 ) {
		$uid = 0; 

		$i = wp_nonce_tick();

		// Nonce generated 0-12 hours ago.
		if ( substr( wp_hash( $i . $action . $uid, 'nonce' ), -12, 10 ) == $nonce )
			return 1;

		// Nonce generated 12-24 hours ago.
		if ( substr( wp_hash( ( $i - 1 ) . $action . $uid, 'nonce' ), -12, 10 ) == $nonce )
			return 2;

		// Invalid nonce.
		return false;
	}
} }


if ( ! function_exists( 'ucc_iawt_get_post_vote_count' ) ) {
function ucc_iawt_get_post_vote_count( $post_id = null ) {
	global $post, $wpdb;

	if ( empty( $post_id ) ) {
		$post_id = $post->ID;
	}

	if ( empty( $post_id ) )
		return false;

	$count = get_post_meta( $post_id, '_ucc_iawt_comment_votes', true );
	if ( empty( $count ) )
		return 0;
	else
		return $count;
} }


if ( ! function_exists( 'ucc_iawt_post_vote_count' ) ) {
function ucc_iawt_post_vote_count( $post_id = null ) {
	echo ucc_iawt_get_post_vote_count( $post_id );
} }


function ucc_iawt_init() {
	// Only load if User Object Framework is present.
	if ( function_exists( 'ucc_uof_object_reference' ) ) {
		// Register table for metadata.
		global $wpdb;
		$wpdb->uof_user_object = $wpdb->prefix . 'uof_user_object';
		$wpdb->uof_user_objectmeta = $wpdb->prefix . 'uof_user_objectmeta';

		load_plugin_textdomain( 'i-agree-with-this', null, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		new UCC_I_Agree_With_This;
	}
}
add_action( 'init', 'ucc_iawt_init' );
