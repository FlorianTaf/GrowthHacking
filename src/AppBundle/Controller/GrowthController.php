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

        $pagesArrayId = array();
        foreach ($pages as $page) {
            $pagesArrayId[] = $page['id'];
        }

        foreach ($tabResult as $result) {
            if (in_array($result['id'], $pages)) {
                $pagesArray['id'] = $result['id'];
            }
        }

        if (count($tabResult) == 0) {
            $result = $this->renderView('AppBundle:Templates:pagesFacebookFound.html.twig', array(
                'tabResult'=>$tabResult));
        } else {
            $result = $this->renderView('AppBundle:Templates:pagesFacebookFound.html.twig', array(
                'tabResult'=>$tabResult,
                'pagesVerifArray'=>$pagesArrayId));
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
            $user->addPage($entityExist);
            $msg = "Page déjà présente";
            $em->flush();
        } else {
            $entity = new GrowthData();
            $name = $request->request->get('name');

            //set fields with values
            $entity->setWebsiteId($id);
            $entity->setName($name);
            $entity->setType('page');
            $entity->setWebsite('facebook');
            $user->addPage($entity);

            //save
            $em->persist($entity);
            $em->flush();

            $msg = "Page ajoutée";
        }

        return new JsonResponse(array(
            'datas' => array(),
            'messages' => $msg,
            'pages' => $this->getUserPagesArray($user)));
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
            $msg = "Page supprimée pour l'utilisateur";
            $user->removePage($entity);
            $em->persist($entity);
            $em->flush();
        } else {
            $msg = "Erreur";
            $success = 500;
        }

        return new JsonResponse(array(
            'code' => $success,
            'datas' => array(),
            'messages' => $msg,
            'pages' => $this->getUserPagesArray($user)));
    }

    /*
     * @desc:
     * - Return a view with new users
     */
    public function listUsersAction(Request $request) {
        $token = $request->request->get("token");
        $pages = $request->request->get("pages");

        $usersFacebookContacted = $request->request->get("usersFacebookContacted");
        $usersFacebookContactedIds = array();
        foreach ($usersFacebookContacted as $userFacebookContacted) {
            $usersFacebookContactedIds[] = $userFacebookContacted['id'];
        }

        //Initialize FB var
        $fb = $this->getFacebookApi();

        /*
         * Request 1 : get feed from pages in database
         */
        $feedArray = array();

        foreach($pages as $page){
            $requestFeed = $fb->request(
                'GET',
                '/'.$page['id'].'/feed'
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

        /*
         * Render the result in a view
         */
        $result = $this->renderView('AppBundle:Templates:usersFacebookAdd.html.twig', array(
            'userArray'=>$usersIdArray,
            'contactedUsers'=>$usersFacebookContactedIds));

        return new JsonResponse(
            array(
                'success'=>true,
                'token'=>$token,
                'result'=>$result
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
            $user->removePage($userPage);
        }

        $em->flush();

        return new JsonResponse(
            array(
                'success'=>true,
            )
        );
    }

    /*
     * @desc:
     * - List all added pages in database
     */
    public function listAddedPagesAction(Request $request) {
        $pages = $request->request->get('pages');

        $resultView = $this->renderView('AppBundle:Templates:pagesFacebookAdded.html.twig', array('tabResult'=>$pages));

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
        $email = $request->request->get("email");
        $usersFacebookContacted = $request->request->get("usersFacebookContacted");
        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository('AppBundle:User')->findOneBy(array('username' => $email));

        if (count($usersFacebookContacted) != 0) {
            foreach ($usersFacebookContacted as $userFacebookContacted) {
                if ($userFacebookContacted['id'] == $id) {
                    $msg = "User déjà contacté -> pas d'ajout";
                    return new JsonResponse(array('datas' => array(), 'messages' => $msg));
                }
            }
            //Si l'utilisateur cherché n'est pas présent, alors on en crée un nouveau
            $entity = new GrowthData();
            $name = $request->request->get('name');

            //set fields with values
            $entity->setWebsiteId($id);
            $entity->setName($name);
            $entity->setType('user');
            $entity->setWebsite('facebook');
            $em->persist($entity);

            $user->addUsersFacebookContacted($entity);
            $em->flush();

            $msg = "User contacté";
        } else {
            $entity = new GrowthData();
            $name = $request->request->get('name');

            //set fields with values
            $entity->setWebsiteId($id);
            $entity->setName($name);
            $entity->setType('user');
            $entity->setWebsite('facebook');
            $em->persist($entity);

            $user->addUsersFacebookContacted($entity);
            $em->flush();

            $msg = "User contacté";
        }

        $userPages = $this->getContactedUsersArray($user, 'facebook');


        return new JsonResponse(array(
            'datas' => array(),
            'messages' => $msg,
            'usersFacebookContacted' => $userPages));
    }

    /*
     * @desc:
     * - List all added pages in database
     */
    public function listContactedUsersAction(Request $request) {
        $usersFacebookContacted = $request->request->get("usersFacebookContacted");

        $resultView = $this->renderView('AppBundle:Templates:usersContactedFacebook.html.twig', array('tabResult'=>$usersFacebookContacted));

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
        $usersTwitterContacted = $request->request->get("usersTwitterContacted");

        // On utilise un tableau dans lequel on va juste stocker les ids (pour pouvoir comparer les ids dans Twig)
        $usersTwitterContactedIds = array();
        foreach ($usersTwitterContacted as $userTwitterContactedId) {
            $usersTwitterContactedIds[] = $userTwitterContactedId['id'];
        }

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
            'contactedUsers'=>$usersTwitterContactedIds));

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
        $usersTwitterContacted = $request->request->get("usersTwitterContacted");

        $resultView = $this->renderView('AppBundle:Templates:usersContactedTwitter.html.twig', array('tabResult'=>$usersTwitterContacted));

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
        $usersTwitterContacted = $request->request->get("usersTwitterContacted");

        $email = $request->request->get("email");
        $info = $request->request->get('info');

        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository('AppBundle:User')->findOneBy(array('username' => $email));

        if (count($usersTwitterContacted) != 0) {
            foreach ($usersTwitterContacted as $userTwitterContacted) {
                if ($userTwitterContacted['id'] == $id) {
                    $msg = "User déjà contacté -> pas d'ajout";
                    $entity = $em->getRepository('AppBundle:GrowthData')->findOnyBy(array(
                        'websiteId' => $userTwitterContacted['id'],
                        'website' => 'twitter'
                    ));
                    $user->addUsersTwitterContacted($entity);
                    $em->flush();
                    return new JsonResponse(array('datas' => array(), 'messages' => $msg));
                }
            }
            //Si l'utilisateur cherché n'est pas présent, alors on en crée un nouveau
            $entity = new GrowthData();
            $name = $request->request->get('name');

            //set fields with values
            $entity->setWebsiteId($id);
            $entity->setName($name);
            $entity->setType('user');
            $entity->setWebsite('twitter');
            $entity->setInfo($info);
            $user->addUsersTwitterContacted($entity);
            $em->persist($entity);

            $em->flush();

            $msg = "User contacté";
        } else {
            $entity = new GrowthData();
            $name = $request->request->get('name');

            //set fields with values
            $entity->setWebsiteId($id);
            $entity->setName($name);
            $entity->setType('user');
            $entity->setWebsite('twitter');
            $entity->setInfo($info);
            $user->addUsersTwitterContacted($entity);
            $em->persist($entity);

            $em->flush();

            $msg = "User contacté";
        }

        $usersTwitterContacted = $this->getContactedUsersArray($user, 'twitter');

        return new JsonResponse(array(
            'datas' => array(),
            'messages' => $msg,
            'usersTwitterContacted' => $usersTwitterContacted));
    }

    /**
     * @param Request $request
     * Requête curl qui va aller interogger l'api de bluesquare pour savoir si l'utilisateur est bien enregistré sur le site de bluesquare
     * @return JsonResponse
     */
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

    /**
     * @param Request $request
     * Retourne les pages déjà ajoutées par l'utilisateur en paramètre
     * @return JsonResponse
     *
     */
    public function checkUserBddAction(Request $request)
    {
        $email = $request->request->get('email');
        $em = $this->getDoctrine()->getManager();

        $emailPresent = $em->getRepository('AppBundle:User')->findOneBy(array('username' => $email));

        $pagesArray = null;
        $contactedTwitterUsersArray = null;
        $contactedFacebookUsersArray = null;

        if ($emailPresent == null) {
            $username = new User();
            $username->setUsername($email);
            $username->setDateFirstConnexion(new \DateTime());
            $em->persist($username);
            $em->flush();
        } else {
            $pagesArray = $this->getUserPagesArray($emailPresent);
            $contactedTwitterUsersArray = $this->getContactedUsersArray($emailPresent, 'twitter');
            $contactedFacebookUsersArray = $this->getContactedUsersArray($emailPresent, 'facebook');
        }

        return new JsonResponse(
            array(
                'pages' => $pagesArray,
                'usersTwitterContacted' => $contactedTwitterUsersArray,
                'usersFacebookContacted' => $contactedFacebookUsersArray
            )
        );
    }

    /**
     * @return Facebook pour notre Api Facebook
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
    private function getUserPagesArray($user)
    {
        $userPages = $user->getPages();
        $userPagesArray = array();
        foreach ($userPages as $userPage) {
            $userPagesArray[] = array('id'=>$userPage->getWebsiteId(), 'name'=>$userPage->getName());
        }

        return $userPagesArray;
    }

    /**
     * @param $username
     * Retourne les utilisateurs contactés concernant l'utilisateur en paramètre ainsi que le site (Facebook ou Twitter)
     * @return array
     */
    private function getContactedUsersArray($username, $website)
    {
        if ($website == 'twitter') {
            $usersContacted = $username->getUsersTwitterContacted();
        } elseif ($website == 'facebook') {
            $usersContacted = $username->getUsersFacebookContacted();
        }

        $usersContactedArray = array();
        foreach ($usersContacted as $userContacted) {
            $usersContactedArray[] = array(
                'id'=>$userContacted->getWebsiteId(),
                'name'=>$userContacted->getName(),
                'info'=>$userContacted->getInfo());
        }

        return $usersContactedArray;
    }

    //Simple requête repository, mais qui va nous servir à plusieurs endroits
    public function getUserPagesRepository($pages, $website)
    {
        $userPages = $this->getDoctrine()->getRepository('AppBundle:GrowthData')->findBy(array(
            'websiteId' => $pages,
            'website' => $website
        ));
        return $userPages;
    }
}
