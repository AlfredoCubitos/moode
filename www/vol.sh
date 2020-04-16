#!/bin/bash
#
# moOde audio player (C) 2014 Tim Curtis
# http://moodeaudio.org
#
# This Program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 3, or (at your option)
# any later version.
#
# This Program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.
#
# 2020-04-16 TC moOde 6.5.0
#

VER="5.1.0"

SQLDB=/var/local/www/db/moode-sqlite3.db

if [[ -z $1 ]]; then
	echo $(sqlite3 $SQLDB "SELECT value FROM cfg_system WHERE id='32'")
	exit 0
fi

if [[ $1 = "--help" ]]; then
echo -e "Usage: vol.sh [OPTION] [VOLUME]
Change the volume and update the knob.

With no OPTION or VOLUME, print the current volume.
With just VOLUME, set current volume to VOLUME.

 -up\t\tVOLUME	value between 1 and 100
 -dn\t\tVOLUME	value between 1 and 100
 -mute\t\tmute or unmute the volume
 -restore\tset volume to current knob level
 --version\tprint the program version
 --help\t\tprint this help text"

	exit 1
fi

if [[ $1 = "--version" ]]; then
	echo "Version: "$VER
	exit 1
fi

# Get config settings
RESULT=$(sqlite3 $SQLDB "SELECT value FROM cfg_system WHERE id IN  ('32', '33', 137)")
# Friendly names
readarray -t arr <<<"$RESULT"
VOLKNOB=${arr[0]}
VOLMUTE=${arr[1]}
MPDMAX=${arr[2]}
# cardnum 0 = i2s or onboard, cardnum 1 = usb

REGEX='^[0-9]+$'

# Parse OPTIONS
if [[ $1 = "-mute" || $1 = "mute" ]]; then
	if [[ $VOLMUTE = "1" ]]; then
		sqlite3 $SQLDB "UPDATE cfg_system SET value='0' WHERE id='33'"
		VOLMUTE=0
		LEVEL=$VOLKNOB
	else
		sqlite3 $SQLDB "UPDATE cfg_system SET value='1' WHERE id='33'"
		VOLMUTE=1
	fi
else
	if [[ $1 = "-restore" || $1 = "restore" ]]; then
		LEVEL=$VOLKNOB
	elif [[ $1 = "-up" || $1 = "up" ]]; then
		if ! [[ $2 =~ $REGEX ]]; then
			echo "VOLUME must only contain digits 0-9"
			exit 1
		else
			LEVEL=$(($VOLKNOB + $2))
		fi
	elif [[ $1 = "-dn" || $1 = "dn" ]]; then
		# volume down step
		if ! [[ $2 =~ $REGEX ]]; then
			echo "VOLUME must only contain digits 0-9"
			exit 1
		else
			LEVEL=$(($VOLKNOB - $2))
		fi
	else
		LEVEL=$1
	fi

	# Numeric check
	if ! [[ $LEVEL =~ $REGEX ]]; then
		echo "Invalid OPTION or VOLUME not numeric"
		exit 1
	fi

	# Limit check
	if (( $LEVEL > $MPDMAX )); then
		LEVEL=$MPDMAX
	fi

	# Range check
	if (( $LEVEL < 0 )); then
		LEVEL=0
	elif (( $LEVEL > 100 )); then
		LEVEL=100
	fi

	# Update knob level
	sqlite3 $SQLDB "UPDATE cfg_system SET value=$LEVEL WHERE id='32'"
fi

# Mute if indicated
if [[ $VOLMUTE = "1" ]]; then
	mpc volume 0 >/dev/null
	exit 1
fi

# Volume: update MPD volume --> MPD idle timeout --> UI updated
mpc volume $LEVEL >/dev/null
