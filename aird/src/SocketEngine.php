<?php
/*
 * Air -- FOSS AJAX IRC Client.
 *
 * Copyright (C) 2009, Robin Burchell <viroteck@viroteck.net>
 * Copyright (C) 2006-2007, Chris Chabot <chabotc@xs4all.nl>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

class SocketEngine
{
	private static $clients = array();

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
		foreach (SocketEngine::$clients as $socket)
		{
			if ($socket->shouldDestroy())
			{
				unset(SocketEngine::$clients[(int)$socket->socket]);
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
