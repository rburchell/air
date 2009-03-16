<?
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

class ircClient extends socketClient {
	private $channels;
	private $server_info = array();
	public  $nick;
	public  $key;
	public  $names = array();
	public  $server;
	public  $client_address;
	public  $http_client;
	public  $output = '';

	/*** handle user commands ***/


	/* TODO commands:
	/ignore (nick)
	/query nick (msg) < open new win!
	/whois nick
	/ping (finish with ping times!)
	/help -> give overview of commands!
	*/

	private function escape($s)
	{
		$s = str_replace("\\", "\\\\", $s);
		$s = htmlentities($s, ENT_QUOTES, 'UTF-8');
//		while (strpos($s, "  ") !== false)
//			$s = str_replace("  ", "&nbsp;&nbsp;", $s);
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
		global $daemon;
		$reason = empty($reason) ? '' : " :$reason";
		$this->write("QUIT$reason\r\n");
		$this->close();
		foreach ($daemon->clients as $http_client) {
			if (get_class($http_client) == 'httpdServerClient' && $http_client->streaming_client && $this->key == $http_client->key) {
				$http_client->close();
				break;
			}
		}
	}

	public function action($destination, $msg)
	{
		$this->write("PRIVMSG $destination :".chr(1).$msg.chr(1)."\r\n");
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
				$this->handle_write();
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
				$this->send_script("chat.onPing('$from');");
				break;
			case 'time':
				$this->write("NOTICE $from :".chr(1).gmdate("D, d M Y H:i:s", time() + 900)." GMT".chr(1)."\r\n");
				$from = $this->escape($from);
				$this->send_script("chat.onTime('$from');");
				break;
			case 'version':
				$this->write("NOTICE $from :".chr(1)."Chat 0.1 prototype by Chris Chabot <chabotc@xs4all.nl>".chr(1)."\r\n");
				$from = $this->escape($from);
				$this->send_script("chat.onVersion('$from');");
				break;
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
				$this->send_script("chat.onAction('$channel', '$from', '$arg');");
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
		$this->send_script("chat.onPrivateMessage('$from','$msg');");

	}

	public function on_msg($from, $channel, $msg)
	{
		$from = $this->escape($from);
		$channel = $this->escape($channel);
		$msg  = $this->escape($msg);

		$this->send_script("chat.onMessage('$from', '$channel', '$msg');");
	}

	public function on_notice($from, $msg)
	{
		$from = $this->escape($from);
		$msg  = $this->escape($msg);
		$this->send_script("chat.onNotice('$from','$msg');");
	}

	public function on_mode($from, $command, $to, $param)
	{
		if (isset($this->channels[$to])) {
			$channel = $this->channels[$to];
			$param   = trim(substr($param, strpos($param, 'MODE') + strlen('MODE') + strlen($to) + 2));
			$param   = explode(' ', $param);
			$mode    = array_shift($param);
			$modelen = strlen($mode);
			$add = $remove = false;
			for ($i = 0; $i < $modelen; $i++) {
				switch($mode[$i]) {
					case '-':
						$remove = true;
						$add    = false;
						break;
					case '+':
						$add    = true;
						$remove = false;
						break;
					case 'o':
						$nick = array_shift($param);
						if ($add) {
							$channel->op($nick, $from);
						} elseif ($remove) {
							$channel->deop($nick, $from);
						}
						break;
					case 'v':
						$nick = array_shift($param);
						if ($add) {
							$channel->voice($nick, $from);
						} elseif ($remove) {
							$channel->devoice($nick, $from);
						}
						break;
					case 'k':
						$key = array_shift($param);
						if ($add) {
							$channel->set_key($key, $from);
						} elseif ($remove) {
							$channel->set_key(false, $from);
						}
						break;
					default:
						if ($mode[$i] == 'b') {
							$hostmask = array_shift($param);
							if ($add) {
								$channel->add_ban($hostmask, $from);
							} elseif ($remove) {
								$channel->remove_ban($hostmask, $from);
							}
						} else {
							/*
							if ($add) {
								$channel->mode .= $mode[$i];
							} elseif ($remove) {
								$channel->mode = str_replace($mode[$i], '', $channel->mode);
							}
							*/
						}
				}
			}
		}
	}

	public function on_server_notice($notice)
	{
		$notice = $this->escape($notice);
		$this->send_script("chat.onServerNotice('$notice');");
	}

