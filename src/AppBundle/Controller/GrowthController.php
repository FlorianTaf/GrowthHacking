<?php

namespace AppBundle\Controller;

use AppBundle\Entity\User;
use BC\BUNDLE\Controller\JsonController;
use AppBundle\Entity\GrowthData;
use Facebook\Facebook;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GrowthController extends Controller
{
    /*
     * desc:
     * - Return a view with all the page found on fb with the keyword
     */
    public function listPagesAction(Request $request) {
        $token = $request->request->get("token");
        $keyword = $request->request->get("keyword");
        $pages = $request->request->get("pages");

        //Initialize FB var
        $fb = $this->getFacebookApi();

        /*
         * Request : get pages by keyword
         */
        $request = $fb->request(
            'GET',
            '/search',
            array(
                'q' => $keyword,
                'type' => 'page'
            )
        );
        $request->setAccessToken($token);

        // Send the request to Graph
        $response = $fb->getClient()->sendRequest($request);
        $tabResult= $response->getGraphEdge()->asArray();

        //Check if pages are in database for the user logged in
        $userPages = $this->getUserPagesRepository($pages, 'facebook', 0);

        $webSiteIds = array();
        foreach ($userPages as $userPage) {
            $webSiteIds[] = $userPage->getWebSiteId();
        }

        $pagesArray = array();
        foreach ($tabResult as $result) {
            if (in_array($result['id'], $webSiteIds)) {
                $pagesArray['id'] = $result['id'];
            }
        }

        if (count($tabResult) == 0) {
            $result = $this->renderView('AppBundle:Templates:pagesFacebookFound.html.twig', array(
                'tabResult'=>$tabResult));
        } else {
            $result = $this->renderView('AppBundle:Templates:pagesFacebookFound.html.twig', array(
                'tabResult'=>$tabResult,
                'pagesVerifArray'=>$pagesArray));
        }

        return new JsonResponse(
            array(
                'success'=>true,
                'token'=>$token,
                'keyword'=>$keyword,
                'result'=>$result,
            )
        );
    }

    /*
     * @desc:
     * - Return an array with the field's value of each entity
     */
    private function makeFieldArray($entities, $nameField) {
        $result = array();

        foreach($entities as $entity) {
            $result[] = $entity->{'get'.ucfirst($nameField)}();
        }

        return $result;
    }

    /*
     * @desc:
     * - Add a new page in database
     */
    public function addPageAction(Request $request)
    {
        $id = $request->request->get('id');
        $email = $request->request->get("email");

        $em = $this->getDoctrine()->getManager();
        $entityExist = $em->getRepository('AppBundle:GrowthData')->findOneBy(array('websiteId'=>$id));
        $user = $em->getRepository('AppBundle:User')->findOneBy(array('username' => $email));

        //verification
        if($entityExist) {
            //If it was a softDelete at 1
            if($entityExist->getSoftDelete() == 1) {
                $entityExist->setSoftDelete(0); //set it to 0
                $user->addPage($entityExist);
                $msg = "Page déjà ajoutée -> softDelete set to 0";
                $em->flush();
            }else
                $msg = "Page déjà ajoutée -> pas d'ajout";
        } else {
            $entity = new GrowthData();
            $name = $request->request->get('name');

            //set fields with values
            $entity->setWebsiteId($id);
            $entity->setName($name);
            $entity->setType('page');
            $entity->setWebsite('facebook');
            $entity->setsoftDelete(0);
            $entity->setIsContacted(0);
            $user->addPage($entity);

            //save
            $em->persist($entity);
            $em->flush();

            $msg = "Page ajoutée";
        }

        return new JsonResponse(array('datas' => array(), 'messages' => $msg));
    }

    /*
     * desc:
     * - Delete a page in the database
     */
    public function deletePageAction(Request $request){
        $id = $request->request->get('id');
        $email = $request->request->get('email');
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:GrowthData')->findOneBy(array('websiteId'=>$id));
        $user = $em->getRepository('AppBundle:User')->findOneBy(array('username' => $email));

        if ($entity) {
            $success = 200;
            $msg = "Page supprimée";
            $entity->setsoftDelete(1);
            $user->removePage($entity);
            $em->persist($entity);
            $em->flush();
        } else {
            $msg = "Erreur";
            $success = 500;
        }

        return new JsonResponse(array('code' => $success, 'datas' => array(), 'messages' => $msg));
    }

    /*
     * @desc:
     * - Return a view with new users
     */
    public function listUsersAction(Request $request) {
        $token = $request->request->get("token");
        $dataRepository = $this->getDoctrine()->getRepository('AppBundle:GrowthData');

        //Initialize FB var
        $fb = $this->getFacebookApi();

        //Get the array of pages' facebookIds
        $pageQuery = $dataRepository->createQueryBuilder('e')
            ->where('e.type like :type')
            ->setParameter('type', '%page%')
            ->andWhere('e.website like :website')
            ->setParameter('website', '%facebook%')
            ->andWhere('e.softDelete = 0')
            ->getQuery();
        $pagesArray = $pageQuery->getResult();
        $pagesIdArray = $this->makeFieldArray($pagesArray, 'websiteId');

        /*
         * Request 1 : get feed from pages in database
         */
        $feedArray = array();

        foreach($pagesIdArray as $pageId){
            $requestFeed = $fb->request(
                'GET',
                '/'.$pageId.'/feed'
            );
            $requestFeed->setAccessToken($token);

            // Send the request to Graph
            $response = $fb->getClient()->sendRequest($requestFeed);
            $tabResultFeed= $response->getGraphEdge()->asArray();

            $feedArray = array_merge($feedArray, $tabResultFeed);
        }
        //Make an array with only the feed's id
        $feedsIdArray = array();
        foreach($feedArray as $feed) {
            $feedsIdArray[] = $feed['id'];
        }

        /*
         * Request 2 : get user who likes the feed selected
         */
        $userArray = array();

        foreach($feedsIdArray as $feedId){
            $requestUser = $fb->request(
                'GET',
                '/'.$feedId.'/likes'
            );
            $requestUser->setAccessToken($token);

            // Send the request to Graph
            $response = $fb->getClient()->sendRequest($requestUser);
            $tabResultUser= $response->getGraphEdge()->asArray();

            $userArray = array_merge($userArray, $tabResultUser);
        }
        //Make an array with the user's id, name and control if the user is not already in
        $usersIdArray = array();
        $listUserId = array(); //just to check id
        foreach($userArray as $user) {
            if(!in_array($user['id'], $listUserId)) {
                $usersIdArray[] = array('name'=>$user['name'], 'id'=>$user['id']);
                $listUserId[] = $user['id'];
            }
        }
        //Array with all the users contacted
        $contactedUsersArray = $this->getDoctrine()->getManager()->getRepository('AppBundle:GrowthData')->findBy(array('type'=>"user", 'softDelete'=>0, 'website'=>'facebook', 'isContacted'=>1));
        $contactedUsersArray = $this->makeFieldArray($contactedUsersArray, 'websiteId');

        /*
         * Render the result in a view
         */
        $result = $this->renderView('AppBundle:Templates:usersFacebookAdd.html.twig', array('userArray'=>$usersIdArray, 'contactedUsers'=>$contactedUsersArray));

        return new JsonResponse(
            array(
                'success'=>true,
                'token'=>$token,
                'result'=>$result,
            )
        );
    }

    /*
     * @desc:
     * - Empty all pages in database
     */
    public function emptyAllAction(Request $request) {
        $email = $request->request->get("email");
        $em = $this->getDoctrine()->getManager();
        $dataRepository = $em->getRepository('AppBundle:GrowthData');
        $user = $em->getRepository('AppBundle:User')->findOneBy(array('username' => $email));

        $userPages = $user->getPages();
        foreach ($userPages as $userPage) {
            $userPage->setSoftDelete(1);
        }

        /*
        $pageQuery = $dataRepository->deleteAllAddedPages();
        $pagesArray = $pageQuery->getResult();

        foreach($pagesArray as $pages) {
            $pages->setSoftDelete(1);
            $em->persist($pages);
        }
        */
        $em->flush();

        return new JsonResponse(
            array(
                'success'=>true,
                'msg'=>'Pages set to softDelete 1'
            )
        );
    }

    /*
     * @desc:
     * - List all added pages in database
     */
    public function listAddedPagesAction(Request $request) {
        $pages = $request->request->get('pages');
        //Get the array of pages
        $pagesArray = $this->getUserPagesRepository($pages, 'facebook', 0);

        $resultArray = array();
        foreach ($pagesArray as $page) {
            $resultArray[] = array('id'=>$page->getWebsiteId(), 'name'=>$page->getName());
        }

        $resultView = $this->renderView('AppBundle:Templates:pagesFacebookAdded.html.twig', array('tabResult'=>$resultArray));

        return new JsonResponse(
            array(
                'success'=>true,
                'result'=>$resultView,
            )
        );
    }

    /*
     * @desc:
     * - Add a new fb user in database
     */
    public function contactUserAction(Request $request) {
        $id = $request->request->get('id');

        $entityExist = $this->getDoctrine()->getManager()->getRepository('AppBundle:GrowthData')->findOneBy(array('websiteId'=>$id, 'website'=>'facebook'));

        //verification
        if($entityExist && $entityExist->getType()!='page') {
            //If it was a softDelete at 1
            if($entityExist->getSoftDelete() == 1) {
                $entityExist->setSoftDelete(0); //set it to 0
                $msg = "User déjà contacté -> softDelete set to 0";
            }else
                $msg = "User déjà contacté -> pas d'ajout";

            //save
            $em = $this->getDoctrine()->getManager();
            $em->persist($entityExist);
            $em->flush();
        } else {
            $entity = new GrowthData();
            $name = $request->request->get('name');

            //set fields with values
            $entity->setWebsiteId($id);
            $entity->setName($name);
            $entity->setType('user');
            $entity->setWebsite('facebook');
            $entity->setsoftDelete(0);
            $entity->setIsContacted(1);

            //save
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            $msg = "User contacté";
        }

        return new JsonResponse(array('datas' => array(), 'messages' => $msg));
    }

    /*
     * @desc:
     * - List all added pages in database
     */
    public function listContactedUsersAction() {
        //Get the array of user
        $contactedUsersArray = $this->getDoctrine()->getManager()->getRepository('AppBundle:GrowthData')->findBy(array(
            'type'=>"user", 'website'=>'facebook', 'softDelete'=>0,'isContacted'=>1), array(
                'id' => 'DESC'));

        $resultArray = array();
        foreach ($contactedUsersArray as $user) {
            $resultArray[] = array('id'=>$user->getWebsiteId(), 'name'=>$user->getName());
        }

        $resultView = $this->renderView('AppBundle:Templates:usersContactedFacebook.html.twig', array('tabResult'=>$resultArray));

        return new JsonResponse(
            array(
                'success'=>true,
                'result'=>$resultView,
            )
        );
    }


    /*
     * desc:
     * - Return a view with all the user found on twitter with the keyword
     */
    public function listTwitterAction(Request $request) {
        $keyword = $request->request->get("keyword");
        $pages = $request->request->get("pages");

        //Check if pages are in database
        $pagesArray = $this->getUserPagesRepository($pages, 'twitter', 1);
        $pagesIdArray = $this->makeFieldArray($pagesArray, 'websiteId');

        //Twitter search
        $bearer = $this->container->get("twitter")->getBearer();
        ( !empty($keyword) ) ? $searchQuery = $keyword : $searchQuery = "empty";
        $tweets = $this->container->get("twitter")->search($searchQuery, "", "", $bearer);
        if(!empty($tweets)){
            foreach($tweets as $tweet){
                $tabResult[] = array("id" => $tweet->user->id, "text" => $tweet->text, "name" => $tweet->user->screen_name);
            }
        } else {
            $tabResult= array();
            $result = $this->renderView('AppBundle:Templates:usersTwitterFound.html.twig', array('tabResult'=>$tabResult));
            return new JsonResponse(
                array(
                    'success' => true,
                    'keyword' => $keyword,
                    'result' => $result
                )
            );
        }


        $result = $this->renderView('AppBundle:Templates:usersTwitterFound.html.twig', array(
            'tabResult'=>$tabResult,
            'contactedUsers'=>$pagesIdArray));

        return new JsonResponse(
            array(
                'success'=>true,
                'keyword'=>$keyword,
                'result'=>$result,
            )
        );
    }

    /*
     * @desc:
     * - List all added users from twitter in database
     */
    public function listContactedUsersTwitterAction(Request $request) {
        $pages = $request->request->get("pages");
        //Get the array of user
        $contactedUsersArray = $this->getUserPagesRepository($pages, 'twitter', 1);

        $resultArray = array();
        foreach ($contactedUsersArray as $user) {
            $resultArray[] = array('id'=>$user->getWebsiteId(), 'name'=>$user->getName(), 'info'=>$user->getInfo());
        }

        $resultView = $this->renderView('AppBundle:Templates:usersContactedTwitter.html.twig', array('tabResult'=>$resultArray));

        return new JsonResponse(
            array(
                'success'=>true,
                'result'=>$resultView,
            )
        );
    }

    /*
     * @desc:
     * - Add a new twitter user in database
     */
    public function contactTwitterUserAction(Request $request) {
        $id = $request->request->get('id');

        $entityExist = $this->getDoctrine()->getManager()->getRepository('AppBundle:GrowthData')->findOneBy(array('websiteId'=>$id, 'website'=>'twitter'));

        //verification
        if($entityExist && $entityExist->getType()=='user') {
            //If it was a softDelete at 1
            if($entityExist->getSoftDelete() == 1) {
                $entityExist->setSoftDelete(0); //set it to 0
                $msg = "User déjà contacté -> softDelete set to 0";
            }else
                $msg = "User déjà contacté -> pas d'ajout";

            //save
            $em = $this->getDoctrine()->getManager();
            $em->persist($entityExist);
            $em->flush();
        } else {
            $entity = new GrowthData();
            $name = $request->request->get('name');
            $info = $request->request->get('info');

            //set fields with values
            $entity->setWebsiteId($id);
            $entity->setName($name);
            $entity->setType('user');
            $entity->setWebsite('twitter');
            $entity->setInfo($info);
            $entity->setsoftDelete(0);
            $entity->setIsContacted(1);

            //save
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            $msg = "User contacté";
        }

        return new JsonResponse(array('datas' => array(), 'messages' => $msg));
    }

    public function userExistsBlueSquareAction(Request $request)
    {
        $email = $request->request->get('email');
        $data = array('email' => $email);

        $curl = curl_init();

        curl_setopt_array(
            $curl, array(
                CURLOPT_URL => 'http://webservice.bluesquare.io/user_growth_hacking',
                //CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => $data,
        ));

        $response = curl_exec($curl);
        $error = curl_error($curl);

        if ($error) {
            //echo "cURL Error #:" . $error;
        } else {
            //echo $response;
        }

        curl_close($curl);

        $response = json_decode($response);

        return new JsonResponse(
            array(
                'response' => $response,
                'mail' => $email,
        ));
    }

    public function checkUserBddAction(Request $request)
    {
        $email = $request->request->get('email');
        $em = $this->getDoctrine()->getManager();

        $emailPresent = $em->getRepository('AppBundle:User')->findOneBy(array('username' => $email));

        $response = null;
        $pages = null;

        if ($emailPresent == null) {
            $username = new User();
            $username->setUsername($email);
            $username->setDateFirstConnexion(new \DateTime());
            $em->persist($username);
            $em->flush();
        } else {
            $pagesArray = $this->getUserPagesArray($emailPresent);
        }

        return new JsonResponse(
            array(
                'pages' => $pagesArray
            )
        );
    }

    /**
     * @return Identifiants pour notre Api Facebook
     */
    private function getFacebookApi()
    {
        $fb = new Facebook([
            'app_id' => '151487542123473',
            'app_secret' => '5fb4fd9c7785c7da8a147587fea161d8',
            'default_graph_version' => 'v2.5',
        ]);
        return $fb;
    }

    /**
     * @param $username
     * @return Les pages concernant l'utilisateur en paramètre (sous forme de tableau)
     */
    private function getUserPagesArray($username)
    {
        $userPages = $username->getPages();
        $userPagesArray = array();
        foreach ($userPages as $userPage) {
            $userPagesArray[] = $userPage->getWebSiteId();
        }

        return $userPagesArray;
    }

    //Simple requête repository, mais qui va nous servir à plusieurs endroits
    public function getUserPagesRepository($pages, $website, $isContacted)
    {
        $userPages = $this->getDoctrine()->getRepository('AppBundle:GrowthData')->findBy(array(
            'websiteId' => $pages,
            'website' => $website,
            'softDelete' => 0,
            'isContacted' => $isContacted
        ));
        return $userPages;
    }
}
