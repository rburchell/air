// WebChat2.0 Copyright (C) 2006-2007, Chris Chabot <chabotc@xs4all.nl>
// Licenced under the GPLv2. For more info see http://www.chabotc.com

/****************************** main chat application  ***********************************/
var xhopts = 
{
	// Which line in the response array are we up to
	nextParsePos: 0,

	// How many poll failures have we experienced
	pollFailures: 0,
}

var Stream =
{
	parseStuff: function(transport)
	{
		var aLines = transport.responseText.split("\n");

		while (xhopts.nextParsePos != aLines.length)
		{
			// This will happen constantly if there is nothing new to recieve
			// This is *important* to keep,  as when a burst of text comes through, it would mark the empty line as evaluated already
			// meaning it would skip the first line of the burst. Is there a better way to do this?
			if (aLines[xhopts.nextParsePos].trim() == '')
			{
				break;
			}

			try
			{
				eval(aLines[xhopts.nextParsePos]);
				xhopts.nextParsePos++;
				xhopts.pollFailures = 0; // if it parses, reset failure count
			}
			catch (e)
			{
				// This can happen if a full JS line hasn't arrived yet.
				chat.debug("EXCEPTION while parsing " + aLines[xhopts.nextParsePos] + " failure count: " + xhopts.pollFailures++);

				if (xhopts.pollFailures > 5)
				{
					chat.debug("Too many poll failures. Skipping line.");
					xhopts.pollFailures = 0;
					xhopts.nextParsePos++;
				}
				else
				{
					break;
				}
			}
		}
	},

	createStream: function(url)
	{
		return new Ajax.Request(url,
		{
			method: 'get',

			onSuccess: function(transport)
			{
				// Call this here in case any last minute data arrived.
				// IE also *only* works through the call here, as IE's XHR doesn't give us responseText in onInteractive (...sigh...)
				Stream.parseStuff(transport);

				// Disconnected. Reset nextParsePos so that eval() knows where to go.
				xhopts.nextParsePos = 0;

				if (transport.status == 200)
				{
					Stream.createStream('/renegotiate?key=' + chat.key);
					return;
				}

				// Add 500ms to the reconnect delay every time we are forced to reconnect, to not bombard the server with requests.
				chat.reconnectdelay += 500;

				// Don't stop an instant reconnect attempt, but if that fails, minimum wait is 5 seconds to stop server hammering.
				if (chat.reconnectdelay > 0 && chat.reconnectdelay < 5000)
					chat.reconnectdelay = 5000;
				else if (chat.reconnectdelay > 50000) // Also cap at 50 seconds.
					chat.reconnectdelay = 50000;

				chat.add("info", "Disconnected from server (HTTP status " + transport.status + "). Reconnecting in " + (chat.reconnectdelay / 1000) + " seconds.");
				setTimeout('chat.frameDisconnected()', chat.reconnectdelay);
			},

			onInteractive: function(transport)
			{
				Stream.parseStuff(transport);
			}
		});
	},
}


