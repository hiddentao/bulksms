<?php


/**
 * A Bulk SMS API (internally uses TextAnywhere service).
 */
class BulkSMS {

    const GATEWAY_URL = 'https://www.textapp.net/webservice/httpservice.aspx';

    private $clientLoginId = null;
    private $clientPassword = null;


    /**
     * Constructor.
     * @param $clientLoginId TextAnywhere client login id.
     * @param $clientPassword TextAnywhere client password.
     */
    public function __construct($clientLoginId, $clientPassword) {
        $this->clientLoginId = $clientLoginId;
        $this->clientPassword = $clientPassword;
    }


    /**
     * Send SMSs to the given numbers in test mode.
     *
     * In test mode the message never actually get send by the third-party service.
     *
     * This returns an array with the contents:
     *
     * 'summary' => array(
     *   'pass' => number passsed,
     *   'fail' => number failed
     * ),
     * 'breakdown' => array(
     *   array('number' => phone number, 'pass' => TRUE or FALSE),
     *   array('number' => phone number, 'pass' => TRUE or FALSE),
     *   ...
     * )
     *
     * @param array $numbers
     * @param $msg
     * @throws BulkSMSException if sending fails for whatever reason.
     */
    public function sendTest(array $numbers, $msg) {
        return $this->send($numbers, $msg, TRUE);
    }

    /**
     * Send SMSs to the given numbers.
     *
     * This returns an array with the contents:
     *
     * 'summary' => array(
     *   'pass' => number passsed,
     *   'fail' => number failed
     * ),
     * 'breakdown' => array(
     *   array('number' => phone number, 'pass' => TRUE or FALSE),
     *   array('number' => phone number, 'pass' => TRUE or FALSE),
     *   ...
     * )
     *
     * @param array $numbers
     * @param $msg
     * @throws BulkSMSException if sending fails for whatever reason.
     */
    public function sendLive(array $numbers, $msg) {
        return $this->send($numbers, $msg, FALSE);
    }

    
    private function send(array $numbers, $msg, $testing) {

        if (160 < strlen($msg))
            throw new BulkSMSException('Message cannot be longer than 160 characters.');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::GATEWAY_URL);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_POST, true);
        
        $params = array(
            'externalLogin' => $this->clientLoginId,
            'password' => $this->clientPassword,
            'clientBillingReference' => time(),
            'clientMessageReference' => time(),
            'originator' => '7city',
            'replyMethodID' => 1,
            'returnCSVString' => 'false',
            'destinations' => implode(',',array_map(array(&$this, 'sanitiseMobileNumber'),$numbers)),
            'body' => $msg,
            'validity' => 1,
            'characterSetID' => 2,
        );
        $params['method'] = ($testing ? 'testsendsms' : 'sendsms');
        $poststr = '';
        foreach ($params as $key => $value) {
            $poststr .= strtolower($key) . '=' . urlencode($value) . '&';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, rtrim($poststr, '&'));

        $xmlresponse = curl_exec($ch);
        curl_close($ch);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(strtolower($xmlresponse));
        if (!$xml) {
            $errstr = '';
            foreach(libxml_get_errors() as $error) {
                $errstr .= " [$error->message]";
            }
            libxml_use_internal_errors(false);
            throw new BulkSMSException(t('Error parsing XML: @error ... @r',array('@error' => $errstr, '@r' => $xmlresponse)));
        }
        libxml_use_internal_errors(false);

        // check for main ok
        if ('transaction ok' != strtolower(trim($xml->transaction->description))) {
            throw new BulkSMSException($xml->transaction->description);
        }

        // count results
        $results = array(
            'summary' => array(
                'pass' => 0,
                'fail' => 0
                ),
            'breakdown' => array()
        );
        foreach ($xml->destinations->children() as $d) {
            $results['breakdown'][] = array('number' => $d->number, 'pass' => (1 == $d->code));
            if (1 == $d->code)
                $results['summary']['pass']++;
            else
                $results['summary']['fail']++;
        }

        return $results;
    }


    /**
     * Sanitize given mobile number so that it can be sent in a request.
     * @param $number
     * @return sanitized number.
     */
    public function sanitiseMobileNumber($number) {
        return preg_replace('/\s+/','',$number);
    }
}


class BulkSMSException extends Exception {
    public function __construct($message)
    {
        parent::__construct('[BulkSMS] ' . $message);
    }

}
