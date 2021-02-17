<?php
namespace ZoteroImportPlus\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class ZoteroImportPlusItemRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLdType()
    {
        return 'o-module-zotero_import:ZoteroImportPlusItem';
    }

    public function getJsonLd()
    {
        return [
            'o-module-zotero_import:import' => $this->import()->getReference(),
            'o:item' => $this->job()->getReference(),
        ];
    }

    public function import()
    {
        return $this->getAdapter('zotero_imports')
            ->getRepresentation($this->resource->getImport());
    }

    public function item()
    {
        return $this->getAdapter('items')
            ->getRepresentation($this->resource->getItem());
    }
}
