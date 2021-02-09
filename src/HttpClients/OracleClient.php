<?php
/**
 * @author Olivier Lamy-Canuel <olivier.lamy-canuel@vanillaforums.com>
 * @copyright 2009-2021 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpClient;
use Garden\Http\HttpHandlerInterface;

/**
 * The Oracle API.
 */
class OracleClient extends HttpClient {

    /**
     * @var string
     */
    private $token;

    private $products = [];

    const maxProductSize = 10;
    const knowledgeBaseID = 1;

    /**
     * OracleClient constructor.
     *
     * @param string $baseUrl
     * @param HttpHandlerInterface|null $handler
     */
    public function __construct(string $baseUrl = "", HttpHandlerInterface $handler = null) {
        parent::__construct($baseUrl, $handler);
        $this->setThrowExceptions(true);
        $this->setDefaultHeader("osvc-crest-application-context", "1");
    }

    /**
     * Set api credentials & interface.
     *
     * @param string $username
     * @param string $password
     * @param string $interface
     */
    public function setToken(string $username, string $password) {
        $this->token = "Basic ". base64_encode ($username . ":" . $password);
        $this->setDefaultHeader("Authorization", $this->token);
    }

    /**
     * Extract the id number at the end of a URL
     *
     * i.e. ..../{id}
     *
     * @param string $parentUrl
     * @return int
     */
    public function getTailNumber(string $parentUrl) {
        preg_match("~\/(\d+)$~", $parentUrl, $matches);
        return $matches[1];
    }

    /**
     * Execute GET /services/rest/connect/v1.4/servicesProducts request against Oracle Rest Api.
     *
     * @param array $query
     * @return array
     */
    public function getProducts(array $query = []) {
        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $url = "/services/rest/connect/v1.4/serviceProducts".$queryParams;
        do{
                $results = $this->get($url)->getBody() ?? null;
                foreach ($results['items'] as $product) {
                $this->products[$product["id"]] = $product["lookupName"];
            }

            $url = $results["links"][2]["href"];

        } while($results["links"][2]["rel"] == "next");
    }

    /**
     * Execute GET /services/rest/connect/latest/answers/{articleID}/products
     *
     * @param string|int $articleID
     * @return array
     */
    public function getArticleProduct($articleID) : string {
        $results = $this->get("/services/rest/connect/latest/answers/{$articleID}/products")->getBody();
        $products = $results["items"] ?? [];
        $product = '';

            if(sizeof($products) < self::maxProductSize && isset($products["href"])){

                foreach($products["href"] as $productURL){
                    $productID = $this->getTailNumber($productURL);
                    $product .= "#" . $this->products[$productID] . " ";
                }

                $product =  '<p>' . $product . '</p>';
        }

        return $product;
    }

    /**
     * Execute GET /services/rest/connect/v1.4/serviceCategories request against Oracle Rest Api.
     *
     * @param array $query
     * @return array
     */
    public function getCategories(array $query = []): iterable {
        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $results = $this->get("/services/rest/connect/v1.4/serviceCategories".$queryParams)->getBody() ?? null;

        foreach ($results['items'] as &$category) {

            if(!isset($category['parent'])){ // Is a root category.
                $category['parent'] = -1;
            } else {
                $category['parent'] = $this->getTailNumber($category['parent']['links'][0]['rel']);
            }
            $category["viewType"] = "help";
            $category["knowledgeBaseID"] = self::knowledgeBaseID;
            $category["sortArticles"] = "dateInsertedDesc";
            $category['description'] = $category['lookupName'].' placeholder description';
        }
        return $results;
    }

