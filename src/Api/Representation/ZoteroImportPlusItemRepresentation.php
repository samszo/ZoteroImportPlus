<?php
namespace ZoteroImportplus\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class ZoteroImportplusItemRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLdType()
    {
        return 'o-module-zotero_importplus:ZoteroImportplusItem';
    }

    public function getJsonLd()
    {
        return [
            'o-module-zotero_importplus:import' => $this->import()->getReference(),
            'o:item' => $this->job()->getReference(),
        ];
    }

    public function import()
    {
        return $this->getAdapter('zotero_importplus')
            ->getRepresentation($this->resource->getImport());
    }

    public function item()
    {
        return $this->getAdapter('items')
            ->getRepresentation($this->resource->getItem());
    }
}
