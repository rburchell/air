<?php
/*
 * ircc - a handy, portable console irc client
 *
 * Copyright (C) 2008 Robin Burchell <w00t@inspircd.org>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; version 2 of the License.
 */

/*
 * Provides various miscellaneous routines and functions.
 */
class Utils
{
	/*
	 * Returns an array of:
	 *  prefix
	 *  command
	 *  params
	 * This obeys IRC line format, i.e.
	 *  :w00t PRIVMSG foo :bar moo cow
	 * gives
	 * [0]: w00t
	 * [1]: PRIVMSG
	 * [2]: foo
	 * [3]: bar moo cow
	 *
	 * On the other hand,
	 * msg moo cow
	 * will return:
	 * [0]: ""
	 * [1]: msg
	 * [2]: moo
	 * [3]: cow
	 *
	 * Passing malformed lines isn't a good idea.
	 */
	static public function ParseLine(&$sLine)
	{
		$i = 0;				// where in the array we're up to
		$j = 0;				// which pos in the original array should be treated as a command

		$aRet = array();
		$aParm = explode(" ", $sLine);

		if ($aParm[0][0] == ":")
		{
			// We have a prefix.
			$aRet[0] = substr($aParm[0], 1);
			$i = 1;
			$j = 1;
		}
		else
		{
			// No prefix.
			$aRet[0] = "";
		}

		for (; $i < count($aParm); $i++)
		{
			if ($i == $j)
				$aParm[$i] = strtoupper($aParm[$i]); // uppercase commands

			if ($aParm[$i][0] == ":")
			{
				// Strip :
				$aParm[$i] = substr($aParm[$i], 1);

				// Merge all further params
				$aRet[$i] = implode(" ", array_slice($aParm, $i));
				break; // and ignore everything else.
			}
			else
			{
				// It's a single param.
				$aRet[$i] = $aParm[$i];
			}
		}

		return $aRet;
	}
}
?>
