<?php
namespace ZoteroImportplus\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class ZoteroImportplusRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'zotero-importplus';
    }

    public function getJsonLdType()
    {
        return 'o-module-zotero_importplus:ZoteroImportplus';
    }

    public function getJsonLd()
    {
        return [
            'o:job' => $this->job()->getReference(),
            'o-module-zotero_importplus:undo_job' => $this->undoJob()->getReference(),
            'o-module-zotero_importplus:name' => $this->resource->getName(),
            'o-module-zotero_importplus:url' => $this->resource->getUrl(),
            'o-module-zotero_importplus:version' => $this->resource->getVersion(),
        ];
    }

    public function job()
    {
        return $this->getAdapter('jobs')
            ->getRepresentation($this->resource->getJob());
    }

    public function undoJob()
    {
        return $this->getAdapter('jobs')
            ->getRepresentation($this->resource->getUndoJob());
    }

    public function version()
    {
        return $this->resource->getVersion();
    }

    public function name()
    {
        return $this->resource->getName();
    }

    public function libraryUrl()
    {
        return $this->resource->getUrl();
    }

    public function importItemCount()
    {
        return $this->resource->getImportItems()->count();
    }
}
