// WebChat2.0 Copyright (C) 2006-2007, Chris Chabot <chabotc@xs4all.nl>
// Licenced under the GPLv2. For more info see http://www.chabotc.com

/****************************** chatEditor WYSIWYG input editor class  ***********************************/
var chatEditorResizer = Class.create();
chatEditorResizer.prototype = {
	initialize: function() {
		this.divSend        = $('send');
		this.divEditor      = $('editor_input');
//		this.divEdit        = $('editor_edit');
		this.divSizer       = $('editor_resizer');
		this.eventMouseDown = this.initDrag.bindAsEventListener(this);
		this.eventMouseMove = this.updateDrag.bindAsEventListener(this);
		this.eventMouseUp   = this.endDrag.bindAsEventListener(this);
		this.divSizer.observe("mousedown", this.eventMouseDown);
	},

	initDrag: function(event) {
		this.pointer = [Event.pointerX(event), Event.pointerY(event)];
		Event.observe(document, "mouseup",   this.eventMouseUp);
		Event.observe(document, "mousemove", this.eventMouseMove);
	},

	updateDrag: function(event) {
		var pointer   = [Event.pointerX(event), Event.pointerY(event)];
		var dy        = pointer[1] - this.pointer[1];
		this.pointer  = pointer;
		var newHeight = parseFloat($(this.divSend).getStyle('height')) - dy;
		if (newHeight > 45 && newHeight < 400) {
			this.resize(newHeight);
		}
	},

	endDrag: function(event) {
		Event.stopObserving(document, "mouseup",   this.eventMouseUp);
		Event.stopObserving(document, "mousemove", this.eventMouseMove);
		document.body.ondrag        = null;
		document.body.onselectstart = null;
	},

	resize: function(newHeight) {
		this.divSend.setStyle({   height : newHeight + 'px'});
		chat.channels.each(function(channel) {
			channel.onResize();
		});
		this.divEditor.setStyle({ height : parseFloat(newHeight - 21) + 'px'});
//		this.divEdit.setStyle({   height : parseFloat(newHeight - 21) + 'px'});
	}
}

