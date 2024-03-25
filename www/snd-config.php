<?php
/**
 * moOde audio player (C) 2014 Tim Curtis
 * http://moodeaudio.org
 *
 * This Program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3, or (at your option)
 * any later version.
 *
 * This Program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/alsa.php';
require_once __DIR__ . '/inc/audio.php';
require_once __DIR__ . '/inc/cdsp.php';
require_once __DIR__ . '/inc/eqp.php';
require_once __DIR__ . '/inc/mpd.php';
require_once __DIR__ . '/inc/session.php';
require_once __DIR__ . '/inc/sql.php';

phpSession('open');
$dbh = sqlConnect();
$cdsp = new CamillaDsp($_SESSION['camilladsp'], $_SESSION['cardnum'], $_SESSION['camilladsp_quickconv']);
$deviceNames = getAlsaDeviceNames();

// AUDIO OUTPUT

// Output device
if (isset($_POST['update_output_device']) && $_POST['output_device'] != $_SESSION['cardnum']) {
	// Validate
	$reservedNames = array(ALSA_LOOPBACK_DEVICE, ALSA_DUMMY_DEVICE, ALSA_EMPTY_CARD);
	if (in_array($deviceNames[$_POST['output_device']], $reservedNames)) {
		$_SESSION['notify']['title'] = 'This device is reserved or empty';
		$_SESSION['notify']['msg'] = 'It cannot be set directly';
	} else {
		// Update ALSA config
		phpSession('write', 'cardnum', $_POST['output_device']);
		phpSession('write', 'adevname', $deviceNames[$_POST['output_device']]);
		phpSession('write', 'amixname', getAlsaMixerName($_SESSION['adevname']));
	    phpSession('write', 'alsavolume', getAlsaVolume($_SESSION['amixname']));
		$mode = getConfiguredAudioOutput() == AO_HDMI ? 'iec958' : 'plughw';
		phpSession('write', 'alsa_output_mode', $mode);

		// Update MPD config
		sqlUpdate('cfg_mpd', $dbh, 'device', $_POST['output_device']);
		phpSession('write', 'volknob_mpd', '-1'); // Reset saved MPD volume
		$queueArgs = '1,0'; // Device change,MPD mixer change
		submitJob('mpdcfg', $queueArgs, 'Settings updated', 'MPD restarted');
	}
}

// Volume type
if (isset($_POST['update_volume_type']) && $_POST['mixer_type'] != $_SESSION['mpdmixer']) {
	$mixerTypeSelected = $_POST['mixer_type'];

	if ($_POST['mixer_type'] == 'null') {
		$mixerChange = 'camilladsp';
		$camillaDspVolumeSync = 'on';
	} else if ($_POST['mixer_type'] == 'none') {
		$mixerChange = 'fixed';
		$camillaDspVolumeSync = 'off';
	} else {
		// Hardware or software
		$mixerChange = $_POST['mixer_type'];
		$camillaDspVolumeSync = 'off';
	}

	phpSession('write', 'camilladsp_volume_sync', $camillaDspVolumeSync);
	sqlUpdate('cfg_mpd', $dbh, 'mixer_type', $_POST['mixer_type']);

	$queueArgs = '0,' . $mixerChange; // Device change,MPD mixer change
	submitJob('mpdcfg', $queueArgs, 'Settings updated', 'MPD restarted');
}
// CamillaDSP volume range
if (isset($_POST['update_camilladsp_volume_range']) && $_POST['camilladsp_volume_range'] != $_SESSION['camilladsp_volume_range']) {
	$_SESSION['camilladsp_volume_range'] = $_POST['camilladsp_volume_range'];
	sysCmd("sed -i '/dynamic_range/c\dynamic_range = " . $_SESSION['camilladsp_volume_range'] . "' /etc/mpd2cdspvolume.config");
	sysCmd('systemctl restart mpd2cdspvolume');
	$_SESSION['notify']['title'] = 'Settings updated';
}

// I2S AUDIO DEVICE

// Flag that controls what is displayed in the Output device field after changing I2S device or overlay
$i2sReboot = false;
// Named device
if (isset($_POST['update_i2s_device'])) {
	if (isset($_POST['i2sdevice']) && $_POST['i2sdevice'] != $_SESSION['i2sdevice']) {
		$i2sReboot = true;
		phpSession('write', 'i2sdevice', $_POST['i2sdevice']);
		submitJob('i2sdevice', '', 'Settings updated', 'Restart required');
	}
}
// Device overlay
if (isset($_POST['update_i2s_overlay'])) {
	if (isset($_POST['i2soverlay']) && $_POST['i2soverlay'] != $_SESSION['i2soverlay']) {
		$i2sReboot = true;
		phpSession('write', 'i2soverlay', $_POST['i2soverlay']);
		submitJob('i2sdevice', '', 'Settings updated', 'Restart required');
	}
}
// Driver options
if (isset($_POST['update_drvoptions'])) {
	if (isset($_POST['drvoptions']) && $_POST['drvoptions'] != 'none') {
		$result = sqlQuery("SELECT driver, drvoptions FROM cfg_audiodev WHERE name='" . $_SESSION['i2sdevice'] . "'", $dbh);
		$driver = explode(',', $result[0]['driver']);
		$driverUpd = $_POST['drvoptions'] == 'Enabled' ? $driver[0] . ',' . $result[0]['drvoptions'] : $driver[0];
		$result = sqlQuery("UPDATE cfg_audiodev SET driver='" . $driverUpd . "' WHERE name='" . $_SESSION['i2sdevice'] . "'", $dbh);
		submitJob('i2sdevice', $_SESSION['i2sdevice'], 'Settings updated', 'Restart required');
	}
}

// ALSA OPTIONS

// Max volume
if (isset($_POST['update_alsavolume_max'])) {
	if (isset($_POST['alsavolume_max'])) {
		submitJob('alsavolume_max', $_POST['alsavolume_max'], 'Settings updated');
		phpSession('write', 'alsavolume_max', $_POST['alsavolume_max']);
	}
}
// Output mode
if (isset($_POST['update_alsa_output_mode'])) {
	if (isset($_POST['alsa_output_mode']) && $_POST['alsa_output_mode'] != $_SESSION['alsa_output_mode']) {
		$oldOutputMode = $_SESSION['alsa_output_mode'];
		$newOutputMode = $_POST['alsa_output_mode'];
		// NOTE: Update session first for functions used in job
		phpSession('write', 'alsa_output_mode', $newOutputMode);
		submitJob('alsa_output_mode', $oldOutputMode, 'Settings updated');
	}
}
// Loopback
if (isset($_POST['update_alsa_loopback'])) {
	if (isset($_POST['alsa_loopback']) && $_POST['alsa_loopback'] != $_SESSION['alsa_loopback']) {
		// Check to see if module is in use
		if ($_POST['alsa_loopback'] == 'Off') {
			$result = sysCmd('sudo modprobe -r snd-aloop');
			if (!empty($result)) {
				$_SESSION['notify']['title'] = 'Unable to turn off';
				$_SESSION['notify']['msg'] = 'Loopback is in use';
				$_SESSION['notify']['duration'] = 5;
			} else {
				submitJob('alsa_loopback', 'Off', 'Settings updated');
				phpSession('write', 'alsa_loopback', 'Off');
			}
		} else {
			submitJob('alsa_loopback', 'On', 'Settings updated');
			phpSession('write', 'alsa_loopback', 'On');
		}
	}
}

// MPD OPTIONS

// General

// Restart mpd
if (isset($_POST['mpdrestart']) && $_POST['mpdrestart'] == 1) {
	submitJob('mpdrestart', '', 'MPD restarted');
}
// Autoplay last played item after reboot/powerup
if (isset($_POST['autoplay']) && $_POST['autoplay'] != $_SESSION['autoplay']) {
	$_SESSION['notify']['title'] = 'Settings updated';
	phpSession('write', 'autoplay', $_POST['autoplay']);
}

// Metadata file
if (isset($_POST['extmeta']) && $_POST['extmeta'] != $_SESSION['extmeta']) {
	phpSession('write', 'extmeta', $_POST['extmeta']);
	$_SESSION['notify']['title'] = 'Settings updated';
}

// Auto-shuffle

// Service
if (isset($_POST['ashufflesvc']) && $_POST['ashufflesvc'] != $_SESSION['ashufflesvc']) {
	$_SESSION['notify']['title'] = 'Settings updated';
	phpSession('write', 'ashufflesvc', $_POST['ashufflesvc']);
	// Turn off MPD random play so no conflict
	$sock = openMpdSock('localhost', 6600);
	sendMpdCmd($sock, 'random 0');
	$resp = readMpdResp($sock);
	// Kill the service if indicated
	if ($_POST['ashufflesvc'] == 0) {
		sysCmd('killall -s 9 ashuffle > /dev/null');
		phpSession('write', 'ashuffle', '0');
		sendMpdCmd($sock, 'consume 0');
		$resp = readMpdResp($sock);
	}
}
// Mode
if (isset($_POST['update_ashuffle_mode']) && $_POST['ashuffle_mode'] != $_SESSION['ashuffle_mode']) {
	phpSession('write', 'ashuffle_mode', $_POST['ashuffle_mode']);
	if ($_SESSION['ashuffle'] == '1') {
		$_SESSION['notify']['title'] = 'Settings updated';
		$_SESSION['notify']['msg'] = 'Random play turned off';
		stopAutoShuffle();
	} else {
		$_SESSION['notify']['title'] = 'Settings updated';
	}
}
// Filter
if (isset($_POST['update_ashuffle_filter']) && $_POST['ashuffle_filter'] != $_SESSION['ashuffle_filter']) {
	$trim_filter = trim($_POST['ashuffle_filter']);
	phpSession('write', 'ashuffle_filter', ($trim_filter == '' ? 'None' : $trim_filter));
	if ($_SESSION['ashuffle'] == '1') {
		$_SESSION['notify']['title'] = 'Settings updated';
		$_SESSION['notify']['msg'] = 'Random play turned off';
		stopAutoShuffle();
	} else {
		$_SESSION['notify']['title'] = 'Settings updated';
	}
}

// Volume options

// Volume step limit
if (isset($_POST['volume_step_limit']) && $_POST['volume_step_limit'] != $_SESSION['volume_step_limit']) {
	phpSession('write', 'volume_step_limit', $_POST['volume_step_limit']);
	$_SESSION['notify']['title'] = 'Settings updated';
}
// Volume MPD mmax
if (isset($_POST['volume_mpd_max']) && $_POST['volume_mpd_max'] != $_SESSION['volume_mpd_max']) {
	phpSession('write', 'volume_mpd_max', $_POST['volume_mpd_max']);
	$_SESSION['notify']['title'] = 'Settings updated';
}
// Display dB volume
if (isset($_POST['update_volume_db_display']) && $_POST['volume_db_display'] != $_SESSION['volume_db_display']) {
	phpSession('write', 'volume_db_display', $_POST['volume_db_display']);
	$_SESSION['notify']['title'] = 'Settings updated';
}
// USB volume knob
if (isset($_POST['update_usb_volknob']) && $_POST['usb_volknob'] != $_SESSION['usb_volknob']) {
	submitJob('usb_volknob', $_POST['usb_volknob'], 'Settings updated');
	phpSession('write', 'usb_volknob', $_POST['usb_volknob']);
}
// Rotary encoder service
if (isset($_POST['update_rotenc'])) {
	if (isset($_POST['rotaryenc']) && $_POST['rotaryenc'] != $_SESSION['rotaryenc']) {
		phpSession('write', 'rotaryenc', $_POST['rotaryenc']);
		submitJob('rotaryenc', $_POST['rotaryenc'], 'Settings updated');
	}
}
// Rotary encoder settings
if (isset($_POST['update_rotenc_params'])) {
	if (isset($_POST['rotenc_params']) && $_POST['rotenc_params'] != $_SESSION['rotenc_params']) {
		phpSession('write', 'rotenc_params', $_POST['rotenc_params']);
		submitJob('rotaryenc', $_POST['rotaryenc'], 'Settings updated');
	}
}

// DSP options

// Crossfade
if (isset($_POST['mpdcrossfade']) && $_POST['mpdcrossfade'] != $_SESSION['mpdcrossfade']) {
	submitJob('mpdcrossfade', $_POST['mpdcrossfade'], 'Settings updated');
	phpSession('write', 'mpdcrossfade', $_POST['mpdcrossfade']);
}
// Crossfeed
if (isset($_POST['crossfeed']) && $_POST['crossfeed'] != $_SESSION['crossfeed']) {
	phpSession('write', 'crossfeed', $_POST['crossfeed']);
	submitJob('crossfeed', $_POST['crossfeed'], 'Settings updated');
}
// Polarity inversion
if (isset($_POST['update_invert_polarity']) && $_POST['invert_polarity'] != $_SESSION['invert_polarity']) {
	submitJob('invpolarity', $_POST['invert_polarity'], 'Settings updated');
	phpSession('write', 'invert_polarity', $_POST['invert_polarity']);
}

// HTTP streaming

// Server
if (isset($_POST['mpd_httpd']) && $_POST['mpd_httpd'] != $_SESSION['mpd_httpd']) {
	submitJob('mpd_httpd', $_POST['mpd_httpd'], 'Settings updated');
	phpSession('write', 'mpd_httpd', $_POST['mpd_httpd']);
}
// Port
if (isset($_POST['mpd_httpd_port']) && $_POST['mpd_httpd_port'] != $_SESSION['mpd_httpd_port']) {
	phpSession('write', 'mpd_httpd_port', $_POST['mpd_httpd_port']);
	submitJob('mpd_httpd_port', $_POST['mpd_httpd_port'], 'Settings updated', 'MPD restarted');
}
// Encoder
if (isset($_POST['mpd_httpd_encoder']) && $_POST['mpd_httpd_encoder'] != $_SESSION['mpd_httpd_encoder']) {
	phpSession('write', 'mpd_httpd_encoder', $_POST['mpd_httpd_encoder']);
	submitJob('mpd_httpd_encoder', $_POST['mpd_httpd_encoder'], 'Settings updated', 'MPD restarted');
}

// EQUALIZERS

// CamillaDSP
if (isset($_POST['update_cdsp_mode']) && $_POST['cdsp_mode'] != $_SESSION['camilladsp']) {
	$currentMode = $_SESSION['camilladsp'];
	$newMode = $_POST['cdsp_mode'];
	phpSession('write', 'camilladsp', $_POST['cdsp_mode']);
	$cdsp->selectConfig($_POST['cdsp_mode']);

	if ($_SESSION['cdsp_fix_playback'] == 'Yes' ) {
		$cdsp->setPlaybackDevice($_SESSION['cardnum'], $_SESSION['alsa_output_mode']);
	}

	$cdsp->updCDSPConfig($newMode, $currentMode, $cdsp);
}
// Parametric eq
$eqfa12p = Eqp12($dbh);
if (isset($_POST['eqfa12p']) && ((intval($_POST['eqfa12p']) ? "On" : "Off") != $_SESSION['eqfa12p'] || intval($_POST['eqfa12p']) != $eqfa12p->getActivePresetIndex())) {
	// Pass old,new curve name to worker job
	$currentActive = $eqfa12p->getActivePresetIndex();
	$newActive = intval($_POST['eqfa12p']);
	$eqfa12p->setActivePresetIndex($newActive);
	phpSession('write', 'eqfa12p', $newActive == 0 ? "Off" : "On");
	submitJob('eqfa12p', $currentActive . ',' . $newActive, 'Settings updated', 'MPD restarted');
}
unset($eqfa12p);
// Graphic eq
if (isset($_POST['alsaequal']) && $_POST['alsaequal'] != $_SESSION['alsaequal']) {
	// Pass old,new curve name to worker job
	phpSession('write', 'alsaequal', $_POST['alsaequal']);
	submitJob('alsaequal', $_SESSION['alsaequal'] . ',' . $_POST['alsaequal'], 'Settings updated');
}

phpSession('close');

$result = sqlRead('cfg_mpd', $dbh);
$cfgMPD = array();
foreach ($result as $row) {
	$cfgMPD[$row['param']] = $row['value'];
}

// AUDIO OUTPUT

// Output device
// Pi HDMI 1 & 2, Pi Headphone jack, I2S device, USB device(s)
if ($i2sReboot === true) {
	$_mpd_select['device'] = "<option value=\"0\" selected>RESTART REQUIRED</option>\n";
} else {
	for ($i = 0; $i < ALSA_MAX_CARDS; $i++) {
		$deviceName = $deviceNames[$i] == ALSA_DUMMY_DEVICE ?
			$i . ':'  . TRX_SENDER_NAME :
			$i . ':'  . $deviceNames[$i];
		$_mpd_select['device'] .= "<option value=\"" . $i . "\" " . (($cfgMPD['device'] == $i) ? "selected" : "") . ">$deviceName</option>\n";
	}
}

// For USB device
// ALSA removes the card id after the device is unplugged or turned off
$_device_error = $deviceNames[$_SESSION['cardnum']] == ALSA_EMPTY_CARD ? 'Device turned off or disconnected' : '';

// Volume type
// Hardware, Software, Fixed (none), CamillaDSP (null)
if ($_SESSION['alsavolume'] != 'none') {
	$_mpd_select['mixer_type'] .= "<option value=\"hardware\" " .
		($cfgMPD['mixer_type'] == 'hardware' ? "selected" : "") . ">Hardware</option>\n";
}
$_mpd_select['mixer_type'] .= "<option value=\"software\" " .
	($cfgMPD['mixer_type'] == 'software' ? "selected" : "") . ">Software</option>\n";
$_mpd_select['mixer_type'] .= "<option value=\"none\" " .
	($cfgMPD['mixer_type'] == 'none' ? "selected" : "") . ">Fixed (0dB)</option>\n";
if ($_SESSION['camilladsp'] != 'off') {
	$_mpd_select['mixer_type'] .= "<option value=\"null\" " .
		($cfgMPD['mixer_type'] == 'null' ? "selected" : "") . ">CamillaDSP</option>\n";
	$_camilladsp_volume_range_hide = ($cfgMPD['mixer_type'] == 'null' && $_SESSION['camilladsp_volume_sync'] == 'on') ? '' : 'hide';
	$_select['camilladsp_volume_range'] .= "<option value=\"30\" " . (($_SESSION['camilladsp_volume_range'] == '30') ? "selected" : "") . ">30 dB</option>\n";
	$_select['camilladsp_volume_range'] .= "<option value=\"40\" " . (($_SESSION['camilladsp_volume_range'] == '40') ? "selected" : "") . ">40 dB</option>\n";
	$_select['camilladsp_volume_range'] .= "<option value=\"50\" " . (($_SESSION['camilladsp_volume_range'] == '50') ? "selected" : "") . ">50 dB</option>\n";
	$_select['camilladsp_volume_range'] .= "<option value=\"60\" " . (($_SESSION['camilladsp_volume_range'] == '60') ? "selected" : "") . ">60 dB</option>\n";
	$_select['camilladsp_volume_range'] .= "<option value=\"70\" " . (($_SESSION['camilladsp_volume_range'] == '70') ? "selected" : "") . ">70 dB</option>\n";
	$_select['camilladsp_volume_range'] .= "<option value=\"80\" " . (($_SESSION['camilladsp_volume_range'] == '80') ? "selected" : "") . ">80 dB</option>\n";
} else {
	$_camilladsp_volume_range_hide = 'hide';
}
// Named I2S devices
$result = sqlQuery("SELECT name FROM cfg_audiodev WHERE iface='I2S' AND list='yes'", $dbh);
sort($result);
$array = array();
$array[0]['name'] = 'None';
$dacList = array_merge($array, $result);
foreach ($dacList as $dac) {
	$selected = ($_SESSION['i2sdevice'] == $dac['name']) ? ' selected' : '';
	$_i2s['i2sdevice'] .= sprintf('<option value="%s"%s>%s</option>\n', $dac['name'], $selected, $dac['name']);
}
// DT overlays
$overlayList = sysCmd('moodeutl -o');
array_unshift($overlayList, 'None');
foreach ($overlayList as $overlay) {
	$overlayName = ($overlay == 'None') ? $overlay : substr($overlay, 0, -5); // Strip .dtbo extension
	// NOTE: This can be used to filter the list
	/*$result = sqlQuery("SELECT name FROM cfg_audiodev WHERE iface='I2S' AND list='yes' AND driver='" . $overlayName . "'", $dbh);
	if ($result === true || $overlayName == 'None') { // true = query executed but returnes no results
		$selected = ($_SESSION['i2soverlay'] == $overlayName) ? ' selected' : '';
		$_i2s['i2soverlay'] .= sprintf('<option value="%s"%s>%s</option>\n', $overlayName, $selected, $overlayName);
	}*/
	$selected = ($_SESSION['i2soverlay'] == $overlayName) ? ' selected' : '';
	$_i2s['i2soverlay'] .= sprintf('<option value="%s"%s>%s</option>\n', $overlayName, $selected, $overlayName);
}
// Driver options
$result = sqlQuery("SELECT chipoptions, driver, drvoptions FROM cfg_audiodev WHERE name='" . $_SESSION['i2sdevice'] . "'", $dbh);
if (!empty($result[0]['drvoptions']) && $_SESSION['i2soverlay'] == 'None' && $i2sReboot === false) {
	$_select['drvoptions'] .= "<option value=\"Enabled\" " . ((strpos($result[0]['driver'], $result[0]['drvoptions']) !== false) ? "selected" : "") . ">" . $result[0]['drvoptions'] . " Enabled</option>\n";
	$_select['drvoptions'] .= "<option value=\"Disabled\" " . ((strpos($result[0]['driver'], $result[0]['drvoptions']) === false) ? "selected" : "") . ">" . $result[0]['drvoptions'] . " Disabled</option>\n";
	$_driveropt_btn_disable = '';
} else {
	$_select['drvoptions'] .= "<option value=\"none\" selected>None available</option>\n";
	$_driveropt_btn_disable = 'disabled';
}

