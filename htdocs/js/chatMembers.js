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

	render: function()
	{
		var sorted = this.members.sort(
				function(a, b)
				{
					var pfxcomp = a.prefixrank - b.prefixrank;
					if (pfxcomp == 0)
					{
						// fall back to string sort
						return a-b;
					}
					else if (pfxcomp > 0)
					{
						return -1;
					}
					else
					{
						return 1;
					}
				});
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
		var m = 
		{
			who: who,
			prefixes: '',
			prefixrank: 0,
			content: this.channel + '_member_' + who,
			toString: function()
			{
				return this.prefixes + this.who;
			},
		}
		this.members.push(m);

		// Use setPrefix to make sure numerical content is correctly set.
		for (var pin = 0; pin < prefixes.length; pin++)
		{
			this.setPrefix(this.channel, who, prefixes[pin]);
		}
		//{who : who, prefixes: prefixes, content : this.channel + '_member_' + who, toString : function() {return (this.prefixes+this.who)} });
	},

	remove: function(who) {
		this.members.splice(this.indexOf(who), 1);
	},

	setPrefix: function(channel, who, prefix)
	{
		var value = chat.currentPrefixes.length - chat.currentPrefixes.indexOf(prefix);
		this.members[this.indexOf(who)].prefixes += prefix;
		// Multiply value by itself to get a better "weighted" total. This means that ~ and @% are different.
		this.members[this.indexOf(who)].prefixrank += value * value;
		this.render();
	},

	unSetPrefix: function(channel, who, prefix)
	{
		var pfreg = new RegExp('\\' + prefix);
		var value = chat.currentPrefixes.length - chat.currentPrefixes.indexOf(prefix);

		this.members[this.indexOf(who)].prefixes = this.members[this.indexOf(who)].prefixes.replace(pfreg, "");
		this.members[this.indexOf(who)].prefixrank -= value * value;
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
