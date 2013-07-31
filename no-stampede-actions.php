<?php

/**
 * A WordPress api to (try) kick off globally singleton actions.  It will lock the action
 * to hopefully prevent other requests from kicking off the same action.  This is highly
 * based off of Mark Jaquith's NSA_Action_Update_Server @author markjaquith (https://gist.github.com/1149945)
 *
 */


if( class_exists( 'No_Stampede_Action_Server' ) )
	return;

class No_Stampede_Action_Server {

	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		if( isset( $_POST[ '_nsa_action' ] ) ) {
			define( 'DOING_BACKGROUND_ACTION', true );
			$action = get_transient( 'nsa_action' . $_POST[ 'key' ] );
			if( $action && $action[ 0 ] == $_POST[ '_nsa_action' ] ) {
				nsa_action( $action[ 1 ] )
					->action_callback( $action[ 2 ], ( array ) $action[ 3 ] )
					->set_lock( $action[ 0 ] )
					->fire_action();
			}
			exit();
		}
	}

}

new No_Stampede_Action_Server();


	class NSA_Action {

		/**
		 * Transient key to use
		 * @var string
		 */
		public $key;
		/**
		 * Unique key to identify that this instance holds the lock
		 * @var string
		 */
		private $lock_key;
		/**
		 * Callback used to complete the action
		 * @var callback
		 */
		private $callback;
		/**
		 * Parameters passed into
		 * @var array
		 */
		private $params;
		/**
		 * Time in seconds that to wait before ever running the action again
		 * Set to 0 by default, which makes the action only run once
		 * @var int
		 */
		private $time_til_next_run = 0;
		/**
		 * Whether to go ahead do the action now or later
		 * @var bool
		 */
		private $force_background_actions = true;
		/**
		 * Maximum number of times to try to wait on cache to be filled bye the
		 * lock owner before doing the callback on it's own
		 * @var int
		 */
		private $max_tries = 5;
		/**
		 * Number of microseconds to wait per try when waiting on the lock owner
		 * @var int
		 */
		private $sleep_time = 300000; //.3 seconds

		public function __construct( $key ) {
			$this->key = $key;
		}

		public function do_action() {
			if( $this->force_background_actions ) {
				$this->schedule_background_action();
				return false;
			} else {
				return $this->fire_action();
			}
		}

		private function schedule_background_action() {
			if( $this->get_action_lock() ) {
				add_action( 'shutdown', array( $this, 'spawn_server' ) );
			}
			return $this;
		}

		public function spawn_server() {
			$server_url = home_url( '/?nsa_actions_request' );
			wp_remote_post( $server_url, array(
				'body' => array(
					'_nsa_action' => $this->lock_key,
					'key' => $this->key
				),
				'timeout' => 0.01,
				'blocking' => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true )
			) );
		}

		public function fire_action() {
			// If you don't supply a callback, we can't update it for you!
			if( empty( $this->callback ) )
				return false;

			if( !$this->get_action_lock() ) {
				if(!(defined('DOING_BACKGROUND_ACTION') && DOING_BACKGROUND_ACTION)) {
					while($this->max_tries > 0 && !$this->action_has_completed()) {
						$this->max_tries--;
						usleep($this->sleep_time);
					}
				}
				if( $this->action_has_completed() )
					return false;
			}
			call_user_func_array( $this->callback, $this->params );


			//set the action lock to expire in time_til_next_run seconds
			//time_til_next_run should be 0 to make this action only happen once
			set_transient($this->get_lock_name(), 'completed', $this->time_til_next_run);
			return true;
		}

		private function action_has_completed() {
			return 'completed' == get_transient($this->get_lock_name());
		}

		private function get_action_lock() {
			//check if action is already locked or the lock is completed
			if($this->action_has_lock() && !$this->action_has_completed()) {
				if( $this->is_lock_owner() )
					return true; //already own it
				return false; //someone else owns it
			}

			//set it for this instance
			$this->lock_key = md5( uniqid( microtime() . mt_rand(), true ) );
			set_transient($this->get_lock_name(), $this->lock_key);
			return true;
		}

		private function action_has_lock() {
			return (bool) get_transient($this->get_lock_name());
		}

		private function is_lock_owner() {
			return $this->lock_key == get_transient($this->get_lock_name());
		}

		private function get_lock_name() {
			return 'nsa_action_' . $this->key;
		}

		public function set_time_til_next_run( $seconds ) {
			$this->time_til_next_run = ( int ) $seconds;
			return $this;
		}

		public function set_lock( $lock ) {
			$this->lock_key = $lock;
			return $this;
		}

		public function background_only($val = true) {
			$this->force_background_actions = (bool) $val;
			return $this;
		}

		public function action_callback( $callback, $params = array( ) ) {
			$this->callback = $callback;
			if( is_array( $params ) )
				$this->params = $params;
			return $this;
		}

		public function set_max_tries( $max_tries ){
			$this->max_tries = (int) $max_tries;
			return $this;
		}
	}

// API so you don't have to use "new"
function nsa_action( $key ) {
	$transient = new NSA_Action( $key );
	return $transient;
}

