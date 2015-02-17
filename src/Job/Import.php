<?php
namespace ZoteroImport\Job;

use Omeka\Job\AbstractJob;
use Omeka\Job\Exception;
use Zend\Http\Response;

class Import extends AbstractJob
{
    /**
     * Vocabularies to cache.
     *
     * @var array
     */
    protected $vocabularies = array(
        'dcterms' => 'http://purl.org/dc/terms/',
        'dctype'  => 'http://purl.org/dc/dcmitype/',
        'bibo'    => 'http://purl.org/ontology/bibo/',
    );

    /**
     * Cache of selected Omeka resource classes
     *
     * @var array
     */
    protected $resourceClasses = array();

    /**
     * Cache of selected Omeka properties
     *
     * @var array
     */
    protected $properties = array();

    /**
     * Priority map between Zotero item types and Omeka resource classes
     *
     * @var array
     */
    protected $itemTypeMap = array();

    /**
     * Priority map between Zotero item fields and Omeka properties
     *
     * @var array
     */
    protected $itemFieldMap = array();

    /**
     * Priority map between Zotero creator types and Omeka properties
     *
     * @var array
     */
    protected $creatorTypeMap = array();

    /**
     * Perform the import.
     */
    public function perform()
    {
        $client = $this->getServiceLocator()->get('Omeka\HttpClient');
        $client->setHeaders(array('Zotero-API-Version' => '3'));

        $uri = new Uri($this->getArg('type'), $this->getArg('id'),
            $this->getArg('collectionKey'), 100);
        $uri = $uri->getUri();

        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        $response = $api->read('item_sets', $this->getArg('itemSet'));
        if ($response->isError()) {
            throw new Exception\RuntimeException('There was an error during item set read.');
        }
        $itemSet = $response->getContent();

        $this->cacheResourceClasses();
        $this->cacheProperties();

        $this->itemTypeMap = require __DIR__ . '/item_type_map.php';
        $this->itemFieldMap = require __DIR__ . '/item_field_map.php';
        $this->creatorTypeMap = require __DIR__ . '/creator_type_map.php';

        do {
            $response = $client->setUri($uri)->send();
            if (!$response->isSuccess()) {
                throw new Exception\RuntimeException(sprintf(
                    'Requested "%s" got "%s".', $uri, $response->renderStatusLine()
                ));
            }

            $zoteroItems = json_decode($response->getBody(), true);
            if (!is_array($zoteroItems)) {
                break;
            }

            $omekaItems = array();
            foreach ($zoteroItems as $zoteroItem) {
                if ('note' == $zoteroItem['data']['itemType']) {
                    continue;
                }
                $omekaItem = array();
                $omekaItem['o:item_set'] = array(array('o:id' => $itemSet->id()));
                $omekaItem = $this->mapResourceClass($zoteroItem, $omekaItem);
                $omekaItem = $this->mapNameValues($zoteroItem, $omekaItem);
                $omekaItem = $this->mapSubjectValues($zoteroItem, $omekaItem);
                $omekaItem = $this->mapValues($zoteroItem, $omekaItem);
                $omekaItems[] = $omekaItem;
            }

            $batchCreate = $api->batchCreate('items', $omekaItems);
            if ($batchCreate->isError()) {
                throw new Exception\RuntimeException('There was an error during item batch create.');
            }

            if ($this->shouldStop()) {
                // @todo consider performing cleanup before stopping
                break;
            }

        } while ($uri = $this->getLink($response, 'next'));
    }