var chatEditor = Class.create();
chatEditor.prototype = {
	initialize: function() {
		// define mIRC layout codes
		this.sizer     = new chatEditorResizer();
		this.menus     = [];
		this.END       = String.fromCharCode(15);
		this.BOLD      = String.fromCharCode(2);
		this.ITALIC    = String.fromCharCode(4);
		this.UNDERLINE = String.fromCharCode(31);
		// and create html editor..
		this.inputEditor = $('editor_input');
		this.createMenu();

		// Command history holder.
		this.commandHistory = new Array();
		this.historyPos = -1;

		// Tab completion
		this.isTabbing = false;
		this.tabDictionary = {};
		this.tabDictionaryChars = "\\_\\|a-zA-Z0-9\\-\\[\\]\\\\`\\^\\{\\}";
		this.tabResult = 0;

		// Monitor keystrokes.
		Event.observe(this.inputEditor, 'keydown', function(event)
		{
			switch (event.keyCode)
			{
				case Event.KEY_RETURN:
					// Send the message off to the server of oz.
					if (!event.shiftKey)
					{
						this.send();
						Event.stop(event);
						return;
					}

					// No, I have no idea what this is for.
					if (parseFloat($(this.sizer.divSend).getStyle('height')) < 60)
					{
						this.sizer.resize(80);
					}
					break;
				case Event.KEY_TAB:
					this.doTabComplete();
					Event.stop(event);
					return;
					break;
				case Event.KEY_DOWN:
					var item = this.historyGetNext();
					if (item != null)
					{
						this.inputEditor.value = item;
					}
					else
					{
						this.inputEditor.value = "";
					}
					Event.stop(event);
					return;
					break;
				case Event.KEY_UP:
					var item = this.historyGetPrevious();
					if (item != null)
					{
						this.inputEditor.value = item;
					}
					Event.stop(event);
					return;
					break;
			}
		}.bind(this));
		this.focus();
	},

	send: function() {
		// Remove returns and translate WYSIWYG html code to mIRC compatible control codes (see chatChannel.js colorize function for the recieving end)
	//	var msg = this.translateTags(this.inputEditor.value);
		var msg = this.inputEditor.value;
		var msgs = msg.split("\n");
		msgs.each(function(msg) {
			msg = msg.replace(/\n/g,'').replace(/\r/g,'');
//			msg = decodeURI(msg);
//			msg = msg.trim();
			if (msg && msg != '') {
				chat.message(msg);
			}
		});

		// Add this item to history.
		this.historyAddItem(this.inputEditor.value);
		setTimeout("chat.editor.clear();",10);
	},

	focus: function()
	{
		this.inputEditor.focus();
	},

	clear: function() {
		this.inputEditor.value = '';
		this.focus();
	},

	/** Add an item to the command history.
	 * @param item The item to add to the command history.
	 * NOTE: Resets the user's position in the command history to the top of the stack.
	 */
	historyAddItem: function(item)
	{
		// Don't allow duplicate items -- just re-blank it
		if (this.commandHistory[0] == item)
		{
			chat.debug("Not adding duplicate history item " + encodeURI(item));
		}
		else
		{
			// Don't allow indefinite growth
			if (this.commandHistory.length > 100)
			{
				this.commandHistory.pop();
			}

			chat.debug("Added item to command history, now " + this.commandHistory.length + " items. Item added is: " + encodeURI(item));

			// Add the item.
			this.commandHistory.unshift(item);
		}

		// Restore pointer to the top of the history stack.
		this.historyPos = -1;
	},

	/** Returns the previous (chronological) item in the command history, if one may be fetched.
	 * @return Returns null if no item may be retrieved (i.e. none stored or already at the end), or the command string.
	 * NOTE: Modifies the command history pointer.
	 */
	historyGetPrevious: function()
	{
		// Don't allow moving past the end of the stack.
		if (this.historyPos > this.commandHistory.length - 1)
			return null;

		chat.debug("GetPrevious: Returning history item " + this.historyPos + " which is: " + this.commandHistory[this.historyPos]);

		this.historyPos++;
		if (this.historyPos > this.commandHistory.length - 1)
			this.historyPos = this.commandHistory.length - 1;

		// Return this string, change position for the future.
		return this.commandHistory[this.historyPos];
	},

	/** Returns the next (chronological) item in the command history, if one may be fetched.
	 * @return Returns null if no item may be retrieved (i.e. none stored or already at the start), or the command string.
	 * NOTE: Modifies the command history pointer.
	 */
	historyGetNext: function()
	{
		if (this.historyPos == 0)
			return null;

		chat.debug("GetNext: Returning history item " + this.historyPos + " which is: " + this.commandHistory[this.historyPos]);

		this.historyPos--;
		if (this.historyPos < 0)
			this.historyPos = 0;

		// Return this string, change position for the future.
		return this.commandHistory[this.historyPos];
	},

	/** Add a word to the tab completion history.
	 * @param word The word to add to the tab completion history.
	 */
	addTabCompleteWord: function(word)
	{
		this.tabDictionary[word.toLowerCase()] = word;
	},

	/** Remove a word from the tab completion history.
	 * @param word The word to remove from tab completion history.
	 */
	removeTabCompleteWord: function(word)
	{
		delete this.tabDictionary[word.toLowerCase()];
	},

	/** Do tab completion on the input box.
	 */
	doTabComplete: function()
	{
		var text = this.inputEditor.value;

		// Get the start of this word
		var iStart = this.getCursorPos() - 1;
		while (iStart > 0 && text[iStart - 1] != ' ')
			iStart--;

		// iStart should now point at the start of a word. Find the end.
		var iEnd = iStart;
		while (iEnd < text.length)
		{
			if (text[iEnd] != ' ')
				iEnd++;
			else
				break;
		}

		// XXX: Eventually, we'll have three lists for tab completion:
		// Recently used, global list, per-channel.
		// Recently used will contain items that have been used recently (like /msg somefag hi).
		// Global will contain all items that are application-wide, like channel names and commands.
		// Per-channel will contain the nicklist.
//		if (chat.current != "info"
		var curchan = chat.channel(chat.current);

		for (var i = 0; i < chat.channel(chat.current).members.members.length; i++)
		{
			var m = chat.channel(chat.current).members.members[i];
			chat.debug("Comparing against " + m.who);

			// 'm' is the channel member, we only want to compare iEnd - iStart characters of their nick, though.
			if (m.who.substring(0, iEnd - iStart).toLowerCase() == text.substring(iStart, iEnd).toLowerCase())
			{
				chat.debug("MATCH! " + m.who);
				// We now want to insert the *latter half* of this word into the input.
				this.insertText(this.getCursorPos(), m.who.substring(iEnd, m.who.length));
				break;
			}
		}

		chat.debug("Got tab complete for word " + text.substring(iStart, iEnd));
	},

	insertText: function(pos, newtext)
	{
		var text = this.inputEditor.value;
		var textbeforepos = text.substring(0,pos);
		var textafterpos = text.substring(pos,text.length);
		this.inputEditor.value = textbeforepos + newtext + textafterpos;
		this.changeCursorPos(textbeforepos.length + newtext.length)
	},

	changeCursorPos: function(newpos)
	{
		if(typeof this.inputEditor.selectionStart!="undefined")
		{
			this.inputEditor.setSelectionRange(newpos,newpos)
		}
		else
		{
			if(this.inputEditor.createTextRange)
			{
				var tmp=this.inputEditor.createTextRange();
				tmp.move("character", newpos);
				tmp.select()
			}

		}
	},

	/** Returns the position of the cursor in the inputbox.
	 */
	getCursorPos: function()
	{
		if (typeof this.inputEditor.selectionStart!="undefined")
		{
			return this.inputEditor.selectionStart
		}
		else
		{
			if (this.inputEditor.createTextRange)
			{
				// IE loves doing things differently.
				var A = document.selection.createRange();
				var B = A.getBookmark();
				return B.charCodeAt(2) - 2;
			}

		}
		return v.length
	},



	closeMenus: function() {
		this.menus.each(function(menu) {
			menu.hide();
		});
	},

	createMenu: function() {
		var cmds = ['bold', 'italic', 'underline', 'forecolor', 'smile'];
		cmds.each(function(cmd) {
			var menu = document.createElement('LI');
			menu.className = 'editor_'+cmd;
			menu.setAttribute('id', 'editor_button_'+cmd);
			$('editor_menu').appendChild(menu);
			if (cmd == 'smile') {
				this.menus.push(new chatSmiliePopup('editor_button_'+cmd, cmd));
				return;
			} else if (cmd == 'forecolor') {
				this.menus.push(new chatColorPopup('editor_button_'+cmd, cmd));
				return;
			} else {
				new chatEditorButton('editor_button_'+cmd, cmd);
			}
		}.bind(this));
	},

	translateTags: function(str) {
		// XXX: We should probably make sure that the user has actually set a colour before appending colour codes..
		if (str[0] != '/')
			str = String.fromCharCode(4) + this.getColor(this.inputEditor.style) + str + String.fromCharCode(15);
		var RegExpCode = [
		['<(\/?)P>|</DIV>|&nbsp;',                                                                         ''],
		['<STRONG>(.*?)<\/STRONG>',                                                                        this.BOLD + '$1' + this.END],
		['<EM>(.*?)<\/EM>',                                                                                this.ITALIC + '$1' + this.END],
		['<U>(.*?)</\U>',                                                                                  this.UNDERLINE + '$1' + this.END],
		['<I>(.*?)</\I>',                                                                                  this.ITALIC + '$1' + this.END],
		['<span style="font-weight: bold;">(.*?)<\/span>',                                                 this.BOLD + '$1' + this.END],
		['<span style="font-style: italic;">(.*?)<\/span>',                                                this.ITALIC + '$1' + this.END],
		['<span style="text-decoration: underline;">(.*?)<\/span>',                                        this.UNDERLINE + '$1' + this.END],
		['<span style="font-weight: bold; text-decoration: underline;">(.*?)<\/span>',                     this.BOLD + this.UNDERLINE + '$1' + this.END + this.END],
		['<span style="text-decoration: underline; font-weight: bold;">(.*?)<\/span>',                     this.UNDERLINE + this.BOLD + '$1' + this.END + this.END],
		['<span style="font-weight: bold; font-style: italic;">(.*?)<\/span>',                             this.BOLD + this.ITALIC + '$1' + this.END + this.END],
		['<span style="font-style: italic; font-weight: bold;">(.*?)<\/span>',                             this.BOLD + this.ITALIC + '$1' + this.END + this.END],
		['<span style="text-decoration: underline; font-style: italic;">(.*?)<\/span>',                    this.UNDERLINE + this.ITALIC + '$1' + this.END + this.END],
		['<span style="font-style: italic; text-decoration: underline;">(.*?)<\/span>',                    this.UNDERLINE + this.ITALIC + '$1' + this.END + this.END],
		['<span style="font-style: italic; text-decoration: underline; font-weight: bold;">(.*?)<\/span>', this.UNDERLINE + this.BOLD + this.ITALIC + '$1' + this.END + this.END + this.END],
		['<span style="font-style: italic; font-weight: bold; text-decoration: underline;">(.*?)<\/span>', this.UNDERLINE + this.BOLD + this.ITALIC + '$1' + this.END + this.END + this.END],
		['<span style="font-weight: bold; font-style: italic; text-decoration: underline;">(.*?)<\/span>', this.UNDERLINE + this.BOLD + this.ITALIC + '$1' + this.END + this.END + this.END],
		['<span style="font-weight: bold; text-decoration: underline; font-style: italic;">(.*?)<\/span>', this.UNDERLINE + this.BOLD + this.ITALIC + '$1' + this.END + this.END + this.END],
		['<span style="text-decoration: underline; font-style: italic; font-weight: bold;">(.*?)<\/span>', this.UNDERLINE + this.BOLD + this.ITALIC + '$1' + this.END + this.END + this.END],
		['<span style="text-decoration: underline; font-weight: bold; font-style: italic;">(.*?)<\/span>', this.UNDERLINE + this.BOLD + this.ITALIC + '$1' + this.END + this.END + this.END],
		['<DIV>|<BR(\/?)>', '']
		];
		RegExpCode.each(function(e) {
			var code = new RegExp(e[0], 'igm');
			str = str.replace(code, e[1]);
		});
		return str;
	},


	getColor: function(color)
	{
		switch (color) {
			case 'white':   return 0;
			case 'black':   return 1;
			case 'navy':    return 2;
			case 'green':   return 3;
			case 'red':     return 4;
			case 'maroon':  return 5;
			case 'purple':  return 6;
			case 'olive':   return 7;
			case 'yellow':  return 8;
			case 'lime':    return 9;
			case 'teal':    return 10;
			case 'aqua':    return 11;
			case 'blue':    return 12;
			case 'fuchsia': return 13;
			case 'gray':    return 14;
			default: 	    return 1;
		}
	}
}


