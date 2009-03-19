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

class ircChannel {
	private $mode;
	private $key;
	private $bans;
	private $topic = '';
	private $channel;
	private $names;
	private $parent;
	private $created;
	private $topic_set_by;
	private $topic_created;

	public function __construct($parent, $channel)
	{
		$this->parent  = $parent;
		$this->channel = $channel;
		$parent->send_script("chat.onJoined('" . $this->parent->escape($this->channel) . "');");
	}

	public function __destruct()
	{
		$this->parent->send_script("chat.onParted('" . $this->parent->escape($this->channel) . "');");
	}

	public function on_topic($topic)
	{
		$this->topic = $topic;
		$this->parent->send_script("chat.onTopic('{$this->parent->escape($this->channel)}', '" . $this->parent->escape($topic) . "');");
	}

	public function on_join($who)
	{
		if (!isset($this->names[$who])) {
			$this->names[$who] = array('nickname' => $who);
			$who               = $this->parent->escape($who);
			$this->parent->send_script("chat.onJoin('{$this->parent->escape($this->channel)}', '$who');");
		}
	}

	public function on_part($who, $message)
	{
		if (isset($this->names[$who])) {
			unset($this->names[$who]);
			$who     = $this->parent->escape($who);
			$message = $this->parent->escape($message);
			$this->parent->send_script("chat.onPart('{$this->parent->escape($this->channel)}', '$who', '$message');");
		}
	}

	public function on_kick($from, $who, $reason)
	{
		unset($this->names[$who]);
		$who    = $this->parent->escape($who);
		$from   = $this->parent->escape($from);
		$reason = $this->parent->escape($reason);
		if ($this->parent->nick == $who) {
			$this->parent->send_script("chat.onKicked('{$this->parent->escape($this->channel)}','$from', '$who', '$reason');");
		} else {
			$this->parent->send_script("chat.onKick('{$this->parent->escape($this->channel)}','$from', '$who', '$reason');");
		}
	}

	public function on_mode($mode)
	{
		$this->mode = $mode;
		$mode = $this->parent->escape($mode);
		$this->parent->send_script("chat.onChannelMode('{$this->parent->escape($this->channel)}','$mode');");
	}

	public function on_nick($from, $to)
	{
		if (isset($this->names[$from])) {
			$member = $this->names[$from];
			$member['nickname'] = $to;
			unset($this->names[$from]);
			$this->names[$to] = $member;
			$from = $this->parent->escape($from);
			$to   = $this->parent->escape($to);
			$this->parent->send_script("chat.onNick('{$this->parent->escape($this->channel)}', '$from', '$to');");
		}
	}

	public function on_quit($who)
	{
		if (isset($this->names[$who])) {
			unset($this->names[$who]);
			$who = $this->parent->escape($who);
			$this->parent->send_script("chat.onPart('{$this->parent->escape($this->channel)}', '$who', 'Quit');");
		}
	}

	public function add_names($names)
	{
		foreach ($names as $name) {
			$operator = $voice = 'false';
			if (substr($name, 0, 1) == '@') {
				$name = substr($name, 1);
				$operator = 'true';
			} elseif (substr($name, 0, 1) == '+') {
				$name  = substr($name, 1);
				$voice = 'true';
			}
			$this->names[$name] = array('nickname' => $name, 'operator' => $operator, 'voice' => $voice);
			$name = $this->parent->escape($name);
			$this->parent->send_script("chat.addMember('{$this->parent->escape($this->channel)}' ,'$name', $operator, $voice);");
		}
	}

	public function who($ident, $host, $server, $nick, $full_name)
	{
		if (isset($this->names[$nick]) && !isset($this->names[$nick]['ident'])) {
			$operator = isset($this->names[$nick]['operator']) ? $this->names[$nick]['operator'] : 'false';
			$voice    = isset($this->names[$nick]['voice'])    ? $this->names[$nick]['voice']    : 'false';
			$this->names[$nick] = array('nickname' => $nick, 'ident' => $ident, 'server' => $server, 'full_name' => $full_name, 'operator' => $operator, 'voice' => $voice);
		}
	}

	public function end_of_names()
	{
		$this->parent->send_script("chat.renderMembers('{$this->parent->escape($this->channel)}');");
	}

	public function end_of_who() {}


	public function channel_created($timestamp)
	{
		$this->created = $timestamp;
	}

	public function topic_set_by($who, $when)
	{
		$this->topic_created = $when;
		$this->topic_set_by  = $who;
	}

	public function op($nick, $from)
	{
		if (isset($this->names[$nick])) {
			$this->names[$nick]['operator'] = true;
			$nick = $this->parent->escape($nick);
			$from = $this->parent->escape($from);
			$this->parent->send_script("chat.opMember('{$this->parent->escape($this->channel)}', '$nick', '$from');");
		}
	}

	public function deop($nick, $from)
	{
		if (isset($this->names[$nick])) {
			$this->names[$nick]['operator'] = false;
			$nick = $this->parent->escape($nick);
			$from = $this->parent->escape($from);
			$this->parent->send_script("chat.deopMember('{$this->parent->escape($this->channel)}', '$nick', '$from');");
		}
	}

	public function voice($nick, $from)
	{
		if (isset($this->names[$nick])) {
			$this->names[$nick]['voice'] = true;
			$nick = $this->parent->escape($nick);
			$from = $this->parent->escape($from);
			$this->parent->send_script("chat.voiceMember('{$this->parent->escape($this->channel)}', '$nick', '$from');");
		}
	}

	public function devoice($nick, $from)
	{
		if (isset($this->names[$nick])) {
			$this->names[$nick]['voice'] = false;
			$nick = $this->parent->escape($nick);
			$from = $this->parent->escape($from);
			$this->parent->send_script("chat.devoiceMember('{$this->parent->escape($this->channel)}', '$nick', '$from');");
		}
	}

	public function set_key($key = false, $from)
	{
		$this->key = $key;
		$key  = $this->parent->escape($key);
		$from = $this->parent->escape($from);
		$this->parent->send_script("chat.setKey('{$this->parent->escape($this->channel)}', '$key', '$from');");
	}

	public function add_ban($hostmask, $from)
	{
		$this->bans[$hostmask] = $hostmask;
		$from = $this->parent->escape($from);
		$hostmask = $this->parent->escape($hostmask);
		$this->parent->send_script("chat.addBan('{$this->parent->escape($this->channel)}', '$hostmask', '$from');");
	}

	public function remove_ban($hostmask, $from)
	{
		if (isset($this->bans[$hostmask])) {
			unset($this->bans[$hostmask]);
			$from = $this->parent->escape($from);
			$hostmask = $this->parent->escape($hostmask);
			$this->parent->send_script("chat.removeBan('{$this->parent->escape($this->channel)}', '$hostmask', '$from');");
		}
	}
}
