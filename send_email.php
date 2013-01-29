<?php
//
//  Copyright (c) 2009-2010, LoveMachine Inc.
//  All Rights Reserved.
//  http://www.lovemachineinc.com
//

require_once('config.php');
require_once('html2text.inc');
require_once('smslist.php');
require_once('functions.php');

/*  send_email
 *
 *  send email using local mail()
 */
function send_email($to, $subject, $html, $plain = null, $headers = array()) {
    //Validate arguments
    $html= replaceEncodedNewLinesWithBr($html);
    if (empty($to) ||
        empty($subject) ||
        (empty($html) && empty($plain) ||
        !is_array($headers))) {
        error_log("attempted to send an empty or misconfigured message");
        return false;
    }
    
    $nameAndAddressRegex = '/(.*)<(.*)>/';
    $toIncludesNameAndAddress = preg_match($nameAndAddressRegex, $to, $toDetails);
    
    if ($toIncludesNameAndAddress) {
        $toName = $toDetails[1];
        $toAddress = $toDetails[2];
    } else {
        $toName = $to;
        $toAddress = $to;
    }

    // If no 'From' address specified, use default
    if (empty($headers['From'])) {
        $fromName = DEFAULT_SENDER;
        $fromAddress = DEFAULT_SENDER;
    } else {
        $fromIncludesNameAndAddress = preg_match($nameAndAddressRegex, $headers['From'], $fromDetails);
        if ($fromIncludesNameAndAddress) {
            $fromName = $fromDetails[1];
            $fromAddress = str_replace(' ', '-', $fromDetails[2]);
        } else {
            $fromName = $headers['From'];
            $fromAddress = str_replace(' ', '-', $headers['From']);
        }
    }
    
    if (!empty($html)) {
        if (empty($plain)) {
            $h2t = new html2text(html_entity_decode($html, ENT_QUOTES), 75);
            $plain = $h2t->convert();
        }
    } else {
        if (empty($plain)) {
            // if both HTML & Plain bodies are empty, don't send mail
            return false;
        }
    }

    $curl = new CURLHandler();
    $postArray = array(
        'from' => $fromAddress,
        'fromname' => $fromName,
        'to' => $toAddress,
        'toname' => $toName,
        'subject' => $subject,
        'html' => $html,
        'text'=> $plain,
        'api_user' => SENDGRID_API_USER,
        'api_key' => SENDGRID_API_KEY
    );
    
    try {
        $result = $curl::Get(SENDGRID_API_URL, $postArray);
    } catch(Exception $e) {
        error_log("[ERROR] Unable to send message through SendGrid API - Exception: " . $e->getMessage());
        return false;
    }
    
    return true;
}

/* notify_sms functions
 *
 * Notify by user_id or by user object
 *
 */
function notify_sms_by_id($user_id, $smssubject, $smsbody)
{
    //Fetch phone info using user_id
    $sql = 'SELECT
             phone, country, provider, smsaddr
            FROM
              '.USERS.'
            WHERE
             id = '. mysql_real_escape_string($user_id);

    $res = mysql_query($sql);
    $phone_info = mysql_fetch_object($res);
    if (is_object($phone_info)) {
        if (! notify_sms_by_object($phone_info, $smssubject, $smsbody) ) {
            error_log("notify_sms_by_id: notify_sms_by_object failed. Not sending SMS. ${smssubject} ${smsbody} Session info: ". var_export($_SESSION));
        } else {
            return true;
        }
    } else {
        error_log("notify_sms_by_id: Query '${sql}' failed. Not sending SMS." .
                  " ${smssubject} ${smsbody} Session info: ". var_export($_SESSION));
    }
}

function objectToArray($object) {
    $dump = '$dump = '.var_export($object, true);
    $dump = preg_replace('/object\([^\)]+\)#[0-9]+ /', 'array', $dump);
    $dump = preg_replace('/[a-zA-Z_]+::__set_state/', '', $dump);
    $dump = str_replace(':protected', '', $dump);
    $dump = str_replace(':private', '', $dump);
    $dump .= ';';
    eval($dump);
    return $dump;
}


