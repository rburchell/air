<?php
/*
WebChat2.0 Copyright (C) 2006-2007, Chris Chabot <chabotc@xs4all.nl>

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

require('ircChannel.php');

class ircClient extends socketClient
{
	/** The HTTP client this IRC client is attached to.
	  */
	private $oHTTPClient;

	private $channels;
	public  $nick;
	public  $key;
	public  $names = array();
	public  $server;
	public  $client_address;
	private $script_sends;

	private $sListModes; // Modes that require a param to add/remove, and may have multiple entries stored (e.g. +beIg)
	private $sParamModes; // Modes that require a param to add/remove (e.g. +Lfk)
	private $sLazyParamModes; // Modes that require a param to add, none to remove (+l)
	private $sNoParam; // Modes that don't take a parameter (+imnt)

	/** When the HTTP client disconnected
	 */
	private $iHTTPDisconnected;
	private $aPendingMessages = array();

	/** Mode -> prefix lookup table.
	  * e.g. o => @
	  */
	private $aPrefixModes;
	/** Reverse mapping (@ -> o)
	  */
	private $aReversePrefixModes;

	/*** handle user commands ***/


	/* TODO commands:
	/ignore (nick)
	/query nick (msg) < open new win!
	/whois nick
	/ping (finish with ping times!)
	/help -> give overview of commands!
	*/

	public function __construct($sKey, $sServer, $iPort)
	{
		$this->key = $sKey;
		AirD::Log(AirD::LOGTYPE_INTERNAL, "Created a new IRC client: " . $sKey, true);
		parent::__construct($sServer, $iPort);
		AirD::$aIRCClients[$sKey] = $this;
	}

	public function setHTTPClient($oHTTPClient)
	{
		$this->oHTTPClient = $oHTTPClient;

		if (!$oHTTPClient)
		{
			$this->iHTTPDisconnected = time();
		}
		else
		{
			// Reconnected, send all pending messages in the queue
			AirD::Log(AirD::LOGTYPE_INTERNAL, "Client reattaching to " . $this->key . ", flushing " . count($this->aPendingMessages) . " to http", true);
			foreach ($this->aPendingMessages as $sMessage)
				$this->oHTTPClient->write($sMessage);

			$this->aPendingMessages = array();
		}
	}

	public function __destruct()
	{
		AirD::Log(AirD::LOGTYPE_INTERNAL, "Destroyed IRC client " . $this->key . " via destructor");
		$this->Destroy();
	}

	public function Destroy()
	{
		AirD::Log(AirD::LOGTYPE_INTERNAL, "Destroyed IRC client " . $this->key);
		$this->close();

		if ($this->oHTTPClient)
		{
			$this->oHTTPClient->close();
			unset($this->oHTTPClient);
		}
		unset(AirD::$aIRCClients[$this->key]);
	}

	public function escape($s)
	{
		$s = str_replace("\\", "\\\\", $s);
//		$s = str_replace("'", "\\'", $s);
		$s = htmlentities($s, ENT_QUOTES, 'UTF-8');
		return $s;
	}

	private function user_command($destination, $msg)
	{
		AirD::Log(AirD::LOGTYPE_IRC, "Processing user command for client " . $this->key . " with destination " . $destination . ": " . $msg, true);
		$cmd   = trim(substr($msg, 1, strpos($msg, ' '))) != '' ? trim(substr($msg, 1, strpos($msg, ' '))) : trim(substr($msg, 1));
		$param = strpos($msg, ' ') !== false ? trim(substr($msg, strpos($msg, ' ') + 1)) : '';
		switch (strtolower($cmd)) {
			case 'version':
				$this->action($param, "VERSION");
				break;
			case 'time':
				$this->action($param, "TIME");
				break;
			case 'ping':
				$this->action($param, "PING ".time());
				break;
			case 'list':
				$this->channel_list();
				break;
			case 'topic':
				if (empty($param)) {
					$this->get_topic($destination);
				} else {
					$this->set_topic($destination, $param);
				}
				break;
			case 'mode':
				$who  = strpos($param, ' ') !== false ? trim(substr($param, 0, strpos($param, ' ')))  : $param;
				$mode = strpos($param, ' ') !== false ? trim(substr($param, strpos($param, ' ') + 1)) : '';
				if (empty($mode)) {
					$this->get_mode($who);
				} else {
					$this->set_mode($who, $mode);
				}
				break;
			case 'kick':
				$who     = (strpos($param, ' ') !== false) ? trim(substr($param, 0, strpos($param, ' '))) : $param;
				$reason  = (strpos($param, ' ') !== false) ? trim(substr($param, strpos($param, ' ')))    : '';
				$this->kick($destination, $who, $reason);
				break;
			case 'names':
				$this->names($param);
				break;
			case 'invite':
				$who   = strpos($param, ' ') !== false ? trim(substr($param, 0, strpos($param, ' ')))  : $param;
				$where = strpos($param, ' ') !== false ? trim(substr($param, strpos($param, ' ') + 1)) : $destination;
				$this->invite($where, $param);
				break;
			case 'me':
			case 'action':
				$this->action($destination, $param);
				break;
			case 'quit':
				$this->quit($param);
				break;
			case 'msg':
			case 'privmsg':
				$who = strpos($param, ' ') !== false ? trim(substr($param, 0, strpos($param, ' ')))  : $param;
				$msg = strpos($param, ' ') !== false ? trim(substr($param, strpos($param, ' ') + 1)) : '';
				$this->message($who, $msg);
				AirD::Log(AirD::LOGTYPE_IRC, "Processing message from client to " . $destination . ": " . $param);
				break;
			case 'say':
				$this->message($destination, $param);
				break;
			case 'notice':
				$this->notice($destination, $param);
				break;
			default:
				$this->write($cmd . " " . $param . "\r\n");
		}
	}

	public function write($s, $l = 5000)
	{
		parent::write($s, $l);
	}

	/*** IRC methods ***/

	public function join($channel)
	{
		$this->write("JOIN $channel\r\n");
	}

	public function part($channel, $reason = false)
	{
		$reason = empty($reason) ? '' : " :$reason";
		$this->write("PART $channel$reason\r\n");
	}

	public function kick($channel, $who, $reason)
	{
		$reason = empty($reason) ? '' : " :$reason";
		$this->write("KICK $channel $who$reason\r\n");
	}

	public function channel_list()
	{
		$this->write("LIST\r\n");
	}

	public function names($channel = false)
	{
		$channel = $channel ? " $channel" : '';
		$this->write("NAMES{$channel}\r\n");
	}

	public function set_topic($channel, $topic)
	{
		$this->write("TOPIC $channel :$topic\r\n");
	}

	public function get_topic($channel)
	{
		$this->write("TOPIC $channel\r\n");
	}

	public function get_mode($who)
	{
		$this->write("MODE $who\r\n");
	}

	public function set_mode($who, $mode)
	{
		$this->write("MODE $who $mode\r\n");
	}

	public function op($channel, $who)
	{
		$this->set_mode($channel, "+o $who");
	}

	public function deop($channel, $who)
	{
		$this->set_mode($channel, "-o $who");
	}

	public function voice($channel, $who)
	{
		$this->set_mode($channel, "+v $who");
	}

	public function devoice($channel, $who)
	{
		$this->set_mode($channel, "-v $who");
	}

	public function ban($channel, $hostmask)
	{
		$this->set_mode($channel, "+b $hostmask");
	}

	public function unban($channel, $hostmask)
	{
		$this->set_mode($channel, "-b $hostmask");
	}

	public function invite($channel, $who)
	{
		$this->write("INVITE $who $channel\r\n");
	}

	public function nick($newnick)
	{
		$this->write("NICK $newnick\r\n");
	}

	public function who($who)
	{
		$this->write("WHO $who\r\n");
	}

	public function whowas($who)
	{
		$this->write("WHOWAS $who\r\n");
	}

	public function whois($who)
	{
		$this->write("WHOIS $who\r\n");
	}

	public function quit($reason = false)
	{
		$reason = empty($reason) ? 'http://my.browserchat.net - free web chat' : " :$reason";
		$this->write("QUIT$reason\r\n");
		$this->close();
	}

	public function action($destination, $msg)
	{
		$sCTCP = chr(1). "ACTION " . $msg.chr(1);
		$this->write("PRIVMSG $destination :". $sCTCP . "\r\n");
		$this->on_ctcp($this->nick, $destination, $sCTCP);
	}

	public function message($destination, $msg)
	{
		AirD::Log(AirD::LOGTYPE_IRC, "Got a client message to " . $destination . ": " . $msg);
		if (substr($msg, 0, 1) == '/') {
			$this->user_command($destination, $msg);
		} else {
			$this->write("PRIVMSG $destination :$msg\r\n");
			if (substr($destination, 0, 1) == '#') {
				$this->on_msg($this->nick, $destination, $msg);
			} else {
				// this was a priv msg..
			}
		}
	}

	public function notice($destination, $msg)
	{
		$this->write("NOTICE $destination :$msg\r\n");
	}

	/*** IRC event handlers ***/

	public function on_privctcp($from, $msg)
	{
		if (($pos = strpos($msg,' ')) !== false) {
			$param = trim(substr($msg, $pos + 1));
			$msg   = trim(substr($msg, 0, $pos));
		}
		$action = strtolower(trim($msg));
		switch ($action) {
			case 'ping':
				$this->write("NOTICE $from :".chr(1)."PING $param".chr(1)."\r\n");
				$from = $this->escape($from);
				$this->send_script("chat.onCTCP('$from', 'PING')");
				break;
			case 'time':
				$this->write("NOTICE $from :".chr(1).gmdate("D, d M Y H:i:s", time() + 900)." GMT".chr(1)."\r\n");
				$from = $this->escape($from);
				$this->send_script("chat.onCTCP('$from', 'TIME')");
				break;
//			case 'version':
//				$this->write("NOTICE $from :".chr(1)."BrowserChat (http://www.browserchat.net)".chr(1)."\r\n");
//				$from = $this->escape($from);
//				$this->send_script("chat.onCTCP('$from', 'VERSION')");
//				break;
		}
	}

	public function on_ctcp($from, $channel, $msg)
	{
		$action = strtolower(trim(substr($msg, 1, strpos($msg, ' '))));
		$arg    = trim(substr($msg, strlen($action) + 1));
		$arg    = substr($arg, 0, strlen($arg) - 1);
		switch ($action) {
			case 'action':
				$from    = $this->escape($from);
				$channel = $this->escape($channel);
				$arg     = $this->escape($arg);
				$this->send_script("chat.onAction('$channel', '$from', '$arg')");
				break;
			default:
				AirD::Log(AirD::LOGTYPE_IRC, "Unknown CTCP: " . $action . "\n", true);
				break;
		}
	}

	public function on_nick($from, $to)
	{
		if (is_array($this->channels)) {
			foreach ($this->channels as $channel) {
				$channel->on_nick($from, $to);
			}
		}
		if ($from == $this->nick) {
			$this->nick = $to;
		}
	}

	public function on_privmsg($from, $msg)
	{
		$from = $this->escape($from);
		$msg  = $this->escape($msg);
		$this->send_script("chat.onPrivateMessage('$from','$msg')");

	}

	public function on_msg($from, $channel, $msg)
	{
		$from = $this->escape($from);
		$channel = $this->escape($channel);
		$msg  = $this->escape($msg);

		$this->send_script("chat.onMessage('$from', '$channel', '$msg')");
	}

	public function on_notice($from, $msg)
	{
		$from = $this->escape($from);
		$msg  = $this->escape($msg);
		$this->send_script("chat.onNotice('$from','$msg')");
	}

	public function on_mode($from, $command, $to, $aModes)
	{
		if (isset($this->channels[$to]))
		{
			AirD::Log(AirD::LOGTYPE_IRC, "Setting mode from " . $from . " with command " . $command . " to " . $to . " and modes " . implode(" ", $aModes));
			$channel = $this->channels[$to];
			$this->send_script("chat.onChannelMode('{$this->escape($to)}','" . $this->escape(implode(" ", $aModes)). "')");
			$mode    = array_shift($aModes);
			$modelen = strlen($mode);
			$add = true;
			for ($i = 0; $i < $modelen; $i++)
			{
				switch($mode[$i])
				{
					case '-':
						$add    = false;
						break;
					case '+':
						$add    = true;
						break;
					default:
						if (strpos($this->sListModes, $mode[$i]) !== false)
						{
							// It's a listmode! This means it requires a mask to set/unset, so do so.
							$sParam = array_shift($aModes);
							AirD::Log(AirD::LOGTYPE_IRC, "Setting listmode type " . $mode[$i] . " param is " . $sParam);
							$add ? $channel->SetListMode($mode[$i], $sParam) : $channe->UnsetListMode($mode[$i], $sParam);
						}
						else if (strpos($this->sParamModes, $mode[$i]) !== false)
						{
							// It's a normal param mode, requires a param to set/unset.
							$sParam = array_shift($aModes);
							AirD::Log(AirD::LOGTYPE_IRC, "Setting param mode type " . $mode[$i] . " param is " . $sParam);
							$add ? $channel->SetMode($mode[$i], $sParam) : $channel->UnsetMode($mode[$i], $sParam);
						}
						else if (strpos($this->sLazyParamModes, $mode[$i]) !== false)
						{
							// Lazy param mode: requires a param to set, none to unset.
							$sParam = $add ? array_shift($aModes) : "";
							AirD::Log(AirD::LOGTYPE_IRC, "Setting lazy param mode type " . $mode[$i] . " param is " . $sParam);
							$add ? $channel->SetMode($mode[$i], $sParam) : $channel->UnsetMode($mode[$i]);
						}
						else if (strpos($this->sNoParam, $mode[$i]) !== false)
						{
							// Simple binary mode: doesn't take a parameter, ever.
							AirD::Log(AirD::LOGTYPE_IRC, "Setting regular mode type " . $mode[$i]);
							$add ? $channel->SetMode($mode[$i]) : $channel->UnsetMode($mode[$i]);
						}
						else
						{
							// Status mode.
							$sParam = array_shift($aModes);
							if (isset($this->aPrefixModes[$mode[$i]]))
							{
								AirD::Log(AirD::LOGTYPE_IRC, "Setting prefix mode type " . $mode[$i] . " param is " . $sParam);
								$add ? $channel->SetPrefixMode($sParam, $this->aPrefixModes[$mode[$i]]) : $channel->UnsetPrefixMode($sParam, $this->aPrefixModes[$mode[$i]]);

							}
							else
							{
								AirD::Log(AirD::LOGTYPE_IRC, "Unknown mode - what shitty ircd is this? not handling: " . $mode[$i] . " param: " . $sParam);
							}
						}
						break;
				}
			}
		}
	}

	public function on_server_notice($notice)
	{
		$notice = $this->escape($notice);
		$this->send_script("chat.onServerNotice('$notice')");
	}

	public function on_error($error)
	{
		AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got error: " . $error);
		$error = $this->escape($error);
		$this->send_script("chat.onError('$error')");
		$this->close();
	}

	public function on_kick($channel, $from, $who, $reason)
	{
		if (isset($this->channels[$channel])) $this->channels[$channel]->on_kick($from, $who, $reason);
	}

	public function on_part($who, $channel, $message)
	{
		if (isset($this->channels[$channel])) $this->channels[$channel]->on_part($who, $message);
		if ($who == $this->nick) {
			$channel = $this->escape($channel);
			$this->send_script("chat.removeWindow('{$channel}')");
			unset($this->channels[$channel]);
		}
	}

	public function on_quit($who)
	{
		foreach ($this->channels as $channel) {
			$channel->on_quit($who);
		}
	}

	public function on_parted($channel)
	{
	}

	public function on_join($who, $channel)
	{
		if (isset($this->channels[$channel])) $this->channels[$channel]->on_join($who);
	}

	public function on_joined($channel)
	{
		$this->channels[$channel] = new ircChannel($this, $channel);
	}

	public function on_topic($channel, $topic)
	{
		if (isset($this->channels[$channel])) $this->channels[$channel]->on_topic($topic);
	}


	/*** irc state functions ***/

	private function rpl_welcome($aParams)
	{
		// reset our nickname, it might be truncated or changed by server
		$this->on_nick($this->nick, $aParams[2]);
		$this->nick = $aParams[2];
	}

	private function rpl_yourhost($aParams)
	{
		$this->send_script("chat.onServerInfo('your_host','" . $this->escape($aParams[3]) . "')");
	}

	private function rpl_created($aParams)
	{
		$this->send_script("chat.onServerInfo('created','" . $this->escape($aParams[3]) . "')");
	}

	private function rpl_myinfo($aParams)
	{
		$this->send_script("chat.onServerInfo('my_info','" . $this->escape($aParams[3]) . "')");
	}

	private function rpl_isupport($aParams)
	{
		// XXX: Technically, this makes a lot of assumptions about IRCd behaviour. We should probably not do that.
		$aTokens = array_slice($aParams, 3);
		foreach ($aTokens as $sToken)
		{
			$aValues = explode("=", $sToken);

			if ($aValues[0] == "CHANMODES")
			{
				// aValues[1] is like cat1,cat2,cat3,cat4 - so explode on ,.
				$aModeTypes = explode(",", $aValues[1]);
				$this->sListModes = $aModeTypes[0];
				$this->sParamModes = $aModeTypes[1];
				$this->sLazyParamModes = $aModeTypes[2];
				$this->sNoParam = $aModeTypes[3];
				AirD::Log(AirD::LOGTYPE_IRC, "Set modegroups to: " . $this->sListModes . " : " . $this->sParamModes . " : " . $this->sLazyParamModes . " : " . $this->sNoParam);
			}
			else if ($aValues[0] == "PREFIX")
			{ 
				// PREFIX is (unfortunately) a lot messier to parse.
				// aValues[1] looks like (ov)@+, or (qaohv)~&@%+, et cetera.
				// First, let's split the ( off.
				$aValues[1] = substr($aValues[1], 1);

				// Now, explode on ) in order to seperate modes from prefixes so we can do this in a simple loop.
				$aPrefixModes = explode(")", $aValues[1]);

				$i = 0;
				while (isset($aPrefixModes[0][$i]) && isset($aPrefixModes[1][$i]))
				{
					// Lookup mode -> value.
					$this->aPrefixModes[$aPrefixModes[0][$i]] = $aPrefixModes[1][$i];

					// Set the reverse too for fast /names parsing
					$this->aReversePrefixModes[$aPrefixModes[1][$i]] = $aPrefixModes[0][$i];
					AirD::Log(AirD::LOGTYPE_IRC, "Added a prefix mode: " . $aPrefixModes[0][$i] . " value: " . $aPrefixModes[1][$i]);
					$i++;
				}

				$this->send_script("chat.onSetPrefixTypes('\\" . implode("\\", $this->aPrefixModes) . "')");
			}
			else if ($aValues[0] == "NAMESX")
			{
				// Multi-prefix support.
				$this->write("PROTOCTL NAMESX\r\n");
			}
		}
		$this->send_script("chat.onServerInfo('ispport','" . $this->escape(implode(" ", array_slice($aParams, 3))) . "')");
	}

	private function rpl_uniqid($aParams)
	{
		$this->send_script("chat.onServerInfo('uniq_id','" . $this->escape(implode(" ", array_slice($aParams, 3))) . "')");
	}

	private function rpl_luserclient($aParams)
	{
		$this->send_script("chat.onServerInfo('local_user_client','" . $this->escape($aParams[3]) . "')");
	}

	private function rpl_luserop($aParams)
	{
		$this->send_script("chat.onServerInfo('local_user_ops','" . $this->escape(implode(" ", array_slice($aParams, 3))) . "')");
	}

	private function rpl_luserme($aParams)
	{
		$this->send_script("chat.onServerInfo('local_user_me','" . $this->escape($aParams[3]) . "')");
	}

	private function rpl_localusercount($aParams)
	{
		$this->send_script("chat.onServerInfo('local_user_count','" . $this->escape($aParams[3]) . "')");
	}

	private function rpl_globalusercount($aParams)
	{
		$this->send_script("chat.onServerInfo('global_user_count','" . $this->escape($aParams[3]) . "')");
	}

	private function rpl_globalconnections($aParams)
	{
		$this->send_script("chat.onServerInfo('global_connections','" . $this->escape($aParams[3]) . "')");
	}

	private function rpl_luserchannels($aParams)
	{
		$this->send_script("chat.onServerInfo('channels_formed','" . $this->escape(implode(" ", array_slice($aParams, 3))) . "')");
	}

	private function rpl_motdstart($aParams)
	{
		$line = $this->escape($aParams[3]);
		$this->send_script("chat.onMotd('" . $line . "')");
	}

	private function rpl_motd($aParams)
	{
		$line = $this->escape($aParams[3]);
		$this->send_script("chat.onMotd('" . $line . "')");
	}

	private function rpl_endofmotd($aParams)
	{
		$line = $this->escape($aParams[3]);
		$this->send_script("chat.onMotd('$line')");
	}

	private function rpl_namreply($aParams)
	{
		if (isset($this->channels[$aParams[4]]))
		{
			$bParsingStatus = true;

			$aNames = explode(" ",  $aParams[5]);

			foreach ($aNames as $sName)
			{
				$iPrefixEnd = 0;

				while (isset($this->aReversePrefixModes[$sName[$iPrefixEnd]]))
					$iPrefixEnd++;

				$sPrefix = substr($sName, 0, $iPrefixEnd);
				$sNick = substr($sName, $iPrefixEnd);

				AirD::Log(AirD::LOGTYPE_IRC, "NAMES: Got user " . $sPrefix . " : " . $sNick);
				$this->channels[$aParams[4]]->AddMember($sNick, $sPrefix);
			}
		}
	}

	private function rpl_endofnames($aParams)
	{
		$this->send_script("chat.renderMembers('{$this->escape($aParams[3])}')");
	}

	private function rpl_channelmodeis($aParams)
	{
		$this->send_script("chat.onChannelMode('{$this->escape($aParams[4])}','" . $this->escape(implode(" ", array_slice($aParams, 5))). "')");
	}

	private function rpl_whoreply($aParams)
	{
		foreach ($this->channels as $channel)
		{
			// ident host server nick full name
			$channel->who($aParams[4], $aParams[5], $aParams[2], $aParams[2], $aParams[9]);
		}

		$this->send_script("chat.onWho('" . $this->escape($aParams[2]). "','" . $this->escape($aParams[4]). "','" . $this->escape($aParams[5]). "','" . $this->escape($aParams[6]). "','" . $this->escape($aParams[9]) . "')");
	}

	private function rpl_endofwho($aParams)
	{
		$this->send_script("chat.onEndOfWho()");
	}

	private function rpl_channelcreatetime($aParams)
	{
		if (isset($this->channels[$aParams[0]]))
			$this->channels[$aParams[3]]->channel_created($aParams[4]);
	}

	private function rpl_topic($aParams)
	{
		$this->on_topic($aParams[3], $aParams[4]);
	}

	private function rpl_topicsetby($aParams)
	{
		if (isset($this->channels[$aParams[3]]))
			$this->channels[$aParams[3]]->topic_set_by($aParams[4], $aParams[5]);
	}

	private function rpl_notopic($aParams)
	{
		$this->send_script("chat.onError('" . $this->escape($aParams[3]) . ": " . $this->escape($aParams[4]) . "')");
	}

	private function rpl_whowasuser($aParams)
	{
		$this->send_script("chat.onWhowas('" . $aParams[3] . "')");
	}

	private function rpl_whoisserver($aParams)
	{
		$this->send_script("chat.onWhois('" . $this->escape($aParams[4]) . "')");
	}

	private function rpl_endofwhowas($aParams)
	{
		$this->send_script("chat.onWhowas('End of /WHOWAS')");
	}

	private function rpl_liststart($aParams)
	{
		$this->send_script("chat.showList(); chat.listWindow.start()");
	}

	private function rpl_list($aParams)
	{
		// Topic will be in params[5].
		if ($aParams[3] != "*")
			$this->send_script("chat.listWindow.add('" . $this->escape($aParams[3]) . "', '" . $this->escape($aParams[4]). "')");
	}

	private function rpl_listend($aParams)
	{
		$this->send_script("chat.listWindow.done()");
	}

	private function err_cannotsendtochan($aParams)
	{
		$this->send_script("chat.onError('" . $this->escape($aParams[3]) . ": " . $this->aParams[4] . "')");
	}

	private function err_nicknameinuse($aParams)
	{
		$this->nick .= "_";
		$this->send_script("chat.onError('" . $this->escape($aParams[3]) . ", trying " .  $this->escape($this->nick) . "')");
		$this->nick($this->nick);
	}

	private function err_generic($aParams)
	{
		$param = $this->escape(implode(" ", array_slice($aParams, 3)));
		$this->send_script("chat.onError('$param')");
	}

	/*** Internal communication functions ***/

	private function handle_server_message($aParams)
	{
		if (is_numeric($aParams[1]) && isset(IRCNumerics::$lookup[$aParams[1]]))
		{
			$function = strtolower(IRCNumerics::$lookup[$aParams[1]]);

			if (is_callable(array($this, $function)))
			{
				$this->$function($aParams);
			}
			elseif (substr($function, 0, 4) == 'err_')
			{
				$this->err_generic($aParams);
			}
			else
			{
				AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got unimplemented message " . $aParams[1]);
				$this->err_generic($aParams);
			}
		} elseif ($aParams[1] == 'NOTICE') {
			$this->on_server_notice($aParams[3]);
		} elseif ($aParams[1] == 'MODE') {
			$this->on_mode($aParams[0], $aParams[1], $aParams[2], array_slice($aParams, 3));
		} else {
			AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got unimplemented message " . $aParams[1]);
			$this->err_generic($aParams);
		}
	}

	private function handle_message($aParams)
	{
		switch ($aParams[1]) {
			case 'PRIVMSG':
				if ($aParams[2] == $this->nick)
				{
					if ($aParams[3][0] == chr(001) && $aParams[3][count($aParams[3]) - 1] == chr(001))
					{
						$this->on_privctcp($aParams[0], str_replace(chr(001), '', $aParams[3]));
					}
					else
					{
						$this->on_privmsg($aParams[0], $aParams[3]);
					}
				}
				else
				{
					if ($aParams[3][0] == chr(001) && $aParams[3][count($aParams[3]) - 1] == chr(001))
					{
						$this->on_ctcp($aParams[0], $aParams[2], $aParams[3]);
					}
					else
					{
						$this->on_msg($aParams[0], $aParams[2], $aParams[3]);
					}
				}
				break;
			case 'NOTICE':
				$this->on_notice($aParams[0], $aParams[3]);
				break;
			case 'KICK':
				$this->on_kick($aParams[2], $aParams[0], $aParams[3], isset($aParams[4]) ? $aParams[4] : "");
				break;
			case 'QUIT':
				$this->on_quit($aParams[0]);
				break;
			case 'PART':
				$this->on_part($aParams[0], $aParams[2], isset($aParams[3]) ? $aParams[3] : "");
				break;
			case 'JOIN':
				if ($aParams[0] == $this->nick) {
					$this->on_joined($aParams[2]);
					$this->get_mode($aParams[2]);
				} else {
					$this->on_join($aParams[0], $aParams[2]);
				}
				break;
			case 'TOPIC':
				$this->on_topic($aParams[2], $aParams[3]);
				break;
			case 'MODE':
				$this->on_mode($aParams[0], $aParams[1], $aParams[2], array_slice($aParams, 3));
				break;
			case 'NICK':
				$this->on_nick($aParams[0], $aParams[2]);
				break;
			default:
				if (is_numeric($command) && isset(IRCNumerics::$lookup[$command])) {
					$function = strtolower(IRCNumerics::$lookup[$command]);
					if (is_callable(array($this, $function))) {
						$this->$function($from, $command, $to, $param);
					} elseif (substr($function, 0, 4) == 'err_') {
						$this->err_generic($aParams);
					} else {
						AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got unimplemented client message " . $command);
						$this->err_generic($aParams);
					}
				} else {
					AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got unimplemented client message " . $command);
						$this->err_generic($aParams);
				}
		}
	}

	private function on_readln($string)
	{
		if (substr($string, 0, 1) == ':')
		{
			$aParse = Utils::ParseLine($string);
			if (!$this->server)
			{
				// XXX: this could be handled better by setting in RPL_WELCOME
				$this->server = $aParse[0];
			}

			if ($aParse[0] == $this->server)
			{
//				AirD::Log(AirD::LOGTYPE_IRC, "handle_server_message: " . implode(" ", $aParse), true);
				$this->handle_server_message($aParse);
			}
			else
			{
				// XXX: Strip user@host, this is a bit meh, we may want to not do this in the future.
				$aFrom = explode("!", $aParse[0]);
				$aParse[0] = $aFrom[0];
//				AirD::Log(AirD::LOGTYPE_IRC, "handle_message: " . implode(" ", $aParse), true);
				$this->handle_message($aParse);
			}
		} elseif (substr($string,0,6) == 'NOTICE') {
			$notice = substr($string, strpos($string, ' ') + 1);
			$this->on_server_notice($notice);
		} elseif (substr($string,0,4) == 'PING')
		{
			$origin = substr($string, 6);
			$this->write("PONG $origin\r\n");
		} elseif (substr($string, 0, 5) == 'ERROR') {
			$this->on_error(substr($string, 6));
		} else {
			AirD::Log(AirD::LOGTYPE_IRC, "Weird unknown string: " . $string);
		}
	}

	public function send_script($msg)
	{
		AirD::Log(AirD::LOGTYPE_JAVASCRIPT, "Sending script nr " . $this->script_sends++ . " to " . $this->key . ": " . $msg);
		if ($this->oHTTPClient)
		{
			// If we've sent a lot to this HTTP client
			if ($this->script_sends % 1000 == 0)
			{
				// Tell the client to go out of streaming mode: this means the HTTP timer will reap the socket eventually,
				// forcing the client to reconnect.
				$this->oHTTPClient->setStreaming(false);
			}

			$this->oHTTPClient->write($msg. ";\n");
		}
		else
		{
			$this->aPendingMessages[] = $msg . ";\n";
		}
	}

	public function on_connect()
	{
		$this->send_script("chat.onSetGUIVersion('" . AirD::VERSION_STRING . "');chat.onConnecting()");
		list($iOctet1, $iOctet2, $iOctet3, $iOctet4) = explode(".", $this->client_address, 4);
		$iDecimal = ((((($iOctet1 * 256 + $iOctet2) * 256) + $iOctet3) * 256) + $iOctet4);
		$sHex = dechex($iDecimal);
		$this->write("USER " . $sHex . " * * :http://my.browserchat.net\r\n");
		$this->write("NICK {$this->nick}\r\n");
	}

	public function on_read()
	{
		while (($pos = strpos($this->read_buffer,"\r\n")) !== FALSE) {
			$string            = trim(substr($this->read_buffer, 0, $pos + 2));
			$this->read_buffer = substr($this->read_buffer, $pos + 2);
			$this->on_readln($string);
		}
	}

	public function shouldDestroy()
	{
		if (!$this->oHTTPClient && time() - $this->iHTTPDisconnected > 10)
		{
			AirD::Log(AirD::LOGTYPE_IRC, "Disconnected IRC session or abandoned IRC session (TS: " . $this->iHTTPDisconnected . ")");
			$this->Destroy();
			return true;
		}

		return parent::shouldDestroy();
	}
}