	public function on_error($error)
	{
		AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got error: " . $error);
		$error = $this->escape($error);
		$this->send_script("chat.onError('$error');");
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
			$this->send_script("chat.removeChannel('{$channel}');");
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

	private function rpl_welcome($from, $command, $to, $param)
	{
		// reset our nickname, it might be truncated or changed by server
		// and update server name, it might represent its self other then the dns name we used
		$this->server = $from;
		$this->on_nick($this->nick, $to);
		$this->nick = $to;
		$this->server_info['motd'] = '';
	}

	private function rpl_yourhost($from, $command, $to, $param)
	{
		$this->server_info['your_host'] = $param;
	}

	private function rpl_created($from, $command, $to, $param)
	{
		$this->server_info['created'] = $param;
	}

	private function rpl_myinfo($from, $command, $to, $param)
	{
		$this->server_info['my_info'] = $param;
	}

	private function rpl_bounce($from, $command, $to, $param)
	{
		$this->server_info['bounce'] = $param;
	}

	private function rpl_uniqid($from, $command, $to, $param)
	{
		$this->server_info['uniq_id'] = $param;
	}

	private function rpl_luserclient($from, $command, $to, $param)
	{
		$this->server_info['local_user_client'] = $param;
	}

	private function rpl_luserme($from, $command, $to, $param)
	{
		$this->server_info['local_user_me'] = $param;
	}

	private function rpl_localusercount($from, $command, $to, $param)
	{
		$this->server_info['local_user_count'] = $param;
	}

	private function rpl_globalusercount($from, $command, $to, $param)
	{
		$this->server_info['global_user_count'] = $param;
	}

	private function rpl_globalconnections($from, $command, $to, $param)
	{
		$this->server_info['global_connections'] = $param;
	}

	private function rpl_luserchannels($from, $command, $to, $param)
	{
		$this->server_info['channels_formed'] = $param;
	}

	private function rpl_motdstart($from, $command, $to, $param)
	{
		$this->server_info['motd'] .= $param."\n";
	}

	private function rpl_motd($from, $command, $to, $param)
	{
		$this->server_info['motd'] .= $param."\n";
	}

	private function rpl_endofmotd($from, $command, $to, $param)
	{
		$this->server_info['motd'] .= $param."\n";
		foreach ($this->server_info as $key => $val) {
			if ($key == 'motd') {
				$lines = explode("\n", $this->server_info['motd']);
				foreach ($lines as $line) {
					$line = $this->escape($line);
					$this->send_script("chat.onMotd('$line');");
				}
			} else {
				$key = $this->escape($key);
				$val = $this->escape($val);
				$this->send_script("chat.onServerInfo('$key','$val');");
			}
		}
	}

	private function rpl_namreply($from, $command, $to, $param)
	{
		$channel = trim(substr($param, 2, strpos($param, ':') - 2));
		$names   = explode(' ',substr($param, strpos($param, ':') + 1));
		if (isset($this->channels[$channel])) $this->channels[$channel]->add_names($names);
	}

	private function rpl_endofnames($from, $command, $to, $param)
	{
		$channel = trim(substr($param, 0, strpos($param, ':')));
		if (isset($this->channels[$channel])) $this->channels[$channel]->end_of_names();
	}

	private function rpl_channelmodeis($from, $command, $to, $param)
	{
		$channel = trim(substr($param, 0, strpos($param,' ')));
		$mode    = trim(substr($param, strpos($param, ' ')));
		if (isset($this->channels[$channel])) $this->channels[$channel]->on_mode($mode);
	}

	private function rpl_whoreply($from, $command, $to, $param)
	{
		$full_name = substr($param, strpos($param, ':') + 1);
		$params    = explode(' ', $param);
		foreach ($this->channels as $channel) {
			$channel->who($params[1], $params[2], $params[3], $params[4], $full_name);
		}
		$ident     = $this->escape($params[1]);
		$host      = $this->escape($params[2]);
		$server    = $this->escape($params[3]);
		$nick      = $this->escape($params[4]);
		$full_name = $this->escape($full_name);
		$this->send_script("chat.onWho('$nick','$ident','$host','$server','$full_name');");
	}

	private function rpl_endofwho($from, $command, $to, $param)
	{
		$channel = substr($param, 0, strpos($param, ' '));
		$this->send_script("chat.onEndOfWho();");
	}

	private function rpl_channelcreatetime($from, $command, $to, $param)
	{
		$params = explode(' ', $param);
		if (isset($this->channels[$params[0]])) $this->channels[$params[0]]->channel_created($params[1]);
	}

	private function rpl_topic($from, $command, $to, $param)
	{
		$topic   = trim(substr($param, strpos($param, ':') + 1));
		$channel = trim(substr($param, 0, strpos($param, ':')));
		$this->on_topic($channel, $topic);
	}

	private function rpl_topicsetby($from, $command, $to, $param)
	{
		$params = explode(' ', $param);
		if (isset($this->channels[$params[0]])) $this->channels[$params[0]]->topic_set_by($params[1], $params[2]);
	}

	private function rpl_notopic($from, $command, $to, $param)
	{
		$channel = $this->escape(substr($param, 0, strpos($param, ':')));
		$msg     = $this->escape(substr($param, strpos($param, ':') + 1));
		$this->send_script("chat.onError('$channel: $msg');");
	}

	private function rpl_whowasuser($from, $command, $to, $param)
	{
		$param = $this->escape($param);
		$this->send_script("chat.onWhowas('$param');");
	}

	private function rpl_whoisserver($from, $command, $to, $param)
	{
		$param = $this->escape($param);
		$this->send_script("chat.onWhois('$param');");
	}

	private function rpl_endofwhowas($from, $command, $to, $param)
	{
		$param = $this->escape($param);
		$this->send_script("chat.onWhowas('End of /WHOWAS');");
	}

	private function rpl_liststart($from, $command, $to, $param)
	{
		$this->send_script("chat.showList(); chat.listWindow.start();");
	}

	private function rpl_list($from, $command, $to, $param)
	{
		$param   = explode(' ',trim(substr($param, 0, strpos($param, ':'))));
		$channel = $this->escape($param[0]);
		$members = $this->escape($param[1]);
		// , '$topic'
		$this->send_script("chat.listWindow.add('$channel', '$members');");
	}

	private function rpl_listend($from, $command, $to, $param)
	{
		$this->send_script("chat.listWindow.done();");
	}

	private function rpl_tryagain($from, $command, $to, $param)
	{
		$param = $this->escape($param);
		$this->send_script("chat.onError('$param');");
	}

	private function err_cannotsendtochan($from, $command, $to, $param)
	{
		$channel = $this->escape(substr($param, 0, strpos($param, ':')));
		$msg     = $this->escape(substr($param, strpos($param, ':') + 1));
		$this->send_script("chat.onError('$channel: $msg');");
	}

	private function err_nicknameinuse($from, $command, $to, $param)
	{
		$this->nick .= "_";
		$param = $this->escape($param);
		$this->send_script("chat.onError('$param, trying $this->nick');");
		$this->nick($this->nick);
	}

	private function err_generic($from, $command, $to, $param)
	{
		$param = $this->escape($param);
		$this->send_script("chat.onError('$param');");
	}

	/*** Internal communication functions ***/

	private function handle_server_message($from, $command, $to, $param)
	{
		AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " processing server message from " . $from . " => " . $to . ": " . $command . " (" . $param. ")", true);
		if (substr($param, 0, 1) == ':') {
			$param = substr($param, 1);
		}
		if (is_numeric($command) && isset(IRCNumerics::$lookup[$command])) {
			$function = strtolower(IRCNumerics::$lookup[$command]);
			if (is_callable(array($this, $function))) {
				$this->$function($from, $command, $to, $param);
			} elseif (substr($function, 0, 4) == 'err_') {
				$this->err_generic($from, $command, $to, $param);
			} else {
				AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got unimplemented message " . $command);
			}
		} elseif ($command == 'NOTICE') {
			$this->on_server_notice($param);
		} elseif ($command == 'MODE') {
			$this->on_mode($from, $command, $to, $param);
		} else {
			AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got unimplemented message " . $command);
		}
	}