var chat =
{
	nickname     : '',
	server       : '',
	key          : false,
	connection   : false,
	connected    : false,
	timer        : false,
	editor       : false,
	windows      : [],
	current      : false,
	listWindow   : false,
	reconnectdelay : 0,

	currentPrefixes : '',

	initialize: function()
	{
		$('new_channel').observe("mousedown", chat.showList);
		chat.editor = new chatEditor;
		chat.addWindow(chat.server, false);
		chat.getWindow(chat.server).show();
		chat.onResize();
		chat.nickname = $('entry_nick').value;
		chat.server = $('entry_server').value;
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
		chat.add(chat.server, message);
	},
	
	tryConnect: function()
	{
		chat.add(chat.server, "Connecting to server: " + chat.server + " as " + chat.nickname + "...");
		Stream.createStream('/get?nickname=' + chat.nickname + '&server=' + chat.server);
	},

	showList: function() {
		if (!chat.listWindow) {
			chat.listWindow = new chatListWindow('channel_list', {height : 440, width : 600, allowResize : false});
		}
		if (!chat.listWindow.visible()) {
			chat.listWindow.show();
		}
	},

	addWindow: function(swindow, canclose)
	{
		if (typeof(canclose) == "undefined")
			canclose = true;

		chat.windows.push(new chatChannel(swindow, canclose));
		chat.onResize();
		chat.editor.focus();
	},

	removeWindow: function(swindow) {
		if (chat.getWindow(swindow) != undefined) {
			chat.getWindow(swindow).destroy();
		}
		chat.editor.focus();
	},

	getWindow: function(swindow) {
	    for (i = 0; i < chat.windows.length; i++) {
			if (chat.windows[i].title == swindow) {
				return chat.windows[i];
			}
	    }
	    return undefined;
	},

	add: function(swindow, message, dosmiley) {
		if (chat.getWindow(swindow) != undefined) {
			chat.getWindow(swindow).add(message, dosmiley);
		}
	},

	sortNames: function(sWindow) {
		if (chat.getWindow(sWindow) != undefined) {
			chat.getWindow(sWindow).sortNames();
		}
	},

	message: function(msg) {
		new Ajax.Request('/message?key=' + chat.key + '&msg=' + encodeURIComponent(msg) + '&channel='+ encodeURIComponent(chat.current),
		{
			method: 'get',
		});
	},

	onConnecting: function() {
		// Reset reconnect delay so we don't get huge delays after a long downtime
		chat.reconnectdelay = 0;
		connected = true;
		chat.add(chat.server, '<span class="notice">Connected to server</span>');
	},

	onServerInfo: function(what, info) {
		chat.add(chat.server, '<span class="notice">'+info+'</span>');
		if (chat.current != chat.server) {
			chat.add(chat.current, '<span class="notice">'+info+'</span>');
		}
	},

	onMotd: function(motd) {
		chat.add(chat.server, '<span class="notice">'+motd+'</span>');
	},

	onCTCP: function(from, ctcp)
	{
		chat.add(chat.server, '<span class="notice">Recieved CTCP ' + ctcp + ' request from '+from+'</span>');
		if (chat.current != chat.server)
		{
			chat.add(chat.current, '<span class="notice">Recieved CTCP ' + ctcp + ' request from '+from+'</span>');
		}
	},

	onMessage: function(from, sWindow, msg) {
		chat.add(sWindow, '<div class="from">'+from+':</div> <span class="message">'+msg+'</span>', true);
	},

	onNotice: function(from, msg) {
		chat.add(chat.server, '<span class="notice">Notice from '+from+': '+msg+'</span>');
		if (chat.current != chat.server)
		{
			chat.add(chat.current, '<span class="notice">Notice from '+from+': '+msg+'</span>', true);
		}
	},

	onWhois: function(msg) {
		chat.add(chat.server, '<span class="notice">'+msg+'</span></span>')
	},

	onWhowas: function(msg) {
		chat.add(chat.server, '<span class="notice">'+msg+'</span></span>')
	},

	onAction: function(sWindow, from, msg) {
		chat.add(sWindow, '<span class="notice">'+from+' <span class="message">'+msg+'</span></span>', true)
	},

	onPrivateMessage: function(from, msg) {
		chat.add(chat.server, '<span class="privmsg">Message from '+from+': <span class="message">'+msg+'</span></span>')
		if (chat.current != chat.server)
		{
			chat.add(chat.current, '<span class="privmsg">Message from '+from+': <span class="message">'+msg+'</span></span>', true)
		}
	},

	onServerNotice: function(notice) {
		chat.add(chat.server, '<span class="notice">Server notice: '+notice+'</span>', true);
	},

	onKick: function(sWindow, from, who, reason) {
		if (chat.getWindow(sWindow) != undefined) {
			chat.getWindow(sWindow).members.remove(who);
			chat.getWindow(sWindow).members.render();
		}
		var reason = (reason != undefined && reason != '') ? ' ('+reason+')' : '';
		chat.add(sWindow, '<span class="kick">'+from+' kicked '+who+' from '+sWindow+reason+'</span>', true);

	},

	onKicked: function(sWindow, from, who, reason) {
		chat.removeWindow(sWindow);
		var reason = (reason != undefined && reason != '') ? ' ('+reason+')' : '';
		chat.add(chat.server,  '<span class="kick">You were kicked from '+sWindow+' by '+from+reason+'</span>', true);
	},

	onError: function(error) {
		chat.add(chat.server, '<span class="kick">Error: '+error+'</span>');
		if (chat.current != chat.server)
		{
			chat.add(chat.current, '<span class="kick">Error: '+error+'</span>');
		}
	},

	onPart: function(sWindow, who, message) {
		if (chat.getWindow(sWindow) != undefined) {
			chat.add(sWindow, '<span class="part">'+who+' left</span>');
			chat.getWindow(sWindow).members.remove(who);
			chat.getWindow(sWindow).members.render();
		}
	},

	onParted: function(sWindow) {
		chat.removeWindow(sWindow);
		chat.add(chat.server, '<span class="part">Left '+sWindow+'</span>');
	},

	onJoin: function(sWindow, who) {
		if (chat.getWindow(sWindow) != undefined) {
			chat.getWindow(sWindow).members.add(who, false, false);
			chat.getWindow(sWindow).members.render();
			chat.add(sWindow, '<span class="join">'+who+' joined '+sWindow+'</span>');
		}
	},

	onJoined: function(sWindow) {
		chat.addWindow(sWindow);
		if (chat.getWindow(sWindow) != undefined) {
			chat.getWindow(sWindow).show();
		}
	},

	onTopic: function(sWindow, topic) {
		if (chat.getWindow(sWindow) != undefined) {
			chat.getWindow(sWindow).setTopic(topic);
		}
	},

	onNick: function(sWindow, from, to) {
		if (from == chat.nickname) {
			chat.nickname = to;
		}
		if (chat.getWindow(sWindow) != undefined) {
			chat.add(sWindow, '<span class="notice">'+from+' changes nickname to '+to+'</span>');
			chat.getWindow(sWindow).members.nick(from, to);
		}
	},

	onWho: function(nick, ident, host, server, full_name) {
		chat.add(chat.server, '<span class="notice"> * '+nick+' ('+ident+'), host: '+host+', server: '+server+', full name: '+full_name+'</span>');
	},

	onEndOfWho: function() {
		chat.add(chat.server, '<span class="notice">End of who</span>');
	},

	onSetGUIVersion: function(verstring) {
		$('version').innerHTML = verstring;
	},

	onSetNumberOfUsers: function(number) {
		$('usercount').innerHTML = number + " users online";
	},

	onChannelMode: function(sWindow, mode) {
		chat.add(sWindow, '<span class="notice">channel mode set to '+mode+'</span>')
	},

	addMember: function(sWindow, who, prefixes) {
		if (chat.getWindow(sWindow) != undefined) {
			chat.getWindow(sWindow).members.add(who, prefixes);
		}
	},

	setPrefix: function(sWindow, who, prefix)
	{
		chat.getWindow(sWindow).members.setPrefix(sWindow, who, prefix);
	},

	unSetPrefix: function(sWindow, who, prefix)
	{
		chat.getWindow(sWindow).members.unSetPrefix(sWindow, who, prefix);
	},

	renderMembers: function(sWindow) {
		if (chat.getWindow(sWindow) != undefined) {
			chat.getWindow(sWindow).members.render();
		}
	},

	onResize: function() {
		var pageWidth     = (document.documentElement.clientWidth  || window.document.body.clientWidth);
		var pageHeight    = (document.documentElement.clientHeight || window.document.body.clientHeight);
		$('send').setStyle({        width : (pageWidth - 10)+'px'});
		$('editor_input').setStyle({ width : (pageWidth - 10)+'px'});
		$('menu_div').setStyle({    width : (pageWidth - 8)+'px'});
		$('editor_menu').setStyle({ width : (pageWidth - 8)+'px'});
		chat.windows.each(function(oWindow) {
			oWindow.onResize();
		});
		window.scrollTo(0, 0);
	},

	frameDisconnected: function() {
		chat.windows.each(function(oWindow) {
			if (oWindow.title != chat.server)
			{
				oWindow.destroy();
			}
		});
		chat.connected = false;
		chat.connection = false;
		chat.tryConnect();
	},

	onSetPrefixTypes: function(prefixes)
	{
		chat.currentPrefixes = prefixes;
	},
}

// String.trim prototype, used in chatEdtitor.js (and others)
String.prototype.trim = function() {
	return this.replace(/^\s+|\s+$/g, "");
};

// Hook up the chat object to the onLoad and onResize events
Event.observe(window, "resize", chat.onResize);
Event.observe(window, "unload", chat.onUnload);
