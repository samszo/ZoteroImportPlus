<?php
namespace ZoteroImport\Job;

use DateTime;
use Omeka\Job\AbstractJob;
use Omeka\Job\Exception;
use Laminas\Http\Client;
use Laminas\Http\Response;
use ZoteroImport\Zotero\Url;

class Import extends AbstractJob
{
    /**
     * Zotero API client
     *
     * @var Client
     */
    protected $client;

    /**
     * Zotero API URL
     *
     * @var Url
     */
    protected $url;

    /**
     * Vocabularies to cache.
     *
     * @var array
     */
    protected $vocabularies = [
        'dcterms' => 'http://purl.org/dc/terms/',
        'dctype'  => 'http://purl.org/dc/dcmitype/',
        'bibo'    => 'http://purl.org/ontology/bibo/',
        //ajout samszo
        'skos'      => 'http://www.w3.org/2004/02/skos/core#',
        'foaf'      => 'http://xmlns.com/foaf/0.1/',
        'oa'        => 'http://www.w3.org/ns/oa#',        
        'jdc'       => 'https://jardindesconnaissances.univ-paris8.fr/onto/jdc#',        
        'schema'    => 'http://schema.org/',
        'rdf'       => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'cito'      => 'http://purl.org/spar/cito',         
        //fin ajout
    ];

    /**
     * Cache of selected Omeka resource classes
     *
     * @var array
     */
    protected $resourceClasses = [];

    /**
     * Cache of selected Omeka properties
     *
     * @var array
     */
    protected $properties = [];

    /**
     * Priority map between Zotero item types and Omeka resource classes
     *
     * @var array
     */
    protected $itemTypeMap = [];

    /**
     * Priority map between Zotero item fields and Omeka properties
     *
     * @var array
     */
    protected $itemFieldMap = [];

    /**
     * Priority map between Zotero creator types and Omeka properties
     *
     * @var array
     */
    protected $creatorTypeMap = [];

    //Ajout samszo
    /**
     * proriété pour gérer les personnes
     *
     * @var array
     */
    protected $persons = [];
    /**
     * proriété pour gérer les tags
     *
     * @var array
     */
    protected $tags = [];
    /**
     * objet pour gérer les logs
     *
     * @var object
     */
    protected $logger;
    /**
     * objet pour gérer l'api
     *
     * @var object
     */
    protected $api;
    /**
     * proriété pour gérer l'identifiant de l'import
     *
     * @var array
     */
    protected $idImport;
    /**
     * proriété pour gérer les actants zotero
     *
     * @var array
     */
    protected $actants = [];

    /**
     * Cache of selected Omeka resource template
     *
     * @var array
     */
    protected $resourceTemplate = [];

    /**
     * Cache of selected Omeka custom vocab
     *
     * @var array
     */
    protected $customVocab = [];
    
    /**
     * Nom de l'actant automatique
     *
     * @var string
     */
    protected $zAutoTag = 'Zotero automatique tagger';
    
    
    //fin ajout

