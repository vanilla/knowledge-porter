<?php
/**
 * @author Alexander Kim <alexander.k@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Adapters;

use mysql_xdevapi\Exception;

/**
 * The Kayako XML adapter.
 */
class KayakoXmlAdapter {
    /** @var string $baseDir */
    private $baseDir;

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
        $file = ROOT_PATH.'/'.$this->baseDir.'/'.$file;
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
}