    /**
     * Cache selected resource classes.
     */
    public function cacheResourceClasses()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->vocabularies as $prefix => $namespaceUri) {
            $classes = $api->search('resource_classes', array(
                'vocabulary_namespace_uri' => $namespaceUri,
            ))->getContent();
            foreach ($classes as $class) {
                $this->resourceClasses[$prefix][$class->localName()] = $class;
            }
        }
    }

    /**
     * Cache selected properties.
     */
    public function cacheProperties()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->vocabularies as $prefix => $namespaceUri) {
            $properties = $api->search('properties', array(
                'vocabulary_namespace_uri' => $namespaceUri,
            ))->getContent();
            foreach ($properties as $property) {
                $this->properties[$prefix][$property->localName()] = $property;
            }
        }
    }

    /**
     * Map Zotero item type to Omeka resource class.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapResourceClass(array $zoteroItem, array $omekaItem)
    {
        if (!isset($zoteroItem['data']['itemType'])) {
            return $omekaItem;
        }
        $type = $zoteroItem['data']['itemType'];
        if (!isset($this->itemTypeMap[$type])) {
            return $omekaItem;
        }
        foreach ($this->itemTypeMap[$type] as $prefix => $localName) {
            if (isset($this->resourceClasses[$prefix][$localName])) {
                $class = $this->resourceClasses[$prefix][$localName];
                $omekaItem['o:resource_class'] = array('o:id' => $class->id());
                return $omekaItem;
            }
        }
        return $omekaItem;
    }

    /**
     * Map Zotero item data to Omeka item values.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapValues(array $zoteroItem, array $omekaItem)
    {
        if (!isset($zoteroItem['data'])) {
            return $omekaItem;
        }
        foreach ($zoteroItem['data'] as $key => $value) {
            if (!$value) {
                continue;
            }
            if (!isset($this->itemFieldMap[$key])) {
                continue;
            }
            foreach ($this->itemFieldMap[$key] as $prefix => $localName) {
                if (isset($this->properties[$prefix][$localName])) {
                    $property = $this->properties[$prefix][$localName];
                    $valueObject = array();
                    $valueObject['property_id'] = $property->id();
                    if ('bibo' == $prefix && 'uri' == $localName) {
                        $valueObject['@id'] = $value;
                    } else {
                        $valueObject['@value'] = $value;
                    }
                    $omekaItem[$property->term()][] = $valueObject;
                    continue 2;
                }
            }
        }
        return $omekaItem;
    }

    /**
     * Map Zotero creator names to the Omeka item values.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapNameValues(array $zoteroItem, array $omekaItem)
    {
        if (!isset($zoteroItem['data']['creators'])) {
            return $omekaItem;
        }
        $creators = $zoteroItem['data']['creators'];
        foreach ($creators as $creator) {
            $creatorType = $creator['creatorType'];
            if (!isset($this->creatorTypeMap[$creatorType])) {
                continue;
            }
            $name = array();
            if (isset($creator['name'])) {
                $name[] = $creator['name'];
            }
            if (isset($creator['firstName'])) {
                $name[] = $creator['firstName'];
            }
            if (isset($creator['lastName'])) {
                $name[] = $creator['lastName'];
            }
            if (!$name) {
                continue;
            }
            $name = implode(' ', $name);
            foreach ($this->creatorTypeMap[$creatorType] as $prefix => $localName) {
                if (isset($this->properties[$prefix][$localName])) {
                    $property = $this->properties[$prefix][$localName];
                    $omekaItem[$property->term()][] = array(
                        '@value' => $name,
                        'property_id' => $property->id(),
                    );
                    continue 2;
                }
            }
        }
        return $omekaItem;
    }

    /**
     * Map Zotero tags to Omeka item values (dcterms:subject).
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapSubjectValues(array $zoteroItem, array $omekaItem)
    {
        if (!isset($zoteroItem['data']['tags'])) {
            return $omekaItem;
        }
        $tags = $zoteroItem['data']['tags'];
        foreach ($tags as $tag) {
            $property = $this->properties['dcterms']['subject'];
            $omekaItem[$property->term()][] = array(
                '@value' => $tag['tag'],
                'property_id' => $property->id(),
            );
        }
        return $omekaItem;
    }

    /**
     * Get a URI from the Link header.
     *
     * @param Response $response
     * @param string $rel The relationship from the current document. Possible
     * values are first, prev, next, last, alternate.
     * @return string|null
     */
    public function getLink(Response $response, $rel)
    {
        $linkHeader = $response->getHeaders()->get('Link');
        if (!$linkHeader) {
            return null;
        }
        preg_match_all(
            '/<([^>]+)>; rel="([^"]+)"/',
            $linkHeader->getFieldValue(),
            $matches
        );
        if (!$matches) {
            return null;
        }
        $key = array_search($rel, $matches[2]);
        if (false === $key) {
            return null;
        }
        return $matches[1][$key];
    }
}
