<?php

namespace soapNTLM;

use \Psr\Log\LoggerInterface;

class Client extends \SoapClient
{
    private $options = array();

    private $logger = null;

    /**
     * Client constructor.
     * @param mixed $url
     * @param array $data
     * @param LoggerInterface|null $logger
     */
    public function __construct($url, $data, LoggerInterface $logger = null)
    {
        if ($logger) {
            $this->logger = $logger;
        }

        $this->options = $data;

        if (empty($data['ntlm_username']) && empty($data['ntlm_password'])) {
            parent::__construct($url, $data);
        } else {
            $this->use_ntlm = true;
            HttpStream\NTLM::$user = $data['ntlm_username'];
            HttpStream\NTLM::$password = $data['ntlm_password'];
            $urlScheme = parse_url($url, PHP_URL_SCHEME);

            stream_wrapper_unregister($urlScheme);
            if (!stream_wrapper_register($urlScheme, '\\soapNTLM\\HttpStream\\NTLM')) {
                throw new Exception("Unable to register HTTP Handler");
            }

            $time_start = microtime(true);
            parent::__construct($url, $data);

            if (!empty($this->logger) && (($end_time = microtime(true) - $time_start) > 0.1)) {
                $this->logger->debug("WSDL Timer", array("time" => $end_time, "url" => $url));
            }

            stream_wrapper_restore($urlScheme);
        }

    }

    /**
     * @param string $request
     * @param string $location
     * @param string $action
     * @param int $version
     * @param int $one_way
     * @return mixed
     */
    public function __doRequest($request, $location, $action, $version, $one_way = 0)
    {
        $this->__last_request = $request;
        $start_time = microtime(true);

        $ch = curl_init($location);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Method: POST',
            'User-Agent: PHP-SOAP-CURL',
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $action . '"',
        ));

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        if (!empty($this->options['ntlm_username']) && !empty($this->options['ntlm_password'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
            curl_setopt($ch, CURLOPT_USERPWD, $this->options['ntlm_username'] . ':' . $this->options['ntlm_password']);
        }

        $response = curl_exec($ch);

        if (!empty($this->logger)) {
            // Log as an error if the curl call isn't a success
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $log_func = $http_status == 200 ? 'debug' : 'error';

            // Log the call
            $this->logger->$log_func("SoapCall: " . $action, [
                "Location" => $location,
                "HttpStatus" => $http_status,
                "Request" => $request,
                "Response" => $response,
                "RequestTime" => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                "RequestConnectTime" => curl_getinfo($ch, CURLINFO_CONNECT_TIME),
                "Time" => microtime(true) - $start_time
            ]);
        }

        return $response;
    }
}
