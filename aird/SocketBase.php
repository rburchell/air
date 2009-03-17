<?
if (!defined('SOL_TCP')) {
	// sometimes not defined oddly enough
	define('SOL_TCP', 6);
}

class socketException extends Exception {}

abstract class SocketBase {
	public $socket;
	public $bind_address;
	public $bind_port;
	public $domain;
	public $type;
	public $protocol;
	public $local_addr;
	public $local_port;
	public $read_buffer    = '';
	public $write_buffer   = '';
	public $connecting     = false;
	public $disconnected   = false;

	public function __construct($bind_address = 0, $bind_port = 0, $domain = AF_INET, $type = SOCK_STREAM, $protocol = SOL_TCP)
	{
		$this->bind_address = $bind_address;
		$this->bind_port    = $bind_port;
		$this->domain       = $domain;
		$this->type         = $type;
		$this->protocol     = $protocol;
		if (($this->socket = @socket_create($domain, $type, $protocol)) === false) {
			throw new socketException("Could not create socket: ".socket_strerror(socket_last_error($this->socket)));
		}
		AirD::Log(AirD::LOGTYPE_INTERNAL, "SocketBase's constructor (FD " . (int)$this->socket . ")", true);
		if (!@socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
			throw new socketException("Could not set SO_REUSEADDR: ".$this->get_error());
		}
		if (!@socket_bind($this->socket, $bind_address, $bind_port)) {
			throw new socketException("Could not bind socket to [$bind_address - $bind_port]: ".socket_strerror(socket_last_error($this->socket)));
		}
		if (!@socket_getsockname($this->socket, $this->local_addr, $this->local_port)) {
			throw new socketException("Could not retrieve local address & port: ".socket_strerror(socket_last_error($this->socket)));
		}
		$this->set_non_block(true);
		SocketEngine::AddFd($this);
	}

	public function __destruct()
	{
		AirD::Log(AirD::LOGTYPE_INTERNAL, "SocketBase's destructor (FD " . (int)$this->socket . ")", true);
		if (is_resource($this->socket)) {
			$this->close();
		}
	}

	public function get_error()
	{
		$error = socket_strerror(socket_last_error($this->socket));
		socket_clear_error($this->socket);
		return $error;
	}

	public function close()
	{
		if (is_resource($this->socket)) {
			@socket_shutdown($this->socket, 2);
			@socket_close($this->socket);
		}
		$this->socket = (int)$this->socket;
	}

	public function write($buffer, $length = 4096)
	{
		if (!is_resource($this->socket)) {
			throw new socketException("Invalid socket or resource");
		} elseif (($ret = @socket_write($this->socket, $buffer, $length)) === false) {
			throw new socketException("Could not write to socket: ".$this->get_error());
		}
		return $ret;
	}

	public function read()
	{
		if (!is_resource($this->socket)) {
			throw new socketException("Invalid socket or resource");
		} elseif (($ret = @socket_read($this->socket, 2048, PHP_BINARY_READ)) == false) {
			throw new socketException("Could not read from socket: ".$this->get_error());
		}
		return $ret;
	}

	public function connect($remote_address, $remote_port)
	{
		$this->remote_address = $remote_address;
		$this->remote_port    = $remote_port;
		if (!is_resource($this->socket)) {
			throw new socketException("Invalid socket or resource");
		}
		elseif (!@socket_connect($this->socket, $remote_address, $remote_port))
		{
			$iCode = socket_last_error();
			if ($iCode != 114 && $iCode != 115) // EINPROGRESS, we most certainly do not want an exception for *that*
				throw new socketException("Could not connect to {$remote_address} - {$remote_port}: ".$this->get_error());
		}
	}

	public function listen($backlog = 128)
	{
		if (!is_resource($this->socket)) {
			throw new socketException("Invalid socket or resource");
		} elseif (!@socket_listen($this->socket, $backlog)) {
			throw new socketException("Could not listen to {$this->bind_address} - {$this->bind_port}: ".$this->get_error());
		}
	}

	public function accept()
	{
		if (!is_resource($this->socket)) {
			throw new socketException("Invalid socket or resource");
		} elseif (($client = socket_accept($this->socket)) === false) {
			throw new socketException("Could not accept connection to {$this->bind_address} - {$this->bind_port}: ".$this->get_error());
		}
		return $client;
	}

	public function set_non_block()
	{
		if (!is_resource($this->socket)) {
			throw new socketException("Invalid socket or resource");
		} elseif (!@socket_set_nonblock($this->socket)) {
			throw new socketException("Could not set socket non_block: ".$this->get_error());
		}
	}

	public function set_block()
	{
		if (!is_resource($this->socket)) {
			throw new socketException("Invalid socket or resource");
		} elseif (!@socket_set_block($this->socket)) {
			throw new socketException("Could not set socket non_block: ".$this->get_error());
		}
	}

	public function set_recieve_timeout($sec, $usec)
	{
		if (!is_resource($this->socket)) {
			throw new socketException("Invalid socket or resource");
		} elseif (!@socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array("sec" => $sec, "usec" => $usec))) {
			throw new socketException("Could not set socket recieve timeout: ".$this->get_error());
		}
	}

	public function set_reuse_address($reuse = true)
	{
		$reuse = $reuse ? 1 : 0;
		if (!is_resource($this->socket)) {
			throw new socketException("Invalid socket or resource");
		} elseif (!@socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, $reuse)) {
			throw new socketException("Could not set SO_REUSEADDR to '$reuse': ".$this->get_error());
		}
	}


	/** Events.
	  */
	public function on_timer() {}
}