// Button disables
if ($_SESSION['audioout'] == 'Bluetooth' ||
	$_SESSION['multiroom_tx'] == 'On' ||
	$_SESSION['multiroom_rx'] == 'On') {
	$_output_device_btn_disabled = 'disabled';
	$_volume_type_btn_disabled = 'disabled';
	$_driveropt_btn_disable = 'disabled';
	$_chip_btn_disable = 'disabled';
	$_chip_link_disable = 'onclick="return false;"';
	$_i2sdevice_btn_disable = 'disabled';
	$_i2soverlay_btn_disable = 'disabled';
} else {
	$_output_device_btn_disabled = '';
	$_volume_type_btn_disabled = '';
	$_i2sdevice_btn_disable = $_SESSION['i2soverlay'] == 'None' ? '' : 'disabled';
	$_i2soverlay_btn_disable = $_SESSION['i2sdevice'] == 'None' ? '' : 'disabled';
	$_driveropt_btn_disable = $i2sReboot === false ? '' : 'disabled';
	$_chip_btn_disable = (!empty($result[0]['chipoptions']) && $_SESSION['i2soverlay'] == 'None' && $i2sReboot === false) ? '' : 'disabled';
	$_chip_link_disable = (!empty($result[0]['chipoptions']) && $_SESSION['i2soverlay'] == 'None' && $i2sReboot === false) ? '' : 'onclick="return false;"';
}

