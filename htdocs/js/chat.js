// WebChat2.0 Copyright (C) 2006-2007, Chris Chabot <chabotc@xs4all.nl>
// Licenced under the GPLv2. For more info see http://www.chabotc.com

/****************************** main chat application  ***********************************/
var chat = {
	nickname     : '',
	server       : '',
	key          : false,
	connection   : false,
	connected    : false,
	timer        : false,
	editor       : false,
	channels     : [],
	current      : false,
	listWindow   : false,
	reconnectdelay : 0,

	// XHR Variables for maintaining a persistant connection to the server.
	xhReq        : false,
	xhPollTimer  : false,
	xhrNextParsePos     : 0,

	initialize: function() {
		$('new_channel').observe("mousedown", chat.showList);
		chat.editor = new chatEditor;
		chat.addChannel('info');
		chat.channel('info').show();
		chat.onResize();
		chat.tryConnect();
//		chat.timer = setInterval('chat.frameCheck()', 10000);
	},

	getparam: function(name) {
		name = name.replace(/[\[]/,"\\\[").replace(/[\]]/,"\\\]");
		var regexS = "[\\?&]"+name+"=([^&#]*)";
		var regex = new RegExp( regexS );
		var results = regex.exec( window.location.href );
		if( results == null )
			return "";
		else
			return results[1];
	},

	debug: function(message)
	{
		// XXX: Make this togglable.
		chat.add("info", message);
	},

	tryConnect: function() {
		chat.add("info", "Connecting to server...");
		chat.nickname      =  this.getparam("nickname");
		chat.server        = "127.0.0.1";

		this.xhReq = this.createXMLHttpRequest();
		this.xhReq.onreadystatechange = function()
		{
			if (this.readyState != 4)
				return;

			// Reset XHR parse position, otherwise nothing will work when we reconnect.
			this.xhrNextParsePos = 0;
			
			// Add 500ms to the reconnect delay every time we are forced to reconnect, to not bombard the server with requests.
			chat.reconnectdelay += 500;

			// Don't stop an instant reconnect attempt, but if that fails, minimum wait is 5 seconds to stop server hammering.
			if (chat.reconnectdelay > 0 && chat.reconnectdelay < 5000)
				chat.reconnectdelay = 5000;
			else if (chat.reconnectdelay > 50000) // Also cap at 50 seconds.
				chat.reconnectdelay = 50000;

			chat.add("info", "Disconnected from server (HTTP status " + this.status + "). Reconnecting in " + (chat.reconnectdelay / 1000) + " seconds.");
			setTimeout('chat.frameDisconnected()', chat.reconnectdelay);
		}
		this.xhReq.open("GET", '/get?nickname=' + chat.nickname + '&server=' + chat.server, true);
		this.xhReq.send(null);
		this.xhPollTimer = setInterval('chat.pollForRead()', 500);
	},

	showList: function() {
		if (!chat.listWindow) {
			chat.listWindow = new chatListWindow('channel_list', {height : 440, width : 600, allowResize : false});
		}
		if (!chat.listWindow.visible()) {
			chat.listWindow.show();
		}
	},

	addChannel: function(channel) {
		chat.channels.push(new chatChannel(channel));
		chat.onResize();
		chat.editor.focus();
	},

	removeChannel: function(channel) {
		if (chat.channel(channel) != undefined) {
			chat.channel(channel).destroy();
		}
		chat.editor.focus();
	},

	channel: function(channel) {
	    for (i = 0; i < chat.channels.length; i++) {
			if (chat.channels[i].channel == channel) {
				return chat.channels[i];
			}
	    }
	    return undefined;
	},

	add: function(channel, message) {
		if (chat.channel(channel) != undefined) {
			chat.channel(channel).add(message);
		}
	},

	sortNames: function(channel) {
		if (chat.channel(channel) != undefined) {
			chat.channel(channel).sortNames();
		}
	},

	message: function(msg) {
		var sendXH = this.createXMLHttpRequest();
		sendXH.open("GET", '/message?key=' + chat.key + '&msg=' + encodeURIComponent(msg) + '&channel='+ encodeURIComponent(chat.current), true);
		sendXH.send(null);
	},

	onConnecting: function() {
		// Reset reconnect delay so we don't get huge delays after a long downtime
		chat.reconnectdelay = 0;
		connected = true;
		chat.add('info', '<span class="notice">Connected to server</span>');
	},

	onServerInfo: function(what, info) {
		chat.add('info', '<span class="notice">'+info+'</span>');
		if (chat.current != 'info') {
			chat.add(chat.current, '<span class="notice">'+info+'</span>');
		}
	},

	onMotd: function(motd) {
		chat.add('info', '<span class="notice">'+motd+'</span>');
	},

	onCTCP: function(from, ctcp)
	{
		chat.add('info', '<span class="notice">Recieved CTCP ' + ctcp + ' request from '+from+'</span>');
		if (chat.current != 'info')
		{
			chat.add(chat.current, '<span class="notice">Recieved CTCP ' + ctcp + ' request from '+from+'</span>');
		}
	},

	onMessage: function(from, channel, msg) {
		chat.add(channel, '<div class="from">'+from+':</div> <span class="message">'+msg+'</span>');
	},

	onNotice: function(from, msg) {
		chat.add('info', '<span class="notice">Notice from '+from+': '+msg+'</span>');
		if (chat.current != 'info') {
			chat.add(chat.current, '<span class="notice">Notice from '+from+': '+msg+'</span>');
		}
	},

	onWhois: function(msg) {
		chat.add('info','<span class="notice">'+msg+'</span></span>')
	},

	onWhowas: function(msg) {
		chat.add('info','<span class="notice">'+msg+'</span></span>')
	},

	onAction: function(channel, from, msg) {
		chat.add(channel, '<span class="notice">'+from+' <span class="message">'+msg+'</span></span>')
	},

	onPrivateMessage: function(from, msg) {
		chat.add('info', '<span class="privmsg">Message from '+from+': <span class="message">'+msg+'</span></span>')
		if (chat.current != 'info') {
			chat.add(chat.current, '<span class="privmsg">Message from '+from+': <span class="message">'+msg+'</span></span>')
		}
	},

	onServerNotice: function(notice) {
		chat.add('info', '<span class="notice">Server notice: '+notice+'</span>');
	},

	onKick: function(channel, from, who, reason) {
		if (chat.channel(channel) != undefined) {
			chat.channel(channel).members.remove(who);
			chat.channel(channel).members.render();
		}
		var reason = (reason != undefined && reason != '') ? ' ('+reason+')' : '';
		chat.add(channel, '<span class="kick">'+from+' kicked '+who+' from '+channel+reason+'</span>');

	},

	onKicked: function(channel, from, who, reason) {
		chat.removeChannel(channel);
		var reason = (reason != undefined && reason != '') ? ' ('+reason+')' : '';
		chat.add('info',  '<span class="kick">You were kicked from '+channel+' by '+from+reason+'</span>');
	},

	onError: function(error) {
		chat.add('info', '<span class="kick">Error: '+error+'</span>');
		if (chat.current != 'info') {
			chat.add(chat.current, '<span class="kick">Error: '+error+'</span>');
		}
	},

	onPart: function(channel, who, message) {
		if (chat.channel(channel) != undefined) {
			chat.add(channel, '<span class="part">'+who+' left</span>');
			chat.channel(channel).members.remove(who);
			chat.channel(channel).members.render();
		}
	},

	onParted: function(channel) {
		chat.removeChannel(channel);
		chat.add('info', '<span class="part">Left '+channel+'</span>');
	},

	onJoin: function(channel, who) {
		if (chat.channel(channel) != undefined) {
			chat.channel(channel).members.add(who, false, false);
			chat.channel(channel).members.render();
			chat.add(channel, '<span class="join">'+who+' joined '+channel+'</span>');
		}
	},

	onJoined: function(channel) {
		chat.addChannel(channel);
		chat.add(channel, '<span class="join">Entered '+channel+'</span>');
		if (chat.channel(channel) != undefined) {
			chat.channel(channel).show();
		}
	},

	onTopic: function(channel, topic) {
		if (chat.channel(channel) != undefined) {
			chat.channel(channel).setTopic(topic);
		}
	},

	onNick: function(channel, from, to) {
		if (from == chat.nickname) {
			chat.nickname = to;
		}
		if (chat.channel(channel) != undefined) {
			chat.add(channel, '<span class="notice">'+from+' changes nickname to '+to+'</span>');
			chat.channel(channel).members.nick(from, to);
		}
	},

	onWho: function(nick, ident, host, server, full_name) {
		chat.add('info', '<span class="notice"> * '+nick+' ('+ident+'), host: '+host+', server: '+server+', full name: '+full_name+'</span>');
	},

	onEndOfWho: function() {
		chat.add('info', '<span class="notice">End of who</span>');
	},

	onSetGUIVersion: function(verstring) {
		$('version').innerHTML = verstring;
	},

	onSetNumberOfUsers: function(number) {
		$('usercount').innerHTML = number + " users online";
	},

	onChannelMode: function(channel, mode) {
		chat.add(channel, '<span class="notice">channel mode set to '+mode+'</span>')
	},

	addMember: function(channel, who, operator, voice) {
		if (chat.channel(channel) != undefined) {
			chat.channel(channel).members.add(who, operator, voice);
		}
	},

	opMember: function(channel, who, from) {
		if (chat.channel(channel) != undefined) {
			chat.add(channel, '<span class="notice">'+from+' gives operator status to '+who+'</span>')
			chat.channel(channel).members.op(who, from);
		}
	},

	deopMember: function(channel, who, from) {
		if (chat.channel(channel) != undefined) {
			chat.add(channel, '<span class="notice">'+from+' removes operator status from '+who+'</span>')
			chat.channel(channel).members.deop(who, from);
		}
	},

	voiceMember: function(channel, who, from) {
		if (chat.channel(channel) != undefined) {
			chat.add(channel, '<span class="notice">'+from+' gives voice to '+who+'</span>')
			chat.channel(channel).members.voice(who, from);
		}
	},

	devoiceMember: function(channel, who, from) {
		if (chat.channel(channel) != undefined) {
			chat.add(channel, '<span class="notice">'+from+' removes voice from '+who+'</span>')
			chat.channel(channel).members.devoice(who, from);
		}
	},

	addBan: function(channel, mask, from) {
		if (chat.channel(channel) != undefined) {
			chat.add(channel, '<span class="notice">'+from+' bans: '+mask+'</span>')
			chat.channel(channel).addBan(mask, from);
		}
	},

	removeBan: function(channel, mask, from) {
		if (chat.channel(channel) != undefined) {
			chat.add(channel, '<span class="notice">'+from+' removes ban: '+mask+'</span>')
			chat.channel(channel).removeBan(mask, from);
		}
	},

	setKey: function(channel, key, from) {
		if (chat.channel(channel) != undefined) {
			chat.add(channel, '<span class="notice">'+from+' sets channel key to '+key+'</span>')
			chat.channel(channel).setKey(key, from);
		}
	},

	renderMembers: function(channel) {
		if (chat.channel(channel) != undefined) {
			chat.channel(channel).members.render();
		}
	},

	onResize: function() {
		var pageWidth     = (document.documentElement.clientWidth  || window.document.body.clientWidth);
		var pageHeight    = (document.documentElement.clientHeight || window.document.body.clientHeight);
		$('send').setStyle({        width : (pageWidth - 10)+'px'});
		$('editor_input').setStyle({ width : (pageWidth - 10)+'px'});
		$('menu_div').setStyle({    width : (pageWidth - 8)+'px'});
		$('editor_menu').setStyle({ width : (pageWidth - 8)+'px'});
		chat.channels.each(function(channel) {
			channel.onResize();
		});
		window.scrollTo(0, 0);
	},

	frameDisconnected: function() {
		$A(chat.channels).each(function(channel) {
			if (channel.channel != 'info') {
				channel.destroy();
			}
		});
		chat.connected = false;
		chat.connection = false;
		chat.tryConnect();
	},

	// Trialling a new non-iframe method.
	createXMLHttpRequest: function()
	{
		try
		{
			return new ActiveXObject("Msxml2.XMLHTTP");
		}
		catch(e) {}
		try
		{
			return new ActiveXObject("Microsoft.XMLHTTP");
		}
		catch(e) {}
		try
		{
			return new XMLHttpRequest();
		}
		catch(e) {}
		alert("XMLHttpRequest not supported");
		return null;
	},

	pollForRead: function()
	{
		// XXX: It may be an optimisation to split this in onReadyStateChange.
		var aLines = this.xhReq.responseText.split("\n");

		while (this.xhrNextParsePos != aLines.length)
		{
			// This will happen constantly if there is nothing new to recieve
			// This is *important* to keep,  as when a burst of text comes through, it would mark the empty line as evaluated already
			// meaning it would skip the first line of the burst. Is there a better way to do this?
			if (aLines[this.xhrNextParsePos].trim() == '')
			{
				break;
			}

			try
			{
				eval(aLines[this.xhrNextParsePos]);
			}
			catch (e)
			{
				// This can happen if a full JS line hasn't arrived yet.
				chat.debug("EXCEPTION while parsing " + aLines[this.xhrNextParsePos]);
				break;
			}

			
			this.xhrNextParsePos++;
		}
	},
}

// Used in chatConnectionWindow, array.random(), returns a random element from the array
Array.prototype.random = function(r) {
	var i = 0, l = this.length;
	if( !r ) { r = this.length; }
	else if( r > 0 ) { r = r % l; }
	else { i = r; r = l + r % l; }
	return this[ Math.floor( r * Math.random() - i ) ];
};

// String.trim prototype, used in chatEdtitor.js (and others)
String.prototype.trim = function() {
	return this.replace(/^\s+|\s+$/g, "");
};

// Hook up the chat object to the onLoad and onResize events
Event.observe(window, "load",   chat.initialize);
Event.observe(window, "resize", chat.onResize);
Event.observe(window, "unload", chat.onUnload);
