<?php
/*
 * Air -- FOSS AJAX IRC Client.
 *
 * Copyright (C) 2009, Robin Burchell <w00t@freenode.net>
 * Copyright (C) 2006-2007, Chris Chabot <chabotc@xs4all.nl>
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

class HTTPServer extends socketServer
{
	public function __construct($iPort)
	{
		// Spawn off a client servicer to handle the request.
		AirD::Log(AirD::LOGTYPE_INTERNAL, "HTTPServer's constructor", true);
		parent::__construct("HTTPClientServer", 0, $iPort);
	}

	public function read()
	{
		AirD::Log(AirD::LOGTYPE_INTERNAL, "HTTPServer's read", true);
		parent::read();
		AirD::Log(AirD::LOGTYPE_INTERNAL, "After HTTPServer's read", true);
	}
}

