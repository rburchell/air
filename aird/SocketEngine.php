<?
class SocketEngine
{
	public static $clients = array();

/*
	public function create_server($server_class, $client_class, $bind_address = 0, $bind_port = 0)
	{
		$server = new $server_class($client_class, $bind_address, $bind_port);
		if (!is_subclass_of($server, 'socketServer')) {
			throw new socketException("Invalid server class specified! Has to be a subclass of socketServer");
		}
		SocketEngine::servers[(int)$server->socket] = $server;
		return $server;
	}

	public function create_client($client_class, $remote_address, $remote_port, $bind_address = 0, $bind_port = 0)
	{
		$client = new $client_class($bind_address, $bind_port);
		if (!is_subclass_of($client, 'socketClient')) {
			throw new socketException("Invalid client class specified! Has to be a subclass of socketClient");
		}
		$client->set_non_block(true);
		$client->connect($remote_address, $remote_port);
		SocketEngine::$clients[(int)$client->socket] = $client;
		return $client;
	}
*/

	private static function create_read_set()
	{
		$ret = array();
		foreach (SocketEngine::$clients as $socket)
		{
			// Reading on a connecting socket will fail with EINPROGRESS. Bad.
			if ($socket->connecting)
			{
				continue;
			}
				$ret[] = $socket->socket;
		}
		return $ret;
	}

	private static function create_write_set()
	{
		$ret = array();
		foreach (SocketEngine::$clients as $socket) {
			if (!empty($socket->write_buffer) || $socket->connecting) {
				$ret[] = $socket->socket;
			}
		}
		return $ret;
	}

	private static function create_exception_set()
	{
		$ret = array();
		foreach (SocketEngine::$clients as $socket) {
			$ret[] = $socket->socket;
		}
		return $ret;
	}

	private static function clean_sockets()
	{
		foreach (SocketEngine::$clients as $socket) {
			if ($socket->disconnected || !is_resource($socket->socket)) {
				if (isset(SocketEngine::$clients[(int)$socket->socket])) {
					unset(SocketEngine::$clients[(int)$socket->socket]);
				}
			}
		}
	}

	private static function get_class($socket)
	{
		if (isset(SocketEngine::$clients[(int)$socket]))
			return SocketEngine::$clients[(int)$socket];
		else
			throw (new socketException("Could not locate socket class for $socket"));
	}

	public static function AddFd($socket)
	{
		AirD::Log(AirD::LOGTYPE_INTERNAL, "Adding socket " . (int)$socket->socket . " to socket engine.");
		SocketEngine::$clients[(int)$socket->socket] = $socket;
	}

	public static function process()
	{
		// if socketClient is in write set, and $socket->connecting === true, set connecting to false and call on_connect
		$read_set      = SocketEngine::create_read_set();
		$write_set     = SocketEngine::create_write_set();
		$exception_set = SocketEngine::create_exception_set();
		$event_time    = time();
		while (($events = socket_select($read_set, $write_set, $exception_set, null)) !== false) {
			AirD::Log(AirD::LOGTYPE_INTERNAL, "Top of main loop.", true);
			if ($events > 0) {
				AirD::Log(AirD::LOGTYPE_INTERNAL, "Processing " . $events . " socket events", true);
				foreach ($read_set as $socket)
				{
					AirD::Log(AirD::LOGTYPE_INTERNAL, "Processing a read event for " . (int)$socket, true);
					SocketEngine::$clients[(int)$socket]->read();
				}
				foreach ($write_set as $socket) {
					$socket = SocketEngine::get_class($socket);
					if (is_subclass_of($socket, 'socketClient')) {
						if ($socket->connecting === true) {
							$socket->on_connect();
							$socket->connecting = false;
						}
						$socket->do_write(true);
					}
				}
				foreach ($exception_set as $socket) {
					$socket = SocketEngine::get_class($socket);
					if (is_subclass_of($socket, 'socketClient')) {
						$socket->on_disconnect();
						if (isset(SocketEngine::$clients[(int)$socket->socket])) {
							unset(SocketEngine::$clients[(int)$socket->socket]);
						}
					}
				}
			}
			if (time() - $event_time > 1) {
				// only do this if more then a second passed, else we'd keep looping this for every bit recieved
				foreach (SocketEngine::$clients as $socket) {
					$socket->on_timer();
				}
				$event_time = time();
			}
			SocketEngine::clean_sockets();
			$read_set      = SocketEngine::create_read_set();
			$write_set     = SocketEngine::create_write_set();
			$exception_set = SocketEngine::create_exception_set();
		}
	}
}