	private function handle_message($from, $command, $to, $param, $who)
	{
		if (substr($to,0,1) == ':') {
			$to = substr($to,1);
		}
		if (strpos($from, '!') !== false) {
			$from = substr($from, 0, strpos($from, '!'));
		}
		AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " processing message from " . $from . " => " . $to . ": " . $command . " (" . $param. ") - " . $who, true);
		// IRC: Client 56e2407a1d19ca64e988283c505ae74e processing message from test => test2: NICK (test!robin@testnet.user NICK test2) - 
		switch ($command) {
			case 'PRIVMSG':
				if ($to == $this->nick) {
					if (substr($param, 0, 1) == chr(001) && substr($param, strlen($param) - 1, 1) == chr(001)) {
						$this->on_privctcp($from, str_replace(chr(001), '', $param));
					} else {
						$this->on_privmsg($from, $param);
					}
				} else {
					if (substr($param, 0, 1) == chr(001) && substr($param, strlen($param) - 1, 1) == chr(001)) {
						$this->on_ctcp($from, $to, $param);
					} else {
						$this->on_msg($from, $to, $param);
					}
				}
				break;
			case 'NOTICE':
				$this->on_notice($from, $param);
				break;
			case 'KICK':
				$this->on_kick($to, $from, $who, $param);
				break;
			case 'QUIT':
				$this->on_quit($from);
				break;
			case 'PART':
				$this->on_part($from, $to, $param);
				break;
			case 'JOIN':
				if ($from == $this->nick) {
					$this->on_joined($param);
					$this->get_mode($to);
				} else {
					$this->on_join($from, $to);
				}
				break;
			case 'TOPIC':
				$this->on_topic($to, $param);
				break;
			case 'MODE':
				$this->on_mode($from, $command, $to, $param);
				break;
			case 'NICK':
				$this->on_nick($from, $to);
				break;
			default:
				if (is_numeric($command) && isset(IRCNumerics::$lookup[$command])) {
					$function = strtolower(IRCNumerics::$lookup[$command]);
					if (is_callable(array($this, $function))) {
						$this->$function($from, $command, $to, $param);
					} elseif (substr($function, 0, 4) == 'err_') {
						$this->err_generic($from, $command, $to, $param);
					} else {
						AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got unimplemented client message " . $command);
					}
				} else {
					AirD::Log(AirD::LOGTYPE_IRC, "Client " . $this->key . " got unimplemented client message " . $command);
				}
		}
	}

	private function on_readln($string)
	{
		global $daemon;
		if (substr($string, 0, 1) == ':') {
			$string = substr($string, 1);
			$match  = explode(' ', $string);
			$from   = $match[0];
			if (!$this->server) {
				$this->server = $from;
			}
			if ($from == $this->server) {
				$pos = strlen($match[0]) + strlen($match[1]) + strlen($match[2]) + 3;
				$this->handle_server_message($from, $match[1], $match[2], trim(substr($string, $pos)));
			} else {
				if (strpos($string, ':') !== false) {
					$string   = substr($string, strpos($string, ':') + 1);
				}
				$who = isset($match[3]) ? $match[3] : '';
				$this->handle_message($from, $match[1], $match[2], $string, $who);
			}
		} elseif (substr($string,0,6) == 'NOTICE') {
			$notice = substr($string, strpos($string, ' ') + 1);
			$this->on_server_notice($notice);
		} elseif (substr($string,0,4) == 'PING') {
			$origin = substr($string, 6);
			$this->write("PONG $origin\r\n");
		} elseif (substr($string, 0, 5) == 'ERROR') {
			$this->on_error(substr($string, 6));
		} else {
			AirD::Log(AirD::LOGTYPE_IRC, "Weird unknown string: " . $string);
		}
		$this->handle_write();
	}

	private function handle_write()
	{
		global $daemon;
		foreach ($daemon->clients as $http_client) {
			if (get_class($http_client) == 'httpdServerClient' && $http_client->streaming_client && $this->key == $http_client->key) {
				$http_client->write_buffer .= $this->output;
				if ($http_client->do_write()) {
					$this->output = '';
				}
			}
		}
	}

	public function send_script($msg)
	{
		AirD::Log(AirD::LOGTYPE_JAVASCRIPT, "Sending to " . $this->key . ": " . $msg);
		$this->output .= "<script type=\"text/javascript\">\n$msg\n</script>\n";
		$this->handle_write();
	}

	public function on_connect()
	{
		$this->send_script("chat.onSetGUIVersion('" . AirD::VERSION_STRING . "');chat.onConnecting();");
		$this->write("USER foobar 0 chabotc.nl :IP {$this->client_address}\r\n");
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
}
