<?php
namespace ZoteroImportplus\Api\Adapter;

use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class ZoteroImportplusAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'zotero_importplus';
    }

    public function getRepresentationClass()
    {
        return \ZoteroImportplus\Api\Representation\ZoteroImportplusRepresentation::class;
    }

    public function getEntityClass()
    {
        return \ZoteroImportplus\Entity\ZoteroImportplus::class;
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();

        if (isset($data['o:job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o:job']['o:id']);
            $entity->setJob($job);
        }
        if (isset($data['o-module-zotero_importplus:undo_job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o-module-zotero_importplus:undo_job']['o:id']);
            $entity->setUndoJob($job);
        }

        if (isset($data['o-module-zotero_importplus:version'])) {
            $entity->setVersion($data['o-module-zotero_importplus:version']);
        }
        if (isset($data['o-module-zotero_importplus:name'])) {
            $entity->setName($data['o-module-zotero_importplus:name']);
        }
        if (isset($data['o-module-zotero_importplus:url'])) {
            $entity->setUrl($data['o-module-zotero_importplus:url']);
        }
    }
}
