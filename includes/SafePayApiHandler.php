<?php

// Exit if accessed directly
if (!defined('ABSPATH'))
{
    exit; 
}

/**
 * Sends API requests to Safepay.
 */
class SafepayAPIHandler {


    public function buildRequestParams(object $data): array
    {
        // Ensure that essential fields are present
        if (!isset($data->securedKey)) {
            throw new InvalidArgumentException('Secured key is required.');
        }
    
        if (!isset($data->params)) {
            throw new InvalidArgumentException('Params are required.');
        }
    
        return array(
            'method' => $data->method ?? 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-SFPY-MERCHANT-SECRET' => $data->securedKey,
            ),
            'body' => json_encode($data->params),
        );
    }
 
    function build_metadata_payload($params, $securedKey)
    {
        // Validate and sanitize the input parameters
        if (empty($params['source']) || empty($params['order_id'])) {
            return new WP_Error('invalid_params', 'Source or Order ID missing', array('status' => 400));
        }
  
        // Create the metadata payload
        $meta_payload = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-SFPY-MERCHANT-SECRET' => $securedKey,
            ),
            'body'=> json_encode($params),
        );

        return $meta_payload;
    }
    function make_transaction_request($baseURL, $args)
    {
        // Perform the POST request
        return wp_remote_post(esc_url_raw($baseURL . SafepayEndpoints::TRANSACTION_ENDPOINT->value), $args);
    }
    public  function fetchToken($securedKey,$params,$baseURL){
        $payload = (object) [
            'method'=>'POST',
            'securedKey' => $securedKey,
            'params'=> $params,
        ];
        $args = self::buildRequestParams($payload);
        
        
        $metaDataEndpoint = esc_url_raw($baseURL.SafepayEndpoints::META_DATA_ENDPOINT->value);

        $responseData = wp_remote_post(esc_url_raw($baseURL.SafepayEndpoints::TOKEN_ENDPOINT->value), $args);
       
        if (is_wp_error($responseData)) {
            return array(false, $responseData->get_error_message());
        } else {
            $userToken = json_decode($responseData['body'], true);
            $code = $responseData['response']['code'];
        }
        $response = self::make_transaction_request($baseURL, $args);

    
        if (is_wp_error($response)) {
           
            return array(false, $response->get_error_message());
        } else {
            $result = json_decode($response['body'], true);
       

            $code = $response['response']['code'];

            if ($code === 201) {
                $trackerToken = $result['data']['tracker']['token'] ?? '';
                $metaPayload = self::build_metadata_payload($params,$securedKey);
                $responseData = self::make_metadata_request($metaDataEndpoint,$trackerToken, $metaPayload);
             
                return array(true, $userToken, $result);
            } else {
                return array(false, null,$code);
            }
        }
    }

    function make_metadata_request($metaDataEndpoint, $trackerToken, $meta_payload) {
        // Sanitize and build the URL
        $sanitizedToken = sanitize_text_field($trackerToken);
        $endpointUrl = esc_url_raw($metaDataEndpoint . '/' . $sanitizedToken . '/metadata');
        // Perform the POST request
        $response = wp_remote_post($endpointUrl, $meta_payload);
    
        return json_decode($response['body'], true);  // Optionally decode if JSON response
    }
}