function notify_sms_by_object($user_obj, $smssubject, $smsbody, $force_twilio = false)
{
    global $smslist;
    $smsbody    = strip_tags($smsbody);

    if (is_array($user_obj)) {
        //error_log("smsbyobject already an array");
        $user_array=$user_obj;
    } elseif (is_object($user_obj)) {
        //error_log("smsbyobject convert to array");
        $user_array=objectToArray($user_obj);
    } else {
        error_log("Notify_sms_by_object does not know how to handle \$user_obj:".gettype($user_obj));
        return false;
    }
    $user = new User();
	$user->findUserById($user_array['id']);
    if ($user->isTwilioSupported($force_twilio)) {
        require_once(dirname(__FILE__) . '/lib/wl-twilio.php');
        $Twilio = new WLTwilio();
        $message = html_entity_decode($smssubject . ': ' . $smsbody, ENT_QUOTES);
        $ret = $Twilio->send_sms($user_array['phone'], $message);
        if ($ret === null && !$force_twilio) {
            // Twilio told us that phone number is invalid
            // lets update the phone_rejected field for user
            $user->setPhone_rejected(date('Y-m-d H:i'));
            $user->save();
        }
        return $ret;
    } else {
        if (array_key_exists('smsaddr',$user_array) && !empty($user_array['smsaddr'])) {
            $smsaddr = $user_array['smsaddr'];
        } else {
            $provider = $user_array['provider'];
            if ( !empty($provider)) {
                if ($provider{0} != '+') {
                    if (   array_key_exists('phone',$user_array)
                        && !empty($user_array['phone'])
                        && array_key_exists('country',$user_array)
                        && !empty($user_array['country'])
                        && array_key_exists($user_array['country'],$smslist)
                        && !empty($smslist[$user_array['country']])
                        && array_key_exists($provider,$smslist[$user_array['country']])
                        && !empty($smslist[$user_array['country']][$provider]))
                    {
                        $smsaddr = str_replace('{n}', $user_array['phone'], $smslist[$user_array['country']][$provider]);
                    } else {
                        error_log("Unable to locate SMS path for userid: ".$user_array['id']);
                        return false;
                    }
                } else {
                    $smsaddr = substr($provider, 1);
                }
            } else {
                return false;
            }
        }
        return send_email($smsaddr,
            html_entity_decode($smssubject, ENT_QUOTES),
            '',
            html_entity_decode($smsbody, ENT_QUOTES),
            array(
                "From" => SMS_SENDER,
                "X-tag" => 'sms',
            )
        );
    }
}

/*  sendTemplateEmail - send email using email template
 *  $template - name of the template to use, for example 'confirmation'
 *  $data - array of key-value replacements for template
 */

function sendTemplateEmail($to, $template, $data = array(), $from = false){
    include (dirname(__FILE__) . "/email/en.php");

    $recipients = is_array($to) ? $to : array($to);

    $replacedTemplate = !empty($data) ?
                        templateReplace($emailTemplates[$template], $data) :
                        $emailTemplates[$template];

    $subject = $replacedTemplate['subject'];
    $html = $replacedTemplate['body'];
    $plain = !empty($replacedTemplate['plain']) ?
                $replacedTemplate['plain'] :
                null;
    $xtag  = !empty($replacedTemplate['X-tag']) ?
                $replacedTemplate['X-tag'] :
                null;

    $headers = array();
    if (!empty($xtag)) {
        $headers['X-tag'] = $xtag;
    }
    if (!empty($from)) {
        $headers['From'] = $from;
    }

    $result = null;
    foreach($recipients as $recipient){
        if (! $result = send_email($recipient, $subject, $html, $plain, $headers)) {
            error_log("send_email:Template: send_email failed");
        }
    }

    return $result;
}

/* templateReplace - function to replace all occurencies of
 * {key} with value from $replacements array
 * for example: if $replacements is array('nickname' => 'John')
 * function will replace {nickname} in $templateData array with 'John'
 */

function templateReplace($templateData, $replacements){

    foreach($templateData as &$templateIndice){
        foreach($replacements as $find => $replacement){

            $pattern = array(
                        '/\{' . preg_quote($find) . '\}/',
                        '/\{' . preg_quote(strtoupper($find)) . '\}/',
                            );
            $templateIndice = preg_replace($pattern, $replacement, $templateIndice);
        }
    }

    return $templateData;
}