var chatEditorButton = Class.create();
chatEditorButton.prototype = {
	initialize: function(element, command) {
		this.initEvents(element, command);
		this.clickEvent = this.onClick.bindAsEventListener(this);
		Event.observe(this.element, "mousedown", this.clickEvent);
	},

	initEvents: function(element, command) {
		this.element    = $(element);
		this.command    = command;
		this.mouseUp    = this.onMouseUp.bindAsEventListener(this);
		this.mouseOver  = this.onMouseOver.bindAsEventListener(this);
		this.mouseOut   = this.onMouseOut.bindAsEventListener(this);
		Event.observe(this.element, "mouseup",   this.mouseUp);
		Event.observe(this.element, "mouseover", this.mouseOver);
		Event.observe(this.element, "mouseout",  this.mouseOut);
	},

	onMouseUp: function() {
		this.element.removeClassName('down');
	},

	onMouseOver: function() {
		this.element.addClassName('over');
	},

	onMouseOut: function() {
		this.element.removeClassName('over');
	},

	onClick: function(event) {
		this.element.addClassName('down');
		chat.editor.focus();
		chat.editor.execCommand(this.command);

	}
}


chatEditorPopup = Class.create();
Object.extend(Object.extend(chatEditorPopup.prototype, chatEditorButton.prototype), {
	initialize: function(element, command) {
		this.initEvents(element, command);
		this.divContent = 'editor_popup_'+element;
		this.createLayout();
		$(this.divContent).setStyle({opacity : 0.85});
		$(this.divContent).hide();
		this.clickEvent  = this.onClick.bindAsEventListener(this);
		this.selectEvent = this.onSelect.bindAsEventListener(this);
		var sel = this.selectEvent;
		Event.observe(this.element,  "click", this.clickEvent);
		if (this.populate != undefined) {
			this.populate();
			$$('#'+this.divContent+' div').each(function(element) {
				Event.observe(element.id, "click", sel);
			});
			$$('#'+this.divContent+' img').each(function(element) {
				Event.observe(element.id, "click", sel);
			});
		}
	},

	createLayout: function() {
		var div1 = document.createElement('DIV');
		div1.setAttribute('id', this.divContent);
		div1.className = 'editor_popup';
		document.body.appendChild(div1);
	},

	onClick: function() {
		if (!$(this.divContent).visible()) {
			this.show();
			chat.editor.focus();
		} else {
			this.hide();
			chat.editor.focus();
		}
	},

	show: function() {
		var dimensions = $(this.divContent).getDimensions();
		$(this.divContent).setStyle({top : (this.element.offsetTop - dimensions.height) - 2 + 'px', left : (this.element.offsetLeft + 1) + 'px'});
		this.element.removeClassName('over');
		this.element.addClassName('down');
		$(this.divContent).show();
	},

	hide: function() {
		this.element.removeClassName('down');
		$(this.divContent).hide();
	},

	onSelect: function(event) {
		var element = Event.element(event);
		var option = this.command == 'smile' ? element.src : element.style.backgroundColor;
		if (this.command == "smile")
		{
			var smilies = new Array();
			smilies['biggrin.gif']			= ':D';
			smilies['smile.gif']			= ':)';
			smilies['sad.gif']			= ':(';
			smilies['surprised.gif']		= ':o';
			smilies['shock.gif']			= ':shock:';
			smilies['confused.gif']			= ':?';
			smilies['cool.gif']			= '8)';
			smilies['lol.gif']			= ':lol:';
			smilies['mad.gif']			= ':x';
			smilies['razz.gif']			= ':p';
			smilies['redface.gif']			= '::oops:';
			smilies['cry.gif']			= ':cry:';
			smilies['evil.gif']			= ':evil:';
			smilies['badgrin.gif']			= ':badgrin:';
			smilies['rolleyes.gif']			= ':roll:';
			smilies['wink.gif']			= ';)';
			smilies['exclaim.gif']			= ':!:';
			smilies['question.gif']			= ':?:';
			smilies['idea.gif']			= ':idea:';
			smilies['arrow.gif']			= ':arrow:';
			smilies['neutral.gif']			= ':|';
			smilies['doubt.gif']			= ':doubt:';

			// We need to do this replace magic because we determine smiley by filename.
			// I'm *well* aware this is absolutely fugly, but it works.
			chat.editor.insertText(chat.editor.getCursorPos(), smilies[element.src.replace(/^.*[\/\\]/g, '')]);
		}
		else
		{
			chat.editor.inputEditor.style.color = option;
		}
		this.hide();
		chat.editor.focus();
	}
});


