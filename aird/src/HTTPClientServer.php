<?php
/*
 * Air -- FOSS AJAX IRC Client.
 *
 * Copyright (C) 2009, Robin Burchell <w00t@freenode.net>
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

class HTTPClientServer extends socketServerClient
{
	private $accepted;
	private $last_action;
	private $max_total_time   = 60;
	private $max_idle_time    = 30;
	private $keep_alive       = false;
	public  $key              = false;
	public  $streaming_client = false;
	private $irc_client       = false;

	public function __destruct()
	{
		AirD::Log(AirD::LOGTYPE_HTTP, "Destroyed HTTP connection " . $this->key . " from destructor");
		$this->Destroy();
	}

	public function Destroy()
	{
		AirD::Log(AirD::LOGTYPE_HTTP, "Destroyed HTTP connection " . $this->key);
		$this->close();
		unset($this->irc_client);
		$this->irc_client = false; // this avoids a warning in case of double-delete. not nice, but agressive deletion is better than lingering objects, and this is better than spurious warnings.
	}

	private function handle_request($request)
	{
		$output = '';
		if (!$request['version'] || ($request['version'] != '1.0' && $request['version'] != '1.1')) {
			// sanity check on HTTP version
			$header  = 'HTTP/'.$request['version']." 400 Bad Request\r\n";
			$output  = '400: Bad request';
			$header .= "Content-Length: ".strlen($output)."\r\n";
		} elseif (!isset($request['method']) || ($request['method'] != 'get' && $request['method'] != 'post')) {
			// sanity check on request method (only get and post are allowed)
			$header  = 'HTTP/'.$request['version']." 400 Bad Request\r\n";
			$output  = '400: Bad request';
			$header .= "Content-Length: ".strlen($output)."\r\n";
		} else {
			// handle request
			if (empty($request['url']) || $request['url'] == "/") {
				$request['url'] = '/index.html';
			}
			// parse get params into $params variable
			if (strpos($request['url'],'?') !== false) {
				$params = substr($request['url'], strpos($request['url'],'?') + 1);
				$params = explode('&', $params);
				foreach($params as $key => $param) {
					$pair = explode('=', $param);
					$params[$pair[0]] = isset($pair[1]) ? $pair[1] : '';
					unset($params[$key]);
				}
				$request['url'] = substr($request['url'], 0, strpos($request['url'], '?'));
			}

			// XXX: By outputting cache control and expires here, we are preventing clients from caching images -- this is not good.
			// Granted, this isn't that big a problem under small load, but it won't scale.
			$header  = "HTTP/{$request['version']} 200 OK\r\n";
			$header .= "Accept-Ranges: bytes\r\n";
			$header .= 'Last-Modified: '.gmdate('D, d M Y H:i:s T', time())."\r\n";
			$header .= "Cache-Control: no-cache, must-revalidate\r\n";
			$header .= "Expires: Mon, 26 Jul 1997 05:00:00 GMT\r\n";
			switch ($request['url']) {
				case '/get':
					// streaming iframe/comet communication (hanging get), don't send content-length!
					$nickname               = isset($params['nickname']) ? urldecode($params['nickname']) : 'chabot';
					$server = "208.68.93.158";
					$this->key              = md5("{$this->remote_address}:{$nickname}:{$server}".mt_rand());
					AirD::Log(AirD::LOGTYPE_HTTP, "New connection from " . $this->remote_address . " to " . $server . " with nickname " . $nickname . " - unique key: " . $this->key);
					// created paired irc client
					$client = new ircClient($this, $this->key, $server, 6667);
					$client->server         = $server;
					$client->client_address = $this->remote_address;
					$client->nick           = $nickname;
					$this->irc_client       = $client;
					$this->streaming_client = true;
					$header = "";
					$header  = "HTTP/{$request['version']} 200 OK\r\n";
					$header .= "Cache-Control: no-cache, must-revalidate\r\n";
					$header .= "Expires: Mon, 26 Jul 1997 05:00:00 GMT\r\n";
					/*$output    = "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\" \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n".
								 "<html xmlns=\"http://www.w3.org/1999/xhtml\">\n".
								 "<head>\n".
								 "<script type=\"text/javascript\">\nvar chat = window.parent.chat;\nchat.key = '{$this->key}';\nchat.connected = true;\n</script>\n".
								 "</head>\n".
								 "<body>\n";*/
				$output    =  "chat.key = '{$this->key}';\nthis.connected = true;\n";

					if (!empty($client->output)) {
						$output .= $client->output;
						$client->output = '';
					}
					break;
				case '/message':
					if (!empty($params['key']) && !empty($params['msg']))
					{
						$sChannel = urldecode($params['channel']);
						AirD::Log(AirD::LOGTYPE_HTTP, "Got a command from client " . $params['key'] . " in " . $params['channel'] . ": " . urldecode($params['msg']),  true);
						if (isset(AirD::$aIRCClients[$params['key']]))
							AirD::$aIRCClients[$params['key']]->message($sChannel, html_entity_decode(urldecode($params['msg'])));
					}
					$output  = "<script type=\"text/javascript\"></script>\r\n";
					$header  = "HTTP/{$request['version']} 200 OK\r\n";
					$header .= "Accept-Ranges: bytes\r\n";
					$header .= "Cache-Control: no-cache, must-revalidate\r\n";
					$header .= "Expires: Mon, 26 Jul 1997 05:00:00 GMT\r\n";
					$header .= "Accept-Ranges: bytes\r\n";
					$header .= "Content-Length: ".strlen($output)."\r\n";
					break;
				default:
					$request['url'] = str_replace('..', '', $request['url']);
					$file = '../htdocs'.$request['url'];
					if (file_exists($file) && is_file($file)) {
						AirD::Log(AirD::LOGTYPE_HTTP, "Client " . $this->remote_address. " requested a file: " . $file,  true);

						// Do basic mime type sniffing. Required for Chrome, and a good idea anyway.
						$sContentType = "";
						$aExt = explode(".", basename($file));
						if (isset($aExt[1]))
						{
							switch (strtolower($aExt[1]))
							{
								case "css":
									$sContentType = "text/css";
									break;
								case "js":
									$sContentType = "text/javascript";
									break;
							}
						}

						// rewrite header
						$header  = "HTTP/{$request['version']} 200 OK\r\n";
						$header .= "Accept-Ranges: bytes\r\n";
						$header .= 'Last-Modified: '.gmdate('D, d M Y H:i:s T', filemtime($file))."\r\n";
						$size    = filesize($file);
						$header .= "Content-Length: $size\r\n";
						if (!empty($sContentType))
							$header .= "Content-Type: " . $sContentType . "\r\n";
						$output  = file_get_contents($file);
					} else {
						AirD::Log(AirD::LOGTYPE_HTTP, "Client " . $this->remote_address. " requested a NONEXISTANT file: " . $file,  true);
						$output  = "<h1>404: Document not found.</h1>Couldn't find $file";
						$header  = "'HTTP/{$request['version']} 404 Not Found\r\n".
						           "Content-Length: ".strlen($output)."\r\n";
					}
					break;
			}
		}
		$header .= 'Date: '.gmdate('D, d M Y H:i:s T')."\r\n";
		if ($this->keep_alive && $request['url'] != '/get') {
			$header .= "Connection: Keep-Alive\r\n";
			$header .= "Keep-Alive: timeout={$this->max_idle_time} max={$this->max_total_time}\r\n";
		} else {
			$this->keep_alive = false;
			$header .= "Connection: Close\r\n";
		}
		return $header."\r\n".$output;
	}

	public function on_read()
	{
		$this->last_action = time();
		if ((strpos($this->read_buffer,"\r\n\r\n")) !== FALSE || (strpos($this->read_buffer,"\n\n")) !== FALSE) {
			$request = array();
			$headers = split("\n", $this->read_buffer);
			$request['uri'] = $headers[0];
			unset($headers[0]);
			while (list(, $line) = each($headers)) {
				$line = trim($line);
				if ($line != '') {
					$pos  = strpos($line, ':');
					$type = substr($line,0, $pos);
					$val  = trim(substr($line, $pos + 1));
					$request[strtolower($type)] = strtolower($val);
				}
			}
			$uri                = $request['uri'];
			$request['method']  = strtolower(substr($uri, 0, strpos($uri, ' ')));
			$request['version'] = substr($uri, strpos($uri, 'HTTP/') + 5, 3);
			$uri                = substr($uri, strlen($request['method']) + 1);
			$request['url']     = substr($uri, 0, strpos($uri, ' '));
			foreach ($request as $type => $val) {
				if ($type == 'connection' && $val == 'keep-alive') {
					$this->keep_alive = true;
				}
			}
			$this->write($this->handle_request($request));
			$this->read_buffer  = '';
		}
	}

	public function on_connect()
	{
		$this->accepted    = time();
		$this->last_action = $this->accepted;
	}

	public function on_disconnect()
	{
		if ($this->irc_client)
		{
			$this->irc_client->quit("Remote client closed connection");
		}

		$this->Destroy();
	}

	public function on_timer()
	{
		$idle_time  = time() - $this->last_action;
		$total_time = time() - $this->accepted;
		if (($total_time > $this->max_total_time || $idle_time > $this->max_idle_time) && !$this->streaming_client) {
			$this->close();
			$this->on_disconnect();
		}

		if ($this->streaming_client && $this->irc_client)
		{
			$this->irc_client->send_script('chat.onSetNumberOfUsers(' . count(AirD::$aIRCClients) . ');');
		}
	}

	public function on_write()
	{
		if (strlen($this->write_buffer) == 0 && !$this->keep_alive && !$this->streaming_client) {
			$this->disconnected = true;
			$this->on_disconnect();
			$this->close();
		}
	}
}
