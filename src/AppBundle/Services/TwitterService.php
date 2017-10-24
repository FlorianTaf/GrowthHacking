<?php

namespace AppBundle\Services;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TwitterService
{

    protected $em;
    protected $schema;

    public function __construct($doctrine, $container)
    {
        $this->em = $doctrine->getManager();
        $this->container = $container;
    }
    
    
    /**
     * @desc:
     *  - Renvoie une liste de tweets
     * @params:
     *  -  (string) "Olive oil" $search
     *  -  (string) "2017/05/01" dateDebut
     *  -  (string) "2018/05/01" dateFin
     */
    public function search($search, $since_id, $max_id, $bearer=0){
        if($bearer==0)
            $bearer = $this->getBearer();

        $searchCurl = curl_init();
        
        //TODO: url encode string;
        $search = urlencode($search);
        
        $count = $this->container->getParameter("tweets_per_search");
        curl_setopt_array($searchCurl, array(
          CURLOPT_URL => "https://api.twitter.com/1.1/search/tweets.json?q=$search&count=$count",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "GET",
          CURLOPT_HTTPHEADER => array(
            "authorization: Bearer $bearer",
            "cache-control: no-cache",
            "content-type: application/x-www-form-urlencoded"
          ),
        ));

        $response = curl_exec($searchCurl);
        $err = curl_error($searchCurl);
        curl_close($searchCurl);
        
        if ($err) {
          echo "cURL Error #:" . $err;
        } else {
          //echo $response;
        }

        $tweets = json_decode($response);
        if(!empty($tweets)){
            if(property_exists($tweets,"statuses")){
                $tweets = $tweets->statuses;
            }
            else{
                $tweets = array();
            }
        }
        return $tweets;
    }
    
    
    /*
     *  @desc:
     *      -   Appel à l'API Tweeter pour obtenir une OAuth V2
     */
    public function getBearer(){
        
        $curl = curl_init();
        $basic = base64_encode($this->container->getParameter('twitter_consumer_key').":".$this->container->getParameter('twitter_consumer_secret'));

        curl_setopt_array($curl, array(
          CURLOPT_URL => "https://api.twitter.com/oauth2/token",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_POSTFIELDS => "grant_type=client_credentials",
          CURLOPT_HTTPHEADER => array(
            "authorization: Basic $basic",
            "cache-control: no-cache",
            "content-type: application/x-www-form-urlencoded"
          ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        
        curl_close($curl);
        
        if ($err) {
            //echo "cURL Error #:" . $err;
        } else {
          //echo $response;
        }
        $response = json_decode($response);
        $token = (string) $response->access_token;

        return $token;
        
    }
}