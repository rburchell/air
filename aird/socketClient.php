<?
abstract class socketClient extends SocketBase {
	public $remote_address = null;
	public $remote_port    = null;
	public $read_buffer    = '';
	public $write_buffer   = '';

	/** If the socket is blocked for writing, this will be true.
	  */
	public $bBlocked = false;

	public function __construct($sServer, $iPort)
	{
		AirD::Log(AirD::LOGTYPE_INTERNAL, "socketClient's constructor", true);
		parent::__construct();
		$this->connect($sServer, $iPort);
		$this->set_non_block(true);
		SocketEngine::AddFd($this);
	}

	public function connect($remote_address, $remote_port)
	{
		$this->connecting = true;
		parent::connect($remote_address, $remote_port);
	}

	public function write($buffer, $length = 4096)
	{
		$this->write_buffer .= $buffer;
		$this->do_write();
	}

	public function do_write($bForceWrite = false)
	{
		// If we haven't explicitly been told to try to write, don't.
		if (!$bForceWrite && $this->bBlocked)
			true;

		// Forced to write by the SE: unset blocked flag
		$this->bBlocked = false;
		$length = strlen($this->write_buffer);
		try {
			$written = parent::write($this->write_buffer, $length);
			if ($written < $length) {
				$this->write_buffer = substr($this->write_buffer, $written);
				$this->bBlocked = true;
			} else {
				$this->write_buffer = '';
			}
			$this->on_write();
			return true;
		} catch (socketException $e) {
			if (socket_last_error() == 11) // Resource temporarily unavailable.
				return false;

			AirD::Log(AirD::LOGTYPE_INTERNAL, "Exception caught while writing to socket " . (int)$this->socket . ": " . $e->getMessage());
			$old_socket         = (int)$this->socket;
			$this->close();
			$this->socket       = $old_socket;
			$this->disconnected = true;
			$this->on_disconnect();
			return false;
			
		}
		return false;
	}

	public function read($length = 4096)
	{
		try {
			$this->read_buffer .= parent::read($length);
			$this->on_read();
		} catch (socketException $e) {
			AirD::Log(AirD::LOGTYPE_INTERNAL, "Exception caught while reading from socket " . (int)$this->socket . ": " . $e->getMessage());
			$old_socket         = (int)$this->socket;
			$this->close();
			$this->socket       = $old_socket;
			$this->disconnected = true;
			$this->on_disconnect();
		}
	}

	public function on_connect() {}
	public function on_disconnect() {}
	public function on_read() {}
	public function on_write() {}
}
