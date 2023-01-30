<?php
/**
 * @author Alexander Kim <alexander.k@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Adapters;

use SimpleXMLElement;

/**
 * The Kayako XML adapter.
 */
class KayakoXmlAdapter {

    /** @var string $baseDir */
    private $baseDir;

    public function __construct()
    {
        $this->ROOT_PATH = dirname(__DIR__ ,2);
    }

    /**
     * Set base directory for xml reader.
     *
     * @param string $baseDir
     */
    public function setBaseDir(string $baseDir) {
        $this->baseDir = $baseDir;
    }

    /**
     * Get knowledge bases from knowledgeBases.xml
     *
     * @return iterable
     */
    public function getKnowledgeBases(): iterable {
        $xml = $this->getXml('knowledgeBases.xml');
        foreach ($xml->children() as $kb) {
            yield (array)$kb;
        }
    }

    /**
     * Get simple xml element from given file.
     *
     * @param $file
     * @return SimpleXMLElement
     * @throws \Exception
     */
    protected function getXml($file): \SimpleXMLElement {
        $file = $this->ROOT_PATH.'/'.$this->baseDir.'/'.$file;
        if (!file_exists($file)) {
            throw new \Exception('File not found: '.$file, 404);
        }
        $xml = simplexml_load_file($file, "SimpleXMLElement", LIBXML_NOCDATA);
        return $xml;
    }

    /**
     * Get knowledge base categories from knowledgeCategories.xml
     *
     * @return iterable
     */
    public function getKnowledgeCategories(): array {
        $res = [];
        $xml = $this->getXml('knowledgeCategories.xml');
        foreach ($xml->children() as $knowledgeCategory) {
            $knowledgeCategory = (array)$knowledgeCategory;
            $knowledgeCategory['knowledgeBaseID'] = 1;
            $res[] = $knowledgeCategory;
        }
        return $res;
    }

    /**
     * Get knowledge bases from knowledgeBases.xml
     *
     * @return iterable
     */
    public function getUsers(): iterable {
        $xml = $this->getXml('users.xml');
        foreach ($xml->children() as $user) {
            yield (array)$user;
        }
    }

    /**
     * Get user data by user id
     *
     * @param int $userID
     * @return array
     */
    public function getUser(int $userID): array {
        $xml = $this->getXml('users.xml');
        $user = $xml->xpath('//staffusers/staff/id[.="'.$userID.'"]/parent::*');
        $result = (array)$user;
        return $result[0] ?? [];
    }

    /**
     * Get articles from articles.xml
     *
     * @return iterable
     */
    public function getArticles(): iterable {
        $xml = $this->getXml('articles.xml');
        foreach ($xml->children() as $article) {
            $row = (array)$article;
            $articleID = $article->xpath('kbarticleid')[0];
            $categories = $article->xpath('categories/categoryid');
            foreach ($categories as $categoryid) {
                $row['kbarticleid'] = $articleID.'-'.$categoryid;
                $row['kayakoArticleID'] = $articleID;
                $row['categoryid'] = (string)$categoryid;
                yield $row;
            }

        }
    }

    /**
     * Get article attachments from /attachments/**.xml
     *
     * @return iterable
     */
    public function getArticleAttachments(array $article): iterable {
        $attachments = $article['attachments'];

        foreach ($attachments->children() as $attachment) {
            $idx = explode('-', $article['kbarticleid']);
            $file = $this->getXml('/attachments/'.$idx[0].'/'.$attachment->id.'.xml');
            $node = $file->xpath('//kbattachment/id[.="'.$attachment->id.'"]/parent::*')[0];
            $path = $this->saveFile('attachments/'.$idx[0].'/'.$node->filename, $node->contents);
            $media = $this->getMedia('/attachments/'.$idx[0].'/'.$attachment->id.'.json');
            $res = (array)$node;
            $res['filePath'] = $path;
            $res['content_url'] = $media['url'] ?? '';
            $res['display_file_name'] = $res['filename'];
            yield $res;
        }
    }

    /**
     * Save attachment as a file
     *
     * @param string $file
     * @param string $content
     * @return string
     */
    public function saveFile(string $file, string $content): string {
        $file = $this->ROOT_PATH.'/'.$this->baseDir.'/'.$file;
        if (!file_exists($file)) {
            file_put_contents($file, base64_decode($content));
        }
        return $file;
    }

    /**
     * Save attachment media as a json file
     *
     * @param string $file
     * @param string $content
     * @return string
     */
    public function saveAttachmentMedia(string $file, string $content, bool $force = false) {
        $file = $this->ROOT_PATH.'/'.$this->baseDir.'/'.$file;
        if ($force || !file_exists($file)) {
            file_put_contents($file, $content);
        }
    }

    /**
     * Get media structure saved as json file if exists.
     *
     * @param string $file
     * @return array
     */
    public function getMedia(string $file): array {
        $media = [];
        $file = $this->ROOT_PATH.'/'.$this->baseDir.'/'.$file;
        if (file_exists($file)) {
            $media = json_decode(file_get_contents($file), true);
        }

        return $media;
    }
}