chatColorPopup = Class.create();
Object.extend(Object.extend(chatColorPopup.prototype, chatEditorPopup.prototype), {
	populate: function() {
		var colors = [
		'white',
		'black',
		'navy',
		'green',
		'red',
		'maroon',
		'purple',
		'olive',
		'yellow',
		'lime',
		'teal',
		'aqua',
		'blue',
		'fuchsia',
		'gray',
		];
		var menuDiv = $(this.divContent);
		var cmd     = this.command;
		colors.each(function(color) {
			var div1 = document.createElement('DIV');
			div1.className = 'editor_color';
			div1.setAttribute('id', 'editor_'+cmd+'_'+color);
			menuDiv.appendChild(div1);
			$('editor_'+cmd+'_'+color).setStyle({backgroundColor: color});
		});
	}
});


chatSmiliePopup = Class.create();
Object.extend(Object.extend(chatSmiliePopup.prototype, chatEditorPopup.prototype), {
	populate: function() {
		var smilies = [

			['biggrin.gif', ':D'],
			['smile.gif', ':)'],
			['sad.gif', ':('],
			['surprised.gif', ':o'],
			['shock.gif', ':shock:'],
			['confused.gif', ':?'],
			['cool.gif', '8)'],
			['lol.gif', ':lol:'],
			['mad.gif', ':x'],
			['razz.gif', ':p'],
			['redface.gif', '::oops:'],
			['cry.gif', ':cry:'],
			['evil.gif', ':evil:'],
			['badgrin.gif', ':badgrin:'],
			['rolleyes.gif', ':roll:'],
			['wink.gif', ';)'],
			['exclaim.gif', ':!:'],
			['question.gif', ':?:'],
			['idea.gif', ':idea:'],
			['arrow.gif', ':arrow:'],
			['neutral.gif', ':|'],
			['doubt.gif', ':doubt:']
		];
		menuDiv = $(this.divContent);
		smilies.each(function(smile) {
			var img1 = document.createElement('IMG');
			img1.className = 'editor_smilie';
			img1.setAttribute('src', '/images/smilies/'+smile[0]);
			img1.setAttribute('id','editor_smile_'+smile[0]);
			menuDiv.appendChild(img1);
		});
	}
});