    /**
     * Perform the import.
     *
     * Accepts the following arguments:
     *
     * - itemSet:       The Omeka item set ID (int)
     * - import:        The Omeka Zotero import ID (int)
     * - type:          The Zotero library type (user, group)
     * - id:            The Zotero library ID (int)
     * - collectionKey: The Zotero collection key (string)
     * - apiKey:        The Zotero API key (string)
     * - importFiles:   Whether to import file attachments (bool)
     * - version:       The Zotero Last-Modified-Version of the last import (int)
     * - timestamp:     The Zotero dateAdded timestamp (UTC) to begin importing (int)
     * 
     * ajout samszo
     * - timestampBefore:     The Zotero dateAdded timestamp (UTC) to begin importing (int)
     * fin ajour
     *
     * Roughly follows Zotero's recommended steps for synchronizing a Zotero Web
     * API client with the Zotero server. But for the purposes of this job, a
     * "sync" only imports parent items (and their children) that have been
     * added to Zotero since the passed timestamp.
     *
     * @see https://www.zotero.org/support/dev/web_api/v3/syncing#full-library_syncing
     */
    public function perform()
    {
        // Raise the memory limit to accommodate very large imports.
        ini_set('memory_limit', '500M');

        $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $this->logger = $this->getServiceLocator()->get('Omeka\Logger');


        $itemSet = $this->api->read('item_sets', $this->getArg('itemSet'))->getContent();

        $this->cacheResourceClasses();
        $this->cacheProperties();
        //ajout samszo
        $this->cacheResourceTemplate();
        $this->cacheCustomVocab();
        //fin ajout

        $this->itemTypeMap = $this->prepareMapping('item_type_map');
        $this->itemFieldMap = $this->prepareMapping('item_field_map');
        $this->creatorTypeMap = $this->prepareMapping('creator_type_map');

        $this->setImportClient();
        $this->setImportUrl();

        $apiVersion = $this->getArg('version', 0);
        $apiKey = $this->getArg('apiKey');
        $collectionKey = $this->getArg('collectionKey');

        $params = [
            'since' => $apiVersion,
            'format' => 'versions',
            // Sort by ascending date added so items are imported roughly in the
            // same order. This way, if there is an error during an import,
            // users can estimate when to set the "Added after" field.
            'sort' => 'dateAdded',
            'direction' => 'asc',
            // Do not import notes.
            //modif samszo
            //'itemType' => '-note',
            //fin modif
        ];
        if ($collectionKey) {
             $url = $this->url->collectionItems($collectionKey, $params);
        } else {
            $url = $this->url->items($params);
        }
        $zItemKeys = array_keys(json_decode($this->getResponse($url)->getBody(), true));

        //ajout samszo
        $this->logger->info($url);
        $this->actant[$this->url->id()] = $this->ajouteActant($this->url->id(), $this->getArg('username'));        
        $this->logger->info("Actant = ".$this->getArg('username')." : ".$this->idActant);
        //fin ajout

        if (empty($zItemKeys)) {
            return;
        }

        // Cache all Zotero parent and child items.
        $zParentItems = [];
        $zChildItems = [];
        //ajout samszo
        $noteItemsParent = [];        
        $noteItems = [];        
        //fin ajout
        foreach (array_chunk($zItemKeys, 50, true) as $zItemKeysChunk) {
            if ($this->shouldStop()) {
                return;
            }
            $url = $this->url->items([
                'itemKey' => implode(',', $zItemKeysChunk),
                // Include the Zotero key so Zotero adds enclosure links to the
                // response. An attachment can only be downloaded if an
                // enclosure link is included.
                'key' => $apiKey,
            ]);
            $zItems = json_decode($this->getResponse($url)->getBody(), true);

            foreach ($zItems as $zItem) {
                $dateAdded = new DateTime($zItem['data']['dateAdded']);
                if ($dateAdded->getTimestamp() < $this->getArg('timestamp', 0)) {
                    // Only import items added since the passed timestamp. Note
                    // that the timezone must be UTC.
                    continue;
                }
                if ($dateAdded->getTimestamp() > $this->getArg('timestampBefore')) {
                    // Only import items added before the passed timestamp. Note
                    // that the timezone must be UTC.
                    $this->logger->info("date ajout = ".$dateAdded->getTimestamp()." : date avant ".$this->getArg('timestampBefore'));
                    continue;
                }

                //ajout samszo
                //récupération de l'actant
                $faitPar=$this->url->id();
                if(isset($zItem['meta']['createdByUser'])){
                    if(!isset($this->actant[$zItem['meta']['createdByUser']['username']])){
                        $this->actant[$zItem['meta']['createdByUser']['username']] = $this->ajouteActant($zItem['meta']['createdByUser']['id'], $zItem['meta']['createdByUser']['username']);        
                    }
                    $faitPar=$this->actant[$zItem['meta']['createdByUser']['username']]->id()."";
                }


                //récupération des tags pour différencier 
                //tags de l'utilisateur et ceux créés automatiquement
                $urlTag = $this->url->itemTags($zItem['key'],[
                    'key' => $apiKey,
                ]);
                $this->logger->info($urlTag);
                $zTags = json_decode($this->getResponse($urlTag)->getBody(),true);
                //$this->logger->info($zTags);
                foreach ($zTags as $t) {
                    $keyTag = $t['tag'];
                    if(!isset($this->tags[$keyTag])){
                        $this->tags[$keyTag]=[
                            'type'=>$t['meta']['type']
                            ,'tag'=>$t['tag']
                            ,'actants'=>[]
                        ];
                    }
                    $actant = $t['meta']['type'] ? $this->zAutoTag : $faitPar;
                    if(!isset($this->tags[$keyTag]['actants'][$actant])){
                        $this->tags[$keyTag]['actants'][$actant]=[];
                    }
                    $this->tags[$keyTag]['actants'][$actant][]=$zItem['key'];
                }

                //fin ajout

                // Unset unneeded data to save memory.
                unset($zItem['library']);
                unset($zItem['version']);
                unset($zItem['meta']);
                unset($zItem['links']['self']);
                unset($zItem['links']['alternate']);

                //ajout samszo
                $this->logger->info('key='.$zItem['key']);
                //$this->logger->info(json_encode($zItem));
                //fin ajout

                if (isset($zItem['data']['parentItem'])) {
                    $zChildItems[$zItem['data']['parentItem']][] = $zItem;
                    //ajout samszo
                    //$this->logger->info('Parent key='.$zItem['data']['parentItem']);
                    //prise en compte des notes
                    if($zItem['data']['itemType']=="note"){
                        if(!$zItem['data']['title'])$zItem['data']['title']='Note : '.$zItem['key'];
                        $noteItemsParent[$zItem['data']['parentItem']][]=$zItem['key'];        
                        $noteItems[$zItem['key']]=0;
                        $zItem['user']=                
                        $zParentItems[$zItem['key']] = $zItem;
                    }
                    //fin ajout                    
                } else {
                    $zParentItems[$zItem['key']] = $zItem;
                }
            }
        }

        // Map Zotero items to Omeka items. Pass by reference so PHP doesn't
        // create a copy of the array, saving memory.
        $oItems = [];
        //ajout samszo
        $propIsRef = $this->properties["dcterms"]["isReferencedBy"];
        $oItemsUpdate = [];
        //
        foreach ($zParentItems as $zParentItemKey => &$zParentItem) {
            $oItem = [];
            $oItem['o:item_set'] = [['o:id' => $itemSet->id()]];
            $oItem = $this->mapResourceClass($zParentItem, $oItem);
            $oItem = $this->mapNameValues($zParentItem, $oItem);
            //modif samszo
            //plus nécessaire car création des tags comme item
            //$oItem = $this->mapSubjectValues($zParentItem, $oItem);
            //fin ajout
            $oItem = $this->mapValues($zParentItem, $oItem);
            $oItem = $this->mapAttachment($zParentItem, $oItem);
            if (isset($zChildItems[$zParentItemKey])) {
                foreach ($zChildItems[$zParentItemKey] as $zChildItem) {
                    //ajout samszo
                    //$this->logger->info(json_encode($zChildItem));
                    //fin ajout
                    $oItem = $this->mapAttachment($zChildItem, $oItem);
                }
            }
            //ajout samszo
            //$this->logger->info(json_encode($oItem));
            //ajoute le compilateur
            $valueObject=[];
            $valueObject['property_id'] = $this->properties["cito"]["isCompiledBy"]->id();
            $valueObject['value_resource_id'] = $this->actant[$this->url->id()]->id();
            $valueObject['type'] = 'resource';
            $oItem[$this->properties["cito"]["isCompiledBy"]->term()][] = $valueObject;
            if($this->actant[$this->url->id()]->id()!=$faitPar){
                $valueObject=[];
                $valueObject['property_id'] = $this->properties["cito"]["isCompiledBy"]->id();
                $valueObject['value_resource_id'] = $faitPar;
                $valueObject['type'] = 'resource';
                $oItem[$this->properties["cito"]["isCompiledBy"]->term()][] = $valueObject;    
            }
    
            //vérifie la présence de l'item pour gérer les mises à jour
            $param = array();
            $param['property'][0]['property']= $propIsRef->id()."";
            $param['property'][0]['type']='eq';
            $param['property'][0]['text']=$zParentItem['key']; 
            //$this->logger->info("RECHERCHE PARAM = ".json_encode($param));
            $result = $this->api->search('items',$param)->getContent();
            //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
            //$this->logger->info("RECHERCHE COUNT = ".count($result));
            if(count($result)){
                $oItemsUpdate[$result[0]->id()] = $oItem;
                //$this->logger->info("UPDATE ITEM".$result[0]->id()." = ".json_encode($result[0]));
            }else //fin ajout                
                $oItems[$zParentItemKey] = $oItem;

            //conserve l'item de la note pour la mise à jour de is part of
            if(isset($noteItems[$zParentItem['key']]))$noteItems[$zParentItem['key']]=$oItem;

            // Unset unneeded data to save memory.
            unset($zParentItems[$zParentItemKey]);
        }

        // Batch create Omeka items.
        $importId = $this->getArg('import');
        $this->idImport = $importId;
        foreach (array_chunk($oItems, 50, true) as $oItemsChunk) {

            //ajout samszo
            //$this->logger->info(json_encode($oItemsChunk));
            //fin ajout                    

            if ($this->shouldStop()) {
                return;
            }
            $response = $this->api->batchCreate('items', $oItemsChunk, [], ['continueOnError' => true]);

            // Batch create Zotero import items.
            $importItems = [];
            foreach ($response->getContent() as $zKey => $item) {
                $importItems[] = [
                    'o:item' => ['o:id' => $item->id()],
                    'o-module-zotero_import:import' => ['o:id' => $importId],
                    'o-module-zotero_import:zotero_key' => $zKey,
                ];
                //ajout samszo
                //enregistre l'identifiant du parent d'une note
                if(isset($noteItemsParent[$zKey])){
                    $noteItemsParent[$zKey]=['oid'=>$item->id(),'items'=>$noteItemsParent[$zKey]];
                }
                //enregistre l'item pour la note
                if(isset($noteItems[$zKey])){
                    $noteItems[$zKey]=$noteItems[$zKey];
                }
                //enregistre l'item pour les notes et les tags
                $oItems[$zKey]=$item;
                //fin ajout
            }
            // The ZoteroImportItem entity cascade detaches items, which saves
            // memory during batch create.
            $this->api->batchCreate('zotero_import_items', $importItems, [], ['continueOnError' => true]);
        }

        //ajout samszo
        //update Omeka items.
        //TODO:compter le nombre de mise à jour
        //$this->logger->info('LISTE UPDATE '.$id.' = '.json_encode($oItemsUpdate));
        foreach ($oItemsUpdate as $id => $oItemChunk) {

            //$this->logger->info('UPDATE '.$id.' = '.json_encode($oItemChunk));

            if ($this->shouldStop()) {
                return;
            }
            $response = $this->api->update('items', $id, $oItemChunk, [], ['isPartial'=>true, 'continueOnError' => true]);
            //$this->logger->info('UPDATE RESULT = \n'.json_encode($response));
            
            // Batch create Zotero import items.
            $zKey = $oItemChunk['dcterms:isReferencedBy'][0]['@value'];
            $importItem = [
                    'o:item' => ['o:id' => $id],
                    'o-module-zotero_import:import' => ['o:id' => $importId],
                    'o-module-zotero_import:zotero_key' => $zKey,
                ];

            //enregistre l'identifiant du parent d'une note
            if(isset($noteItemsParent[$zKey])){
                $noteItemsParent[$zKey]=['oid'=>$id,'items'=>$noteItemsParent[$zKey]];
            }
            //enregistre l'identifiant de la note
            if(isset($noteItems[$zKey])){
                $noteItems[$zKey]=$noteItems[$zKey];
            }
            //enregistre l'item de l'item pour les notes et les tags
            $oItems[$zKey]=$this->api->read('items', $id)->getContent();

            // The ZoteroImportItem entity cascade detaches items, which saves
            // memory during batch create.
            //TODO:compter le nombre de notice et de citation
            $this->api->create('zotero_import_items', $importItem, [], ['continueOnError' => true]);

            // Unset unneeded data to save memory.
            unset($oItemsUpdate[$id]);

        }

        //mise à jour des propriétés is part of avec l'identifiant de la ressource
        $propIsPart = $this->properties["dcterms"]["isPartOf"];
        //$this->logger->info('noteItemsParent = '.json_encode($noteItemsParent));
        //$this->logger->info('noteItems = '.json_encode($noteItems));
        foreach ($noteItemsParent as $zKeyP => $itemP) {
            $idP = $itemP['oid'];
            foreach ($itemP['items'] as $zKey) {
                $item = $noteItems[$zKey];
                $param =[
                    'property_id'=>$propIsPart->id(),
                    'type'=>'resource',
                    'value_resource_id'=>$idP
                ];
                $item["dcterms:isPartOf"][0]=$param;
                //$this->logger->info('UPDATE '.$noteItems[$zKey]['oid'].' IS PART OF '.$idP.' = '.json_encode($item));
                $this->api->update('items', $oItems[$zKey]->id(), $item, [], ['isPartial'=>true, 'continueOnError' => true]);            
            }
            // Unset unneeded data to save memory.
            unset($noteItems[$zKey]);
            unset($noteItemsParent[$zKeyP]);
        }


        //création des annotations pour chaque tags de chaque items
        //$this->logger->info('tags = '.json_encode($this->tags));
        foreach ($this->tags as $tag) {
            $oIdsTag = $this->ajouteTag($tag);     
            //création des relations avec les items
            foreach ($tag['actants'] as $act => $vals) {
                //$this->logger->info('act = '.$act);
                if(!$this->actant[$act]){
                    $this->actant[$act] = $this->ajouteActant($act, $act);        
                }
                foreach ($vals as $zKey) {
                    foreach ($oIdsTag as $oIdTag) {
                        $this->ajouteAnnotation($oItems[$zKey],$this->actant[$act], $oIdTag);                        
                    }                                        
                }
            }
        }        
        //fin ajout

    }

