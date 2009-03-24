#!/usr/bin/php -Cq
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

ini_set('max_execution_time', '0');
ini_set('assert.bail', false);
ini_set('max_input_time', '0');
ini_set('mbstring.func_overload', '0');
ini_set('output_handler', '');
ini_set('default_socket_timeout','10');
ini_set('memory_limit','512M');
date_default_timezone_set("UTC");
set_time_limit(0);

function __autoload($sClass)
{
	AirD::Log(AirD::LOGTYPE_INTERNAL, "Loading " . $sClass);
	include("./src/" . $sClass . ".php");

	if (!class_exists($sClass))
		AirD::Log(AirD::LOGTYPE_INTERNAL, "Loaded " . $sClass . " as a file, but class still doesn't exist. Aiee.");
}

abstract class AirD
{
	public static $aIRCClients = array();

	const VERSION_STRING = "1.0";

	const LOGTYPE_INTERNAL = "INTERNAL";
	const LOGTYPE_HTTP = "HTTP";
	const LOGTYPE_IRC = "IRC";
	const LOGTYPE_JAVASCRIPT = "JAVASCRIPT";

	public static function Log($sType, $sMessage, $bDebug = false)
	{
		echo strftime('%T') . " " . $sType . ": " . $sMessage . "\n";
	}
}

if (!class_exists(Config))
	die("Please make a config.");

error_reporting(E_ALL | E_NOTICE | E_STRICT);
AirD::Log(AirD::LOGTYPE_INTERNAL, "AirD " . AirD::VERSION_STRING . " starting up...");

new HTTPServer(2001);
SocketEngine::process();
