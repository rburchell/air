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
	private $topic = '';
	private $channel;
	private $names;
	private $parent;
	private $created;
	private $topic_set_by;
	private $topic_created;
	private $aModes;

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

	// Used seperately from JOIN because it involves prefixes.
	// NOTE: sPrefixes is assumed to be a slash delimited string.
	public function AddMember($sUser, $sPrefixes)
	{
		for ($i = 0; $i < strlen($sPrefixes); $i++)
			$sPStr = "\\" . $sPrefixes[$i];
		$this->names[$sUser] = array("nickname" => $sUser, "prefixes" => $sPrefixes);
		$this->parent->send_script("chat.addMember('" . $this->parent->escape($this->channel) . "', '" . $this->parent->escape($sUser) . "', '" . $sPStr . "')");
	}

	public function who($ident, $host, $server, $nick, $full_name)
	{
		if (isset($this->names[$nick]))
		{
			$this->names[$nick]['ident'] = $ident;
			$this->names[$nick]['server'] = $server;
			$this->names[$nick]['full_name'] = $full_name;
			$this->names[$nick]['host'] = $host;
		}
	}

	public function channel_created($timestamp)
	{
		$this->created = $timestamp;
	}

	public function topic_set_by($who, $when)
	{
		$this->topic_created = $when;
		$this->topic_set_by  = $who;
	}

	/** Sets a non-listmode on this channel. Mode may either be boolean (on/off) or parameter (+k style).
	  * @param cModeChar The mode character to set, e.g. 'i', 'k', etc.
	  * @param vValue The value to associate with the mode, e.g. true, 'somekeyhere', etc.
	  */
	public function SetMode($cModeChar, $vValue = true)
	{
		AirD::Log(AirD::LOGTYPE_IRC, "SetMode: " . $cModeChar . ": " . $vValue, true);
		$this->aModes[$cModeChar] = $vValue;
	}

	/** Unsets a non-listmoode (boolean or parameter types) on a channel.
	  * @param cModeChar The mode character to unset.
	z  */
	public function UnsetMode($cModeChar)
	{
		AirD::Log(AirD::LOGTYPE_IRC, "UnsetMode: " . $cModeChar, true);
		unset($this->aModes[$cModeChar]);
	}

	/** Adds a listmode mask value to the given listmode type list for this channel.
	  * @param cModeChar The mode character to set (e.g. 'b')
	  * @param vValue The mask to set (e.g. 'w00t!sucks@donkey.nads)
	  */
	public function SetListMode($cModeChar, $sValue)
	{
		AirD::Log(AirD::LOGTYPE_IRC, "SetListMode: " . $cModeChar . ": " . $sValue, true);
		$this->aModes[$cModeChar][$sValue] = $sValue;
	}

	/** Removes a given listmode mask from the channel.
	  * @param cModeChar The mode character to unset ('b')
	  * @param sValue The mask to remove (e.g. 'w00t!is@the.greatest).
	  */
	public function UnsetListMode($cModeChar, $sValue)
	{
		AirD::Log(AirD::LOGTYPE_IRC, "UnsetListMode: " . $cModeChar . ": " . $sValue, true);
		unset($this->aModes[$cModeChar][$sValue]);
	}

	public function SetPrefixMode($sUser, $cPrefix)
	{
		$this->parent->send_script("chat.setPrefix('" . $this->parent->escape($this->channel) . "', '" . $this->parent->escape($sUser) . "', '\\" . $cPrefix . "')");

	}

	public function UnsetPrefixMode($sUser, $cPrefix)
	{
		$this->parent->send_script("chat.unSetPrefix('" . $this->parent->escape($this->channel) . "', '" . $this->parent->escape($sUser) . "', '\\" . $cPrefix . "')");

	}
}