    /**
     * Execute GET /services/rest/connect/latest/serviceCategories/{categoryID}/names/{translationID} request against Oracle Rest Api.
     *
     * @param int $categoryID
     * @param array $translationID
     * @return array
     */
    public function getCategoryTranslations(int $categoryID): iterable {

        $translations = [];

        if(true){
            $results = $this->get("/services/rest/connect/latest/serviceCategories/{$categoryID}/names/")->getBody();
            $names = $results['items'] ?? null;

            foreach($names as $name){
                $id = $this->getTailNumber($name['href']);
                    $resultName = $this->get($name['href'])->getBody() ?? null;
                    $translations[$id]['name']['value'] = str_replace(' ', '%20', $resultName['labelText']);
                    $translations[$id]['name']['locale'] = $resultName['language']['lookupName'];
            }
        }

        if(false){
            $results = $this->get("/services/rest/connect/latest/serviceCategories/{$categoryID}/descriptions/")->getBody();
            $descriptions = $results['items'] ?? null;

            foreach($descriptions as $description){
                $id = $this->getTailNumber($description['href']);
                    $resultDesc = $this->get($description['href'])->getBody() ?? null;
                    $translations[$id]['description']['value'] = $resultDesc['labelText'];
                    $translations[$id]['description']['locale'] = $resultDesc['language']['lookupName'];
            }
        }

        return $translations;
    }

    /**
     * Execute GET /services/rest/connect/latest/answers/{articleID}/categories request against Oracle Rest Api.
     *
     * @param int $articleID
     * @return int
     */
    public function getArticleCategory(int $articleID): int {
        $results = $this->get("/services/rest/connect/latest/answers/{$articleID}/categories")->getBody();

        // An article can be assigned to multiple category. We use the first one by convention.
        if(isset($results['items'][0]['href'])) {
            $arr = explode('/', $results['items'][0]['href']);
            if(is_numeric(end($arr))){
                return end($arr);
            }
        }
        return 0;
    }

    /**
     * Execute GET /services/rest/connect/latest/answers/{articleID}/siblingAnswers request against Oracle Rest Api.
     *
     * @param int $articleID
     * @return int
     */
    public function getSiblingArticleID(int $articleID): int {
        $results = $this->get("/services/rest/connect/latest/answers/{$articleID}/siblingAnswers")->getBody();

        // This endpoint will fetch all the related articles in ascending order, we use the first one by convention.
        if(isset($results['items'][0]['href'])) {
            $arr = explode('/', $results['items'][0]['href']);
            if(is_numeric(end($arr))){
                return end($arr);
            }
        }
        return $articleID;
    }

    /**
     * Execute GET /services/rest/connect/latest/answers/{articleID}/fileAttachments request against Oracle Rest Api.
     *
     * @param string|int $articleID
     * @return array
     */
    public function getArticleAttachments($articleID): iterable {
        $results = $this->get("/services/rest/connect/latest/answers/{$articleID}/fileAttachments")->getBody();
        return $results['items'] ?? null;
    }

    /**
     * Execute GET /services/rest/connect/latest/answers/ request against Oracle Rest Api.
     *
     * @param array $query
     * @return array
     */
    public function getArticles(array $query = []): iterable {
        $queryParams = empty($query) ? "" : "?".http_build_query($query);
        $results = $this->get("/services/rest/connect/latest/answers?fields=language,question,solution,summary".$queryParams)->getBody() ?? null;

        foreach ($results['items'] as &$article) {

            $article['format'] = 'html';
            $article['siblingArticleID'] = $this->getSiblingArticleID($article['id']);
            $article['knowledgeCategoryID'] = $this->getArticleCategory($article['id']);
            $article['body'] = $article['question'] . ' ' .$article['solution'] . $this->getArticleProduct($article['id']);
            $article['language'] = $article['language']['lookupName'];
            // $articles[$article['id']]['oracleUserID'] = $this->getTailNumber($article['updatedByAccount']['links'][0]['href']);
        }

        return $results;
    }

    /**
     * Execute GET /services/rest/connect/latest/accounts/{id} request against Oracle Rest Api.
     *
     * @param int $userID
     * @return array
     */
    public function getUser($userID): array {
        $results = $this->get("/services/rest/connect/latest/accounts/".$userID)->getBody();
        return $results['user'] ?? [];
    }

    /**
     * Execute GET /services/rest/connect/v1.4/variables request against Oracle Rest Api.
     *
     * @param array $query
     * @return array
     */
    public function getVariables(array $query = []): iterable {
        $uri = "/services/rest/connect/v1.4/variables";
        $variables = [];

        $queryParams = empty($query) ? "" : "?".http_build_query($query);
        $results = $this->get($uri.$queryParams)->getBody();

        foreach ($results['items'] as $variableID) {
            // TODO
        }

        return $articles ?? [];
    }

    /**
     * @return string
     */
    public function getToken(): string {
        return $this->token;
    }

}