// ALSA OPTIONS

// Max volume
if ($_SESSION['alsavolume'] == 'none') {
	$_alsavolume_max = '';
	$_alsavolume_max_readonly = 'readonly';
	$_alsavolume_max_disable = 'disabled';
	$_alsavolume_max_msg = "<i>Hardware volume controller not detected</i><br>";
} else {
	$_alsavolume_max = $_SESSION['alsavolume_max'];
	$_alsavolume_max_readonly = '';
	$_alsavolume_max_disable = '';
	$_alsavolume_max_msg = '';
}
// Output mode
$_alsa_output_mode_disable = $_SESSION['alsa_loopback'] == 'Off' ? '' : 'disabled';
if (substr($_SESSION['hdwrrev'], 3, 1) >= 3 && str_contains($_SESSION['adevname'], 'HDMI')) {
	// Pi-3 or higher and HDMI output set
	$_select['alsa_output_mode'] .= "<option value=\"iec958\" " . (($_SESSION['alsa_output_mode'] == 'iec958') ? "selected" : "") . ">" . ALSA_OUTPUT_MODE_NAME['iec958'] . "</option>\n";
	$_alsa_plugin_and_cardnum = $_SESSION['alsa_output_mode'];
} else {
	$_select['alsa_output_mode'] .= "<option value=\"plughw\" " . (($_SESSION['alsa_output_mode'] == 'plughw') ? "selected" : "") . ">" . ALSA_OUTPUT_MODE_NAME['plughw'] . "</option>\n";
	$_select['alsa_output_mode'] .= "<option value=\"hw\" " . (($_SESSION['alsa_output_mode'] == 'hw') ? "selected" : "") . ">" . ALSA_OUTPUT_MODE_NAME['hw'] . "</option>\n";
	$_alsa_plugin_and_cardnum = $_SESSION['alsa_output_mode'] . ':' . $_SESSION['cardnum'] . ',0';
}
// Loopback
$_alsa_loopback_disable = '';
$autoClick = " onchange=\"autoClick('#btn-set-alsa-loopback');\" " . $_alsa_loopback_disable;
$_select['alsa_loopback_on']  .= "<input type=\"radio\" name=\"alsa_loopback\" id=\"toggle-alsa-loopback-1\" value=\"On\" " . (($_SESSION['alsa_loopback'] == 'On') ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['alsa_loopback_off'] .= "<input type=\"radio\" name=\"alsa_loopback\" id=\"toggle-alsa-loopback-2\" value=\"Off\" " . (($_SESSION['alsa_loopback'] == 'Off') ? "checked=\"checked\"" : "") . $autoClick . ">\n";
// Multiroom configure
$_multiroom_feat_enable = $_SESSION['feat_bitmask'] & FEAT_MULTIROOM ? '' : 'hide';

// MPD OPTIONS

// Autoplay after start
$autoClick = " onchange=\"autoClick('#btn-set-autoplay');\"";
$_select['autoplay_on']  .= "<input type=\"radio\" name=\"autoplay\" id=\"toggle-autoplay-1\" value=\"1\" " . (($_SESSION['autoplay'] == 1) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['autoplay_off'] .= "<input type=\"radio\" name=\"autoplay\" id=\"toggle-autoplay-2\" value=\"0\" " . (($_SESSION['autoplay'] == 0) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
// Metadata file
$autoClick = " onchange=\"autoClick('#btn-set-extmeta');\"";
$_select['extmeta_on']  .= "<input type=\"radio\" name=\"extmeta\" id=\"toggle-extmeta-1\" value=\"1\" " . (($_SESSION['extmeta'] == 1) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['extmeta_off'] .= "<input type=\"radio\" name=\"extmeta\" id=\"toggle-extmeta-2\" value=\"0\" " . (($_SESSION['extmeta'] == 0) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
// Auto-shuffle
$autoClick = " onchange=\"autoClick('#btn-set-ashufflesvc');\"";
$_select['ashufflesvc_on']  .= "<input type=\"radio\" name=\"ashufflesvc\" id=\"toggle-ashufflesvc-1\" value=\"1\" " . (($_SESSION['ashufflesvc'] == 1) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['ashufflesvc_off'] .= "<input type=\"radio\" name=\"ashufflesvc\" id=\"toggle-ashufflesvc-2\" value=\"0\" " . (($_SESSION['ashufflesvc'] == 0) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['ashuffle_mode'] .= "<option value=\"Track\" " . (($_SESSION['ashuffle_mode'] == 'Track') ? "selected" : "") . ">Track</option>\n";
$_select['ashuffle_mode'] .= "<option value=\"Album\" " . (($_SESSION['ashuffle_mode'] == 'Album') ? "selected" : "") . ">Album</option>\n";
$_ashuffle_filter = str_replace('"', '&quot;', $_SESSION['ashuffle_filter']);
// Volume step limit
$_select['volume_step_limit'] .= "<option value=\"2\" " . (($_SESSION['volume_step_limit'] == '2') ? "selected" : "") . ">2</option>\n";
$_select['volume_step_limit'] .= "<option value=\"5\" " . (($_SESSION['volume_step_limit'] == '5') ? "selected" : "") . ">5</option>\n";
$_select['volume_step_limit'] .= "<option value=\"10\" " . (($_SESSION['volume_step_limit'] == '10') ? "selected" : "") . ">10</option>\n";
// Max MPD volume
$_volume_mpd_max = $_SESSION['volume_mpd_max'];
// Display dB volume
$autoClick = " onchange=\"autoClick('#btn-set-volume-db-display');\"";
$_select['volume_db_display_on']  .= "<input type=\"radio\" name=\"volume_db_display\" id=\"toggle-volume-db-display-1\" value=\"1\" " . (($_SESSION['volume_db_display'] == 1) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['volume_db_display_off'] .= "<input type=\"radio\" name=\"volume_db_display\" id=\"toggle-volume-db-display-2\" value=\"0\" " . (($_SESSION['volume_db_display'] == 0) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
// USB volume knob
$autoClick = " onchange=\"autoClick('#btn-set-usb-volknob');\"";
$_select['usb_volknob_on']  .= "<input type=\"radio\" name=\"usb_volknob\" id=\"toggle-usb-volknob-1\" value=\"1\" " . (($_SESSION['usb_volknob'] == 1) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['usb_volknob_off'] .= "<input type=\"radio\" name=\"usb_volknob\" id=\"toggle-usb-volknob-2\" value=\"0\" " . (($_SESSION['usb_volknob'] == 0) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
// Rotary encoder
$autoClick = " onchange=\"autoClick('#btn-set-rotaryenc');\"";
$_select['rotaryenc_on']  .= "<input type=\"radio\" name=\"rotaryenc\" id=\"toggle-rotaryenc-1\" value=\"1\" " . (($_SESSION['rotaryenc'] == 1) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['rotaryenc_off'] .= "<input type=\"radio\" name=\"rotaryenc\" id=\"toggle-rotaryenc-2\" value=\"0\" " . (($_SESSION['rotaryenc'] == 0) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['rotenc_params'] = $_SESSION['rotenc_params'];
// Crossfade
$_mpdcrossfade = $_SESSION['mpdcrossfade'];
// Configure DSP buttons
if ($_SESSION['audioout'] == 'Local' &&
	$_SESSION['multiroom_tx'] == 'Off' &&
	$_SESSION['multiroom_rx'] != 'On') {
	// Only one DSP'can be on
	$_invpolarity_set_disabled = ($_SESSION['crossfeed'] != 'Off' || $_SESSION['eqfa12p'] != 'Off' || $_SESSION['alsaequal'] != 'Off' || $_SESSION['camilladsp'] != 'off') ? 'disabled' : '';
	$_crossfeed_set_disabled = ($_SESSION['invert_polarity'] != '0' || $_SESSION['eqfa12p'] != 'Off' || $_SESSION['alsaequal'] != 'Off' || $_SESSION['camilladsp'] != 'off') ? 'disabled' : '';
	$_eqfa12p_set_disabled = ($_SESSION['invert_polarity'] != '0' || $_SESSION['crossfeed'] != 'Off' || $_SESSION['alsaequal'] != 'Off' || $_SESSION['camilladsp'] != 'off') ? 'disabled' : '';
	$_alsaequal_set_disabled = ($_SESSION['invert_polarity'] != '0' || $_SESSION['crossfeed'] != 'Off' || $_SESSION['eqfa12p'] != 'Off' || $_SESSION['camilladsp'] != 'off') ? 'disabled' : '';
	$piModel = substr($_SESSION['hdwrrev'], 3, 1);
	$piName = $_SESSION['hdwrrev'];
	$cmModel = substr($_SESSION['hdwrrev'], 3, 3); // Generic Pi-CM3+, Pi-CM4 for future use
	// CamillaDSP can only be used on 64-bit capable ARM7
	if (
		strpos($piName, 'Pi-Zero 2') !== false ||
		$piName == 'Allo USBridge SIG [CM3+ Lite 1GB v1.0]' ||
		$piName == 'Pi-2B 1.2 1GB' ||
		$piModel >= 3
	) {
		$_camilladsp_set_disabled = ($_SESSION['invert_polarity'] != '0' || $_SESSION['crossfeed'] != 'Off' || $_SESSION['eqfa12p'] != 'Off' || $_SESSION['alsaequal'] != 'Off') ? 'disabled' : '';
	} else {
		$_camilladsp_set_disabled = 'disabled';
	}
} else {
	// Don't allow any DSP to be set for:
	// Bluetooth speaker, Multiroom Sender/Receiver On or ALSA output mode "Pure Direct"
	$_invpolarity_set_disabled = 'disabled';
	$_crossfeed_set_disabled = 'disabled';
	$_eqfa12p_set_disabled = 'disabled';
	$_alsaequal_set_disabled = 'disabled';
	$_camilladsp_set_disabled = 'disabled';
}

// Polarity inversion
$autoClick = " onchange=\"autoClick('#btn-set-invert-polarity');\" " . $_invpolarity_set_disabled;
$_select['invert_polarity_on']  .= "<input type=\"radio\" name=\"invert_polarity\" id=\"toggle-invert-polarity-1\" value=\"1\" " . (($_SESSION['invert_polarity'] == 1) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['invert_polarity_off'] .= "<input type=\"radio\" name=\"invert_polarity\" id=\"toggle-invert-polarity-2\" value=\"0\" " . (($_SESSION['invert_polarity'] == 0) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
// Crossfeed
$_select['crossfeed'] .= "<option value=\"Off\" " . (($_SESSION['crossfeed'] == 'Off' OR $_SESSION['crossfeed'] == '') ? "selected" : "") . ">Off</option>\n";
if ($_crossfeed_set_disabled == '') {
	$_select['crossfeed'] .= "<option value=\"700 3.0\" " . (($_SESSION['crossfeed'] == '700 3.0') ? "selected" : "") . ">700 Hz 3.0 dB</option>\n";
	$_select['crossfeed'] .= "<option value=\"700 4.5\" " . (($_SESSION['crossfeed'] == '700 4.5') ? "selected" : "") . ">700 Hz 4.5 dB</option>\n";
	$_select['crossfeed'] .= "<option value=\"800 6.0\" " . (($_SESSION['crossfeed'] == '800 6.0') ? "selected" : "") . ">800 Hz 6.0 dB</option>\n";
	$_select['crossfeed'] .= "<option value=\"650 10.0\" " . (($_SESSION['crossfeed'] == '650 10.0') ? "selected" : "") . ">650 Hz 10.0 dB</option>\n";
}
// HTTP streaming server
$autoClick = " onchange=\"autoClick('#btn-set-mpd-httpd');\"";
$_select['mpd_httpd_on']  .= "<input type=\"radio\" name=\"mpd_httpd\" id=\"toggle-mpd-httpd-1\" value=\"1\" " . (($_SESSION['mpd_httpd'] == 1) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
$_select['mpd_httpd_off'] .= "<input type=\"radio\" name=\"mpd_httpd\" id=\"toggle-mpd-httpd-2\" value=\"0\" " . (($_SESSION['mpd_httpd'] == 0) ? "checked=\"checked\"" : "") . $autoClick . ">\n";
// Port
$_mpd_httpd_port = $_SESSION['mpd_httpd_port'];
// Encoder
$_select['mpd_httpd_encoder'] .= "<option value=\"flac\" " . (($_SESSION['mpd_httpd_encoder'] == 'flac') ? "selected" : "") . ">FLAC</option>\n";
$_select['mpd_httpd_encoder'] .= "<option value=\"lame\" " . (($_SESSION['mpd_httpd_encoder'] == 'lame') ? "selected" : "") . ">LAME (MP3)</option>\n";

// EQUALIZERS

// CamillaDSP
$configs = $cdsp->getAvailableConfigs();
foreach ($configs as $config_file=>$config_name) {
	$selected = ($_SESSION['camilladsp'] == $config_file) ? 'selected' : '';
	$_select['cdsp_mode'] .= sprintf("<option value='%s' %s>%s</option>\n", $config_file, $selected, ucfirst($config_name));
}
// CamillaDSP 2 config description
$_config_description = $cdsp->getConfigDescription($_SESSION['camilladsp']);

// Check if the config file is valid
if ($_SESSION['camilladsp'] != 'off' && $_SESSION['camilladsp'] != 'custom') {
	$result = $cdsp->checkConfigFile($_SESSION['camilladsp']);
	$msg = implode('<br>', $result['msg']);
	if ($result['valid'] == CDSP_CHECK_NOTFOUND) {
		$_config_check = '<span><span style="color: red">&#10007;</span> ' . $msg . '</span>';
	} else if ($result['valid'] == CDSP_CHECK_VALID) {
		$_config_check = '<span><span style="color: green">&check;</span> ' . $msg . '</span>';
	} else {
		$_config_check = '<span><span style="color: red">&#10007;</span> ' . $msg . '</span>';
	}
}
// Parametric equalizer
$eqfa12p = Eqp12($dbh);
$presets = $eqfa12p->getPresets();
$array = array();
$array[0] = 'Off';
$curveList = $_eqfa12p_set_disabled == '' ? array_replace($array, $presets) : $array;
$curve_selected_id = $eqfa12p->getActivePresetIndex();
foreach ($curveList as $key=>$curveName) {
	$selected = ($key == $curve_selected_id) ? 'selected' : '';
	$_select['eqfa12p'] .= sprintf('<option value="%s" %s>%s</option>\n', $key, $selected, $curveName);
}
unset($eqfa12p);
// Graphic equalizer
$result = sqlQuery('SELECT curve_name FROM cfg_eqalsa', $dbh);
$array = array();
$array[0]['curve_name'] = 'Off';
$curveList = $_alsaequal_set_disabled == '' ? array_merge($array, $result) : $array;
foreach ($curveList as $curve) {
	$curveName = $curve['curve_name'];
	$selected = ($_SESSION['alsaequal'] == $curve['curve_name']) ? 'selected' : '';
	$_select['alsaequal'] .= sprintf('<option value="%s" %s>%s</option>\n', $curve['curve_name'], $selected, $curveName);
}

waitWorker('snd-config');

$tpl = "snd-config.html";
$section = basename(__FILE__, '.php');
storeBackLink($section, $tpl);

include('header.php');
eval("echoTemplate(\"" . getTemplate("templates/$tpl") . "\");");
include('footer.php');
