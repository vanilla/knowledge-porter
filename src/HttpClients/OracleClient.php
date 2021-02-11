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

    /**
     * @var array
     */
    private $products = [];
    private $variables = [];

    const maxProductSize = 10;
    const knowledgeBaseID = 1;
    const rootCategory = 1;

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
     * @return int|mixed
     */
    public function getTailNumber(string $parentUrl) {
        preg_match("~\/(\d+)$~", $parentUrl, $matches);
        return $matches[1];
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
        $categories = [];

        foreach ($results['items'] as $item) {
            $id = $item['id'];
            $category = $this->get("/services/rest/connect/v1.4/serviceCategories/".$id)->getBody() ?? null;
            yield $category;
        }
    }

    /**
     * Execute GET /services/rest/connect/latest/serviceCategories/{categoryID}/names/{translationID} request against Oracle Rest Api.
     *
     * @param int $categoryID
     * @param bool $translateNames
     * @param bool $translateDescription
     * @return array
     */
    public function getCategoryTranslations(int $categoryID, bool $translateNames = false, bool $translateDescription = false): iterable {

        $translations = [];

        if($translateNames){
            $results = $this->get("/services/rest/connect/latest/serviceCategories/{$categoryID}/names/")->getBody();
            $names = $results['items'] ?? null;

            foreach($names as $name){
                $resultName = $this->get($name['href'])->getBody() ?? null;
                $locale = $resultName['language']['lookupName'];
                yield [[
                    'propertyName' => 'name',
                    'translation' => $resultName['labelText'],
                    'locale' => $locale,
                ]];
            }
        }
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
                $url = $results["links"][2]["href"];
                $this->products[$product["id"]] = $product["lookupName"];
            }
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
                $product .= $this->products[$productID] . ", ";
            }
        }
        return $product;
    }

    /**
     * Execute GET /services/rest/connect/latest/answers/{articleID}/siblingAnswers request against Oracle Rest Api.
     *
     * @param int $articleID
     * @return int
     */
    public function getSiblingArticleID(int $articleID): int {
        $results = $this->get("/services/rest/connect/latest/answers/{$articleID}/siblingAnswers")->getBody();
        $siblingID = PHP_INT_MAX;
        // This endpoint will fetch all the related articles in ascending order, we use the first one by convention.
        if(isset($results['items'][0]['href'])) {
            $arr = explode('/', $results['items'][0]['href']);
            if(is_numeric(end($arr))){
                $siblingID = end($arr);
            }
        }
        return min($articleID, $siblingID);
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

    public function formatKeywords($keywords, string $products): string {

        $key = '';
        if(isset($keywords) || isset($productss)){
            if(isset($keywords)){
                $key .= "#" . str_replace(', ', ' #', $keywords) . ' ';
            }

            if(isset($productss)){
                $key .= "#" . str_replace(', ', ' #', $products);
            }
            $key = '<p>' . $key . '<p>';
        }
        return $key;
    }

    /**
     * Execute GET /services/rest/connect/latest/answers/ request against Oracle Rest Api.
     *
     * @param array $query
     * @param array $locales
     * @param bool $importProducts
     * @param bool $importVariables
     * @return array
     */
    public function getArticles(array $query = [], array $locales, bool $importProducts = false, bool $importVariables = false): iterable {
        $queryParams = empty($query) ? "" : "&". http_build_query($query);
        $results = $this->get("/services/rest/connect/latest/answers?fields=language".$queryParams)->getBody() ?? null;
        $products = '';

        foreach ($results['items'] as &$article) {

            $skip = !in_array($article['language']['lookupName'], $locales);

            if(!$article['skip']){

                if($importProducts){
                    $products = $this->getArticleProduct($article['id']);
                }

                $article = $this->get("/services/rest/connect/latest/answers/" . $article['id'])->getBody() ?? null;
                $article['skip'] = $skip;
                $article['articleID'] = $this->getSiblingArticleID($article['id']);
                $article['knowledgeCategoryID'] = $this->getArticleCategory($article['id']);
                //$articles['items'][$id]['oracleUserID'] = $this->getTailNumber($article['updatedByAccount']['links'][0]['href']);

                $body = $article['question'] . ' ' .$article['solution'];

                if($importVariables){
                    $body = $this->replaceVariables($body, $article['language']['id']);
                }

                $article['body'] = $body . $this->formatKeywords($article['keywords'], $products);
            }
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
     * Oracle variables act as macro and need to be replaced in the body of the articles.
     *
     * @param array $query
     * @return array
     */
    public function getVariables(array $query = []) {
        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $results = $this->get("/services/rest/connect/v1.4/variables".$queryParams)->getBody() ?? null;

        foreach ($results['items'] as $item) {
            $variableID = $item['id'];
            $macro = $item['lookupName'];
            $interfaceValues = $this->get("/services/rest/connect/v1.4/variables/{$variableID}/interfaceValues")->getBody() ?? null;

            foreach ($interfaceValues['items'] as $localization){
                $localizationID = $this->getTailNumber($localization['href']);
                $variableValue = $this->get("/services/rest/connect/v1.4/variables/{$variableID}/interfaceValues/{$localizationID}")->getBody() ?? null;
                if(isset($variableValue['value'])){
                    $this->variables[$localizationID][$macro] = $variableValue['value'];
                }
            }
        }
    }

    /**
     * Replace
     *
     * @param $body
     * @param $localeID
     * @return string|string[]
     */
    public function replaceVariables($body, $localeID){
        $replace_map =  $this->variables[$localeID];
        return str_replace(array_keys($replace_map), array_values($replace_map), $body);
    }

    /**
     * @return string
     */
    public function getToken(): string {
        return $this->token;
    }

}