    //ajout samszo
     /** Ajoute un actant dans omeka
     *
     * @param string $id
     * @param string $username
     * @return o:item
     */
    protected function ajouteActant($id, $username)
    {
        //vérifie la présence de l'item pour gérer les mises à jour
        $param = array();
        $param['property'][0]['property']= $this->properties["foaf"]["accountName"]->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$username; 
        //$this->logger->info("RECHERCHE PARAM = ".json_encode($param));
        $result = $this->api->search('items',$param)->getContent();
        //$this->logger->info("RECHERCHE ITEM",$result);
        if(count($result)){
            //TODO:mettre à jour l'actant
            return $result[0];
        }else{
            $oItem = [];
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["foaf"]["accountName"]->id();
            $valueObject['@value'] = $username;
            $valueObject['type'] = 'literal';
            $oItem[$this->properties["foaf"]["accountName"]->term()][] = $valueObject;    
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["foaf"]["account"]->id();
            $valueObject['@value'] = "Zotero";
            $valueObject['type'] = 'literal';
            $oItem[$this->properties["foaf"]["account"]->term()][] = $valueObject;    
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["schema"]["identifier"]->id();
            $valueObject['@value'] = $id."";
            $valueObject['type'] = 'literal';
            $oItem[$this->properties["schema"]["identifier"]->term()][] = $valueObject;    
            $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['jdc']['Actant']->id()];
            $oItem['o:resource_template'] = ['o:id' => $this->resourceTemplate['Actant']->id()];
            $this->logger->info("ajouteActant",$oItem);            
            //création de l'actant
            $result = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
            //TODO:compter le nombre d'actant
            return $result;
        }              

    }

    /**
     * Ajoute un tag au format skos
     *
     * @param array $tag
     * @return array
     */
    protected function ajouteTag($tag)
    {
        //prise en compte des -
        $tags = explode(' - ', $tag['tag']);
        $oIds = [];
        $i = 0;
        foreach ($tags as $t) {
            //vérifie la présence de l'item pour gérer la création
            $param = array();
            $param['property'][0]['property']= $this->properties["skos"]["prefLabel"]->id()."";
            $param['property'][0]['type']='eq';
            $param['property'][0]['text']=$t; 
            //$this->logger->info("RECHERCHE PARAM = ".json_encode($param));
            $result = $this->api->search('items',$param)->getContent();
            //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
            //$this->logger->info("RECHERCHE COUNT = ".count($result));
            if(count($result)){
                $oIds[] = $result[0];
                //$this->logger->info("ID TAG EXISTE".$result[0]->id()." = ".json_encode($result[0]));
            }else{
                $oItem = [];
                $class = $this->resourceClasses['skos']['Concept'];
                $oItem['o:resource_class'] = ['o:id' => $class->id()];
                $valueObject = [];
                $valueObject['property_id'] = $this->properties["dcterms"]["title"]->id();
                $valueObject['@value'] = $t;
                $valueObject['type'] = 'literal';
                $oItem[$this->properties["dcterms"]["title"]->term()][] = $valueObject;
                $valueObject = [];
                $valueObject['property_id'] = $this->properties["skos"]["prefLabel"]->id();
                $valueObject['@value'] = $t;
                $valueObject['type'] = 'literal';
                $oItem[$this->properties["skos"]["prefLabel"]->term()][] = $valueObject;
                //prise en compte du concept parent broader
                if(isset($oIds[($i-1)])){
                    $valueObject = [];
                    $valueObject['property_id'] = $this->properties["skos"]["broader"]->id();
                    $valueObject['value_resource_id'] = $oIds[($i-1)]->id();
                    $valueObject['type'] = 'resource';
                    $oItem[$this->properties["skos"]["broader"]->term()][] = $valueObject;    
                }
                //création du tag
                $result = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
                $oIds[] = $result;
                $importItem = [
                    'o:item' => ['o:id' => $oIds[$i]->id()],
                    'o-module-zotero_import:import' => ['o:id' => $this->idImport],
                    'o-module-zotero_import:zotero_key' => $tag['tag'],
                ];
                $this->api->create('zotero_import_items', $importItem, [], ['continueOnError' => true]);

                //met à jour des concepts enfant narrower
                if(isset($oIds[($i-1)])){
                    $oItem = [];
                    $valueObject = [];
                    $valueObject['property_id'] = $this->properties["skos"]["narrower"]->id();
                    $valueObject['value_resource_id'] = $oIds[$i]->id();
                    $valueObject['type'] = 'resource';
                    $oItem[$this->properties["skos"]["narrower"]->term()][]= $valueObject;
                    $this->api->update('items', $oIds[($i-1)]->id(), $oItem, [], ['continueOnError' => true,'isPartial'=>1, 'collectionAction' => 'append']);
                    $this->logger->info("UPDATE narrower".$oIds[($i-1)]->id()." = ".json_encode($oItem));

                    /*
                    $oIds[$i-1]['item'][$this->properties["skos"]["narrower"]->term()][] = $valueObject;    
                    $this->api->update('items', $oIds[($i-1)]['id'], $oIds[($i-1)]['item'], [], ['continueOnError' => true,'isPartial'=>1, 'collectionAction' => 'append']);
                    $this->logger->info("UPDATE narrower".$oIds[($i-1)]['id']." = ".json_encode($oIds[($i-1)]['item']));
                    */
                }
                //        
                //$this->logger->info("ID TAG CREATE ".$oIdTag." = ".json_encode($result));
            }   
            $i++;
        }
        //TODO:compter le nombre de tag créé

        return $oIds;
    }
    

    /**
     * Ajoute une personne au format foaf
     *
     * @param string    $name
     * @param array     $p
     * @return array
     */
    protected function ajoutePersonne($name, $p)
    {
        //valorisation de l'item
        $class = $this->resourceClasses['foaf']['Person'];
        $this->persons[$name]=['contribs'=>[]
            ,'item'=>[
                'o:resource_class' => ['o:id' => $class->id()]
            ]];
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["dcterms"]["title"]->id();
        $valueObject['@value'] = $name;
        $valueObject['type'] = 'literal';
        $this->persons[$name]['item'][$this->properties["foaf"]["givenName"]->term()][] = $valueObject;
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["foaf"]["givenName"]->id();
        $valueObject['@value'] = $name;
        $valueObject['type'] = 'literal';
        $this->persons[$name]['item'][$this->properties["foaf"]["givenName"]->term()][] = $valueObject;
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["foaf"]["firstName"]->id();
        $valueObject['@value'] = $p['firstName'];
        $valueObject['type'] = 'literal';
        $this->persons[$name]['item'][$this->properties["foaf"]["firstName"]->term()][] = $valueObject;
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["foaf"]["lastName"]->id();
        $valueObject['@value'] = $p['lastName'];
        $valueObject['type'] = 'literal';
        $this->persons[$name]['item'][$this->properties["foaf"]["lastName"]->term()][] = $valueObject;

        //vérifie la présence de l'item pour gérer la création ou la mise à jour
        $param = array();
        $param['property'][0]['property']= $this->properties["foaf"]["givenName"]->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$name; 
        //$this->logger->info("RECHERCHE PARAM = ".json_encode($param));
        $result = $this->api->search('items',$param)->getContent();
        //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
        //$this->logger->info("RECHERCHE COUNT = ".count($result));
        if(count($result)){
            $this->persons[$name]['id']=$result[0]->id();
            $this->api->update('items', $this->persons[$name]['id'], $this->persons[$name]['item'], [], ['continueOnError' => true,'isPartial'=>1, 'collectionAction' => 'append']);
        }else{
            $result = $this->api->create('items', $this->persons[$name]['item'], [], ['continueOnError' => true])->getContent();
            $this->persons[$name]['id']=$result->id();
        }
        //TODO:compter le nombre de personne créées

        return $this->persons[$name];
    }


    /**
     * Ajoute une annotation au format open annotation
     *
     * @param  o:item $doc
     * @param  o:item $act
     * @param  o:item $tag
     * @return array
     */
    protected function ajouteAnnotation($doc, $act, $tag)
    {
        $ref = "idDoc:".$doc->id()
        ."_idActant:".$act->id()
        ."_idTag:".$tag->id();
        $this->logger->info("ajouteAnnotation ".$ref);
        
        //vérifie la présence de l'item pour gérer la création ou la mise à jour
        $param = array();
        $param['property'][0]['property']= $this->properties["dcterms"]["isReferencedBy"]->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$ref; 
        $result = $this->api->search('annotations',$param)->getContent();
        //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
        //$this->logger->info("RECHERCHE COUNT = ".count($result));
        $update = false;
        if(count($result)){
            $update = true;
            $idAno = $result[0]->id();
        }            

        //création de l'annotation       
        $oItem = [];

        //référence
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["dcterms"]["isReferencedBy"]->id();
        $valueObject['@value'] = $ref;
        $valueObject['type'] = 'literal';
        $oItem[$this->properties["dcterms"]["isReferencedBy"]->term()][] = $valueObject;    

        //motivation
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["oa"]["motivatedBy"]->id();
        $valueObject['@value'] = 'tagging';
        $valueObject['type'] = 'customvocab:'.$this->customVocab['Annotation oa:motivatedBy'];
        $oItem[$this->properties["oa"]["motivatedBy"]->term()][] = $valueObject;    

        //annotator = actant
        $valueObject = [];                
        $valueObject['value_resource_id']=$act->id();        
        $valueObject['property_id']=$this->properties["dcterms"]["creator"]->id();
        $valueObject['type']='resource';    
        $oItem['dcterms:creator'][] = $valueObject;    

        //source = doc 
        $valueObject = [];                
        $valueObject['property_id']=$this->properties["oa"]["hasSource"]->id();
        $valueObject['type']='resource';
        $valueObject['value_resource_id']=$doc->id();
        $oItem['oa:hasSource'][] = $valueObject;    
         
        //body = texte explicatif
        $valueObject = [];                
        $valueObject['rdf:value'][0]['@value']=$act->displayTitle()
            .' a taggé le document '.$doc->displayTitle()
            .' avec le tag '.$tag->displayTitle();        
        $valueObject['rdf:value'][0]['property_id']=$this->properties["rdf"]["value"]->id();
        $valueObject['rdf:value'][0]['type']='literal';    
        $valueObject['oa:hasPurpose'][0]['@value']='classifying';
        $valueObject['oa:hasPurpose'][0]['property_id']=$this->properties["oa"]["hasPurpose"]->id();
        $valueObject['oa:hasPurpose'][0]['type']='customvocab:'.$this->customVocab['Annotation Body oa:hasPurpose'];
        $oItem['oa:hasBody'][] = $valueObject;    

        //target = tag 
        $valueObject = [];                
        $valueObject['rdf:value'][0]['value_resource_id']=$tag->id();        
        $valueObject['rdf:value'][0]['property_id']=$this->properties["rdf"]["value"]->id();
        $valueObject['rdf:value'][0]['type']='resource';    
        $valueObject['rdf:type'][0]['@value']='o:Item';        
        $valueObject['rdf:type'][0]['property_id']=$this->properties["rdf"]["type"]->id();
        $valueObject['rdf:type'][0]['type']='customvocab:'.$this->customVocab['Annotation Target rdf:type'];            
        $oItem['oa:hasTarget'][] = $valueObject;    

        //type omeka
        $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['oa']['Annotation']->id()];
        $oItem['o:resource_template'] = ['o:id' => $this->resourceTemplate['Annotation']->id()];

        if($update){
            $result = $this->api->update('annotations', $idAno, $oItem, []
                , ['isPartial'=>true, 'continueOnError' => true]);
        }else{
            //création de l'annotation
            $result = $this->api->create('annotations', $oItem, [], ['continueOnError' => true])->getContent();        

        }        

        //TODO:enregistrer le nombre de création et d'update    

        //met à jour l'item avec le tag 
        $param = [];
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["skos"]["semanticRelation"]->id();
        $valueObject['value_resource_id'] = $tag->id();
        $valueObject['type'] = 'resource';
        $param[$this->properties["skos"]["semanticRelation"]->term()][] = $valueObject;
        $this->api->update('items', $doc->id(), $param, []
            , ['isPartial'=>true, 'continueOnError' => true, 'collectionAction' => 'append']);

        return $result;

    }
    

    //fin ajout

    /**
     * Set the HTTP client to use during this import.
     */
    public function setImportClient()
    {
        $headers = ['Zotero-API-Version' => '3'];
        if ($apiKey = $this->getArg('apiKey')) {
            $headers['Authorization'] = sprintf('Bearer %s', $apiKey);
        }
        $this->client = $this->getServiceLocator()->get('Omeka\HttpClient')
            ->setHeaders($headers)
            // Decrease the chance of timeout by increasing to 20 seconds,
            // which splits the time between Omeka's default (10) and Zotero's
            // upper limit (30).
            ->setOptions(['timeout' => 20]);
    }

    /**
     * Set the Zotero URL object to use during this import.
     */
    public function setImportUrl()
    {
        $this->url = new Url($this->getArg('type'), $this->getArg('id'));
    }

    /**
     * Get a response from the Zotero API.
     *
     * @param string $url
     * @return Response
     */
    public function getResponse($url)
    {
        $response = $this->client->setUri($url)->send();
        if (!$response->isSuccess()) {
            throw new Exception\RuntimeException(sprintf(
                'Requested "%s" got "%s".', $url, $response->renderStatusLine()
            ));
        }
        return $response;
    }

    /**
     * Cache selected resource classes.
     */
    public function cacheResourceClasses()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->vocabularies as $prefix => $namespaceUri) {
            $classes = $api->search('resource_classes', [
                'vocabulary_namespace_uri' => $namespaceUri,
            ])->getContent();
            foreach ($classes as $class) {
                $this->resourceClasses[$prefix][$class->localName()] = $class;
                //$this->logger->info("cacheResourceClasses = ".$prefix." : ".$class->localName());
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
            $properties = $api->search('properties', [
                'vocabulary_namespace_uri' => $namespaceUri,
            ])->getContent();
            foreach ($properties as $property) {
                $this->properties[$prefix][$property->localName()] = $property;
            }
        }
    }


    /**ajout samszo
     * Cache selected resource template.
     */
    public function cacheResourceTemplate()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $arrRT = ["Annotation","Actant"];
        foreach ($arrRT as $label) {
            $rt = $api->search('resource_templates', [
                'label' => $label,
            ])->getContent();
            $this->resourceTemplate[$label]=$rt[0];
            //$this->logger->info("cacheResourceTemplate = ".$label,$rt);
        }
        //$this->logger->info("cacheResourceTemplate",$this->resourceTemplate);            
    }
    /**
     * Cache custom vocab.
     */
    public function cacheCustomVocab()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $arrRT = ["Annotation Target rdf:type","Annotation oa:motivatedBy","Annotation Body oa:hasPurpose"];
        foreach ($arrRT as $label) {
            $customVocab = $api->read('custom_vocabs', [
                'label' => $label,
            ], [], ['responseContent' => 'reference'])->getContent();
            $this->customVocab[$label]=$customVocab->id();
            //$this->logger->info("cacheCustomVocab = ".$label." : ".$customVocab->id());
        }
    }

    //fin ajout

    /**
     * Convert a mapping with terms into a mapping with prefix and local name.
     *
     * @param string $mapping
     * @return array
     */
    protected function prepareMapping($mapping)
    {
        $map = require dirname(dirname(__DIR__)) . '/data/mapping/' . $mapping . '.php';
        foreach ($map as &$term) {
            if ($term) {
                $value = explode(':', $term);
                $term = [$value[0] => $value[1]];
            } else {
                $term = [];
            }
        }
        return $map;
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
                $omekaItem['o:resource_class'] = ['o:id' => $class->id()];
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
                    $valueObject = [];
                    $valueObject['property_id'] = $property->id();
                    if ('bibo' == $prefix && 'uri' == $localName) {
                        $valueObject['@id'] = $value;
                        $valueObject['type'] = 'uri';
                    } else {
                        $valueObject['@value'] = $value;
                        $valueObject['type'] = 'literal';
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
            $name = [];
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
                //ajout samszo                
                if(!isset($this->persons[$name])){
                    $this->ajoutePersonne($name, $creator);                    
                }
                //fin ajout

                if (isset($this->properties[$prefix][$localName])) {
                    $property = $this->properties[$prefix][$localName];
                    $omekaItem[$property->term()][] = [
                        'property_id' => $property->id(),
                        /*ajout samszo
                        '@value' => $name,
                        'type' => 'literal',
                        */
                        'value_resource_id' => $this->persons[$name]['id'],
                        'type' => 'resource'
                    ];
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
            //ajout samszo
            //prise en compte des -
            $tags = explode(' - ', $tag['tag']);
            foreach ($tags as $t) {
                $omekaItem[$property->term()][] = [
                    '@value' => $t,
                    'property_id' => $property->id(),
                    'type' => 'literal',
                ];
                $omekaItem['o-module-folksonomy:tag-new'][] = $t;
            }
            //fin ajout
        }
        return $omekaItem;
    }


    /**
     * Map an attachment.
     *
     * There are four kinds of Zotero attachments: imported_url, imported_file,
     * linked_url, and linked_file. Only imported_url and imported_file have
     * files, and only when the response includes an enclosure link. For
     * linked_url, the @id URL was already mapped in mapValues(). For
     * linked_file, there is nothing to save.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return string
      */
    public function mapAttachment($zoteroItem, $omekaItem)
    {
        if ('attachment' === $zoteroItem['data']['itemType']
            && isset($zoteroItem['links']['enclosure'])
            && $this->getArg('importFiles')
            && $this->getArg('apiKey')
        ) {
            $property = $this->properties['dcterms']['title'];
            $omekaItem['o:media'][] = [
                'o:ingester' => 'url',
                'o:source'   => $this->url->itemFile($zoteroItem['key']),
                'ingest_url' => $this->url->itemFile(
                    $zoteroItem['key'],
                    ['key' => $this->getArg('apiKey')]
                ),
                $property->term() => [
                    [
                        '@value' => $zoteroItem['data']['title'],
                        'property_id' => $property->id(),
                        'type' => 'literal',
                    ],
                ],
            ];
        }
        return $omekaItem;
    }

    /**
     * Get a URL from the Link header.
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
