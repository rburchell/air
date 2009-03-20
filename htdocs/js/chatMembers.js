// WebChat2.0 Copyright (C) 2006-2007, Chris Chabot <chabotc@xs4all.nl>
// Licenced under the GPLv2. For more info see http://www.chabotc.com

/************************** chatMembers class implimentation ***********************************/
var chatMembers = Class.create();
chatMembers.prototype = {
	initialize: function(channel, content, header, parent) {
		this.channel  = channel;
		this.content  = content;
		this.header   = header;
		this.tooltips = [];
		this.menus    = [];
		this.members  = [];
		this.parent   = parent;
	},

	destroy: function() {
		this.clear();
	},

	clear: function() {
		while ($(this.content).firstChild) {
			$(this.content).removeChild($(this.content).firstChild);
		}
		$(this.content).update('');
	},

	render: function() {
		var sorted = this.members.sort();
		var length = sorted.length;
		var operator = '';
		var voice    = '';
		var member   = false;
		$(this.header).update(length+' members');
		this.clear();
		for (var i = 0 ; i < length ; i++)
		{
			member = sorted[i];
			prefixes = member.prefixes != undefined && member.prefixes ? member.prefixes : '';
			// class 'memberoperator' and 'membervoice' are bleh. figure out a way to do this properly.
			new Insertion.Bottom($(this.content), '<li class="member" id="'+member.content+'">'+member+'</li>');
		}
	},

	add: function(who, prefixes) {
		this.members.push({who : who, prefixes: prefixes, content : this.channel + '_member_' + who, toString : function() {return (this.prefixes+this.who)} });
	},

	remove: function(who) {
		this.members.splice(this.indexOf(who), 1);
	},

	setPrefix: function(channel, who, prefix)
	{
		this.members[this.indexOf(who)].prefixes += prefix;
		this.render();
	},

	unSetPrefix: function(channel, who, prefix)
	{
		var pfreg = new RegExp('\\' + prefix);

		this.members[this.indexOf(who)].prefixes = this.members[this.indexOf(who)].prefixes.replace(pfreg, "");
		this.render();
	},

	nick: function(from, to) {
		if (this.indexOf(from) != -1) {
			this.members[this.indexOf(from)].who = to;
			this.render();
		}
	},

	

	indexOf: function(who) {
	    for (i = 0; i < this.members.length; i++) {
			if (this.members[i].who == who) {
				return i;
			}
	    }
	    return -1;
	},

	member: function(who) {
		var index = this.indexOf(who);
		return (index != -1) ? this.members[index] : undefined;
	}
}
