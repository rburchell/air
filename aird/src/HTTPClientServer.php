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
	private $max_total_time   = 20;
	private $max_idle_time    = 10;
	private $keep_alive       = false;
	public  $key              = false;
	public  $streaming_client = false;
	private $irc_client       = false;

	private $aHeaders = array();

	public function __destruct()
	{
		AirD::Log(AirD::LOGTYPE_HTTP, "Destroyed HTTP connection " . $this->key . " from destructor");
		$this->Destroy();
	}

	public function Destroy()
	{
		AirD::Log(AirD::LOGTYPE_HTTP, "Destroyed HTTP connection " . $this->key);
		if ($this->irc_client)
			$this->irc_client->setHTTPClient(null);
		$this->close();
	}

	public function setStreaming($bStreaming)
	{
		$this->streaming_client = $bStreaming;
	}

	private function setHeader($sHeader, $sValue)
	{
		$this->aHeaders[$sHeader] = $sValue;
	}

	private function sendResponse($sHTTPVersion, $iResponse, $sResponse, $sBody)
	{
		// Set date
		$this->setHeader("Date", gmdate('D, d M Y H:i:s T'));

		// First, do something useful with HTTP info.
		$sResponse = "HTTP/". $sHTTPVersion . " " . $iResponse . " " . $sResponse . "\r\n";

		// Headers next.
		foreach ($this->aHeaders as $sKey => $sVal)
		{
			$sResponse .= $sKey . ": " . $sVal . "\r\n";
		}

		// End headers
		$sResponse .= "\r\n";

		// Now body.
		$sResponse .= $sBody;

		// Write whole response
		$this->write($sResponse);

		// Blank headers, primarily for keepalive.
		$this->aHeaders = array();
	}

	private function handleFileRequest($aRequest)
	{
		while (strpos("..", $aRequest['url']) !== false)
		$aRequest['url'] = str_replace('..', '', $aRequest['url']);

		$file = '../htdocs'.$aRequest['url'];
		if (!file_exists($file) || !is_file($file))
		{
			AirD::Log(AirD::LOGTYPE_HTTP, "Client " . $this->remote_address. " requested a NONEXISTANT file: " . $file,  true);
			$this->setHeader("Last-Modified", gmdate('D, d M Y H:i:s T', time()));
			$this->setHeader("Cache-Control", "no-cache, must-revalidate");
			$this->setHeader("Expires", "Mon, 26 Jul 1997 05:00:00 GMT");
			$sMsg = "<h1>404</h1>The document you searched so hard for doesn't exist. Sorry!";
			$this->setHeader("Content-Length", strlen($sMsg));
			$this->sendResponse($aRequest['version'], 404, "404 Not Found", $sMsg);
			return;
		}

		$mtime = filemtime($file);

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
					$sContentType = "application/javascript";
					break;
			}
		}

		$this->setHeader("Last-Modified", gmdate('D, d M Y H:i:s T', filemtime($file)));
		if (!empty($sContentType))
			$this->setHeader("Content-Type", $sContentType);

		//  [if-modified-since] => fri,  20 mar 2009 00:10:32 gmt
		if (isset($aRequest['if-modified-since']) && strtotime($aRequest['if-modified-since']) == $mtime)
		{
			// Previously requested data, just send 304
			AirD::Log(AirD::LOGTYPE_HTTP, "Client " . $this->remote_address. " requested a CACHED file: " . $file,  true);
			$this->sendResponse($aRequest['version'], 304, "Not Modified", "");
			return;
		}
		else
		{
			// Newly requested data, process request properly
			AirD::Log(AirD::LOGTYPE_HTTP, "Client " . $this->remote_address. " requested a file: " . $file,  true);
			// Send file.
			$sFile = file_get_contents($file);

			$this->setHeader("Content-Length", strlen($sFile)); // was using filesize($file)
			$this->sendResponse($aRequest['version'], 200, "OK", $sFile);
		}
	}

	private function handle_request($request)
	{
		$aHeaders = array();
		$sOutput = "";
		$output = '';

		// Use the IP squid gives us
		if (isset($request['x-forwarded-for']))
			$this->remote_address = $request['x-forwarded-for'];

		// Sanity check on HTTP version
		if (!$request['version'] || ($request['version'] != '1.0' && $request['version'] != '1.1'))
		{
			$sMsg = "Bad request: You specified a version of HTTP I don't understand. I only speak 1.0 or 1.1.";
			$this->setHeader("Content-Length", strlen($sMsg));
			$this->sendResponse($request['version'], 400, "Bad Request", $sMsg);
			return;
		}
		
		// sanity check on request method (only get and post are allowed)
		if (!isset($request['method']) || ($request['method'] != 'get'))
		{
			$sMsg = "Bad request: You specified a HTTP method I don't understand. I only understand GET.";
			$this->setHeader("Content-Length", strlen($sMsg));
			$this->sendResponse($request['version'], 400, "Bad Request", $sMsg);
			return;
		}


		// Do some basic URI mongling.
		if (empty($request['url']) || $request['url'] == "/")
		{
			$request['url'] = '/index.html';
		}

		// GET params => array.
		if (strpos($request['url'],'?') !== false)
		{
			$params = substr($request['url'], strpos($request['url'],'?') + 1);
			$params = explode('&', $params);
			foreach($params as $key => $param)
			{
				$pair = explode('=', $param);
				$params[$pair[0]] = isset($pair[1]) ? $pair[1] : '';
				unset($params[$key]);
			}
			$request['url'] = substr($request['url'], 0, strpos($request['url'], '?'));
		}

		if ($this->keep_alive)
		{
			$this->setHeader("Connection", "Keep-Alive");
			$this->setHeader("Keep-Alive", "timeout={$this->max_idle_time} max={$this->max_total_time}");
			AirD::Log(AirD::LOGTYPE_HTTP, "Keepalive");
		}
		else
		{
			$this->setHeader("Connection", "Close");
			AirD::Log(AirD::LOGTYPE_HTTP, "Close");
		}

		switch ($request['url'])
		{
			case '/get':
				// streaming iframe/comet communication (hanging get), don't send content-length!
				$nickname               = (isset($params['nickname']) && !empty($params['nickname'])) ? urldecode($params['nickname']) : 'bc' . mt_rand(0, 9999);
				$server                 = (isset($params['server']) && !empty($params['server'])) ? urldecode($params['server']) : "irc.browserchat.net";
				$this->key              = md5("{$this->remote_address}:{$nickname}:{$server}".mt_rand());
				AirD::Log(AirD::LOGTYPE_HTTP, "New connection from " . $this->remote_address . " to " . $server . " with nickname " . $nickname . " - unique key: " . $this->key);
				// created paired irc client
				$client = new ircClient($this->key, $server, 6667);
				$client->setHTTPClient($this);
				$client->client_address = $this->remote_address;
				$client->nick           = $nickname;
				$this->irc_client       = $client;
				$this->setStreaming(true);
				$this->setHeader("Cache-Control", "no-cache, must-revalidate");
				$this->setHeader("Expires", "Mon, 26 Jul 1997 05:00:00 GMT");
				$this->sendResponse($request['version'], 200, "OK", "chat.key = '{$this->key}';\nthis.connected = true;\n");

				if (!empty($client->output))
				{
					$this->write($client->output);
					$client->output = '';
				}
				break;
			case '/renegotiate':
				if (isset($params['key']) && !empty($params['key']) && isset(AirD::$aIRCClients[$params['key']]))
				{
					$this->irc_client       = AirD::$aIRCClients[$params['key']];
					$this->setStreaming(true);
					$this->key = $params['key'];
					AirD::Log(AirD::LOGTYPE_HTTP, "Renegotiated for " . $params['key']);
					$this->setHeader("Cache-Control", "no-cache, must-revalidate");
					$this->setHeader("Expires", "Mon, 26 Jul 1997 05:00:00 GMT");
					$this->sendResponse($request['version'], 200, "OK", "chat.key = '{$this->key}';\nthis.connected = true;\n");
					AirD::$aIRCClients[$params['key']]->setHTTPClient($this);

					if (!empty($client->output))
					{
						$this->write($client->output);
						$client->output = '';
					}
				}
				else
				{
					AirD::Log(AirD::LOGTYPE_HTTP, "Renegotiation for " . $params['key'] . " failed");
					$sMsg = "Bad request: The key was invalid"; 
					$this->setHeader("Content-Length", strlen($sMsg));
					$this->sendResponse($request['version'], 400, "Bad Request", $sMsg);
					return;
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

				$this->setHeader("Cache-Control", "no-cache, must-revalidate");
				$this->setHeader("Expires", "Mon, 26 Jul 1997 05:00:00 GMT");
				$this->sendResponse($request['version'], 200, "OK", "");
				break;
			default:
				$this->handleFileRequest($request);
				break;
		}
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
			foreach ($request as $type => $val)
			{
				if ($type == 'connection' && $val == 'keep-alive')
				{
					$this->keep_alive = true;
				}
			}
			$this->handle_request($request);
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
		$this->Destroy();
	}

	public function on_timer()
	{
		$idle_time  = time() - $this->last_action;
		$total_time = time() - $this->accepted;

		if ($this->streaming_client && $this->irc_client)
		{
			$this->irc_client->send_script('chat.onSetNumberOfUsers(' . count(AirD::$aIRCClients) . ');');
		}

		if (($total_time > $this->max_total_time || $idle_time > $this->max_idle_time) && !$this->streaming_client) {
			$this->on_disconnect();
			$this->Destroy();
		}
	}

	public function on_write()
	{
		if (strlen($this->write_buffer) == 0 && !$this->keep_alive && !$this->streaming_client) {
			$this->disconnected = true;
			$this->on_disconnect();
			$this->Destroy();
		}
	}
}
